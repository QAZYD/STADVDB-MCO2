<?php
// distributed_db.php

// Detect if this is master server
$masterIP = "10.2.14.129"; // Server0 internal IP
$serverIPs = trim(shell_exec("hostname -I"));
$serverIPArray = explode(' ', $serverIPs);
$currentIP = $serverIPArray[0];
$isMaster = ($currentIP === $masterIP);
?>
<?php
$nodeStatusFile = __DIR__ . "/recovery/node_status.php";
$nodeStates = include $nodeStatusFile;

// If toggle form submitted
if (isset($_POST['toggle_node']) && isset($_POST['node'])) {
    $node = $_POST['node'];

    if (isset($nodeStates[$node])) {
        $current = $nodeStates[$node]['online'];
        $nodeStates[$node]['online'] = !$current;

        // Write updated states back to PHP file
        $export = "<?php\nreturn " . var_export($nodeStates, true) . ";\n?>";
        file_put_contents($nodeStatusFile, $export);

        $msg = "Node '$node' turned " . ($nodeStates[$node]['online'] ? "ON" : "OFF");
    } else {
        $msg = "Invalid node: $node";
    }

    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($msg));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Distributed DB Management & Crash Recovery Simulation</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        button { margin: 5px; padding: 10px 20px; font-size: 14px; cursor: pointer; }
        button:disabled { background-color: #ccc; cursor: not-allowed; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .section h3 { margin-top: 0; color: #333; }
        #log { 
            margin-top: 20px; 
            white-space: pre-wrap; 
            background: #1e1e1e; 
            color: #d4d4d4;
            padding: 15px; 
            border-radius: 5px;
            max-height: 500px; 
            overflow-y: auto; 
            font-family: 'Consolas', monospace;
            font-size: 13px;
        }
        .success { color: #4ec9b0; }
        .error { color: #f14c4c; }
        .warning { color: #cca700; }
        .info { color: #3794ff; }
        #nodeStatus { 
            display: flex; 
            gap: 20px; 
            margin-bottom: 20px; 
        }
        .node-card {
            padding: 15px;
            border-radius: 5px;
            min-width: 150px;
            text-align: center;
        }
        .node-online { background: #d4edda; border: 1px solid #28a745; }
        .node-offline { background: #f8d7da; border: 1px solid #dc3545; }
        .node-unknown { background: #fff3cd; border: 1px solid #ffc107; }
    </style>
</head>
<body>

<h1>Distributed Database Management & Crash Recovery</h1>

<!-- Node Health Status -->
<div class="section">
    <h3>Node Health Status</h3>
    <div id="nodeStatus">
        <div class="node-card node-unknown" id="status-central">
            <strong>Central Node</strong><br>
            <span>Checking...</span>
        </div>
        <div class="node-card node-unknown" id="status-node2">
            <strong>Node 2</strong><br>
            <span>Checking...</span>
        </div>
        <div class="node-card node-unknown" id="status-node3">
            <strong>Node 3</strong><br>
            <span>Checking...</span>
        </div>
    </div>
    <button id="refreshHealthBtn">Refresh Health Status</button>
</div>
<div class="section">
  <h3>Manual Node Toggle</h3>
  <button onclick="toggleNode('central', 'off')">Turn Central OFF</button>
  <button onclick="toggleNode('central', 'on')">Turn Central ON</button>
  <button onclick="toggleNode('node2', 'off')">Turn Node2 OFF</button>
  <button onclick="toggleNode('node2', 'on')">Turn Node2 ON</button>
  <button onclick="toggleNode('node3', 'off')">Turn Node3 OFF</button>
  <button onclick="toggleNode('node3', 'on')">Turn Node3 ON</button>
</div>

<!-- Fragment Distribution -->
<div class="section">
    <h3>Fragment Distribution</h3>
    <?php if ($isMaster): ?>
        <button id="runDistributionBtn">Run Fragment Distribution</button>
    <?php else: ?>
        <p style="color:red;">This server is not the master. Fragment distribution is disabled.</p>
    <?php endif; ?>
</div>

<!-- Concurrency Cases -->
<div class="section">
    <h3>Concurrency Simulation Cases</h3>
    <button id="runCase1Btn">Case #1: Concurrent Reads</button>
    <button id="runCase2Btn">Case #2: Master-Slave Timing</button>
    <button id="runCase3Btn">Case #3: Multi-Master Conflict</button>
</div>

<!-- Crash Recovery Cases -->
<div class="section">
    <h3>Crash Recovery Simulation Cases</h3>
    <button id="runCrashCase1Btn">Crash Case #1: Slave→Central Failure</button>
    <button id="runCrashCase2Btn">Crash Case #2: Central Node Recovery</button>
    <button id="runCrashCase3Btn">Crash Case #3: Central→Slave Failure</button>
    <button id="runCrashCase4Btn" data-node="node2">Crash Case #4: Node 2 Recovery</button>
    <button id="runCrashCase4Node3Btn" data-node="node3">Crash Case #4: Node 3 Recovery</button>
</div>

<!-- Log Output -->
<div id="log">Logs will appear here...</div>

<script>
const logDiv = document.getElementById("log");

// Helper to append messages to log with styling
function appendLog(message, type = 'info') {
    const timestamp = new Date().toLocaleTimeString();
    let className = '';
    
    if (message.includes('✔') || message.includes('SUCCESS')) className = 'success';
    else if (message.includes('✖') || message.includes('ERROR') || message.includes('failed')) className = 'error';
    else if (message.includes('⚠') || message.includes('WARNING')) className = 'warning';
    else className = 'info';
    
    const span = document.createElement('span');
    span.className = className;
    span.textContent = `[${timestamp}] ${message}\n`;
    logDiv.appendChild(span);
    logDiv.scrollTop = logDiv.scrollHeight;
}

function clearLog() {
    logDiv.innerHTML = '';
}

// Update node health display
function updateNodeStatus(nodeName, isOnline, ip) {
    const card = document.getElementById(`status-${nodeName}`);
    if (card) {
        card.className = `node-card ${isOnline ? 'node-online' : 'node-offline'}`;
        const displayName = nodeName.charAt(0).toUpperCase() + nodeName.slice(1);
        card.innerHTML = `
            <strong>${displayName}</strong><br>
            <span>${ip}</span><br>
            <span>${isOnline ? '● Online' : '○ Offline'}</span>
        `;
    }
}

// Fetch node health status
function refreshHealthStatus() {
    fetch("recovery/node_health_check.php")
    .then(res => res.json())
    .then(status => {
        for (const [node, info] of Object.entries(status)) {
            updateNodeStatus(node, info.online, info.ip);
        }
        appendLog("Node health status refreshed");
    })
    .catch(err => {
        appendLog("Failed to refresh health status: " + err, 'error');
    });
}

// Initial health check
refreshHealthStatus();

// Refresh health button
document.getElementById("refreshHealthBtn").addEventListener("click", refreshHealthStatus);

// Fragment distribution
<?php if ($isMaster): ?>
document.getElementById("runDistributionBtn").addEventListener("click", function() {
    this.disabled = true;
    clearLog();
    appendLog("Starting fragment distribution...");

    fetch("distributed_db_action.php", { 
        method: "POST", 
        body: "run=1",
        headers: { "Content-Type": "application/x-www-form-urlencoded" }
    })
    .then(res => res.text())
    .then(log => {
        log.split('\n').forEach(line => appendLog(line));
        appendLog("Fragment distribution completed.");
        this.disabled = false;
    })
    .catch(err => {
        appendLog("Error during fragment distribution: " + err, 'error');
        this.disabled = false;
    });
});
<?php endif; ?>

// Case #1 concurrency simulation
document.getElementById("runCase1Btn").addEventListener("click", function() {
    this.disabled = true;
    clearLog();
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
        }
        appendLog("Case #1 simulation completed.");
        this.disabled = false;
    })
    .catch(err => {
        appendLog("Error during Case #1 simulation: " + err, 'error');
        this.disabled = false;
    });
});

// Case #2 master-slave timing simulation
document.getElementById("runCase2Btn").addEventListener("click", function() {
    this.disabled = true;
    clearLog();
    appendLog("Running Case #2 Master-Slave Timing Simulation...");

    fetch("case2_backend.php")
    .then(res => res.json())
    .then(results => {
        appendLog("=== Case #2 Master-Slave Timing Results ===");
        for (const [level, info] of Object.entries(results)) {
            appendLog(`Isolation Level: ${level}`);
            if (info.before_commit) {
                appendLog("  --- Before Master Commit ---");
                appendLog("  Node1: " + JSON.stringify(info.before_commit.node1));
                appendLog("  Node2: " + JSON.stringify(info.before_commit.node2));
            }
            if (info.after_commit) {
                appendLog("  --- After Master Commit ---");
                appendLog("  Master: " + JSON.stringify(info.after_commit.master));
                appendLog("  Node1: " + JSON.stringify(info.after_commit.node1));
                appendLog("  Node2: " + JSON.stringify(info.after_commit.node2));
            }
        }
        appendLog("Case #2 simulation completed.");
        this.disabled = false;
    })
    .catch(err => {
        appendLog("Error during Case #2 simulation: " + err, 'error');
        this.disabled = false;
    });
});

// Case #3 multi-master write conflict simulation
document.getElementById("runCase3Btn").addEventListener("click", function() {
    this.disabled = true;
    clearLog();
    appendLog("Running Case #3 Multi-Master Write Conflict Simulation...");

    fetch("case3_backend.php")
        .then(res => res.json())
        .then(results => {
            appendLog("=== Case #3 Multi-Master Write Conflict Results ===");

            for (const [level, data] of Object.entries(results)) {
                appendLog(`\nIsolation Level: ${level}`);
                appendLog("  Server 0: " + JSON.stringify(data.server0));
                appendLog("  Server 1: " + JSON.stringify(data.server1));
                appendLog("  Final Value: " + JSON.stringify(data.final_value));
            }

            appendLog("\nCase #3 simulation completed.");
            this.disabled = false;
        })
        .catch(err => {
            appendLog("Error during Case #3 simulation: " + err, 'error');
            this.disabled = false;
        });
});

// ========== CRASH RECOVERY CASES ==========

// Crash Case #1: Slave to Central Failure
document.getElementById("runCrashCase1Btn").addEventListener("click", function() {
    this.disabled = true;
    clearLog();
    appendLog("Running Crash Case #1: Slave→Central Replication Failure...");

    fetch("recovery/crash_case1_backend.php")
    .then(res => res.json())
    .then(results => {
        appendLog("=== Crash Case #1 Results ===");
        results.log.forEach(line => appendLog(line));
        appendLog("");
        appendLog("Strategy: " + JSON.stringify(results.strategy, null, 2));
        this.disabled = false;
        refreshHealthStatus();
    })
    .catch(err => {
        appendLog("Error during Crash Case #1: " + err, 'error');
        this.disabled = false;
    });
});

// Crash Case #2: Central Node Recovery
document.getElementById("runCrashCase2Btn").addEventListener("click", function() {
    this.disabled = true;
    clearLog();
    appendLog("Running Crash Case #2: Central Node Recovery...");

    fetch("recovery/crash_case2_backend.php")
    .then(res => res.json())
    .then(results => {
        appendLog("=== Crash Case #2 Results ===");
        results.log.forEach(line => appendLog(line));
        appendLog("");
        appendLog("Recovered: " + results.recovered);
        appendLog("Failed: " + results.failed);
        appendLog("Strategy: " + JSON.stringify(results.recovery_strategy, null, 2));
        this.disabled = false;
        refreshHealthStatus();
    })
    .catch(err => {
        appendLog("Error during Crash Case #2: " + err, 'error');
        this.disabled = false;
    });
});

// Crash Case #3: Central to Slave Failure
document.getElementById("runCrashCase3Btn").addEventListener("click", function() {
    this.disabled = true;
    clearLog();
    appendLog("Running Crash Case #3: Central→Slave Replication Failure...");

    fetch("recovery/crash_case3_backend.php")
    .then(res => res.json())
    .then(results => {
        appendLog("=== Crash Case #3 Results ===");
        results.log.forEach(line => appendLog(line));
        appendLog("");
        appendLog("Replication Results: " + JSON.stringify(results.replication, null, 2));
        appendLog("Availability Strategy: " + JSON.stringify(results.availability_strategy, null, 2));
        this.disabled = false;
        refreshHealthStatus();
    })
    .catch(err => {
        appendLog("Error during Crash Case #3: " + err, 'error');
        this.disabled = false;
    });
});

// Crash Case #4: Node 2 Recovery
document.getElementById("runCrashCase4Btn").addEventListener("click", function() {
    this.disabled = true;
    clearLog();
    const node = this.getAttribute('data-node');
    appendLog(`Running Crash Case #4: ${node} Recovery...`);

    fetch(`recovery/crash_case4_backend.php?node=${node}`)
    .then(res => res.json())
    .then(results => {
        appendLog(`=== Crash Case #4 Results (${node}) ===`);
        results.log.forEach(line => appendLog(line));
        appendLog("");
        appendLog("Recovered: " + results.recovered);
        appendLog("Failed: " + results.failed);
        appendLog("Strategy: " + JSON.stringify(results.recovery_strategy, null, 2));
        this.disabled = false;
        refreshHealthStatus();
    })
    .catch(err => {
        appendLog(`Error during Crash Case #4 (${node}): ` + err, 'error');
        this.disabled = false;
    });
});

// Crash Case #4: Node 3 Recovery
document.getElementById("runCrashCase4Node3Btn").addEventListener("click", function() {
    this.disabled = true;
    clearLog();
    const node = this.getAttribute('data-node');
    appendLog(`Running Crash Case #4: ${node} Recovery...`);

    fetch(`recovery/crash_case4_backend.php?node=${node}`)
    .then(res => res.json())
    .then(results => {
        appendLog(`=== Crash Case #4 Results (${node}) ===`);
        results.log.forEach(line => appendLog(line));
        appendLog("");
        appendLog("Recovered: " + results.recovered);
        appendLog("Failed: " + results.failed);
        appendLog("Strategy: " + JSON.stringify(results.recovery_strategy, null, 2));
        this.disabled = false;
        refreshHealthStatus();
    })
    .catch(err => {
        appendLog(`Error during Crash Case #4 (${node}): ` + err, 'error');
        this.disabled = false;
    });
});

// AJAX toggle for node status
function toggleNode(node, state) {
    fetch("recovery/toggle_node_status.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ node, state })
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            appendLog(`Node ${res.node} switched ${res.online ? "ON" : "OFF"}`, res.online ? "success" : "warning");
            refreshHealthStatus();
        } else {
            appendLog(`Failed to toggle node: ${res.error}`, "error");
        }
    })
    .catch(err => appendLog("Toggle error: " + err, "error"));
}

</script>

</body>
</html>
