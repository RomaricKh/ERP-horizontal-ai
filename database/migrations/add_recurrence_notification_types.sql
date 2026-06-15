-- Migration: Add recurrence notification types to notification_preferences ENUM
-- Required for: EXPENSE_DUE, SALARY_DUE, EXPENSE_OVERDUE, SALARY_OVERDUE notifications

ALTER TABLE `notification_preferences`
  MODIFY COLUMN `type` enum(
    'PO_STATUS_CHANGE',
    'INVOICE_DUE',
    'STOCK_LOW',
    'PAYMENT_RECEIVED',
    'USER_MESSAGE',
    'SYSTEM_ALERT',
    'EXPENSE_DUE',
    'SALARY_DUE',
    'EXPENSE_OVERDUE',
    'SALARY_OVERDUE'
  ) NOT NULL;

-- Insert notification type records for the new recurrence types
INSERT IGNORE INTO `notification_types` (`code`, `description`) VALUES
  ('EXPENSE_DUE', 'Recurring expense payment coming due'),
  ('SALARY_DUE', 'Recurring salary payment coming due'),
  ('EXPENSE_OVERDUE', 'Recurring expense payment is overdue'),
  ('SALARY_OVERDUE', 'Recurring salary payment is overdue');
