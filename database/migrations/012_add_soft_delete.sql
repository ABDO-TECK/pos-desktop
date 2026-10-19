-- إضافة عمود deleted_at للجداول الرئيسية (Soft Delete)
ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE customers ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE suppliers ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE invoices ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;

-- إضافة فهارس لتسريع الاستعلامات مع الفلترة
ALTER TABLE products ADD INDEX idx_products_deleted (deleted_at);
ALTER TABLE customers ADD INDEX idx_customers_deleted (deleted_at);
ALTER TABLE suppliers ADD INDEX idx_suppliers_deleted (deleted_at);
ALTER TABLE invoices ADD INDEX idx_invoices_deleted (deleted_at);
