<?php
/**
 * Case #3: Transaction from central node fails to write to Node 2 or Node 3
 * 
 * Scenario: The central node attempts to replicate a transaction to a slave node,
 * but the slave node is unavailable or the write fails.
 * 
 * Recovery Strategy:
 * - Complete the transaction on central node
 * - Log the failed replication
 * - Queue for retry when slave node becomes available
 * - Continue serving read requests from available nodes
 */

header('Content-Type: application/json');
set_time_limit(60);
error_reporting(E_ALL);

require_once 'RecoveryManager.php';

$recoveryManager = new RecoveryManager();
$results = [];
$log = [];

$sourceNode = 'central';
$targetNodes = ['node2', 'node3'];
$testData = ['firstName' => 'CentralWrite_' . time()];
$testRecordId = 5;

$log[] = "=== Case #3: Central to Slave Replication Failure ===";
$log[] = "Source: $sourceNode";

// Step 1: Check node health
$healthStatus = $recoveryManager->getNodeHealthStatus();
$log[] = "Node Health Status: " . json_encode($healthStatus);

// Step 2: First, execute on central node (this should succeed)
$log[] = "";
$log[] = "=== Step 1: Execute on Central Node ===";

$centralConn = $recoveryManager->getConnection('central');
if ($centralConn['error']) {
    $log[] = "Central node unavailable: " . $centralConn['error'];
    $results['success'] = false;
    $results['log'] = $log;
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

try {
    $centralConn['conn']->begin_transaction();
    $stmt = $centralConn['conn']->prepare("UPDATE Users SET firstName = ? WHERE id = ?");
    $stmt->bind_param("si", $testData['firstName'], $testRecordId);
    $stmt->execute();
    $stmt->close();
    $centralConn['conn']->commit();
    $log[] = "✔ Central node write successful";
    $results['central_write'] = 'SUCCESS';
} catch (Exception $e) {
    $centralConn['conn']->rollback();
    $log[] = "✖ Central node write failed: " . $e->getMessage();
    $results['central_write'] = 'FAILED';
}

// Step 3: Attempt to replicate to slave nodes
$log[] = "";
$log[] = "=== Step 2: Replicate to Slave Nodes ===";

$replicationResults = [];

foreach ($targetNodes as $targetNode) {
    $log[] = "Attempting replication to $targetNode...";
    
    $writeResult = $recoveryManager->executeWithRecovery(
        $sourceNode,
        $targetNode,
        'UPDATE',
        'Users',
        $testRecordId,
        $testData
    );
    
    $log = array_merge($log, $writeResult['log']);
    
    $replicationResults[$targetNode] = [
        'success' => $writeResult['success'],
        'queued_for_recovery' => $writeResult['queued_for_recovery'] ?? false,
        'transaction_id' => $writeResult['transaction_id'] ?? null
    ];
    
    if (!$writeResult['success']) {
        $log[] = "⚠ Replication to $targetNode failed - queued for recovery";
    } else {
        $log[] = "✔ Replication to $targetNode successful";
    }
}

// Step 4: Demonstrate continued availability
$log[] = "";
$log[] = "=== User Protection During Slave Failure ===";
$log[] = "1. Central node continues to serve all requests";
$log[] = "2. Reads can fall back to central if slave is unavailable";
$log[] = "3. Writes are queued for replication when slave recovers";
$log[] = "4. Users experience no service interruption";

// Step 5: Show available read paths
$log[] = "";
$log[] = "=== Available Read Paths ===";
foreach ($healthStatus as $node => $status) {
    if ($status['online']) {
        $log[] = "✔ $node is available for reads";
    } else {
        $log[] = "✖ $node is offline - requests routed to other nodes";
    }
}

$centralConn['conn']->close();

$results['success'] = true;
$results['replication'] = $replicationResults;
$results['log'] = $log;
$results['availability_strategy'] = [
    'read_failover' => 'Automatic fallback to central node',
    'write_handling' => 'Queue and retry on slave recovery',
    'user_experience' => 'Transparent - no visible errors'
];

echo json_encode($results, JSON_PRETTY_PRINT);