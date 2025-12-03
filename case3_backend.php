<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Utility helper to log with timestamps
function log_msg($msg) {
    echo "[" . date("H:i:s") . "] $msg\n";
    @ob_flush();
    @flush();
}

/**
 * Execute a prepared statement with retries on deadlock / lock wait timeout.
 *
 * $mysqli    : mysqli connection (must be opened by caller)
 * $sql       : SQL with placeholders
 * $types     : bind_param types string or null if none
 * $params    : array of params (in same order), or empty array
 * $maxRetries: how many retries (default 5)
 *
 * Returns true on success. Throws exception on final failure.
 */
function execute_with_retries(mysqli $mysqli, string $sql, ?string $types = null, array $params = [], int $maxRetries = 5) {
    $attempt = 0;

    while (true) {
        $attempt++;
        try {
            $stmt = $mysqli->prepare($sql);
            if ($stmt === false) {
                throw new mysqli_sql_exception("Prepare failed: " . $mysqli->error);
            }

            if ($types !== null && count($params) > 0) {
                // bind params dynamically
                $bind_names = [];
                $bind_names[] = $types;
                for ($i = 0; $i < count($params); $i++) {
                    $bind_name = 'bind' . $i;
                    $$bind_name = $params[$i];
                    $bind_names[] = &$$bind_name;
                }
                call_user_func_array([$stmt, 'bind_param'], $bind_names);
            }

            $ok = $stmt->execute();
            if ($ok === false) {
                throw new mysqli_sql_exception("Execute failed: " . $stmt->error);
            }

            $stmt->close();
            return true; // success
        } catch (mysqli_sql_exception $e) {
            // detect deadlock or lock wait timeout messages
            $msg = $e->getMessage();
            $is_deadlock = (stripos($msg, 'deadlock') !== false) || (stripos($msg, 'lock wait timeout') !== false);

            // If this is a deadlock/lock wait timeout and we can retry, do it
            if ($is_deadlock && $attempt <= $maxRetries) {
                $wait_ms = rand(100, 500) * $attempt; // increasing backoff
                log_msg("Query attempt #$attempt failed with lock contention: " . trim($msg));
                log_msg(" -> Retrying after {$wait_ms}ms (maxRetries={$maxRetries})...");
                usleep($wait_ms * 1000);
                // continue loop to retry
            } else {
                // final failure or non-retriable error
                throw $e;
            }
        }
    }
}

/**
 * Perform a transactional update with retries.
 * The callback receives a mysqli connection where it should run statements (use execute_with_retries to run statements).
 * This handles begin/commit/rollback and retries the entire transaction on deadlock.
 *
 * $hostConfig : array with host,user,pass,db
 * $isolation  : isolation level string for session
 * $callback   : function(mysqli $conn) { ... } - should throw on failures
 * $maxRetries : number of full-transaction retries
 */
function transaction_with_retries(array $hostConfig, string $isolation, callable $callback, int $maxRetries = 5) {
    $attempt = 0;
    while (true) {
        $attempt++;
        $mysqli = new mysqli($hostConfig['host'], $hostConfig['user'], $hostConfig['pass'], $hostConfig['db']);
        if ($mysqli->connect_error) {
            throw new mysqli_sql_exception("Connect failed to {$hostConfig['host']}: " . $mysqli->connect_error);
        }

        try {
            // Set session isolation and disable autocommit
            $mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL $isolation");
            $mysqli->query("SET autocommit = 0");
            $mysqli->begin_transaction();

            // Let caller run statements on $mysqli
            $callback($mysqli);

            // commit and close
            $mysqli->commit();
            $mysqli->close();
            return true;
        } catch (mysqli_sql_exception $e) {
            // rollback safely
            @($mysqli->rollback());
            $msg = $e->getMessage();
            $is_deadlock = (stripos($msg, 'deadlock') !== false) || (stripos($msg, 'lock wait timeout') !== false);

            $mysqli->close();

            if ($is_deadlock && $attempt <= $maxRetries) {
                $wait_ms = rand(100, 500) * $attempt;
                log_msg("Transaction attempt #$attempt failed with lock contention: " . trim($msg));
                log_msg(" -> Retrying transaction after {$wait_ms}ms (maxRetries={$maxRetries})...");
                usleep($wait_ms * 1000);
                continue; // retry whole transaction
            }

            // rethrow final or non-retriable error
            throw $e;
        }
    }
}

/* -----------------------
   START OF SIMULATION
   ----------------------- */

$nodes = [
    'server0' => ['host'=>'10.2.14.129','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
    'server1' => ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker','http_base'=>'http://10.2.14.130']
];

$levels = ["READ UNCOMMITTED","READ COMMITTED","REPEATABLE READ","SERIALIZABLE"];

$targetId = 1;
$value0 = 'Server0Write';
$value1 = 'Server1Write';

// 10 second HTTP timeout
$context = stream_context_create([
    'http'=>['timeout'=>10]
]);

$results = [];

log_msg("=== Starting Case #3 Multi-Master Simulation ===");

foreach ($levels as $level) {
    log_msg("");
    log_msg("=== Isolation Level: $level ===");

    // 1) Trigger Server1 via HTTP
    $url = $nodes['server1']['http_base']
         . '/simulated_case3.php'
         . '?isolation=' . urlencode($level)
         . '&server_id=server1'
         . '&id=' . urlencode($targetId)
         . '&value=' . urlencode($value1);

    log_msg("Calling Server1 HTTP endpoint:");
    log_msg("  URL = $url");

    $server1_raw = @file_get_contents($url, false, $context);
    if ($server1_raw === false) {
        log_msg("!! ERROR: Server1 HTTP request failed or timed out.");
        $server1 = ['status'=>'ERROR','duration'=>0,'row'=>null];
    } else {
        log_msg("Server1 HTTP response:");
        log_msg("  RAW: $server1_raw");
        $server1 = json_decode($server1_raw,true);
    }

    // 2) Server0 DB write (transaction with retries)
    log_msg("Connecting to Server0 DB and performing transactional write...");
    try {
        transaction_with_retries($nodes['server0'], $level, function(mysqli $m) use ($targetId, $value0) {
            $ts0 = microtime(true);
            $ver0 = (int) round($ts0 * 1000000);
            $server0_id = "server0";

            log_msg("Server0 updating row id={$targetId} with value '{$value0}' (inside transaction).");

            // Use execute_with_retries for the statement itself (to handle intermittent lock contention)
            execute_with_retries($m,
                "UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?",
                "sdsii",
                [$value0, $ts0, $server0_id, $ver0, $targetId],
                5
            );

            // create artificial overlap
            log_msg("Server0 sleeping 200ms to create overlap...");
            usleep(200000);
        }, 5); // transaction retries up to 5
        log_msg("Server0 committed write (transaction succeeded).");
    } catch (mysqli_sql_exception $e) {
        log_msg("ERROR: Server0 transactional write failed: " . $e->getMessage());
        // Record result and continue to next isolation level
        $results[$level] = [
            'server0' => ['status'=>'ERROR','message'=>$e->getMessage()],
            'server1' => $server1,
            'row0'    => null,
            'row1'    => isset($server1['row']) ? $server1['row'] : null,
            'winner'  => null
        ];
        log_msg("Skipping propagation for isolation level $level due to write failure.");
        continue;
    }

    // 3) Read final rows from both servers
    // Connect to Server0 for read
    $m_read = new mysqli($nodes['server0']['host'],$nodes['server0']['user'],$nodes['server0']['pass'],$nodes['server0']['db']);
    if ($m_read->connect_error) {
        log_msg("!! ERROR: cannot connect to Server0 for read: " . $m_read->connect_error);
        $row0 = null;
    } else {
        $row0 = $m_read->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();
        $m_read->close();
    }
    log_msg("Server0 row: " . json_encode($row0));

    // Connect to Server1 DB to read row (fallback to HTTP-provided row if DB connect fails)
    $s1 = new mysqli($nodes['server1']['host'],$nodes['server1']['user'],$nodes['server1']['pass'],$nodes['server1']['db']);
    if ($s1->connect_error) {
        log_msg("!! Server1 DB connect failed. Using HTTP-provided row instead.");
        $row1 = isset($server1['row']) ? $server1['row'] : null;
    } else {
        $row1 = $s1->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();
        $s1->close();
    }
    log_msg("Server1 row: " . json_encode($row1));

    // 4) Conflict resolution
    log_msg("Resolving conflicts...");
    $winner = null;
    if ($row0 && $row1) {
        log_msg("Comparing timestamps...");
        $ts0f = floatval($row0['last_update_ts']);
        $ts1f = floatval($row1['last_update_ts']);

        if ($ts0f > $ts1f) {
            $winner = ['row'=>$row0,'source'=>'server0'];
        } elseif ($ts1f > $ts0f) {
            $winner = ['row'=>$row1,'source'=>'server1'];
        } else {
            log_msg("Timestamps equal, resolving by server name...");
            $winner = ($row0['last_update_server'] >= $row1['last_update_server'])
                ? ['row'=>$row0,'source'=>'server0']
                : ['row'=>$row1,'source'=>'server1'];
        }
    } elseif ($row0) {
        $winner = ['row'=>$row0,'source'=>'server0'];
    } elseif ($row1) {
        $winner = ['row'=>$row1,'source'=>'server1'];
    } else {
        $winner = ['row'=>null,'source'=>'none'];
    }

    log_msg("Winner is from: " . $winner['source']);
    log_msg("Winner row: " . json_encode($winner['row']));

    // 5) Propagate the winner: do each propagation with retries (non-transactional updates,
    // but each update is retried on lock contention).
    if ($winner['row']) {
        log_msg("Propagating winner to both servers...");

        $resolvedValue = $winner['row']['firstName'];
        $resolved_ts = microtime(true);
        $resolved_ver = (int) round($resolved_ts * 1000000);
        $last_update_server = $winner['row']['last_update_server'];

        // Update Server0 using a fresh connection and retries
        log_msg("Updating Server0 with resolved value: $resolvedValue");
        try {
            $m2 = new mysqli($nodes['server0']['host'],$nodes['server0']['user'],$nodes['server0']['pass'],$nodes['server0']['db']);
            if ($m2->connect_error) {
                throw new mysqli_sql_exception("Connect failed to server0 for propagation: " . $m2->connect_error);
            }

            execute_with_retries($m2,
                "UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?",
                "sdsii",
                [$resolvedValue, $resolved_ts, $last_update_server, $resolved_ver, $targetId],
                5
            );

            $m2->close();
            log_msg("Server0 update (propagation) succeeded.");
        } catch (mysqli_sql_exception $e) {
            log_msg("ERROR: propagation to Server0 failed: " . $e->getMessage());
            // continue to attempt Server1, but record error in results
        }

        // Update Server1 using a fresh connection and retries
        log_msg("Updating Server1 with resolved value: $resolvedValue");
        try {
            $s1b = new mysqli($nodes['server1']['host'],$nodes['server1']['user'],$nodes['server1']['pass'],$nodes['server1']['db']);
            if ($s1b->connect_error) {
                throw new mysqli_sql_exception("Connect failed to server1 for propagation: " . $s1b->connect_error);
            }

            execute_with_retries($s1b,
                "UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?",
                "sdsii",
                [$resolvedValue, $resolved_ts, $last_update_server, $resolved_ver, $targetId],
                5
            );

            $s1b->close();
            log_msg("Server1 update (propagation) succeeded.");
        } catch (mysqli_sql_exception $e) {
            log_msg("ERROR: propagation to Server1 failed: " . $e->getMessage());
        }
    }

    // collect results for this isolation level
    $results[$level] = [
        'server0' => ['status'=>'Committed','duration'=>round(microtime(true)-$ts0,6)],
        'server1' => $server1,
        'row0'    => $row0,
        'row1'    => $row1,
        'winner'  => $winner
    ];

    log_msg("Completed isolation level $level.");
}

log_msg("=== Simulation Complete ===");
echo json_encode($results, JSON_PRETTY_PRINT);
