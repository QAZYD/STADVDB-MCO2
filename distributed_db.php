<?php
// distributed_db.php

// Configuration: Master server internal IP
$masterIP = "10.2.14.129"; // Server0's internal IP

// Detect current server's internal IP
$serverIPs = trim(shell_exec("hostname -I")); // returns space-separated IPs
$serverIPArray = explode(' ', $serverIPs);
$currentIP = $serverIPArray[0]; // use the first IP

// Check if this is the master server
$isMaster = ($currentIP === $masterIP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Distributed DB Actions</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<h1>Distributed Database Management</h1>

<button id="runBtn" disabled>Run Scripts</button>
<div id="log"></div>

<!-- Pass PHP variable to JS via data attribute -->
<script>
    window.appConfig = {
        isMaster: <?php echo json_encode($isMaster); ?>,
        actionUrl: "distributed_db_action.php"
    };
</script>
<script src="scripts.js"></script>

</body>
</html>
