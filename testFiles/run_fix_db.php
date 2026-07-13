<?php
$pdo = new PDO('mysql:host=localhost;dbname=erp;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$sql = file_get_contents(__DIR__ . '/fix_db.sql');
$pdo->exec($sql);
echo "fix_db.sql executed successfully via PHP.\n";
