<?php
// case2_master_slave_timing.php
header('Content-Type: application/json');
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
    // Not committing yet
}

function pollSlaveWithTiming($conn, $id, $attempts=5, $delaySec=1) {
    $reads = [];
    for ($i=0; $i<$attempts; $i++) {
        $reads[] = [
            'time' => date('H:i:s'),
            'data' => readUser($conn, $id)
        ];
        sleep($delaySec);
    }
    return $reads;
}

$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$userId = 1;
$newName = "Phase2Timing";

$results = [];

foreach ($isolationLevels as $level) {
    // Connect nodes
    $master = getConnection($nodes['master']);
    $node1 = getConnection($nodes['node1']);
    $node2 = getConnection($nodes['node2']);

    if ($master['error'] || $node1['error'] || $node2['error']) {
        $results[$level] = [
            'error_master'=>$master['error'] ?? null,
            'error_node1'=>$node1['error'] ?? null,
            'error_node2'=>$node2['error'] ?? null
        ];
        continue;
    }

    // Set transaction isolation level
    $master['conn']->query("SET TRANSACTION ISOLATION LEVEL $level");

    // Master updates
    masterUpdateUser($master['conn'], $userId, $newName . "_$level");

    // Master reads during uncommitted transaction
    $masterDirtyRead = [
        'time' => date('H:i:s'),
        'data' => readUser($master['conn'], $userId)
    ];

    // Slaves poll while master transaction is not committed
    $node1Reads = pollSlaveWithTiming($node1['conn'], $userId);
    $node2Reads = pollSlaveWithTiming($node2['conn'], $userId);

    // Commit master transaction
    $master['conn']->commit();

    // Final reads after commit
    $finalMasterRead = [
        'time' => date('H:i:s'),
        'data' => readUser($master['conn'], $userId)
    ];
    $finalNode1Read = [
        'time' => date('H:i:s'),
        'data' => readUser($node1['conn'], $userId)
    ];
    $finalNode2Read = [
        'time' => date('H:i:s'),
        'data' => readUser($node2['conn'], $userId)
    ];

    // Close connections
    $master['conn']->close();
    $node1['conn']->close();
    $node2['conn']->close();

    $results[$level] = [
        'master_dirty_read'=>$masterDirtyRead,
        'slave1_poll_reads'=>$node1Reads,
        'slave2_poll_reads'=>$node2Reads,
        'final_reads'=>[
            'master'=>$finalMasterRead,
            'slave1'=>$finalNode1Read,
            'slave2'=>$finalNode2Read
        ]
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT);
exit;
