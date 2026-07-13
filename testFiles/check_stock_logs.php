<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

echo "--- AUDIT LOGS FOR ITEM 12 ---\n";
$stmt = $db->query("SELECT * FROM audit_logs WHERE entity_type = 'items' AND entity_id = 12 ORDER BY created_at ASC");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
