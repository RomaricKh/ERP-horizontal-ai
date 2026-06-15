-- Migration: Add Notifications tables
-- This migration creates the database schema for the user notifications system:
-- notifications, notification_preferences

-- --------------------------------------------------------
-- Table: notifications
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` enum('PO_STATUS_CHANGE','INVOICE_DUE','STOCK_LOW','PAYMENT_RECEIVED','USER_MESSAGE','SYSTEM_ALERT') NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `sender_id` int NULL,
  `recipient_id` int NOT NULL,
  `business_id` int NOT NULL,
  `entity_type` varchar(100) NULL,
  `entity_id` int NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime NULL,
  `deleted_at` datetime NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_recipient_business` (`recipient_id`, `business_id`, `deleted_at`, `created_at`),
  INDEX `idx_recipient_unread` (`recipient_id`, `business_id`, `is_read`, `deleted_at`),
  INDEX `idx_poll` (`recipient_id`, `business_id`, `created_at`, `deleted_at`),
  INDEX `idx_cleanup` (`deleted_at`),
  CONSTRAINT `fk_notifications_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notifications_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: notification_preferences
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `notification_preferences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `business_id` int NOT NULL,
  `type` enum('PO_STATUS_CHANGE','INVOICE_DUE','STOCK_LOW','PAYMENT_RECEIVED','USER_MESSAGE','SYSTEM_ALERT') NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_business_type` (`user_id`, `business_id`, `type`),
  CONSTRAINT `fk_notification_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_preferences_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
