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

// Only master can run scripts
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

function runScripts(array $scripts, string $baseDir, array $remoteServers = []) {
    $log = [];

    foreach ($scripts as $index => $script) {
        $scriptPath = "$baseDir/$script";
        if (!file_exists($scriptPath)) continue;

        chmod($scriptPath, 0755);

        if ($index === 0) {
            // create_fragments.sh
            $cmd = "sudo $scriptPath 2>&1";
            popen($cmd, 'r'); // just run, no detailed output
            $log[] = "✔ Fragments created";
        } 
        elseif ($index === 1) {
            // push_fragments.sh
            $cmd = "sudo $scriptPath 2>&1";
            popen($cmd, 'r');
            $log[] = "✔ Fragments pushed";
        } 
        else {
            // import_fragments*.sh
            $serverIndex = $index - 2;
            $server = $remoteServers[$serverIndex];
            $cmd = "ssh {$server['user']}@{$server['host']} 'bash -s' < $scriptPath 2>&1";
            popen($cmd, 'r');
            $log[] = "✔ Fragments server" . ($serverIndex + 1) . " successful";
        }
    }

    return implode("<br />", $log);
}

$scripts = [
    "create_fragments.sh",
    "push_fragments.sh",
    "import_fragments1.sh",
    "import_fragments2.sh"
];

$baseDir = "/var/www/html/myProject/scripts";

echo runScripts($scripts, $baseDir, $remoteServers);
