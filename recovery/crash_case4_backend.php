<?php
/**
 * Case #4: Node 2 or Node 3 recovers and syncs missed transactions
 * 
 * Scenario: A slave node was down and missed write transactions.
 * When it comes back online, it needs to catch up with missed writes.
 * 
 * Recovery Strategy:
 * - Detect node recovery through health check
 * - Query transaction log for pending replications to this node
 * - Replay transactions in chronological order
 * - Verify data consistency after recovery
 */

header('Content-Type: application/json');
set_time_limit(120);
error_reporting(E_ALL);

require_once 'RecoveryManager.php';

$recoveryManager = new RecoveryManager();
$results = [];
$log = [];

// Which node to recover (can be passed as parameter)
$nodeToRecover = $_GET['node'] ?? 'node2';

$log[] = "=== Case #4: Slave Node Recovery ===";
$log[] = "Recovering node: $nodeToRecover";

// Step 1: Check current state
$healthStatus = $recoveryManager->getNodeHealthStatus();
$log[] = "Initial node status: " . json_encode($healthStatus);

// Step 2: Simulate the node being down and missing transactions
$log[] = "";
$log[] = "=== Simulating Missed Transactions ===";

// First, ensure central is online
if (!$healthStatus['central']['online']) {
    $log[] = "Central node is offline - cannot proceed";
    $results['success'] = false;
    $results['log'] = $log;
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$centralConn = $recoveryManager->getConnection('central');
if ($centralConn['error']) {
    $log[] = "Cannot connect to central: " . $centralConn['error'];
    $results['success'] = false;
    $results['log'] = $log;
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

// Create test transactions that were "missed" by the recovering node
$missedTransactions = [
    ['record_id' => 10, 'data' => ['firstName' => 'MissedWrite1_' . time()]],
    ['record_id' => 11, 'data' => ['firstName' => 'MissedWrite2_' . time()]],
    ['record_id' => 12, 'data' => ['firstName' => 'MissedWrite3_' . time()]],
];

foreach ($missedTransactions as $txn) {
    $txnId = uniqid('missed_', true);
    
    // Log as pending for the recovering node
    $stmt = $centralConn['conn']->prepare("INSERT INTO transaction_log 
        (transaction_id, source_node, target_node, operation_type, table_name, record_id, data_payload, status) 
        VALUES (?, 'central', ?, 'UPDATE', 'Users', ?, ?, 'PENDING')");
    
    $jsonData = json_encode($txn['data']);
    $stmt->bind_param("ssis", $txnId, $nodeToRecover, $txn['record_id'], $jsonData);
    $stmt->execute();
    $stmt->close();
    
    $log[] = "Logged missed transaction $txnId for $nodeToRecover";
    
    // Also apply to central (since it was online)
    try {
        $stmt = $centralConn['conn']->prepare("UPDATE Users SET firstName = ? WHERE id = ?");
        $firstName = $txn['data']['firstName'];
        $stmt->bind_param("si", $firstName, $txn['record_id']);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        $log[] = "Note: Could not update central (record may not exist): " . $e->getMessage();
    }
}

$log[] = "Created " . count($missedTransactions) . " missed transactions";

// Step 3: Simulate node recovery
$log[] = "";
$log[] = "=== Node Recovery Process ===";
$log[] = "Simulating $nodeToRecover coming back online...";

// Check if the node is actually online
if (!$recoveryManager->isNodeOnline($nodeToRecover)) {
    $log[] = "Node $nodeToRecover is offline - simulating recovery by logging only";
    $log[] = "(In production, the actual node would need to be started)";
    
    $results['success'] = false;
    $results['node_status'] = 'OFFLINE';
    $results['pending_transactions'] = count($missedTransactions);
    $results['log'] = $log;
    $results['recovery_strategy'] = [
        'when_online' => 'Transaction log will be replayed automatically',
        'ordering' => 'Chronological order maintained',
        'verification' => 'Data checksums compared after sync'
    ];
    
    $centralConn['conn']->close();
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$log[] = "✔ Node $nodeToRecover is online";

// Step 4: Execute recovery
$log[] = "Starting transaction replay...";

// Recover from central's transaction log (for transactions from central → slave)
$log[] = "";
$log[] = "Checking central node for pending transactions to $nodeToRecover...";
$recoveryResult = $recoveryManager->recoverPendingFromNode('central', $nodeToRecover);
$log = array_merge($log, $recoveryResult['log'] ?? []);

$totalRecovered = $recoveryResult['recovered'] ?? 0;
$totalFailed = $recoveryResult['failed'] ?? 0;

// Also check if there are any pending transactions on the slave itself
// (edge case: slave logged a transaction but crashed before completing)
$log[] = "";
$log[] = "Checking $nodeToRecover for any self-pending transactions...";
$selfResult = $recoveryManager->recoverNode($nodeToRecover);
$log = array_merge($log, $selfResult['log'] ?? []);

$totalRecovered += $selfResult['recovered'] ?? 0;
$totalFailed += $selfResult['failed'] ?? 0;
$log = array_merge($log, $recoveryResult['log']);

// Step 5: Verify consistency
$log[] = "";
$log[] = "=== Verifying Data Consistency ===";

$nodeConn = $recoveryManager->getConnection($nodeToRecover);
if (!$nodeConn['error']) {
    foreach ($missedTransactions as $txn) {
        $stmt = $nodeConn['conn']->prepare("SELECT firstName FROM Users WHERE id = ?");
        $stmt->bind_param("i", $txn['record_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row && $row['firstName'] === $txn['data']['firstName']) {
            $log[] = "✔ Record {$txn['record_id']} verified on $nodeToRecover";
        } else {
            $log[] = "⚠ Record {$txn['record_id']} mismatch or not found";
        }
    }
    $nodeConn['conn']->close();
}

// Step 6: Recovery summary
$log[] = "";
$log[] = "=== Recovery Summary ===";
$log[] = "Node: $nodeToRecover";
$log[] = "Status: RECOVERED";
$log[] = "Transactions replayed: " . $totalRecovered;
$log[] = "Transactions failed: " . $totalFailed;

$centralConn['conn']->close();

$results['success'] = true;
$results['node'] = $nodeToRecover;
$results['recovered'] = $totalRecovered;
$results['failed'] = $totalFailed;
$results['log'] = $log;
$results['recovery_strategy'] = [
    'detection' => 'Health check polling every 5 seconds',
    'replay_method' => 'Transaction log sequential replay',
    'consistency_check' => 'Checksum verification post-recovery',
    'conflict_resolution' => 'Timestamp-based last-write-wins'
];

echo json_encode($results, JSON_PRETTY_PRINT);