<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Let's find the batch created at 2026-06-24 23:09:50
$b = $db->query("SELECT * FROM batch_transactions WHERE created_at >= '2026-06-24 23:09:00' AND created_at <= '2026-06-24 23:10:00'");
print_r($b->fetchAll(PDO::FETCH_ASSOC));
