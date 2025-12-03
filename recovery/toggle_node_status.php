<?php
header('Content-Type: application/json');

$response = [
    'success' => false,
    'debug' => []
];

$nodeFile = __DIR__ . '/node_status.php';
$response['debug'][] = "node file path = $nodeFile";

$input = json_decode(file_get_contents('php://input'), true);
$response['debug'][] = ['raw_input' => file_get_contents('php://input')];

if (!$input) {
    $response['error'] = 'No valid JSON input received.';
    echo json_encode($response);
    exit;
}

$node = $input['node'] ?? null;
$state = $input['state'] ?? null;
$response['debug'][] = ['node' => $node, 'state' => $state];

if (!$node || !$state) {
    $response['error'] = 'Missing node or state.';
    echo json_encode($response);
    exit;
}

$newState = strtolower($state) === 'on';

// Load current node list
if (!file_exists($nodeFile)) {
    $response['error'] = 'node_status.php not found.';
    echo json_encode($response);
    exit;
}

$nodes = include $nodeFile;
$response['debug'][] = ['loaded_nodes' => $nodes];

if (!is_array($nodes) || !isset($nodes[$node])) {
    $response['error'] = "Invalid node: $node";
    echo json_encode($response);
    exit;
}

// Modify
$nodes[$node]['online'] = $newState;

// Write new content
$content = "<?php\nreturn " . var_export($nodes, true) . ";\n?>";

if (@file_put_contents($nodeFile, $content) === false) {
    $response['error'] = "Failed to write to node_status.php (permissions?).";
    echo json_encode($response);
    exit;
}

$response['success'] = true;
$response['node'] = $node;
$response['online'] = $newState;
$response['debug'][] = 'File write successful';

// Optional: small log
file_put_contents(
    __DIR__ . '/status_log.txt',
    "[" . date('Y-m-d H:i:s') . "] Node '$node' set to " . ($newState ? 'ONLINE' : 'OFFLINE') . "\n",
    FILE_APPEND
);

echo json_encode($response, JSON_PRETTY_PRINT);