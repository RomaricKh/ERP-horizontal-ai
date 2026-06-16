-- Create prepaid_assets table for tracking advance payments as prepaid assets
CREATE TABLE IF NOT EXISTS `prepaid_assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `bulk_advance_payment_id` int DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `total_amount_bc` decimal(15,2) NOT NULL,
  `per_period_amount` decimal(15,2) NOT NULL,
  `per_period_amount_bc` decimal(15,2) NOT NULL,
  `currency` varchar(3) NOT NULL,
  `periods_total` int NOT NULL,
  `periods_recognized` int NOT NULL DEFAULT 0,
  `status` enum('active','fully_recognized','cancelled') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pa_business` (`business_id`),
  KEY `idx_pa_bap` (`bulk_advance_payment_id`),
  KEY `idx_pa_status` (`status`),
  CONSTRAINT `fk_pa_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_bap` FOREIGN KEY (`bulk_advance_payment_id`) REFERENCES `bulk_advance_payments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Create accounting_entries table for double-entry accounting records
CREATE TABLE IF NOT EXISTS `accounting_entries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `entry_date` date NOT NULL,
  `entry_type` varchar(50) NOT NULL,
  `debit_account` varchar(100) NOT NULL,
  `credit_account` varchar(100) NOT NULL,
  `amount_fc` decimal(15,2) NOT NULL,
  `amount_bc` decimal(15,2) NOT NULL,
  `currency` varchar(3) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int DEFAULT NULL,
  `description` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ae_business` (`business_id`),
  KEY `idx_ae_date` (`entry_date`),
  KEY `idx_ae_reference` (`reference_type`, `reference_id`),
  CONSTRAINT `fk_ae_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
