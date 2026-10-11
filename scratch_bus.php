<?php
require_once __DIR__ . '/bootstrap.php';
$db = App\Config\Database::getConnection();
$stmt = $db->query("SELECT id, name FROM businesses");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
