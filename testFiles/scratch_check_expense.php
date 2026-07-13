<?php
require 'bootstrap.php';
$db = \App\Config\Database::getConnection();
print_r($db->query("SELECT * FROM recurrence_instances WHERE payment_record_id = 14")->fetchAll(PDO::FETCH_ASSOC));
print_r($db->query("SELECT * FROM recurrence_instances WHERE business_id = 3")->fetchAll(PDO::FETCH_ASSOC));
