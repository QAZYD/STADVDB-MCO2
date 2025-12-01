<?php
// case1_backend_debug.php
header('Content-Type: application/json');

// Enable all errors for debugging
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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

// Connect to a node
function getConnection($node) {
    $conn = new mysqli($node['host'], $node['user'], $node['pass'], $node['db']);
    if ($conn->connect_error) {
        return ['conn' => null, 'error' => $conn->connect_error];
    }
    return ['conn' => $conn, 'error' => null];
}

// Read a user with transaction & isolation level
function readUser($conn, $userId, $isolationLevel) {
    try {
        // Set isolation level BEFORE starting the transaction
        $conn->query("SET TRANSACTION ISOLATION LEVEL $isolationLevel");
        $conn->begin_transaction();

        // Make sure to use the correct table name
        $stmt = $conn->prepare("SELECT * FROM Users WHERE id = ?");
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $result = $stmt->get_result();
        $data = $result->fetch_assoc();

        $stmt->close();
        $conn->commit();

        return $data ?: null;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

$isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
$results = [];

foreach ($isolationLevels as $level) {
    $results[$level] = [];

    // Node 1
    $c1 = getConnection($nodes['node1']);
    if ($c1['error']) {
        $results[$level]['node1'] = ['connection_error' => $c1['error']];
    } else {
        $results[$level]['node1'] = readUser($c1['conn'], 1, $level);
        $c1['conn']->close();
    }

    // Node 2
    $c2 = getConnection($nodes['node2']);
    if ($c2['error']) {
        $results[$level]['node2'] = ['connection_error' => $c2['error']];
    } else {
        $results[$level]['node2'] = readUser($c2['conn'], 50001, $level);
        $c2['conn']->close();
    }

    // Master node
    $c0 = getConnection($nodes['master']);
    if ($c0['error']) {
        $results[$level]['master_node1'] = ['connection_error' => $c0['error']];
        $results[$level]['master_node2'] = ['connection_error' => $c0['error']];
    } else {
        $results[$level]['master_node1'] = readUser($c0['conn'], 1, $level);
        $results[$level]['master_node2'] = readUser($c0['conn'], 50001, $level);
        $c0['conn']->close();
    }
}

// Output debug JSON
echo json_encode($results, JSON_PRETTY_PRINT);
exit;
