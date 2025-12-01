<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Distributed DB Setup</title>
    <style>
        body { font-family: monospace; padding: 20px; }
        pre { background: #f0f0f0; padding: 15px; border-radius: 5px; white-space: pre-wrap; }
        button { padding: 10px 20px; font-size: 16px; }
        button:disabled { background: #ccc; cursor: not-allowed; }
    </style>
</head>
<body>

<h2>Distributed Database Setup</h2>

<p>Server IP: <strong id="server-ip"></strong></p>

<button id="run-btn" disabled>Run Distributed DB Setup</button>

<pre id="log-output"></pre>

<script>
const isMaster = <?php echo $isMasterFlag; ?>;
document.getElementById('server-ip').innerText = "<?php echo $currentIPHtml; ?>";

const btn = document.getElementById('run-btn');
const logOutput = document.getElementById('log-output');

if (isMaster) {
    btn.disabled = false;

    btn.addEventListener('click', () => {
        btn.disabled = true;
        logOutput.textContent = "Starting scripts...\n";

        const xhr = new XMLHttpRequest();
        xhr.open("POST", "distributed_db_action.php", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

        xhr.onreadystatechange = function() {
            if (xhr.readyState === XMLHttpRequest.DONE) {
                logOutput.textContent += xhr.responseText;
                btn.disabled = false;
            }
        };

        xhr.send("run=1");
    });
} else {
    logOutput.textContent = "⚠ This action can only be run on the master server (Server0).";
}
</script>

</body>
</html>
