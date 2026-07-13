<?php
$files = glob("c:/wamp64/www/ERP-horizontal-ai/public/assets/js/*/*.js");
foreach ($files as $file) {
    $lines = file($file);
    foreach ($lines as $i => $line) {
        if (stripos($line, 'batch_number') !== false || stripos($line, '-B-') !== false) {
            echo basename($file) . ":" . ($i + 1) . ": " . trim($line) . "\n";
        }
    }
}
