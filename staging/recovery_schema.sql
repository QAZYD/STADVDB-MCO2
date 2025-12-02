-- =============================================================================
-- Recovery Schema for Distributed Database Crash Recovery
-- Run the script on ALL nodes (Central, Node2, Node3)
-- =============================================================================

-- Create transaction log table to track all pending/failed transactions
CREATE TABLE IF NOT EXISTS transaction_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(64) NOT NULL,
    source_node VARCHAR(50) NOT NULL,
    target_node VARCHAR(50) NOT NULL,
    operation_type ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    table_name VARCHAR(100) NOT NULL,
    record_id INT NOT NULL,
    data_payload JSON,
    status ENUM('PENDING', 'COMMITTED', 'FAILED', 'RECOVERED') DEFAULT 'PENDING',
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_target (target_node, status),
    INDEX idx_transaction_id (transaction_id)
);

-- Create node health status table to track node availability
CREATE TABLE IF NOT EXISTS node_health (
    node_name VARCHAR(50) PRIMARY KEY,
    node_ip VARCHAR(50) NOT NULL,
    last_heartbeat TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_online BOOLEAN DEFAULT TRUE,
    INDEX idx_online (is_online)
);

-- Insert initial node health records (run only on central node)
INSERT INTO node_health (node_name, node_ip, is_online) VALUES
('central', '10.2.14.129', TRUE),
('node2', '10.2.14.130', TRUE),
('node3', '10.2.14.131', TRUE)
ON DUPLICATE KEY UPDATE last_heartbeat = CURRENT_TIMESTAMP;