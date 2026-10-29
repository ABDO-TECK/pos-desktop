-- Migration: 025_legacy_schema_convergence
-- Description: Targeted structural convergence from migration 025 to target 059 explicit restore convergence, candidate 13.
-- Resolves known 68 structural differences against the signed fresh baseline contract.
-- Intentionally scoped to explicit restore convergence; not part of standard migration sequence.

-- 1. Add missing column products.is_weighable at position 8
ALTER TABLE products ADD COLUMN is_weighable TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity;

-- 2. Quantity columns (INT(11) -> DECIMAL(10,3))
ALTER TABLE products MODIFY COLUMN quantity DECIMAL(10,3) NOT NULL DEFAULT 0.000;
ALTER TABLE invoice_items MODIFY COLUMN quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000;
ALTER TABLE purchases MODIFY COLUMN quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000;

-- 3. Display width normalization (INT(10) -> INT(11)) for primary keys and foreign keys
-- Parent / standalone tables
ALTER TABLE categories MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE customers MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE suppliers MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE users MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE products MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE products MODIFY COLUMN category_id INT(11) NULL DEFAULT NULL;
ALTER TABLE purchase_invoices MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE purchase_invoices MODIFY COLUMN supplier_id INT(11) NOT NULL;
ALTER TABLE invoices MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE invoices MODIFY COLUMN user_id INT(11) NOT NULL;
ALTER TABLE tokens MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE tokens MODIFY COLUMN user_id INT(11) NOT NULL;

-- Child / dependent tables
ALTER TABLE product_barcodes MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE product_barcodes MODIFY COLUMN product_id INT(11) NOT NULL;
ALTER TABLE invoice_items MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE invoice_items MODIFY COLUMN invoice_id INT(11) NOT NULL;
ALTER TABLE invoice_items MODIFY COLUMN product_id INT(11) NOT NULL;
ALTER TABLE purchases MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE purchases MODIFY COLUMN supplier_id INT(11) NOT NULL;
ALTER TABLE purchases MODIFY COLUMN product_id INT(11) NOT NULL;
ALTER TABLE customer_ledger MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE customer_ledger MODIFY COLUMN customer_id INT(11) NOT NULL;
ALTER TABLE customer_ledger MODIFY COLUMN invoice_id INT(11) NULL DEFAULT NULL COMMENT 'رابط للفاتورة إن وجدت';
ALTER TABLE customer_ledger MODIFY COLUMN created_by INT(11) NULL DEFAULT NULL COMMENT 'معرف المستخدم الذي سجّل القيد';
ALTER TABLE supplier_ledger MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE supplier_ledger MODIFY COLUMN supplier_id INT(11) NOT NULL;
ALTER TABLE supplier_ledger MODIFY COLUMN purchase_invoice_id INT(11) NULL DEFAULT NULL COMMENT 'رابط لفاتورة المشتريات إن وجدت';
ALTER TABLE supplier_ledger MODIFY COLUMN created_by INT(11) NULL DEFAULT NULL COMMENT 'معرف المستخدم الذي سجّل القيد';

-- 4. Ordinal column reordering to match exact fresh schema positions
ALTER TABLE customers MODIFY COLUMN loyalty_points INT(11) DEFAULT 0 AFTER initial_balance;
ALTER TABLE invoices MODIFY COLUMN branch_id INT(11) DEFAULT 1 AFTER delivery_notes;
ALTER TABLE products MODIFY COLUMN branch_id INT(11) DEFAULT 1 AFTER size_name;
ALTER TABLE purchase_invoices MODIFY COLUMN branch_id INT(11) DEFAULT 1 AFTER delivery_notes;
ALTER TABLE users MODIFY COLUMN branch_id INT(11) DEFAULT 1 AFTER force_password_change;

-- 5. Drop historical duplicate non-unique indexes (14 index rows across 9 index names)
ALTER TABLE customer_ledger DROP INDEX idx_customer_type;
ALTER TABLE expenses DROP INDEX idx_expenses_category_id;
ALTER TABLE expenses DROP INDEX idx_expenses_date;
ALTER TABLE invoice_items DROP INDEX idx_invoice_product;
ALTER TABLE invoices DROP INDEX idx_status_created;
ALTER TABLE purchases DROP INDEX fk_purchase_product;
ALTER TABLE purchases DROP INDEX idx_supplier_created;
ALTER TABLE supplier_ledger DROP INDEX idx_supplier_type;
ALTER TABLE users DROP INDEX fk_user_branch;

-- 6. Add missing foreign key constraint and index on invoices.customer_id
ALTER TABLE invoices ADD CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL;
