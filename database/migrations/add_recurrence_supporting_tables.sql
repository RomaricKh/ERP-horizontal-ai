-- Migration: Add recurrence supporting tables
-- This migration creates the database schema for:
-- bulk_advance_payments, prepaid_assets, accounting_entries, recurrence_audit_log

-- --------------------------------------------------------
-- Table: bulk_advance_payments
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `bulk_advance_payments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `recurrence_schedule_id` int UNSIGNED NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `total_amount_bc` decimal(15,2) NOT NULL,
  `currency` varchar(3) NOT NULL,
  `periods_count` int UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `payment_record_id` int NULL,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_bap_business` (`business_id`),
  INDEX `idx_bap_schedule` (`recurrence_schedule_id`),
  INDEX `idx_bap_payment_record` (`payment_record_id`),
  CONSTRAINT `fk_bap_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bap_schedule` FOREIGN KEY (`recurrence_schedule_id`) REFERENCES `recurrence_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bap_payment_record` FOREIGN KEY (`payment_record_id`) REFERENCES `payment_slips` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: prepaid_assets
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `prepaid_assets` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `bulk_advance_payment_id` int UNSIGNED NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `total_amount_bc` decimal(15,2) NOT NULL,
  `per_period_amount` decimal(15,2) NOT NULL,
  `per_period_amount_bc` decimal(15,2) NOT NULL,
  `currency` varchar(3) NOT NULL,
  `periods_total` int UNSIGNED NOT NULL,
  `periods_recognized` int UNSIGNED NOT NULL DEFAULT 0,
  `status` enum('active','fully_amortized','reversed') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_pa_business_status` (`business_id`, `status`),
  INDEX `idx_pa_bulk_advance` (`bulk_advance_payment_id`),
  CONSTRAINT `fk_pa_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_bulk_advance` FOREIGN KEY (`bulk_advance_payment_id`) REFERENCES `bulk_advance_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: accounting_entries
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `accounting_entries` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `entry_date` date NOT NULL,
  `entry_type` enum('prepaid_asset_creation','expense_recognition','prepaid_asset_reversal') NOT NULL,
  `debit_account` varchar(100) NOT NULL,
  `credit_account` varchar(100) NOT NULL,
  `amount_fc` decimal(15,2) NOT NULL,
  `amount_bc` decimal(15,2) NOT NULL,
  `currency` varchar(3) NOT NULL,
  `reference_type` varchar(50) NOT NULL,
  `reference_id` int UNSIGNED NOT NULL,
  `description` text NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ae_business_type_date` (`business_id`, `entry_type`, `entry_date`),
  INDEX `idx_ae_reference` (`reference_type`, `reference_id`),
  CONSTRAINT `fk_ae_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: recurrence_audit_log
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `recurrence_audit_log` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `user_id` int NULL,
  `entity_type` enum('schedule','instance') NOT NULL,
  `entity_id` int NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `previous_state` json NULL,
  `new_state` json NULL,
  `metadata` json NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ral_business_entity` (`business_id`, `entity_type`, `entity_id`),
  INDEX `idx_ral_business_action_date` (`business_id`, `action_type`, `created_at`),
  CONSTRAINT `fk_ral_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
