<?php
set_time_limit(0);

// Trigger Server 1 worker
$server1Url = "http://10.2.14.130/simulate_case3.php";
$server1Response = file_get_contents($server1Url);

// Server 0 local write
$mysqli = new mysqli("localhost", "G9_0", "password", "yourdb");
$mysqli->query("SET autocommit = 0");
$mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

$mysqli->begin_transaction();

$startLocal = microtime(true);

$mysqli->query("UPDATE users SET firstName = 'Server0Write' WHERE id = 1");

sleep(2); // overlaps with Server1

$mysqli->commit();

$endLocal = microtime(true);

// Parse Server 1 output
$server1 = json_decode($server1Response, true);

// Fetch final value
$res = $mysqli->query("SELECT firstName FROM users WHERE id = 1 LIMIT 1");
$final = $res->fetch_assoc();

// Output
echo "=== Case #3 Multi-Master Writes ===<br><br>";

echo "Server 0 committed in: " . ($endLocal - $startLocal) . " seconds<br>";
echo "Server 1 committed in: " . $server1['duration'] . " seconds<br><br>";

echo "Final value of row 1:<br>";
echo json_encode($final);
