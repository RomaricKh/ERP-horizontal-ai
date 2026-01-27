<?php
// bootstrap.php

require_once __DIR__ . '/vendor/autoload.php';   // Composer autoloader
require_once __DIR__ . '/src/Config/config.php'; // App config

use App\Config\Database;

// Start session for authentication
session_start();

// Connect to DB
Database::connect($config['db']);
?>