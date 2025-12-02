<?php
/**
 * RecoveryManager - Handles distributed database crash recovery
 * 
 * Recovery Strategy:
 * 1. All write operations are logged before execution
 * 2. Failed replications are queued for retry
 * 3. Node health is continuously monitored
 * 4. When nodes recover, missed transactions are replayed
 */

class RecoveryManager {
    private $nodes;
    private $centralNode;
    private $maxRetries = 3;
    private $retryDelay = 2; // seconds

    public function __construct() {
        $this->nodes = [
            'central' => ['host' => '10.2.14.129', 'user' => 'G9_1', 'pass' => 'pass1234', 'db' => 'faker'],
            'node2'   => ['host' => '10.2.14.130', 'user' => 'G9_1', 'pass' => 'pass1234', 'db' => 'faker'],
            'node3'   => ['host' => '10.2.14.131', 'user' => 'G9_1', 'pass' => 'pass1234', 'db' => 'faker'],
        ];
        $this->centralNode = 'central';
    }

    /**
     * Get connection to a specific node
     */
    public function getConnection($nodeName) {
        if (!isset($this->nodes[$nodeName])) {
            return ['conn' => null, 'error' => "Unknown node: $nodeName"];
        }
        
        $node = $this->nodes[$nodeName];
        $conn = @new mysqli($node['host'], $node['user'], $node['pass'], $node['db']);
        
        if ($conn->connect_error) {
            return ['conn' => null, 'error' => $conn->connect_error];
        }
        
        return ['conn' => $conn, 'error' => null];
    }

    /**
     * Check if a node is online
     */
    public function isNodeOnline($nodeName) {
        $result = $this->getConnection($nodeName);
        if ($result['conn']) {
            $result['conn']->close();
            return true;
        }
        return false;
    }

    /**
     * Get health status of all nodes
     */
    public function getNodeHealthStatus() {
        $status = [];
        foreach (array_keys($this->nodes) as $nodeName) {
            $status[$nodeName] = [
                'online' => $this->isNodeOnline($nodeName),
                'ip' => $this->nodes[$nodeName]['host'],
                'checked_at' => date('Y-m-d H:i:s')
            ];
        }
        return $status;
    }

    /**
     * Log a transaction before execution
     */
    public function logTransaction($conn, $transactionId, $sourceNode, $targetNode, $operationType, $tableName, $recordId, $dataPayload) {
        $stmt = $conn->prepare("INSERT INTO transaction_log 
            (transaction_id, source_node, target_node, operation_type, table_name, record_id, data_payload, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING')");
        
        $jsonPayload = json_encode($dataPayload);
        $stmt->bind_param("sssssss", $transactionId, $sourceNode, $targetNode, $operationType, $tableName, $recordId, $jsonPayload);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }

    /**
     * Update transaction status
     */
    public function updateTransactionStatus($conn, $transactionId, $status, $incrementRetry = false) {
        if ($incrementRetry) {
            $stmt = $conn->prepare("UPDATE transaction_log SET status = ?, retry_count = retry_count + 1 WHERE transaction_id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE transaction_log SET status = ? WHERE transaction_id = ?");
        }
        $stmt->bind_param("ss", $status, $transactionId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Get pending transactions for a target node
     */
    public function getPendingTransactions($conn, $targetNode) {
        $stmt = $conn->prepare("SELECT * FROM transaction_log 
            WHERE target_node = ? AND status IN ('PENDING', 'FAILED') AND retry_count < ? 
            ORDER BY created_at ASC");
        $stmt->bind_param("si", $targetNode, $this->maxRetries);
        $stmt->execute();
        $result = $stmt->get_result();
        $transactions = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $transactions;
    }

    /**
     * Execute a write operation with recovery support
     */
    public function executeWithRecovery($sourceNode, $targetNode, $operationType, $tableName, $recordId, $dataPayload) {
        $transactionId = uniqid('txn_', true);
        $log = [];

        // Step 1: Log the transaction on source node
        $sourceConn = $this->getConnection($sourceNode);
        if ($sourceConn['error']) {
            return ['success' => false, 'error' => "Source node unavailable: " . $sourceConn['error'], 'log' => $log];
        }

        $this->logTransaction(
            $sourceConn['conn'], 
            $transactionId, 
            $sourceNode, 
            $targetNode, 
            $operationType, 
            $tableName, 
            $recordId, 
            $dataPayload
        );
        $log[] = "Transaction $transactionId logged on $sourceNode";

        // Step 2: Attempt to execute on target node
        $targetConn = $this->getConnection($targetNode);
        if ($targetConn['error']) {
            // Target node is down - mark as FAILED for later recovery
            $this->updateTransactionStatus($sourceConn['conn'], $transactionId, 'FAILED', true);
            $sourceConn['conn']->close();
            $log[] = "Target node $targetNode is unavailable - transaction queued for recovery";
            return [
                'success' => false, 
                'error' => "Target node unavailable: " . $targetConn['error'],
                'transaction_id' => $transactionId,
                'queued_for_recovery' => true,
                'log' => $log
            ];
        }

        // Step 3: Execute the operation
        try {
            $targetConn['conn']->begin_transaction();
            
            if ($operationType === 'UPDATE') {
                $this->executeUpdate($targetConn['conn'], $tableName, $recordId, $dataPayload);
            } elseif ($operationType === 'INSERT') {
                $this->executeInsert($targetConn['conn'], $tableName, $dataPayload);
            }
            
            $targetConn['conn']->commit();
            $this->updateTransactionStatus($sourceConn['conn'], $transactionId, 'COMMITTED');
            $log[] = "Transaction $transactionId committed on $targetNode";
            
            $sourceConn['conn']->close();
            $targetConn['conn']->close();
            
            return ['success' => true, 'transaction_id' => $transactionId, 'log' => $log];
            
        } catch (Exception $e) {
            $targetConn['conn']->rollback();
            $this->updateTransactionStatus($sourceConn['conn'], $transactionId, 'FAILED', true);
            $log[] = "Transaction $transactionId failed: " . $e->getMessage();
            
            $sourceConn['conn']->close();
            $targetConn['conn']->close();
            
            return [
                'success' => false, 
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'queued_for_recovery' => true,
                'log' => $log
            ];
        }
    }

    /**
     * Execute UPDATE operation
     */
    private function executeUpdate($conn, $tableName, $recordId, $data) {
        $setClauses = [];
        $values = [];
        $types = "";
        
        foreach ($data as $column => $value) {
            $setClauses[] = "$column = ?";
            $values[] = $value;
            $types .= is_int($value) ? "i" : "s";
        }
        
        $values[] = $recordId;
        $types .= "i";
        
        $sql = "UPDATE $tableName SET " . implode(", ", $setClauses) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Execute INSERT operation
     */
    private function executeInsert($conn, $tableName, $data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');
        $values = array_values($data);
        $types = "";
        
        foreach ($values as $value) {
            $types .= is_int($value) ? "i" : "s";
        }
        
        $sql = "INSERT INTO $tableName (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Recover missed transactions for a node that just came back online
     */
    public function recoverNode($nodeName) {
        $log = [];
        $recovered = 0;
        $failed = 0;

        // Get connection to central node to read transaction log
        $centralConn = $this->getConnection($this->centralNode);
        if ($centralConn['error']) {
            return ['success' => false, 'error' => "Cannot connect to central node", 'log' => $log];
        }

        // Check if target node is now online
        if (!$this->isNodeOnline($nodeName)) {
            $centralConn['conn']->close();
            return ['success' => false, 'error' => "Node $nodeName is still offline", 'log' => $log];
        }

        $log[] = "Starting recovery for node: $nodeName";

        // Get pending transactions for this node
        $pendingTxns = $this->getPendingTransactions($centralConn['conn'], $nodeName);
        $log[] = "Found " . count($pendingTxns) . " pending transactions";

        foreach ($pendingTxns as $txn) {
            $targetConn = $this->getConnection($nodeName);
            if ($targetConn['error']) {
                $log[] = "Node went offline during recovery";
                break;
            }

            try {
                $targetConn['conn']->begin_transaction();
                $data = json_decode($txn['data_payload'], true);
                
                if ($txn['operation_type'] === 'UPDATE') {
                    $this->executeUpdate($targetConn['conn'], $txn['table_name'], $txn['record_id'], $data);
                } elseif ($txn['operation_type'] === 'INSERT') {
                    $this->executeInsert($targetConn['conn'], $txn['table_name'], $data);
                }
                
                $targetConn['conn']->commit();
                $this->updateTransactionStatus($centralConn['conn'], $txn['transaction_id'], 'RECOVERED');
                $log[] = "Recovered transaction: " . $txn['transaction_id'];
                $recovered++;
                
            } catch (Exception $e) {
                $targetConn['conn']->rollback();
                $this->updateTransactionStatus($centralConn['conn'], $txn['transaction_id'], 'FAILED', true);
                $log[] = "Failed to recover transaction " . $txn['transaction_id'] . ": " . $e->getMessage();
                $failed++;
            }
            
            $targetConn['conn']->close();
        }

        $centralConn['conn']->close();

        return [
            'success' => true,
            'recovered' => $recovered,
            'failed' => $failed,
            'log' => $log
        ];
    }

    /**
     * Simulate node failure
     */
    public function simulateNodeFailure($nodeName) {
        // In a real scenario, this would stop the MySQL service
        // For simulation, we'll use a flag in the database
        $centralConn = $this->getConnection($this->centralNode);
        if ($centralConn['error']) {
            return ['success' => false, 'error' => $centralConn['error']];
        }

        $stmt = $centralConn['conn']->prepare("UPDATE node_health SET is_online = FALSE WHERE node_name = ?");
        $stmt->bind_param("s", $nodeName);
        $stmt->execute();
        $stmt->close();
        $centralConn['conn']->close();

        return ['success' => true, 'message' => "Node $nodeName marked as offline"];
    }

    /**
     * Simulate node recovery
     */
    public function simulateNodeRecovery($nodeName) {
        $centralConn = $this->getConnection($this->centralNode);
        if ($centralConn['error']) {
            return ['success' => false, 'error' => $centralConn['error']];
        }

        $stmt = $centralConn['conn']->prepare("UPDATE node_health SET is_online = TRUE, last_heartbeat = NOW() WHERE node_name = ?");
        $stmt->bind_param("s", $nodeName);
        $stmt->execute();
        $stmt->close();
        $centralConn['conn']->close();

        // Trigger recovery process
        return $this->recoverNode($nodeName);
    }
}
?>