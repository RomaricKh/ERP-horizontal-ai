<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Fetch business profits
$stmt = $db->query("SELECT * FROM business_profits");
$bp = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Business Profits:\n";
print_r($bp);

// Fetch profit distributions
$stmt = $db->query("SELECT * FROM profit_distributions");
$pd = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Profit Distributions:\n";
print_r($pd);
