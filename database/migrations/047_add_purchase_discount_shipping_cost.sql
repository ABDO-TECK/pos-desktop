-- 047: Store supplier discounts and inbound shipping costs on purchase invoices.
-- The legacy vehicle_number column is intentionally retained for historical data,
-- but is no longer accepted or written by the purchase API.

ALTER TABLE purchase_invoices
  ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total,
  ADD COLUMN shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount;
