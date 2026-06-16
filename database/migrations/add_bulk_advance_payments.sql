-- Add bulk_advance_payments table and advance-related columns to recurrence_instances

CREATE TABLE IF NOT EXISTS `bulk_advance_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `recurrence_schedule_id` int unsigned NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `total_amount_bc` decimal(15,2) NOT NULL,
  `currency` varchar(3) NOT NULL,
  `periods_count` int NOT NULL,
  `payment_date` date NOT NULL,
  `payment_record_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bap_business` (`business_id`),
  KEY `idx_bap_schedule` (`recurrence_schedule_id`),
  CONSTRAINT `fk_bap_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_bap_schedule` FOREIGN KEY (`recurrence_schedule_id`) REFERENCES `recurrence_schedules` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Add advance payment tracking columns to recurrence_instances
ALTER TABLE `recurrence_instances`
  ADD COLUMN `is_advance_payment` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `bulk_advance_payment_id` int DEFAULT NULL,
  ADD CONSTRAINT `fk_instances_bap` FOREIGN KEY (`bulk_advance_payment_id`) REFERENCES `bulk_advance_payments` (`id`);
