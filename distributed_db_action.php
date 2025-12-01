<?php
set_time_limit(0);
ob_implicit_flush(true);
ob_end_flush();

// Configuration
$masterIP = "10.2.14.129"; // Server0's internal IP
$serverIPs = trim(shell_exec("hostname -I"));
$serverIPArray = explode(' ', $serverIPs);
$currentIP = $serverIPArray[0];
$isMaster = ($currentIP === $masterIP);

if (!isset($_POST['run']) || !$isMaster) {
    http_response_code(403);
    echo "❌ Forbidden: Not master or invalid request.";
    exit;
}

// Remote servers
$remoteServers = [
    [ "host" => "10.2.14.130", "user" => "simon" ],
    [ "host" => "10.2.14.131", "user" => "simon" ]
];

// Steps log
$log = [];

function runCommand($cmd, &$log, $message) {
    $proc = popen($cmd, 'r');
    if (is_resource($proc)) {
        while (!feof($proc)) {
            fgets($proc); // ignore output, we only want simplified log
        }
        pclose($proc);
    }
    $log[] = "✔ $message";
}

$baseDir = "/var/www/html/myProject/scripts";

// 1. Create fragments
runCommand("sudo $baseDir/create_fragments.sh 2>&1", $log, "Fragments created");

// 2. Push fragments
runCommand("sudo $baseDir/push_fragments.sh 2>&1", $log, "Fragments pushed");

// 3. Import fragments to server1
$server = $remoteServers[0];
runCommand("ssh {$server['user']}@{$server['host']} 'bash -s' < $baseDir/import_fragments1.sh 2>&1", $log, "Fragments server1 successful");

// 4. Import fragments to server2
$server = $remoteServers[1];
runCommand("ssh {$server['user']}@{$server['host']} 'bash -s' < $baseDir/import_fragments2.sh 2>&1", $log, "Fragments server2 successful");

// Output with newlines in browser
echo "<pre>" . implode("\n", $log) . "</pre>";
