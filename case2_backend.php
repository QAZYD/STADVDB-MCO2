<?php
// case2_master_slave_sync_json.php
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

function pollNode($conn, $id, $attempts=3, $delay=1) {
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

// IDs and new values
$ids = [1 => 'Bob', 50001 => 'Steve'];
$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {

    $master = getConnection($nodes['master']);
    $node1  = getConnection($nodes['node1']);
    $node2  = getConnection($nodes['node2']);

    if ($master['error'] || $node1['error'] || $node2['error']) {
        $results[$level]['connection_error'] = [$master['error'],$node1['error'],$node2['error']];
        continue;
    }

    try {
        $m = $master['conn'];
        $m->query("SET TRANSACTION ISOLATION LEVEL $level");
        $m->begin_transaction();

        // Master writes
        foreach ($ids as $id=>$name) {
            updateUser($m, $id, $name);
        }

        // Synchronous replication to slaves
        foreach ($ids as $id=>$name) {
            updateUser($node1['conn'], $id, $name);
            updateUser($node2['conn'], $id, $name);
        }

        // Poll slaves before commit
        $pollBefore = [
            'node1_before_commit' => pollNode($node1['conn'], 1),
            'node2_before_commit' => pollNode($node2['conn'], 50001)
        ];

        // Commit master
        $m->commit();

        // Poll slaves after commit
        $pollAfter = [
            'node1_after_commit' => pollNode($node1['conn'], 1),
            'node2_after_commit' => pollNode($node2['conn'], 50001)
        ];

        // Rollback master for testing
        $m->begin_transaction();
        updateUser($m, 1, 'OriginalName1');
        updateUser($m, 50001, 'OriginalName2');
        $m->commit();

        $results[$level] = [
            'poll_before_commit' => $pollBefore,
            'poll_after_commit' => $pollAfter
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
