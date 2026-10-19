-- Migration: 005_add_invoice_amount_due
-- Description: Adds amount_due to invoices.

ALTER TABLE invoices ADD COLUMN amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'المتبقي على ذمة العميل بعد خصم العربون' AFTER change_due;
