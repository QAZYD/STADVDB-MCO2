<?php
// distributed_db_action.php
set_time_limit(0);
ob_implicit_flush(true);
ob_end_flush();

// Configuration: Master server IP
$masterIP = "10.2.14.129"; // Server0's internal IP

// Detect current server's IP
$currentIP = getHostByName(getHostName());

// Check if this is the master server
$isMaster = ($currentIP === $masterIP);

// Function to run scripts and capture logs
function runScripts(array $scripts, string $baseDir) {
    $output = "";
    foreach ($scripts as $script) {
        $scriptPath = "$baseDir/$script";

        if (!file_exists($scriptPath)) {
            $output .= "❌ Script $scriptPath not found.\n";
            continue;
        }

        chmod($scriptPath, 0755);
        $output .= "=== Running $script ===\n";

        $proc = popen($scriptPath . " 2>&1", 'r');
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

// Handle AJAX request
if (isset($_POST['run']) && $isMaster) {
    $scripts = [
        "create_fragments.sh",
        "push_fragments.sh",
        "import_fragments1.sh",
        "import_fragments2.sh"
    ];

    $baseDir = "/var/www/html/myProject/scripts";

    echo nl2br(runScripts($scripts, $baseDir));
    exit;
}

// Pass server info to the HTML file
$isMasterFlag = $isMaster ? "1" : "0";
$currentIPHtml = htmlspecialchars($currentIP);
