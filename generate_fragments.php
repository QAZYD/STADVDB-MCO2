<?php
// Path to your shell script
$script = "/var/www/html/myProject/scripts/create_fragment.sh";

// Execute the script
exec("sudo $script 2>&1", $output, $return_var);

if ($return_var === 0) {
    echo "<h3>Fragments created successfully:</h3>";
    echo "<pre>" . implode("\n", $output) . "</pre>";
} else {
    echo "<h3>Error creating fragments:</h3>";
    echo "<pre>" . implode("\n", $output) . "</pre>";
}
?>
