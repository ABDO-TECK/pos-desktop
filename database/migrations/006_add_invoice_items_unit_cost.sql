-- Migration: 006_add_invoice_items_unit_cost
-- Description: Adds unit_cost to invoice_items.

ALTER TABLE invoice_items ADD COLUMN unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'تكلفة الوحدة لحظة البيع (للتقارير)' AFTER price;
