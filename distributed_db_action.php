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

// Define remote servers for imports
$remoteServers = [
    [ "host" => "10.2.14.130", "user" => "user1" ], // Server1
    [ "host" => "10.2.14.131", "user" => "user2" ]  // Server2
];

function runScripts(array $scripts, string $baseDir, array $remoteServers = []) {
    $output = "";

    foreach ($scripts as $script) {
        $scriptPath = "$baseDir/$script";

        if (!file_exists($scriptPath)) {
            $output .= "❌ Script $scriptPath not found.\n";
            continue;
        }

        chmod($scriptPath, 0755);
        $output .= "=== Running $script ===\n";

        // Determine command to run
        if (strpos($script, 'import_fragments') === 0) {
            // Remote import script
            $serverIndex = ($script === 'import_fragments1.sh') ? 0 : 1;
            $server = $remoteServers[$serverIndex];

            $cmd = "ssh {$server['user']}@{$server['host']} 'bash -s' < $scriptPath 2>&1";
            $output .= "Running import remotely on {$server['host']}...\n";
        } else {
            // Local script (create or push) → run as root using sudo
            $cmd = "sudo $scriptPath 2>&1";
        }

        // Execute command
        $proc = popen($cmd, 'r');
        if (is_resource($proc)) {
            while (!feof($proc)) {
                $line = fgets($proc);
                if ($line !== false) {
                    $output .= $line;
                    flush();
                }
            }
            pclose($proc);
        }

        $output .= "=== Finished $script ===\n\n";
    }

    return $output;
}

$scripts = [
    "create_fragments.sh",    // local on Server0 → root
    "push_fragments.sh",      // local on Server0 → root
    "import_fragments1.sh",   // remote on Server1
    "import_fragments2.sh"    // remote on Server2
];

$baseDir = "/var/www/html/myProject/scripts";

echo nl2br(runScripts($scripts, $baseDir, $remoteServers));
