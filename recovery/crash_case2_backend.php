<?php
/**
 * Case #2: Central node recovers and replays missed transactions
 * 
 * Scenario: The central node was down and missed write transactions.
 * When it comes back online, it needs to catch up with missed writes.
 * 
 * Recovery Strategy:
 * - Check transaction logs on slave nodes
 * - Identify transactions that failed to replicate
 * - Replay them in order to central node
 * - Mark transactions as recovered
 */

header('Content-Type: application/json');
set_time_limit(120);
error_reporting(E_ALL);

require_once 'RecoveryManager.php';

$recoveryManager = new RecoveryManager();
$results = [];
$log = [];

$log[] = "=== Case #2: Central Node Recovery ===";

// Step 1: Check if central node is now online
$healthStatus = $recoveryManager->getNodeHealthStatus();
$log[] = "Current node status: " . json_encode($healthStatus);

if (!$healthStatus['central']['online']) {
    $log[] = "Central node is still offline - cannot proceed with recovery";
    $results['success'] = false;
    $results['log'] = $log;
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$log[] = "Central node is online - beginning recovery process";

// Step 2: First, simulate some failed transactions that need recovery
$log[] = "";
$log[] = "=== Simulating Failed Transactions ===";

// Create test transactions that "failed" earlier
$testTransactions = [
    ['source' => 'node2', 'record_id' => 2, 'data' => ['firstName' => 'RecoveryTest1']],
    ['source' => 'node3', 'record_id' => 3, 'data' => ['firstName' => 'RecoveryTest2']],
    ['source' => 'node2', 'record_id' => 4, 'data' => ['firstName' => 'RecoveryTest3']],
];

$centralConn = $recoveryManager->getConnection('central');
if ($centralConn['error']) {
    $log[] = "Cannot connect to central: " . $centralConn['error'];
    $results['success'] = false;
    $results['log'] = $log;
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

// Log some pending transactions for recovery demonstration
foreach ($testTransactions as $txn) {
    $txnId = uniqid('recovery_demo_', true);
    
    $stmt = $centralConn['conn']->prepare("INSERT INTO transaction_log 
        (transaction_id, source_node, target_node, operation_type, table_name, record_id, data_payload, status) 
        VALUES (?, ?, 'central', 'UPDATE', 'Users', ?, ?, 'PENDING')
        ON DUPLICATE KEY UPDATE status = 'PENDING'");
    
    $jsonData = json_encode($txn['data']);
    $stmt->bind_param("ssis", $txnId, $txn['source'], $txn['record_id'], $jsonData);
    $stmt->execute();
    $stmt->close();
    
    $log[] = "Created pending transaction: $txnId from " . $txn['source'];
}

// Step 3: Perform recovery
$log[] = "";
$log[] = "=== Executing Recovery ===";

$recoveryResult = $recoveryManager->recoverNode('central');
$log = array_merge($log, $recoveryResult['log']);

// Step 4: Verify recovery
$log[] = "";
$log[] = "=== Recovery Summary ===";
$log[] = "Transactions recovered: " . ($recoveryResult['recovered'] ?? 0);
$log[] = "Transactions failed: " . ($recoveryResult['failed'] ?? 0);

// Step 5: Show the recovery process
$log[] = "";
$log[] = "=== Recovery Process Explanation ===";
$log[] = "1. Central node comes back online";
$log[] = "2. System checks transaction_log for PENDING/FAILED entries";
$log[] = "3. Each missed transaction is replayed in chronological order";
$log[] = "4. Successfully recovered transactions marked as RECOVERED";
$log[] = "5. Failed recoveries are retried up to max_retries limit";

$centralConn['conn']->close();

$results['success'] = true;
$results['recovered'] = $recoveryResult['recovered'] ?? 0;
$results['failed'] = $recoveryResult['failed'] ?? 0;
$results['log'] = $log;
$results['recovery_strategy'] = [
    'method' => 'Transaction log replay',
    'ordering' => 'Chronological (FIFO)',
    'retry_policy' => 'Up to 3 retries with exponential backoff',
    'conflict_resolution' => 'Last write wins'
];

echo json_encode($results, JSON_PRETTY_PRINT);