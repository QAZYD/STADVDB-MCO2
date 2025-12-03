<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// nodes
$nodes = [
  'server0' => ['host'=>'10.2.14.129','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
  'server1' => ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker','http_base'=>'http://10.2.14.130']
];

// isolation levels
$levels = ["READ UNCOMMITTED","READ COMMITTED","REPEATABLE READ","SERIALIZABLE"];

// target row id and values to write
$targetId = 1;
$value0 = 'Server0Write';
$value1 = 'Server1Write';

// HTTP timeout context (30s)
$context = stream_context_create(['http'=>['timeout'=>30]]);

$results = [];

foreach ($levels as $level) {
    // 1) Trigger Server1 (HTTP) to do its write with metadata
    $url = $nodes['server1']['http_base'] . '/simulated_case3.php'
         . '?isolation=' . urlencode($level)
         . '&server_id=' . urlencode('server1')
         . '&id=' . urlencode($targetId)
         . '&value=' . urlencode($value1);

    $server1Response = @file_get_contents($url, false, $context);
    $server1 = $server1Response ? json_decode($server1Response, true) : null;
    if (!$server1) {
        $server1 = ['status'=>'ERROR','duration'=>0,'row'=>null];
    }

    // 2) Server0 local write with metadata
    $m = new mysqli($nodes['server0']['host'],$nodes['server0']['user'],$nodes['server0']['pass'],$nodes['server0']['db']);
    if ($m->connect_error) {
        $results[$level] = ['error'=>'server0_connect'];
        continue;
    }
    $m->query("SET autocommit = 0");
    $m->query("SET SESSION TRANSACTION ISOLATION LEVEL $level");
    $m->begin_transaction();

    $ts0 = microtime(true);
    $ver0 = (int) round($ts0 * 1000000);
    $stmt = $m->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
    $server0_id = 'server0';
    $stmt->bind_param("sdsii",$value0,$ts0,$server0_id,$ver0,$targetId);
    $stmt->execute();
    $stmt->close();

    usleep(200000); // overlap
    $m->commit();

    // read both rows from both servers to compare metadata (best-effort)
    $row0 = $m->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();

    // try to query server1's DB directly (optional, faster than HTTP)
    $s1 = new mysqli($nodes['server1']['host'],$nodes['server1']['user'],$nodes['server1']['pass'],$nodes['server1']['db']);
    $row1 = null;
    if (!$s1->connect_error) {
        $row1 = $s1->query("SELECT id,firstName,last_update_ts,last_update_server,version FROM Users WHERE id=$targetId")->fetch_assoc();
        $s1->close();
    } else {
        // fallback to HTTP result if DB connect unavailable
        $row1 = isset($server1['row']) ? $server1['row'] : null;
    }

    // 3) Conflict resolution: choose winner by last_update_ts, then server id
    $winner = null;
    if ($row0 && $row1) {
        $ts0f = floatval($row0['last_update_ts']);
        $ts1f = floatval($row1['last_update_ts']);
        if ($ts0f > $ts1f) $winner = ['row'=>$row0,'source'=>'server0'];
        elseif ($ts1f > $ts0f) $winner = ['row'=>$row1,'source'=>'server1'];
        else {
            // tie — use server id lexicographic
            if ($row0['last_update_server'] >= $row1['last_update_server']) $winner = ['row'=>$row0,'source'=>'server0'];
            else $winner = ['row'=>$row1,'source'=>'server1'];
        }
    } elseif ($row0) {
        $winner = ['row'=>$row0,'source'=>'server0'];
    } elseif ($row1) {
        $winner = ['row'=>$row1,'source'=>'server1'];
    } else {
        $winner = ['row'=>null,'source'=>'none'];
    }

    // 4) Propagate winner to both servers (write resolved winner back)
    if ($winner['row']) {
        $resolvedValue = $winner['row']['firstName'];
        $resolved_ts = microtime(true);
        $resolved_ver = (int) round($resolved_ts * 1000000);
        // update server0
        $m2 = new mysqli($nodes['server0']['host'],$nodes['server0']['user'],$nodes['server0']['pass'],$nodes['server0']['db']);
        $stmt2 = $m2->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
        $stmt2->bind_param("sdsii",$resolvedValue,$resolved_ts,$winner['row']['last_update_server'],$resolved_ver,$targetId);
        $stmt2->execute();
        $stmt2->close();
        $m2->close();

        // update server1 (direct DB connection)
        $s1b = new mysqli($nodes['server1']['host'],$nodes['server1']['user'],$nodes['server1']['pass'],$nodes['server1']['db']);
        if (!$s1b->connect_error) {
            $stmt3 = $s1b->prepare("UPDATE Users SET firstName=?, last_update_ts=?, last_update_server=?, version=? WHERE id=?");
            $stmt3->bind_param("sdsii",$resolvedValue,$resolved_ts,$winner['row']['last_update_server'],$resolved_ver,$targetId);
            $stmt3->execute();
            $stmt3->close();
            $s1b->close();
        } else {
            // fallback: attempt HTTP call to set resolved value on server1 (you could create an endpoint)
            // skipped here for brevity
        }
    }

    // record results
    $results[$level] = [
        'server0' => ['status'=>'Committed','duration'=>round(microtime(true)-$ts0,6)],
        'server1' => ['status'=> $server1['status'] ?? 'ERROR','duration'=> $server1['duration'] ?? 0],
        'row0' => $row0,
        'row1' => $row1,
        'winner' => $winner
    ];

    $m->close();
}

// print JSON for frontend
echo json_encode($results, JSON_PRETTY_PRINT);
