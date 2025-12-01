<?php
// case1_backend.php
header('Content-Type: application/json');

// Database nodes (local connections on each VM)
$nodes = [
    'master' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'G9_1',
        'pass' => 'password',
        'db'   => 'faker'
    ],
    'node1' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'G9_1',
        'pass' => 'password',
        'db'   => 'faker'
    ],
    'node2' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'G9_1',
        'pass' => 'password',
        'db'   => 'faker'
    ]
];

function getConnection($node) {
    $conn = new mysqli($node['host'], $node['user'], $node['pass'], $node['db'], $node['port']);
    if ($conn->connect_error) die(json_encode(['error' => $conn->connect_error]));
    return $conn;
}

function readUser($conn, $userId, $isolationLevel) {
    $conn->query("SET TRANSACTION ISOLATION LEVEL $isolationLevel");
    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();

    $stmt->close();
    $conn->commit();
    return $data ?: null;
}

// Case #1: Concurrent reads
$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {
    $results[$level] = [];

    // Node1 reads ID 1
    $conn1 = getConnection($nodes['node1']);
    $results[$level]['node1'] = readUser($conn1, 1, $level);
    $conn1->close();

    // Node2 reads ID 50001
    $conn2 = getConnection($nodes['node2']);
    $results[$level]['node2'] = readUser($conn2, 50001, $level);
    $conn2->close();

    // Parity check: master reads both
    $conn0 = getConnection($nodes['master']);
    $results[$level]['master_node1'] = readUser($conn0, 1, $level);
    $results[$level]['master_node2'] = readUser($conn0, 50001, $level);
    $conn0->close();
}

// Return JSON
echo json_encode($results, JSON_PRETTY_PRINT);
