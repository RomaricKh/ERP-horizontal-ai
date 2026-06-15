-- Migration: Add recurrence_schedules table
-- This migration creates the database schema for the Recurrence_Engine's
-- schedule configuration, supporting both expense categories and salary recurrences.

-- --------------------------------------------------------
-- Table: recurrence_schedules
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `recurrence_schedules` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` enum('expense_category','salary') NOT NULL,
  `entity_id` int NOT NULL,
  `business_id` int NOT NULL,
  `frequency` enum('weekly','bi-weekly','monthly','quarterly','yearly') NOT NULL,
  `default_amount` decimal(15,2) NOT NULL,
  `default_currency` varchar(3) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_business_entity_active` (`business_id`, `entity_type`, `is_active`),
  INDEX `idx_entity_type_id` (`entity_type`, `entity_id`),
  CONSTRAINT `fk_recurrence_schedules_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
