<?php
/**
 * Case #1: Transaction from Node 2 or Node 3 fails to write to central node
 * 
 * Scenario: A slave node attempts to replicate a transaction to the central node,
 * but the central node is unavailable (e.g., MySQL service stopped).
 * 
 * Recovery Strategy:
 * - Log the transaction locally on the originating node
 * - Queue for retry when central node becomes available
 * - User is notified of temporary unavailability but can continue local operations
 */

header('Content-Type: application/json');
set_time_limit(60);
error_reporting(E_ALL);

require_once 'RecoveryManager.php';

$recoveryManager = new RecoveryManager();
$results = [];
$log = [];

// Test from BOTH slave nodes
$slaveNodes = ['node2', 'node3'];
$targetNode = 'central';
$testRecordId = 1;

$log[] = "=== Case #1: Slave to Central Replication Failure ===";
$log[] = "Testing from: " . implode(', ', $slaveNodes) . " → $targetNode";

// Step 1: Check current node health
$healthStatus = $recoveryManager->getNodeHealthStatus();
$log[] = "Node Health Status: " . json_encode($healthStatus, JSON_PRETTY_PRINT);

// Step 2: Detect central node availability
if (!$healthStatus['central']['online']) {
    $log[] = "⚠ Central node appears OFFLINE (MySQL service is likely stopped). Proceeding with test.";
} else {
    $log[] = "✅ Central node appears ONLINE — for a proper failure test, turn off central node first.";
}

// Step 3: Attempt write operations from BOTH slave nodes
$log[] = "";
$log[] = "=== Attempting Write Operations ===";

$transactionResults = [];

foreach ($slaveNodes as $sourceNode) {
    $testData = ['firstName' => 'CrashTest_' . $sourceNode . '_' . time()];
    
    $log[] = "";
    $log[] = "--- Testing from $sourceNode ---";
    $log[] = "Attempting write operation from $sourceNode to $targetNode...";
    
    $writeResult = $recoveryManager->executeWithRecovery(
        $sourceNode,
        $targetNode,
        'UPDATE',
        'Users',
        $testRecordId,
        $testData
    );
    
    $log = array_merge($log, $writeResult['log']);
    
    if (!$writeResult['success']) {
        $log[] = "⛔ Write from $sourceNode to central failed";
        $log[] = "Transaction queued for recovery: " . ($writeResult['queued_for_recovery'] ? 'Yes' : 'No');
        $log[] = "Transaction ID: " . ($writeResult['transaction_id'] ?? 'N/A');
        
        $transactionResults[$sourceNode] = [
            'success' => false,
            'queued' => $writeResult['queued_for_recovery'] ?? false,
            'transaction_id' => $writeResult['transaction_id'] ?? null
        ];
    } else {
        $log[] = "✅ Write from $sourceNode succeeded (central node was reachable)";
        $transactionResults[$sourceNode] = [
            'success' => true,
            'transaction_id' => $writeResult['transaction_id'] ?? null
        ];
    }
}

// Step 4: Show how users are shielded
$log[] = "";
$log[] = "=== User Protection Strategy ===";
$log[] = "1. Local writes are still accepted on each slave node";
$log[] = "2. Transactions are logged on their respective source nodes";
$log[] = "3. User sees success message (eventual consistency)";
$log[] = "4. Background process will sync when central recovers";

// Determine overall failure status
$anyFailure = false;
foreach ($transactionResults as $result) {
    if (!$result['success']) {
        $anyFailure = true;
        break;
    }
}

$results['failure_detected'] = $anyFailure;
$results['transactions'] = $transactionResults;
$results['log'] = $log;
$results['strategy'] = [
    'user_shielding' => 'Local writes continue, queued for replication',
    'data_consistency' => 'Eventual consistency',
    'recovery_method' => 'Transaction log replay on node recovery'
];

echo json_encode($results, JSON_PRETTY_PRINT);