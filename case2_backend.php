<?php
// case2_master_slave_verbose.php
header('Content-Type: application/json');
set_time_limit(180);

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

// Master updates both IDs
function masterUpdateUsers($conn, $id1, $name1, $id2, $name2) {
    $conn->begin_transaction();
    $stmt = $conn->prepare("UPDATE Users SET firstName=? WHERE id=?");

    $stmt->bind_param("si", $name1, $id1);
    $stmt->execute();

    $stmt->bind_param("si", $name2, $id2);
    $stmt->execute();

    $stmt->close();
}

function pollNode($conn, $id, $attempts=5, $delay=1) {
    $reads = [];
    for ($i=0; $i<$attempts; $i++) {
        $reads[] = [
            'attempt' => $i+1,
            'time' => date('H:i:s'),
            'data' => readUser($conn, $id)
        ];
        sleep($delay);
    }
    return $reads;
}

// IDs to test
$idNode1 = 1;       // shard A
$idNode2 = 50001;   // shard B

$newNames = [
    'id1' => 'Bob',
    'id2' => 'Steve'
];

$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {
    $results[$level] = [];

    $master = getConnection($nodes['master']);
    $node1  = getConnection($nodes['node1']);
    $node2  = getConnection($nodes['node2']);

    if ($master['error'] || $node1['error'] || $node2['error']) continue;

    $m = $master['conn'];
    $m->query("SET TRANSACTION ISOLATION LEVEL $level");

    // Master updates both IDs
    masterUpdateUsers($m, $idNode1, $newNames['id1'], $idNode2, $newNames['id2']);
    $results[$level]['master_update_time'] = date('H:i:s');

    // Master dirty reads (before commit)
    $results[$level]['master_dirty_reads'] = [
        'id1' => readUser($m, $idNode1),
        'id2' => readUser($m, $idNode2)
    ];

    // Slave polling during master transaction
    $results[$level]['slave1_poll_reads'] = pollNode($node1['conn'], $idNode1);
    $results[$level]['slave2_poll_reads'] = pollNode($node2['conn'], $idNode2);

    // Commit master
    $m->commit();
    $results[$level]['master_commit_time'] = date('H:i:s');

    // Final reads after commit
    $results[$level]['final_reads'] = [
        'master' => [
            'id1' => readUser($m, $idNode1),
            'id2' => readUser($m, $idNode2)
        ],
        'node1' => readUser($node1['conn'], $idNode1),
        'node2' => readUser($node2['conn'], $idNode2)
    ];

    // Rollback to original values to restore DB (simulate reset)
    $stmt = $m->prepare("UPDATE Users SET firstName=? WHERE id=?");
    // Reset id1
    $stmt->bind_param("si", $results[$level]['slave1_poll_reads'][0]['data']['firstName'], $idNode1);
    $stmt->execute();
    // Reset id2
    $stmt->bind_param("si", $results[$level]['slave2_poll_reads'][0]['data']['data']['firstName'], $idNode2);
    $stmt->execute();
    $stmt->close();

    $m->close();
    $node1['conn']->close();
    $node2['conn']->close();
}

echo json_encode($results, JSON_PRETTY_PRINT);
exit;
