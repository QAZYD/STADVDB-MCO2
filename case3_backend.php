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

// nodes
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

    /* =====================================
       1) Trigger Server1 via HTTP
    ======================================*/
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

    /* =====================================
       2) Server0 DB write
    ======================================*/
    log_msg("Connecting to Server0 DB...");
    $m = new mysqli($nodes['server0']['host'],$nodes['server0']['user'],$nodes['server0']['pass'],$nodes['server0']['db']);
    log_msg("Connected to Server0 successfully.");

    $m->query("SET autocommit = 0");
    $m->query("SET SESSION TRANSACTION ISOLATION LEVEL $level");
    log_msg("Server0 transaction started with $level.");

    $m->begin_transaction();

    $ts0 = microtime(true);
    $ver0 = (int) round($ts0 * 1000000);
    $server0_id = "server0";

    log_msg("Server0 updating row id=$targetId with value '$value0'.");

    $stmt = $m->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
    $stmt->bind_param("sdsii",$value0,$ts0,$server0_id,$ver0,$targetId);
    $stmt->execute();
    $stmt->close();

    log_msg("Server0 sleeping 200ms to create overlap...");
    usleep(200000);

    $m->commit();
    log_msg("Server0 committed write.");

    /* =====================================
       3) Read final rows from both servers
    ======================================*/
    log_msg("Reading Server0 row state...");
    $row0 = $m->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();
    log_msg("Server0 row: " . json_encode($row0));

    log_msg("Connecting to Server1 DB to read its row...");
    $s1 = new mysqli($nodes['server1']['host'],$nodes['server1']['user'],$nodes['server1']['pass'],$nodes['server1']['db']);

    if ($s1->connect_error) {
        log_msg("!! Server1 DB connect failed. Using HTTP-provided row instead.");
        $row1 = isset($server1['row']) ? $server1['row'] : null;
    } else {
        $row1 = $s1->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();
        log_msg("Server1 row: " . json_encode($row1));
        $s1->close();
    }

    /* =====================================
       4) Conflict resolution
    ======================================*/
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

    /* =====================================
       5) Propagate the winner
    ======================================*/
    if ($winner['row']) {
        log_msg("Propagating winner to both servers...");

        $resolvedValue = $winner['row']['firstName'];
        $resolved_ts = microtime(true);
        $resolved_ver = (int) round($resolved_ts * 1000000);

        // Update Server0
        log_msg("Updating Server0 with resolved value: $resolvedValue");
        $m2 = new mysqli($nodes['server0']['host'],$nodes['server0']['user'],$nodes['server0']['pass'],$nodes['server0']['db']);
        $stmt2 = $m2->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
        $stmt2->bind_param("sdsii",$resolvedValue,$resolved_ts,$winner['row']['last_update_server'],$resolved_ver,$targetId);

        try {
            $stmt2->execute();
        } catch(mysqli_sql_exception $e) {
            $deadlock = strpos($e->getMessage(), 'lock wait timeout') !== false || 
                        strpos($e->getMessage(), 'deadlock') !== false;

            if ($deadlock) {
                echo json_encode([
                    "status" => "ERROR",
                    "type" => "DEADLOCK",
                    "isolation" => $level,
                    "message" => "Deadlock detected under Serializable"
                ]);
                exit;
            }

            echo json_encode([
                "status" => "ERROR",
                "type"   => "SQL_ERROR",
                "message" => $e->getMessage()
            ]);
            exit;
        }

        $stmt2->close();
        $m2->close();

        // Update Server1 DB
        log_msg("Updating Server1 with resolved value: $resolvedValue");
        $s1b = new mysqli($nodes['server1']['host'],$nodes['server1']['user'],$nodes['server1']['pass'],$nodes['server1']['db']);
        if (!$s1b->connect_error) {
            $stmt3 = $s1b->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
            $stmt3->bind_param("sdsii",$resolvedValue,$resolved_ts,$winner['row']['last_update_server'],$resolved_ver,$targetId);
            $stmt3->execute();
            $stmt3->close();
            $s1b->close();
        } else {
            log_msg("!! Server1 DB unavailable for propagation.");
        }
    }

    $results[$level] = [
        'server0' => ['status'=>'Committed','duration'=>round(microtime(true)-$ts0,6)],
        'server1' => $server1,
        'row0' => $row0,
        'row1' => $row1,
        'winner' => $winner
    ];

    log_msg("Completed isolation level $level.");
}

log_msg("=== Simulation Complete ===");

echo json_encode($results, JSON_PRETTY_PRINT);
