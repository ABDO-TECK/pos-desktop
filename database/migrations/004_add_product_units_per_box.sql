-- Migration: 004_add_product_units_per_box
-- Description: Adds units_per_box to products if it does not exist.

ALTER TABLE products ADD COLUMN units_per_box INT NOT NULL DEFAULT 1 COMMENT 'عدد القطع في الصندوق الواحد' AFTER low_stock_threshold;
