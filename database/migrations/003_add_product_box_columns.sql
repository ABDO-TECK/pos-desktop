-- Migration: 003_add_product_box_columns
-- Description: Adds box_barcode and units_per_box to products if they do not exist.

ALTER TABLE products ADD COLUMN box_barcode VARCHAR(100) NULL DEFAULT NULL UNIQUE AFTER barcode;
