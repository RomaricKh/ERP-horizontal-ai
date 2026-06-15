-- Migration: Seed notification_types with system types
-- Part of REQ-012: Fix notification table redundancy
-- The notification_types table exists but is empty. Seed it with the known system types.

INSERT INTO notification_types (id, code, description) VALUES
  (1, 'low_stock',        'Triggered when item stock falls below min_stock'),
  (2, 'overdue_payment',  'Triggered when a recurrence instance becomes overdue'),
  (3, 'expiry_alert',     'Triggered when a batch is near expiry'),
  (4, 'invoice_overdue',  'Triggered when a sales invoice passes its due date'),
  (5, 'system',           'General system messages')
ON DUPLICATE KEY UPDATE description = VALUES(description);
