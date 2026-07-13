<?php
$db = new PDO('mysql:host=localhost;dbname=erp;charset=utf8mb4', 'root', '');
$stmt = $db->query("SELECT is_deleted FROM sales_invoices LIMIT 1");
if ($stmt) {
    print_r($stmt->fetch(PDO::FETCH_ASSOC));
} else {
    echo "No is_deleted column";
}
