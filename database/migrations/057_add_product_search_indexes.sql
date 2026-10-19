-- Migration 057: Add composite and search indexes for product catalog performance
CREATE INDEX idx_products_barcode_deleted ON products (barcode, deleted_at);
