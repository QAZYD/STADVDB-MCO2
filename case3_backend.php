<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(0);

// Trigger Server1 worker
$server1Url = "http://10.2.14.130/simulate_case3.php"; // Server1 URL
$server1Response = file_get_contents($server1Url);
$server1 = json_decode($server1Response, true);
if (!$server1) {
    $server1 = ["status" => "ERROR", "duration" => 0];
}

// Server0 write
$mysqli = new mysqli("localhost", "G9_0", "password", "yourdb");
$mysqli->query("SET autocommit = 0");
$mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

$startLocal = microtime(true);
$mysqli->begin_transaction();
$mysqli->query("UPDATE users SET firstName = 'Server0Write' WHERE id = 1");
sleep(2); // overlap with Server1
$mysqli->commit();
$endLocal = microtime(true);

// Fetch final value of the row
$res = $mysqli->query("SELECT firstName FROM users WHERE id = 1 LIMIT 1");
$final = $res->fetch_assoc();

// Return clean JSON
echo json_encode([
    "server0" => ["status" => "Committed", "duration" => $endLocal - $startLocal],
    "server1" => ["status" => $server1["status"], "duration" => $server1["duration"]],
    "final_value" => $final
]);
