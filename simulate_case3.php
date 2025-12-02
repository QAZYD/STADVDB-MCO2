<?php
header("Content-Type: application/json");

$mysqli = new mysqli("localhost", "G9_1", "password", "yourdb");
$mysqli->query("SET autocommit = 0");
$mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

$mysqli->begin_transaction();

$start = microtime(true);

$mysqli->query("UPDATE users SET firstName = 'Server1Write' WHERE id = 1");

sleep(2); // simulate long-running write so it overlaps with Server 0

$mysqli->commit();

$end = microtime(true);

echo json_encode([
    "server" => "Server 1",
    "status" => "Committed",
    "duration" => $end - $start
]);
