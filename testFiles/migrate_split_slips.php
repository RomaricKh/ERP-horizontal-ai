<?php
require 'vendor/autoload.php';

$pdo = new PDO('mysql:host=localhost;dbname=erp', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->beginTransaction();

try {
    // 1. Create collection_slip_lines table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `collection_slip_lines` (
            `id` int NOT NULL AUTO_INCREMENT,
            `collection_slip_id` int NOT NULL,
            `account_id` int NOT NULL,
            `currency` varchar(10) NOT NULL,
            `exchange_rate` decimal(24,12) NOT NULL DEFAULT '1.000000000000',
            `amount_fc` decimal(12,2) NOT NULL,
            `amount_bc` decimal(12,2) NOT NULL,
            `method` enum('CASH','BANK','CHECK','TRANSFER') NOT NULL,
            `notes` text,
            PRIMARY KEY (`id`),
            KEY `fk_csl_slip` (`collection_slip_id`),
            KEY `fk_csl_account` (`account_id`),
            CONSTRAINT `fk_csl_slip` FOREIGN KEY (`collection_slip_id`) REFERENCES `collection_slips` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_csl_account` FOREIGN KEY (`account_id`) REFERENCES `business_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    // 2. Create payment_slip_lines table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payment_slip_lines` (
            `id` int NOT NULL AUTO_INCREMENT,
            `payment_slip_id` int NOT NULL,
            `account_id` int NOT NULL,
            `currency` varchar(10) NOT NULL,
            `exchange_rate` decimal(24,12) NOT NULL DEFAULT '1.000000000000',
            `amount_fc` decimal(12,2) NOT NULL,
            `amount_bc` decimal(12,2) NOT NULL,
            `method` enum('CASH','BANK','CHECK','TRANSFER') NOT NULL,
            `notes` text,
            PRIMARY KEY (`id`),
            KEY `fk_psl_slip` (`payment_slip_id`),
            KEY `fk_psl_account` (`account_id`),
            CONSTRAINT `fk_psl_slip` FOREIGN KEY (`payment_slip_id`) REFERENCES `payment_slips` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_psl_account` FOREIGN KEY (`account_id`) REFERENCES `business_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    // 3. Migrate Collection Slips data
    $csls = $pdo->query("SELECT id, account_id, currency, exchange_rate, amount_fc, amount_bc, method FROM collection_slips")->fetchAll(PDO::FETCH_ASSOC);
    $stmtCsl = $pdo->prepare("INSERT INTO collection_slip_lines (collection_slip_id, account_id, currency, exchange_rate, amount_fc, amount_bc, method) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($csls as $c) {
        $stmtCsl->execute([$c['id'], $c['account_id'], $c['currency'], $c['exchange_rate'], $c['amount_fc'], $c['amount_bc'], $c['method']]);
    }

    // 4. Migrate Payment Slips data
    $psls = $pdo->query("SELECT id, account_id, currency, exchange_rate, amount_fc, amount_bc, method FROM payment_slips")->fetchAll(PDO::FETCH_ASSOC);
    $stmtPsl = $pdo->prepare("INSERT INTO payment_slip_lines (payment_slip_id, account_id, currency, exchange_rate, amount_fc, amount_bc, method) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($psls as $p) {
        $stmtPsl->execute([$p['id'], $p['account_id'], $p['currency'], $p['exchange_rate'], $p['amount_fc'], $p['amount_bc'], $p['method']]);
    }

    // 5. Add total_bc columns
    $pdo->exec("ALTER TABLE collection_slips ADD COLUMN `total_bc` decimal(12,2) NOT NULL AFTER `slip_date`");
    $pdo->exec("ALTER TABLE payment_slips ADD COLUMN `total_bc` decimal(12,2) NOT NULL AFTER `payment_date`");

    // 6. Backfill total_bc
    $pdo->exec("UPDATE collection_slips SET total_bc = amount_bc");
    $pdo->exec("UPDATE payment_slips SET total_bc = amount_bc");

    // 7. Drop FKs and columns from collection_slips
    // Need to drop FK constraint first!
    $pdo->exec("ALTER TABLE collection_slips DROP FOREIGN KEY fk_collection_slips_account");
    $pdo->exec("ALTER TABLE collection_slips DROP COLUMN account_id");
    $pdo->exec("ALTER TABLE collection_slips DROP COLUMN currency");
    $pdo->exec("ALTER TABLE collection_slips DROP COLUMN exchange_rate");
    $pdo->exec("ALTER TABLE collection_slips DROP COLUMN amount_fc");
    $pdo->exec("ALTER TABLE collection_slips DROP COLUMN amount_bc");
    $pdo->exec("ALTER TABLE collection_slips DROP COLUMN method");

    // 8. Drop FKs and columns from payment_slips
    $pdo->exec("ALTER TABLE payment_slips DROP FOREIGN KEY fk_payment_slips_account");
    $pdo->exec("ALTER TABLE payment_slips DROP COLUMN account_id");
    $pdo->exec("ALTER TABLE payment_slips DROP COLUMN currency");
    $pdo->exec("ALTER TABLE payment_slips DROP COLUMN exchange_rate");
    $pdo->exec("ALTER TABLE payment_slips DROP COLUMN amount_fc");
    $pdo->exec("ALTER TABLE payment_slips DROP COLUMN amount_bc");
    $pdo->exec("ALTER TABLE payment_slips DROP COLUMN method");

    $pdo->commit();
    echo "Migration successful!\n";

} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
