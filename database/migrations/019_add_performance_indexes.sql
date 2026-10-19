-- ============================================================
-- 019: إضافة فهارس الأداء بناءً على الاستعلامات الفعلية
-- ============================================================

-- invoices: البحث حسب التاريخ + الحالة (تقارير يومية/شهرية)
ALTER TABLE invoices ADD INDEX idx_inv_date_status (created_at, status);

-- invoices: البحث حسب payment_method (تقارير المدفوعات)
ALTER TABLE invoices ADD INDEX idx_inv_payment (payment_method);

-- invoice_items: جلب بيانات البيع حسب المنتج (تقارير المنتجات الأكثر مبيعاً)
ALTER TABLE invoice_items ADD INDEX idx_ii_product_invoice (product_id, invoice_id);

-- expenses: البحث حسب التاريخ (تقرير يومي)
ALTER TABLE expenses ADD INDEX idx_exp_date (expense_date);

-- expenses: البحث حسب التصنيف + التاريخ
ALTER TABLE expenses ADD INDEX idx_exp_cat_date (category_id, expense_date);

-- purchases: البحث حسب المنتج (تقرير تكلفة المخزون)
ALTER TABLE purchases ADD INDEX idx_pur_product (product_id);

-- products: البحث حسب الكمية المنخفضة (تنبيهات المخزون)
ALTER TABLE products ADD INDEX idx_prod_low_stock (quantity, low_stock_threshold, deleted_at);

-- customer_ledger: حساب الرصيد حسب العميل
ALTER TABLE customer_ledger ADD INDEX idx_cl_customer_type (customer_id, type);

-- supplier_ledger: حساب الرصيد حسب المورد
ALTER TABLE supplier_ledger ADD INDEX idx_sl_supplier_type (supplier_id, type);

-- tokens: تنظيف التوكنات المنتهية
ALTER TABLE tokens ADD INDEX idx_token_expires (expires_at);

-- audit_logs: البحث حسب التاريخ (الأحدث أولاً)
ALTER TABLE audit_logs ADD INDEX idx_audit_created_desc (created_at DESC);
