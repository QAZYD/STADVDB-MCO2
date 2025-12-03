<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Buffer all logs
$log_buffer = [];

// Log helper
function log_msg($msg) {
    global $log_buffer;
    $log_buffer[] = "[" . date("H:i:s") . "] $msg";
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
$errors = [];

log_msg("=== Starting Case #3 Multi-Master Simulation ===");

foreach ($levels as $level) {
    log_msg("");
    log_msg("=== Isolation Level: $level ===");

    try {
        /* Trigger Server1 via HTTP */
        $url = $nodes['server1']['http_base']
             . '/simulated_case3.php'
             . '?isolation=' . urlencode($level)
             . '&server_id=server1'
             . '&id=' . urlencode($targetId)
             . '&value=' . urlencode($value1);
        log_msg("Calling Server1 HTTP endpoint: $url");
        $server1_raw = @file_get_contents($url, false, $context);

        if ($server1_raw === false) {
            log_msg("!! ERROR: Server1 HTTP request failed or timed out.");
            $server1 = ['status'=>'ERROR','duration'=>0,'row'=>null];
        } else {
            $server1 = json_decode($server1_raw,true);
            log_msg("Server1 HTTP response: " . $server1_raw);
        }

        /* Server0 write */
        $m = new mysqli($nodes['server0']['host'],$nodes['server0']['user'],$nodes['server0']['pass'],$nodes['server0']['db']);
        $m->query("SET autocommit = 0");
        $m->query("SET SESSION TRANSACTION ISOLATION LEVEL $level");
        $m->begin_transaction();

        $ts0 = microtime(true);
        $ver0 = (int) round($ts0 * 1000000);
        $server0_id = "server0";

        $stmt = $m->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
        $stmt->bind_param("sdsii",$value0,$ts0,$server0_id,$ver0,$targetId);
        $stmt->execute();
        $stmt->close();
        usleep(200000);
        $m->commit();

        $row0 = $m->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();
        $m->close();

        $s1 = new mysqli($nodes['server1']['host'],$nodes['server1']['user'],$nodes['server1']['pass'],$nodes['server1']['db']);
        if ($s1->connect_error) {
            $row1 = isset($server1['row']) ? $server1['row'] : null;
        } else {
            $row1 = $s1->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();
            $s1->close();
        }

        /* Conflict resolution */
        if ($row0 && $row1) {
            $winner = floatval($row0['last_update_ts']) > floatval($row1['last_update_ts']) ? ['row'=>$row0,'source'=>'server0'] : ['row'=>$row1,'source'=>'server1'];
        } elseif ($row0) {
            $winner = ['row'=>$row0,'source'=>'server0'];
        } elseif ($row1) {
            $winner = ['row'=>$row1,'source'=>'server1'];
        } else {
            $winner = ['row'=>null,'source'=>'none'];
        }

        /* Propagate winner */
        if ($winner['row']) {
            $resolvedValue = $winner['row']['firstName'];
            $resolved_ts = microtime(true);
            $resolved_ver = (int) round($resolved_ts * 1000000);
            $winner_server = $winner['row']['last_update_server'];

            // Server0
            $m2 = new mysqli($nodes['server0']['host'],$nodes['server0']['user'],$nodes['server0']['pass'],$nodes['server0']['db']);
            $stmt2 = $m2->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
            $stmt2->bind_param("sdsii",$resolvedValue,$resolved_ts,$winner_server,$resolved_ver,$targetId);
            $stmt2->execute();
            $stmt2->close();
            $m2->close();

            // Server1
            $s1b = new mysqli($nodes['server1']['host'],$nodes['server1']['user'],$nodes['server1']['pass'],$nodes['server1']['db']);
            if (!$s1b->connect_error) {
                $stmt3 = $s1b->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
                $stmt3->bind_param("sdsii",$resolvedValue,$resolved_ts,$winner_server,$resolved_ver,$targetId);
                $stmt3->execute();
                $stmt3->close();
                $s1b->close();
            }
        }

        $results[$level] = [
            'server0' => ['status'=>'Committed','row'=>$row0],
            'server1' => $server1,
            'winner' => $winner
        ];

    } catch(mysqli_sql_exception $e) {
        $errors[$level] = [
            'type' => 'SQL_ERROR',
            'message' => $e->getMessage()
        ];
    }
}

echo json_encode([
    'logs' => $log_buffer,
    'results' => $results,
    'errors' => $errors
], JSON_PRETTY_PRINT);
