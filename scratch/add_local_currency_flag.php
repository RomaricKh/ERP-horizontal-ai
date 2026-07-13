<?php
require __DIR__ . '/../bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
try {
    $db->exec("ALTER TABLE business_accounts ADD COLUMN is_local_currency_account TINYINT(1) NOT NULL DEFAULT 0");
    echo "Column added successfully.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
