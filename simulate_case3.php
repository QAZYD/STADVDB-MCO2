<?php
header("Content-Type: application/json");
set_time_limit(0);

// Connect to local DB
$mysqli = new mysqli("localhost", "G9_1", "password", "yourdb");
if ($mysqli->connect_error) {
    echo json_encode(["status" => "ERROR", "duration" => 0]);
    exit;
}

$mysqli->query("SET autocommit = 0");
$mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

$start = microtime(true);
$mysqli->begin_transaction();

// Update row to simulate Server1 write
$mysqli->query("UPDATE users SET firstName = 'Server1Write' WHERE id = 1");

// Sleep to overlap with Server0
sleep(2);

$mysqli->commit();
$end = microtime(true);

echo json_encode([
    "status" => "Committed",
    "duration" => $end - $start
]);
