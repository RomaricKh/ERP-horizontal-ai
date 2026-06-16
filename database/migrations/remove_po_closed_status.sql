-- Migration: Remove CLOSED status from purchase orders
-- CLOSED is no longer used; RECEIVED is the terminal state.
-- Payment status is computed dynamically from payment_allocations.

-- Revert any existing CLOSED POs back to RECEIVED
UPDATE purchase_orders SET status = 'RECEIVED', updated_at = NOW() WHERE status = 'CLOSED';

-- Note: The ENUM still contains 'CLOSED' for backwards compatibility,
-- but the application no longer sets or transitions to this status.
-- A future migration can drop the ENUM value once confirmed safe.
