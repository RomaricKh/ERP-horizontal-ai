<?php
require_once 'vendor/autoload.php';
$db = new PDO('mysql:host=localhost;dbname=erp', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$repo = new \App\Repository\CollectionAllocationRepository($db);
print_r($repo->findBySlip(1));
