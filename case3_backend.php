<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// =====================
// Node Credentials
// =====================
$nodes = [
    'server0' => ['host'=>'10.2.14.129','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
    'server1' => ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'], // remote node
];

// =====================
// Trigger Server 1
// =====================
$server1Response = file_get_contents("http://ccscloud.dlsu.edu.ph:60230/simulate_case3.php");
$server1 = json_decode($server1Response, true);
if (!$server1) {
    $server1 = ["status" => "ERROR", "duration" => 0];
}

// =====================
// Server 0 local write
// =====================
$mysqli = new mysqli($nodes['server0']['host'], $nodes['server0']['user'], $nodes['server0']['pass'], $nodes['server0']['db']);
$mysqli->query("SET autocommit = 0");
$mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

$startLocal = microtime(true);
$mysqli->begin_transaction();
$mysqli->query("UPDATE Users SET firstName = 'Server0Write' WHERE id = 1");
sleep(2); // overlap with Server1
$mysqli->commit();
$endLocal = microtime(true);

// Fetch final value
$res = $mysqli->query("SELECT firstName FROM Users WHERE id = 1 LIMIT 1");
$final = $res->fetch_assoc();

// =====================
// Return JSON
// =====================
echo json_encode([
    "server0" => ["status" => "Committed", "duration" => $endLocal - $startLocal],
    "server1" => ["status" => $server1["status"], "duration" => $server1["duration"]],
    "final_value" => $final
]);
