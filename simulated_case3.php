<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// config
$node = ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker']; // local DB on Server1
$server_id = isset($_GET['server_id']) ? $_GET['server_id'] : 'server1';
$level = isset($_GET['isolation']) ? $_GET['isolation'] : 'READ UNCOMMITTED';
$targetId = isset($_GET['id']) ? intval($_GET['id']) : 1;
$newValue = isset($_GET['value']) ? $_GET['value'] : 'Server1Write';

// connect
$mysqli = new mysqli($node['host'],$node['user'],$node['pass'],$node['db']);
if ($mysqli->connect_error) {
    echo json_encode(['status'=>'ERROR','error'=>'connect','duration'=>0]);
    exit;
}

// set isolation and begin
$mysqli->query("SET autocommit = 0");
$mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL $level");
$mysqli->begin_transaction();

// prepare metadata
$ts = microtime(true); // float seconds (e.g. 1700000000.1234)
$version = (int) round($ts * 1000000); // optional logical version

// perform write (update value, ts, server, version)
$stmt = $mysqli->prepare("UPDATE Users SET firstName = ?, last_update_ts = ?, last_update_server = ?, version = ? WHERE id = ?");
$stmt->bind_param("sdsii", $newValue, $ts, $server_id, $version, $targetId);
$stmt->execute();
$stmt->close();

// simulate overlap
usleep(200000); // 0.2s

$mysqli->commit();
$duration = microtime(true) - $ts;

// return current row state
$res = $mysqli->query("SELECT id, firstName, last_update_ts, last_update_server, version FROM Users WHERE id = $targetId LIMIT 1");
$row = $res->fetch_assoc();

echo json_encode([
    'status'=>'Committed',
    'duration'=>$duration,
    'server_id'=>$server_id,
    'isolation'=>$level,
    'row'=>$row
]);
