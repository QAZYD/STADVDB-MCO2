<?php
// Path to your shell script
$script = "/var/www/html/myProject/scripts/import_fragments.sh";

// Execute the shell script
exec("sudo $script 2>&1", $output, $return_var);

// Show results
if ($return_var === 0) {
    echo "<h3>Fragments distributed successfully:</h3>";
    echo "<pre>" . implode("\n", $output) . "</pre>";
} else {
    echo "<h3>Error distributing fragments:</h3>";
    echo "<pre>" . implode("\n", $output) . "</pre>";
}
?>
