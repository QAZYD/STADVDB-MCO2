<?php
// case2_master_slave_verbose.php
header('Content-Type: application/json');
set_time_limit(180); // longer execution for polling

error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$nodes = [
    'master' => ['host'=>'10.2.14.129','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
    'node1'  => ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
    'node2'  => ['host'=>'10.2.14.131','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
];

function getConnection($node) {
    $conn = new mysqli($node['host'], $node['user'], $node['pass'], $node['db']);
    if ($conn->connect_error) return ['conn'=>null,'error'=>$conn->connect_error];
    return ['conn'=>$conn,'error'=>null];
}

function readUser($conn, $id) {
    $stmt = $conn->prepare("SELECT id, firstName FROM Users WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data ?: null;
}

function masterUpdateUser($conn, $id, $newName) {
    $conn->begin_transaction();
    $stmt = $conn->prepare("UPDATE Users SET firstName=? WHERE id=?");
    $stmt->bind_param("si", $newName, $id);
    $stmt->execute();
    $stmt->close();
    // Do not commit yet
}

function pollSlaveWithTiming($conn, $id, $attempts=5, $delaySec=1) {
    $reads = [];
    for ($i=0; $i<$attempts; $i++) {
        $reads[] = [
            'attempt' => $i+1,
            'time' => date('H:i:s'),
            'data' => readUser($conn, $id)
        ];
        sleep($delaySec);
    }
    return $reads;
}

$userId = 1;
$newNameBase = "Phase2Timing";
$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {
    $results[$level] = [];

    try {
        $master = getConnection($nodes['master']);
        $node1  = getConnection($nodes['node1']);
        $node2  = getConnection($nodes['node2']);

        // Log connection status
        $results[$level]['connections'] = [
            'master'=>$master['error'] ?? 'ok',
            'node1'=>$node1['error'] ?? 'ok',
            'node2'=>$node2['error'] ?? 'ok'
        ];

        if ($master['error'] || $node1['error'] || $node2['error']) continue;

        $results[$level]['transaction_start_time'] = date('H:i:s');

        // Set isolation level BEFORE starting transaction
        $master['conn']->query("SET TRANSACTION ISOLATION LEVEL $level");
        $results[$level]['isolation_level_set'] = $level;

        // Master update
        masterUpdateUser($master['conn'], $userId, $newNameBase . "_$level");
        $results[$level]['master_update_time'] = date('H:i:s');
        $results[$level]['master_update_value'] = $newNameBase . "_$level";

        // Master dirty read
        $masterDirtyRead = ['time'=>date('H:i:s'),'data'=>readUser($master['conn'],$userId)];
        $results[$level]['master_dirty_read'] = $masterDirtyRead;

        // Slave polls
        $results[$level]['slave1_poll_reads'] = pollSlaveWithTiming($node1['conn'], $userId);
        $results[$level]['slave2_poll_reads'] = pollSlaveWithTiming($node2['conn'], $userId);

        // Commit master
        $master['conn']->commit();
        $results[$level]['master_commit_time'] = date('H:i:s');

        // Final reads after commit
        $results[$level]['final_reads'] = [
            'master'=>['time'=>date('H:i:s'),'data'=>readUser($master['conn'],$userId)],
            'slave1'=>['time'=>date('H:i:s'),'data'=>readUser($node1['conn'],$userId)],
            'slave2'=>['time'=>date('H:i:s'),'data'=>readUser($node2['conn'],$userId)],
        ];

        // Close connections
        $master['conn']->close();
        $node1['conn']->close();
        $node2['conn']->close();

    } catch (Exception $e) {
        $results[$level]['exception'] = $e->getMessage();
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
exit;
