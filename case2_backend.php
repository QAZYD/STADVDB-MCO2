<?php
// case2_master_writes_json.php
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

// IDs to update
$ids = [
    1 => 'Bob',
    50001 => 'Steve'
];

// Original values for rollback
$originals = [
    1 => 'OriginalName1',
    50001 => 'OriginalName2'
];

$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {
    try {
        $master = getConnection($nodes['master']);
        $node1  = getConnection($nodes['node1']);
        $node2  = getConnection($nodes['node2']);

        if ($master['error'] || $node1['error'] || $node2['error']) {
            $results[$level]['connection_error'] = [$master['error'],$node1['error'],$node2['error']];
            continue;
        }

        $m = $master['conn'];
        $m->query("SET TRANSACTION ISOLATION LEVEL $level");
        $m->begin_transaction();

        // Master writes
        foreach ($ids as $id => $name) {
            updateUser($m, $id, $name);
        }

        // Synchronous replication to slaves
        foreach ($ids as $id => $name) {
            updateUser($node1['conn'], $id, $name);
            updateUser($node2['conn'], $id, $name);
        }

        $m->commit();

        // Read results after commit
        $results[$level] = [
            'master' => [
                1 => readUser($m, 1),
                50001 => readUser($m, 50001)
            ],
            'node1' => [
                1 => readUser($node1['conn'], 1),
                50001 => readUser($node1['conn'], 50001)
            ],
            'node2' => [
                1 => readUser($node2['conn'], 1),
                50001 => readUser($node2['conn'], 50001)
            ]
        ];

        // Rollback to original values
        $m->begin_transaction();
        foreach ($originals as $id => $name) {
            updateUser($m, $id, $name);
            updateUser($node1['conn'], $id, $name);
            updateUser($node2['conn'], $id, $name);
        }
        $m->commit();

        $m->close();
        $node1['conn']->close();
        $node2['conn']->close();

    } catch (Exception $e) {
        $results[$level]['exception'] = $e->getMessage();
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
exit;
