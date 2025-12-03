<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function log_msg($msg) {
    echo "[" . date("H:i:s") . "] $msg\n";
    @ob_flush();
    @flush();
}

$nodes = [
    'server0' => ['host'=>'10.2.14.129','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
    'server1' => ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker','http_base'=>'http://10.2.14.130']
];

$levels = ["READ UNCOMMITTED","READ COMMITTED","REPEATABLE READ","SERIALIZABLE"];
$targetId = 1;
$value0 = 'Server0Write';
$value1 = 'Server1Write';
$results = [];

log_msg("=== Starting Multi-Master Deadlock Experiment ===");

foreach ($levels as $level) {
    log_msg("");
    log_msg("=== Isolation Level: $level ===");

    // 1) Start Server1 async via curl
    $url = $nodes['server1']['http_base'] . '/simulated_case3.php'
         . '?isolation=' . urlencode($level)
         . '&server_id=server1'
         . '&id=' . urlencode($targetId)
         . '&value=' . urlencode($value1);

    log_msg("Triggering Server1 asynchronously: $url");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Connection: close"]);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_exec($ch); // fire-and-forget
    curl_close($ch);

    // 2) Server0 transaction
    $m = new mysqli($nodes['server0']['host'],$nodes['server0']['user'],$nodes['server0']['pass'],$nodes['server0']['db']);
    $m->query("SET autocommit=0");
    $m->query("SET SESSION TRANSACTION ISOLATION LEVEL $level");
    $m->begin_transaction();

    $ts0 = microtime(true);
    $ver0 = (int)round($ts0 * 1000000);

    try {
        $stmt = $m->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
        $stmt->bind_param("sdsii",$value0,$ts0,'server0',$ver0,$targetId);
        $stmt->execute();
        $stmt->close();

        log_msg("Server0 updated row, sleeping 200ms...");
        usleep(200000);

        $m->commit();
        log_msg("Server0 committed write.");
    } catch(mysqli_sql_exception $e) {
        $m->rollback();
        $deadlock = strpos($e->getMessage(), 'deadlock') !== false || strpos($e->getMessage(), 'lock wait timeout') !== false;
        $results[$level] = [
            'status'=>'ERROR',
            'type'=>$deadlock ? 'DEADLOCK' : 'SQL_ERROR',
            'message'=>$e->getMessage()
        ];
        log_msg("Transaction failed: " . $e->getMessage());
        continue; // go to next isolation level
    }

    // 3) Read Server0 row
    $row0 = $m->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();
    log_msg("Server0 row: " . json_encode($row0));
    $m->close();

    // 4) Read Server1 row (best-effort)
    $s1 = new mysqli($nodes['server1']['host'],$nodes['server1']['user'],$nodes['server1']['pass'],$nodes['server1']['db']);
    $row1 = $s1->connect_error ? null : $s1->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();
    $s1->close();
    log_msg("Server1 row: " . json_encode($row1));

    // 5) Conflict resolution
    $winner = null;
    if ($row0 && $row1) {
        $winner = (floatval($row0['last_update_ts']) >= floatval($row1['last_update_ts']))
                  ? ['row'=>$row0,'source'=>'server0'] 
                  : ['row'=>$row1,'source'=>'server1'];
    } elseif ($row0) {
        $winner = ['row'=>$row0,'source'=>'server0'];
    } elseif ($row1) {
        $winner = ['row'=>$row1,'source'=>'server1'];
    } else {
        $winner = ['row'=>null,'source'=>'none'];
    }

    log_msg("Winner: " . $winner['source']);

    $results[$level] = [
        'server0'=>$row0,
        'server1'=>$row1,
        'winner'=>$winner
    ];
}

log_msg("=== Experiment Complete ===");
echo json_encode($results, JSON_PRETTY_PRINT);
