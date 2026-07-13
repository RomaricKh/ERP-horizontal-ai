<?php
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('c:/wamp64/www/ERP-horizontal-ai/src/'));
foreach ($dir as $file) {
    if ($file->isFile() && $file->getExtension() == 'php') {
        $content = file_get_contents($file->getPathname());
        if (stripos($content, 'batchNumber') !== false || stripos($content, 'uq_batch_number') !== false) {
            echo "Match found in: " . $file->getPathname() . "\n";
            $lines = file($file->getPathname());
            foreach ($lines as $i => $line) {
                if (stripos($line, 'batchNumber') !== false || stripos($line, 'uq_batch_number') !== false) {
                    echo "  Line " . ($i + 1) . ": " . trim($line) . "\n";
                }
            }
        }
    }
}
