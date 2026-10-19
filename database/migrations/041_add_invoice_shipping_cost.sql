-- Migration: 041_add_invoice_shipping_cost
-- Store delivery charges separately while keeping them in the invoice total.

ALTER TABLE invoices
    ADD COLUMN shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax;
