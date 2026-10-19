-- ============================================================
-- 023: مراجعة الفهارس بناءً على تحليل الأداء EXPLAIN
-- ============================================================

-- جدول invoice_items كان يقوم بـ Full Table Scan للبحث عن المنتجات الأكثر مبيعاً
-- إضافة فهرس يغطي (product_id, quantity) ليصبح Covering Index لعمليات الـ SUM و GROUP BY
ALTER TABLE invoice_items ADD INDEX idx_ii_product_qty (product_id, quantity);

-- تحسين استعلام low_stock الذي كان يقوم بـ Full Table Scan بسبب غياب فهرس مخصص للبحث عن المحذوفات بشكل أساسي
ALTER TABLE products ADD INDEX idx_prod_deleted (deleted_at);
