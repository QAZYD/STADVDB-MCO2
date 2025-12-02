<?php
/**
 * Node Health Check API
 * Returns the online/offline status of all nodes
 */

header('Content-Type: application/json');

require_once 'RecoveryManager.php';

$recoveryManager = new RecoveryManager();
$healthStatus = $recoveryManager->getNodeHealthStatus();

echo json_encode($healthStatus);