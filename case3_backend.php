<?php
header("Content-Type: application/json");
set_time_limit(0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $masters = [
        'server0' => ['host'=>'10.2.14.129','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
        'server1' => ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
    ];

    $conn0 = new mysqli(...array_values($masters['server0']));
    $conn1 = new mysqli(...array_values($masters['server1']));

    $conn0->query("SET autocommit=0");
    $conn1->query("SET autocommit=0");
    $conn0->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");
    $conn1->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

    $conn0->begin_transaction();
    $conn1->begin_transaction();

    $conn0->query("UPDATE users SET firstName='Server0Write' WHERE id=1");
    $conn1->query("UPDATE users SET firstName='Server1Write' WHERE id=1");

    sleep(2);

    $conn0->commit();
    $conn1->commit();

    $final0 = $conn0->query("SELECT firstName FROM users WHERE id=1 LIMIT 1")->fetch_assoc();
    $final1 = $conn1->query("SELECT firstName FROM users WHERE id=1 LIMIT 1")->fetch_assoc();

    $conn0->close();
    $conn1->close();

    echo json_encode([
        "server0_final" => $final0,
        "server1_final" => $final1
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        "error" => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
