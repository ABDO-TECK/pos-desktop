-- Migration: 002_update_invoice_status
-- Description: Changes invoice status column from ENUM to VARCHAR to support 'reserved' status.

ALTER TABLE invoices 
MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'completed';
