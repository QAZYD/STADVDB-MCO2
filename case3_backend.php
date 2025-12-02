<?php
set_time_limit(0);
header("Content-Type: application/json");

// ---- Trigger Server 1 worker ----
$server1Url = "http://10.2.14.130/simulate_case3.php";
$server1Response = file_get_contents($server1Url);

// If server1 didn't reply properly
$server1 = json_decode($server1Response, true);
if (!$server1) {
    $server1 = [
        "status" => "ERROR",
        "duration" => 0
    ];
}

// ---- Server 0 local write ----
$mysqli = new mysqli("localhost", "G9_0", "password", "yourdb");
$mysqli->query("SET autocommit = 0");
$mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

$mysqli->begin_transaction();

$startLocal = microtime(true);

$mysqli->query("UPDATE users SET firstName = 'Server0Write' WHERE id = 1");

sleep(2); // Overlap with Server 1

$mysqli->commit();

$endLocal = microtime(true);

// ---- Fetch final value ----
$res = $mysqli->query("SELECT firstName FROM users WHERE id = 1 LIMIT 1");
$final = $res->fetch_assoc();

// ---- Return JSON ----
echo json_encode([
    "server0" => [
        "status" => "Committed",
        "duration" => $endLocal - $startLocal
    ],
    "server1" => [
        "status" => $server1["status"],
        "duration" => $server1["duration"]
    ],
    "final_value" => $final
]);
