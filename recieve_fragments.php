<?php
// Base folder for fragments
$baseDir = __DIR__ . '/';

// Ensure Node1/Node2 folders exist
if (!is_dir($baseDir . 'Node1')) mkdir($baseDir . 'Node1', 0777, true);
if (!is_dir($baseDir . 'Node2')) mkdir($baseDir . 'Node2', 0777, true);

foreach ($_FILES as $file) {
    $folder = $file['name'] === 'Users_node1.sql' ? 'Node1/' : 'Node2/';
    $target = $baseDir . $folder . basename($file['name']);

    if(move_uploaded_file($file['tmp_name'], $target)){
        echo "Saved {$file['name']} to $folder\n";
    } else {
        echo "Failed to save {$file['name']}\n";
    }
}
?>
