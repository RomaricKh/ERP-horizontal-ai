<?php
// Temporary script to verify config loading
require __DIR__ . '/src/Config/config.php';
echo "Config loaded successfully:\n";
echo "  DB Host: " . $config['db']['host'] . "\n";
echo "  DB Name: " . $config['db']['dbname'] . "\n";
echo "  DB User: " . $config['db']['user'] . "\n";
echo "  DB Pass: " . ($config['db']['pass'] === '' ? '(empty)' : '***') . "\n";
echo "  JWT Secret: " . substr($config['jwt_secret'], 0, 10) . "...\n";
echo "  Cron Secret: " . substr($config['cron_secret'], 0, 10) . "...\n";
echo "\nAll values loaded from .env file.\n";
