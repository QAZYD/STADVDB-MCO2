<?php
// case2_master_writes_logging.php
header('Content-Type: application/json');
set_time_limit(0); // allow long execution

error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Flush output immediately
ob_implicit_flush(true);
ob_end_flush();

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

function pollNode($conn, $id, $attempts=5, $delay=1, $nodeName='') {
    $reads = [];
    for ($i=0; $i<$attempts; $i++) {
        $data = readUser($conn, $id);
        $reads[] = ['attempt'=>$i+1, 'time'=>date('H:i:s'), 'data'=>$data];
        // Real-time log
        echo "[$nodeName] Poll attempt " . ($i+1) . ": " . json_encode($data) . "\n";
        flush();
        sleep($delay);
    }
    return $reads;
}

// IDs for testing
$idMaster1 = 1;
$idMaster2 = 50001;

$newNames = [
    $idMaster1 => 'Bob',
    $idMaster2 => 'Steve'
];

$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {
    echo "\n=== Isolation Level: $level ===\n";
    flush();

    try {
        $master = getConnection($nodes['master']);
        $node1  = getConnection($nodes['node1']);
        $node2  = getConnection($nodes['node2']);

        if ($master['error'] || $node1['error'] || $node2['error']) {
            throw new Exception("Connection error: " . json_encode([$master['error'],$node1['error'],$node2['error']]));
        }

        $m = $master['conn'];
        $m->query("SET TRANSACTION ISOLATION LEVEL $level");
        echo "Master isolation level set to $level\n"; flush();

        $m->begin_transaction();

        // Master writes
        updateUser($m, $idMaster1, $newNames[$idMaster1]);
        echo "Master updated id $idMaster1 to {$newNames[$idMaster1]}\n"; flush();
        updateUser($m, $idMaster2, $newNames[$idMaster2]);
        echo "Master updated id $idMaster2 to {$newNames[$idMaster2]}\n"; flush();

        // Nodes read BEFORE master commits
        $pollBeforeCommit = [
            'node1_reads_before_commit' => pollNode($node1['conn'], $idMaster1, 5, 1, 'Node1'),
            'node2_reads_before_commit' => pollNode($node2['conn'], $idMaster2, 5, 1, 'Node2')
        ];

        // Master dirty read
        $masterDirty = [
            'id1' => readUser($m, $idMaster1),
            'id2' => readUser($m, $idMaster2)
        ];
        echo "Master dirty read: " . json_encode($masterDirty) . "\n"; flush();

        // Commit master
        $m->commit();
        $commitTime = date('H:i:s');
        echo "Master committed at $commitTime\n"; flush();

        // Nodes read AFTER commit
        $pollAfterCommit = [
            'node1_reads_after_commit' => pollNode($node1['conn'], $idMaster1, 5, 1, 'Node1'),
            'node2_reads_after_commit' => pollNode($node2['conn'], $idMaster2, 5, 1, 'Node2')
        ];

        // Rollback master for testing
        $m->begin_transaction();
        updateUser($m, $idMaster1, 'OriginalName1');
        updateUser($m, $idMaster2, 'OriginalName2');
        $m->commit();
        echo "Master rolled back to original names\n"; flush();

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
        echo "❌ Error: " . $e->getMessage() . "\n"; flush();
        $results[$level]['exception'] = $e->getMessage();
    }
}

echo "\n=== JSON Results ===\n";
echo json_encode($results, JSON_PRETTY_PRINT);
exit;
