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

// Simulate a write from Node 2 that needs to replicate to central
$sourceNode = 'node2';
$targetNode = 'central';
$testData = ['firstName' => 'CrashTest_' . time()];
$testRecordId = 1;

$log[] = "=== Case #1: Slave to Central Replication Failure ===";
$log[] = "Source: $sourceNode, Target: $targetNode";

// Step 1: Check current node health
$healthStatus = $recoveryManager->getNodeHealthStatus();
$log[] = "Node Health Status: " . json_encode($healthStatus, JSON_PRETTY_PRINT);

// Step 2: Detect central node availability
if (!$healthStatus['central']['online']) {
    $log[] = "⚠ Central node appears OFFLINE (MySQL service is likely stopped). Proceeding with test.";
} else {
    $log[] = "✅ Central node appears ONLINE — for a proper failure test, stop the MySQL service on central first.";
}

// Step 3: Attempt the write operation
$log[] = "Attempting write operation...";
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
    $log[] = "Write to central node failed (likely due to MySQL being offline)";
    $log[] = "Transaction queued for recovery: " . ($writeResult['queued_for_recovery'] ? 'Yes' : 'No');
    $log[] = "Transaction ID: " . ($writeResult['transaction_id'] ?? 'N/A');
    
    $results['failure_detected'] = true;
    $results['transaction_queued'] = $writeResult['queued_for_recovery'] ?? false;
    $results['transaction_id'] = $writeResult['transaction_id'] ?? null;
} else {
    $log[] = "Write succeeded (central node was reachable)";
    $results['failure_detected'] = false;
}

// Step 4: Show how users are shielded
$log[] = "";
$log[] = "=== User Protection Strategy ===";
$log[] = "1. Local writes are still accepted on $sourceNode";
$log[] = "2. Transaction is logged for later replication";
$log[] = "3. User sees success message (eventual consistency)";
$log[] = "4. Background process will sync when central recovers";

$results['log'] = $log;
$results['strategy'] = [
    'user_shielding' => 'Local writes continue, queued for replication',
    'data_consistency' => 'Eventual consistency',
    'recovery_method' => 'Transaction log replay on node recovery'
];

echo json_encode($results, JSON_PRETTY_PRINT);