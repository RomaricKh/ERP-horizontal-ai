<?php
$files = glob("c:/wamp64/www/ERP-horizontal-ai/src/*/*.php");
foreach ($files as $file) {
    $lines = file($file);
    foreach ($lines as $i => $line) {
        if (stripos($line, 'batchNumber') !== false || stripos($line, 'batch_number') !== false) {
            echo basename($file) . ":" . ($i + 1) . ": " . trim($line) . "\n";
        }
    }
}
