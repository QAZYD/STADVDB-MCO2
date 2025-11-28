<?php
require 'config.php';

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$result = $conn->query("SELECT COUNT(*) as total FROM Users");

if($result){
    $row = $result->fetch_assoc();
    echo "Node 0 Users: " . $row['total'];
} else {
    echo "Query failed: " . $conn->error;
}
?>
