<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// =====================
// Local DB credentials for Server 1
// =====================
$node = ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'];

$mysqli = new mysqli($node['host'], $node['user'], $node['pass'], $node['db']);
if ($mysqli->connect_error) {
    echo json_encode(["status" => "ERROR", "duration" => 0]);
    exit;
}

$mysqli->query("SET autocommit = 0");
$mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

$start = microtime(true);
$mysqli->begin_transaction();

// Simulate Server1 write
$mysqli->query("UPDATE users SET firstName = 'Server1Write' WHERE id = 1");

// Sleep to overlap with Server0
sleep(2);

$mysqli->commit();
$end = microtime(true);

echo json_encode([
    "status" => "Committed",
    "duration" => $end - $start
]);
