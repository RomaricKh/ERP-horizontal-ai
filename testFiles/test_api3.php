<?php
$db = new PDO('mysql:host=localhost;dbname=erp;charset=utf8mb4', 'root', '');
$stmt = $db->query("SELECT business_id FROM items WHERE id = 9");
if ($stmt) {
    print_r($stmt->fetch(PDO::FETCH_ASSOC));
}
