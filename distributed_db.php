<?php
// distributed_db.php

// Detect if this is master server
$masterIP = "10.2.14.129"; // Server0 internal IP
$serverIPs = trim(shell_exec("hostname -I"));
$serverIPArray = explode(' ', $serverIPs);
$currentIP = $serverIPArray[0];
$isMaster = ($currentIP === $masterIP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Distributed DB Management & Concurrency Simulation</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        button { margin: 5px; padding: 10px 20px; font-size: 16px; }
        #log { margin-top: 20px; white-space: pre-wrap; background: #f5f5f5; padding: 10px; border: 1px solid #ccc; max-height: 500px; overflow-y: auto; }
    </style>
</head>
<body>

<h1>Distributed Database Management</h1>

<?php if ($isMaster): ?>
    <button id="runDistributionBtn">Run Fragment Distribution</button>
<?php else: ?>
    <p style="color:red;">This server is not the master. Fragment distribution is disabled.</p>
<?php endif; ?>

<button id="runCase1Btn">Run Case #1 Simulation</button>
<button id="runCase2Btn">Run Case #2 Master-Slave Timing</button>
<button id="runCase3Btn">Run Case #3 Multi-Master Write Conflict</button>

<div id="log">Logs will appear here...</div>

<script>
const logDiv = document.getElementById("log");

// Helper to append messages to log
function appendLog(message) {
    logDiv.textContent += message + "\n";
    logDiv.scrollTop = logDiv.scrollHeight;
}

// Fragment distribution
<?php if ($isMaster): ?>
document.getElementById("runDistributionBtn").addEventListener("click", function() {
    this.disabled = true;
    appendLog("Starting fragment distribution...");

    fetch("distributed_db_action.php", { 
        method: "POST", 
        body: "run=1",
        headers: { "Content-Type": "application/x-www-form-urlencoded" }
    })
    .then(res => res.text())
    .then(log => {
        appendLog(log);
        appendLog("Fragment distribution completed.\n");
        this.disabled = false;
    })
    .catch(err => {
        appendLog("❌ Error during fragment distribution: " + err);
        this.disabled = false;
    });
});
<?php endif; ?>

// Case #1 concurrency simulation
document.getElementById("runCase1Btn").addEventListener("click", function() {
    this.disabled = true;
    appendLog("Running Case #1 concurrency simulation...");

    fetch("case1_backend.php")
    .then(res => res.json())
    .then(results => {
        appendLog("=== Case #1 Simulation Results ===");
        for (const [level, nodes] of Object.entries(results)) {
            appendLog(`Isolation Level: ${level}`);
            for (const [node, data] of Object.entries(nodes)) {
                appendLog(`  ${node}: ${JSON.stringify(data)}`);
            }
            appendLog(""); // newline
        }
        appendLog("Case #1 simulation completed.\n");
        this.disabled = false;
    })
    .catch(err => {
        appendLog("❌ Error during Case #1 simulation: " + err);
        this.disabled = false;
    });
});

// Case #2 master-slave timing simulation
document.getElementById("runCase2Btn").addEventListener("click", function() {
    this.disabled = true;
    appendLog("Running Case #2 Master-Slave Timing Simulation...");

    fetch("case2_backend.php")
    .then(res => res.json())
.then(results => {
    appendLog("=== Case #2 Master-Slave Timing Results ===");
    for (const [level, info] of Object.entries(results)) {
        appendLog(`Isolation Level: ${level}`);

        // Before commit reads
        appendLog("  --- Before Master Commit ---");
        appendLog("  Node1: " + JSON.stringify(info.before_commit.node1));
        appendLog("  Node2: " + JSON.stringify(info.before_commit.node2));

        // After commit reads
        appendLog("  --- After Master Commit ---");
        appendLog("  Master: " + JSON.stringify(info.after_commit.master));
        appendLog("  Node1: " + JSON.stringify(info.after_commit.node1));
        appendLog("  Node2: " + JSON.stringify(info.after_commit.node2));

        appendLog(""); // newline
    }
});

});

// Case #3 multi-master write conflict simulation
document.getElementById("runCase3Btn").addEventListener("click", function() {
    this.disabled = true;
    appendLog("Running Case #3 Multi-Master Write Conflict Simulation...");

    fetch("case3_backend.php")
        .then(res => res.json())
        .then(results => {
            appendLog("=== Case #3 Multi-Master Write Conflict Results ===");
            appendLog("Server 0 final value: " + JSON.stringify(results.server0_final));
            appendLog("Server 1 final value: " + JSON.stringify(results.server1_final));
            appendLog("Case #3 simulation completed.\n");
            this.disabled = false;
        })
        .catch(err => {
            appendLog("❌ Error during Case #3 simulation: " + err);
            this.disabled = false;
        });
});


</script>

</body>
</html>
