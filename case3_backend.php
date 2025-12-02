<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// =====================
// Master DB credentials
// =====================
$masters = [
    'server0' => ['host'=>'10.2.14.129','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
    'server1' => ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
];

// =====================
// Connect to both masters
// =====================
$conn0 = new mysqli($masters['server0']['host'], $masters['server0']['user'], $masters['server0']['pass'], $masters['server0']['db']);
$conn1 = new mysqli($masters['server1']['host'], $masters['server1']['user'], $masters['server1']['pass'], $masters['server1']['db']);

// Set up transactions
$conn0->query("SET autocommit=0");
$conn1->query("SET autocommit=0");
$conn0->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");
$conn1->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

// Begin both transactions
$conn0->begin_transaction();
$conn1->begin_transaction();

// Perform writes with overlapping sleep to simulate concurrency
$conn0->query("UPDATE users SET firstName='Server0Write' WHERE id=1");
$conn1->query("UPDATE users SET firstName='Server1Write' WHERE id=1");

// Sleep to create overlap (simulates long-running transaction)
sleep(2);

// Commit both transactions
$conn0->commit();
$conn1->commit();

// Fetch final values from both servers
$final0 = $conn0->query("SELECT firstName FROM users WHERE id=1 LIMIT 1")->fetch_assoc();
$final1 = $conn1->query("SELECT firstName FROM users WHERE id=1 LIMIT 1")->fetch_assoc();

// Close connections
$conn0->close();
$conn1->close();

// =====================
// Return JSON
// =====================
echo json_encode([
    "server0_final" => $final0,
    "server1_final" => $final1
], JSON_PRETTY_PRINT);
