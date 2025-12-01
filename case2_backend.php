<?php
// case2_master_writes.php
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

function updateUser($conn, $id, $newName) {
    $stmt = $conn->prepare("UPDATE Users SET firstName=? WHERE id=?");
    $stmt->bind_param("si", $newName, $id);
    $stmt->execute();
    $stmt->close();
}

function pollNode($conn, $id, $attempts=5, $delay=1) {
    $reads = [];
    for ($i=0; $i<$attempts; $i++) {
        $reads[] = [
            'attempt'=>$i+1,
            'time'=>date('H:i:s'),
            'data'=>readUser($conn, $id)
        ];
        sleep($delay);
    }
    return $reads;
}

// IDs for testing
$idMaster1 = 1;       // master writes
$idMaster2 = 50001;   // master writes

$newNames = [
    $idMaster1 => 'Bob',
    $idMaster2 => 'Steve'
];

$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {
    try {
        $master = getConnection($nodes['master']);
        $node1  = getConnection($nodes['node1']);
        $node2  = getConnection($nodes['node2']);

        if ($master['error'] || $node1['error'] || $node2['error']) continue;

        $m = $master['conn'];
        $m->query("SET TRANSACTION ISOLATION LEVEL $level");

        $m->begin_transaction();

        // Master writes
        updateUser($m, $idMaster1, $newNames[$idMaster1]);
        updateUser($m, $idMaster2, $newNames[$idMaster2]);

        // Node1 & Node2 read before commit
        $pollBeforeCommit = [
            'node1_reads_before_commit' => pollNode($node1['conn'], $idMaster1),
            'node2_reads_before_commit' => pollNode($node2['conn'], $idMaster2)
        ];

        // Optional: master dirty reads
        $masterDirty = [
            'id1' => readUser($m, $idMaster1),
            'id2' => readUser($m, $idMaster2)
        ];

        // Commit master
        $m->commit();
        $commitTime = date('H:i:s');

        // Node1 & Node2 read after commit
        $pollAfterCommit = [
            'node1_reads_after_commit' => pollNode($node1['conn'], $idMaster1),
            'node2_reads_after_commit' => pollNode($node2['conn'], $idMaster2)
        ];

        // Rollback master for testing
        $m->begin_transaction();
        updateUser($m, $idMaster1, 'OriginalName1');
        updateUser($m, $idMaster2, 'OriginalName2');
        $m->commit();

        $results[$level] = [
            'master_dirty_read' => $masterDirty,
            'poll_before_commit' => $pollBeforeCommit,
            'commit_time' => $commitTime,
            'poll_after_commit' => $pollAfterCommit
        ];

        $m->close();
        $node1['conn']->close();
        $node2['conn']->close();

    } catch (Exception $e) {
        $results[$level]['exception'] = $e->getMessage();
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
exit;
