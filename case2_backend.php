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

// modified master update → updates both shard IDs
function masterUpdateShardParticipants($conn, $idA, $idB, $suffix) {
    $conn->begin_transaction();

    // update shard A id (node1)
    $stmt = $conn->prepare("UPDATE Users SET firstName=? WHERE id=?");
    $nameA = "Phase2Case2_A_$suffix";
    $stmt->bind_param("si", $nameA, $idA);
    $stmt->execute();

    // update shard B id (node2)
    $nameB = "Phase2Case2_B_$suffix";
    $stmt->bind_param("si", $nameB, $idB);
    $stmt->execute();

    $stmt->close();
    return [$nameA, $nameB];
}

function pollShard($conn, $id, $attempts=5, $delay=1) {
    $reads = [];
    for ($i=0; $i<$attempts; $i++) {
        $reads[] = [
            'time'=>date('H:i:s'),
            'data'=>readUser($conn, $id)
        ];
        sleep($delay);
    }
    return $reads;
}

// shard IDs
$idNode1 = 1;        // shard A
$idNode2 = 50001;    // shard B

$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {

    $master = getConnection($nodes['master']);
    $node1  = getConnection($nodes['node1']);
    $node2  = getConnection($nodes['node2']);

    if ($master['error'] || $node1['error'] || $node2['error']) continue;

    $m = $master['conn'];

    $m->query("SET TRANSACTION ISOLATION LEVEL $level");

    // master updates both shard values
    list($nameA, $nameB) = masterUpdateShardParticipants(
        $m, $idNode1, $idNode2, $level
    );

    // master dirty reads
    $dirtyReadA = readUser($m, $idNode1);
    $dirtyReadB = readUser($m, $idNode2);

    // slaves poll
    $poll1 = pollShard($node1['conn'], $idNode1);
    $poll2 = pollShard($node2['conn'], $idNode2);

    // commit
    $m->commit();

    // final reads after commit
    $finalA = readUser($m, $idNode1);
    $finalB = readUser($m, $idNode2);

    $results[$level] = [
        'updated_rows' => [
            'node1_id' => $idNode1,
            'new_value_node1' => $nameA,
            'node2_id' => $idNode2,
            'new_value_node2' => $nameB
        ],
        'dirty_reads' => [
            'master_on_node1_id' => $dirtyReadA,
            'master_on_node2_id' => $dirtyReadB
        ],
        'slave_polling' => [
            'node1_reads' => $poll1,
            'node2_reads' => $poll2
        ],
        'final_commit_reads' => [
            'master_node1_id' => $finalA,
            'master_node2_id' => $finalB,
            'node1_final' => readUser($node1['conn'], $idNode1),
            'node2_final' => readUser($node2['conn'], $idNode2)
        ]
    ];

    $m->close();
    $node1['conn']->close();
    $node2['conn']->close();
}

echo json_encode($results, JSON_PRETTY_PRINT);
exit;
