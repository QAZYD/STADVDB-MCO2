<?php
error_reporting(E_ERROR | E_PARSE);
mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json');

// Database nodes
$nodes = [
    'master' => [
        'host' => '10.2.14.129',
        'user' => 'G9_1',
        'pass' => 'pass1234',
        'db'   => 'faker'
    ],
    'node1' => [
        'host' => '10.2.14.130',
        'user' => 'G9_1',
        'pass' => 'pass1234',
        'db'   => 'faker'
    ],
    'node2' => [
        'host' => '10.2.14.131',
        'user' => 'G9_1',
        'pass' => 'pass1234',
        'db'   => 'faker'
    ]
];

function getConnection($node) {
    $conn = @new mysqli($node['host'], $node['user'], $node['pass'], $node['db']);
    if ($conn->connect_error) {
        return null;
    }
    return $conn;
}

function readUser($conn, $userId, $isolationLevel) {
    if (!$conn) return null;
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

$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {
    $results[$level] = [];

    $conn1 = getConnection($nodes['node1']);
    $results[$level]['node1'] = readUser($conn1, 1, $level);
    if ($conn1) $conn1->close();

    $conn2 = getConnection($nodes['node2']);
    $results[$level]['node2'] = readUser($conn2, 50001, $level);
    if ($conn2) $conn2->close();

    $conn0 = getConnection($nodes['master']);
    $results[$level]['master_node1'] = readUser($conn0, 1, $level);
    $results[$level]['master_node2'] = readUser($conn0, 50001, $level);
    if ($conn0) $conn0->close();
}

echo json_encode($results, JSON_PRETTY_PRINT);
exit;
