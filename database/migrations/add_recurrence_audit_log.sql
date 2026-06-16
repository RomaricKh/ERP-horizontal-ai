-- Create recurrence_audit_log table for tracking recurrence-related actions
CREATE TABLE IF NOT EXISTS `recurrence_audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `previous_state` json DEFAULT NULL,
  `new_state` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ral_business` (`business_id`),
  KEY `idx_ral_entity` (`entity_type`, `entity_id`),
  KEY `idx_ral_created` (`created_at`),
  CONSTRAINT `fk_ral_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
