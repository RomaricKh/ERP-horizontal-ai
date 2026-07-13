<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=erp', 'root', '');
print_r($pdo->query("SELECT * FROM permissions")->fetchAll(PDO::FETCH_ASSOC));
