-- ============================================================
-- POS Supermarket System - Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS pos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pos_db;

-- ============================================================
-- Schema Versions (For Migration Tracking)
-- ============================================================
CREATE TABLE IF NOT EXISTS schema_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(255) NOT NULL UNIQUE,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Categories
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Products
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    barcode VARCHAR(100) UNIQUE NOT NULL,
    box_barcode VARCHAR(100) NULL DEFAULT NULL UNIQUE,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cost  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    is_weighable TINYINT(1) NOT NULL DEFAULT 0,
    sell_by_weight TINYINT(1) NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 5,
    units_per_box INT NOT NULL DEFAULT 1 COMMENT 'عدد القطع في الصندوق الواحد — للبيع بالكرتون',
    category_id INT NULL,
    branch_id INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_barcode (barcode),
    INDEX idx_name (name),
    INDEX idx_category (category_id),
    INDEX idx_products_deleted (deleted_at),
    INDEX idx_prod_deleted (deleted_at),
    INDEX idx_prod_low_stock (quantity, low_stock_threshold, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extra barcodes for the same product (primary remains products.barcode)
CREATE TABLE IF NOT EXISTS product_barcodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    barcode VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_product_barcodes_barcode (barcode),
    KEY idx_product_barcodes_product (product_id),
    CONSTRAINT fk_pb_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Monotonic change sequence used by resumable offline catalog sync.
-- Triggers are installed by migration 043 after the base schema is loaded.
CREATE TABLE IF NOT EXISTS product_catalog_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_catalog_changes_branch_sequence (branch_id, id),
    KEY idx_catalog_changes_product (product_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Branches (Multi-branch support)
-- ============================================================
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,
    branch_id INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    CONSTRAINT fk_user_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Tokens (API Auth)
-- ============================================================
CREATE TABLE IF NOT EXISTS tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_token_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Refresh Tokens (for session renewal)
-- ============================================================
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_refresh_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_refresh_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Settings
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` MEDIUMTEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Expenses
-- ============================================================
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    notes TEXT NULL,
    expense_date DATETIME NOT NULL,
    branch_id INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_expense_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_expense_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_exp_date (expense_date),
    INDEX idx_exp_cat_date (category_id, expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Customers (نظام العملاء والبيع بالآجل)
-- ============================================================
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) NULL,
    address TEXT NULL,
    initial_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'رصيد مبدئي — لعميل قديم له دين مسبق',
    loyalty_points INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_customer_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    INDEX idx_name (name),
    INDEX idx_customers_deleted (deleted_at),
    INDEX idx_customers_branch_deleted_name (branch_id, deleted_at, name, id),
    UNIQUE INDEX uq_customers_branch_id (branch_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Invoices
-- ============================================================
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    customer_id INT NULL COMMENT 'رابط العميل — فارغ للمبيعات النقدية',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash','card','vodafone_cash','instapay','other_wallet','credit') NOT NULL DEFAULT 'cash',
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    change_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'المتبقي على ذمة العميل بعد خصم العربون',
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    branch_id INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_invoice_user     FOREIGN KEY (user_id)     REFERENCES users(id),
    CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    INDEX idx_created_at (created_at),
    INDEX idx_user       (user_id),
    INDEX idx_customer   (customer_id),
    INDEX idx_invoices_deleted (deleted_at),
    INDEX idx_inv_date_status (created_at, status),
    INDEX idx_inv_payment (payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Invoice Items
-- ============================================================
CREATE TABLE IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    price DECIMAL(10,2) NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'تكلفة الوحدة لحظة البيع (للتقارير)',
    subtotal DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_invoice (invoice_id),
    INDEX idx_product (product_id),
    INDEX idx_ii_product_invoice (product_id, invoice_id),
    INDEX idx_ii_product_qty (product_id, quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Suppliers
-- ============================================================
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    initial_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'رصيد مبدئي — لمورد قديم له دين مسبق',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_supplier_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    INDEX idx_suppliers_deleted (deleted_at),
    INDEX idx_suppliers_branch_deleted_name (branch_id, deleted_at, name, id),
    UNIQUE INDEX uq_suppliers_branch_id (branch_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Purchase Invoices (فواتير المشتريات — رأس الفاتورة)
-- ============================================================
CREATE TABLE IF NOT EXISTS purchase_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    items_count INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    branch_id INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pinv_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    INDEX idx_pinv_supplier (supplier_id),
    INDEX idx_pinv_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Purchases (Stock In from Suppliers — بنود الفاتورة)
-- ============================================================
CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_invoice_id INT NULL,
    supplier_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchase_pinv FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_purchase_product FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_purchase_invoice (purchase_invoice_id),
    INDEX idx_supplier (supplier_id),
    INDEX idx_created_at_purchase (created_at),
    INDEX idx_pur_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Customer Ledger (كشف الحساب — دفتر الأستاذ)
-- ============================================================
CREATE TABLE IF NOT EXISTS customer_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    customer_id INT NOT NULL,
    type ENUM('debit','credit') NOT NULL COMMENT 'debit=مدين (مبيعات آجلة), credit=دائن (دفعات)',
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(500) NULL COMMENT 'البيان: فاتورة بيع / دفعة نقدية / رصيد مبدئي...',
    invoice_id INT NULL COMMENT 'رابط للفاتورة إن وجدت',
    created_by INT NULL COMMENT 'معرف المستخدم الذي سجّل القيد',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_customer FOREIGN KEY (branch_id, customer_id) REFERENCES customers(branch_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_ledger_invoice  FOREIGN KEY (invoice_id)  REFERENCES invoices(id)  ON DELETE SET NULL,
    INDEX idx_customer_ledger (customer_id),
    INDEX idx_ledger_created  (created_at),
    INDEX idx_cl_customer_type (customer_id, type),
    INDEX idx_customer_ledger_branch_recent (branch_id, customer_id, created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Supplier Ledger (كشف حساب المورد — دفتر الأستاذ)
-- ============================================================
CREATE TABLE IF NOT EXISTS supplier_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    supplier_id INT NOT NULL,
    type ENUM('debit','credit') NOT NULL COMMENT 'debit=مدين (مشتريات آجلة), credit=دائن (دفعات للمورد)',
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(500) NULL COMMENT 'البيان: فاتورة شراء / دفعة نقدية / رصيد مبدئي...',
    purchase_invoice_id INT NULL COMMENT 'رابط لفاتورة المشتريات إن وجدت',
    created_by INT NULL COMMENT 'معرف المستخدم الذي سجّل القيد',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sledger_supplier FOREIGN KEY (branch_id, supplier_id) REFERENCES suppliers(branch_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_sledger_pinvoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
    INDEX idx_supplier_ledger (supplier_id),
    INDEX idx_sledger_created (created_at),
    INDEX idx_sl_supplier_type (supplier_id, type),
    INDEX idx_supplier_ledger_branch_recent (branch_id, supplier_id, created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Audit Logs (سجل المراجعة)
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT            NULL,
    action      VARCHAR(50)    NOT NULL COMMENT 'مثال: delete_invoice, update_stock, update_price',
    entity_type VARCHAR(50)    NOT NULL COMMENT 'مثال: invoice, product, inventory',
    entity_id   INT            NULL     COMMENT 'ID العنصر المتأثر',
    old_value   JSON           NULL     COMMENT 'القيمة قبل التعديل',
    new_value   JSON           NULL     COMMENT 'القيمة بعد التعديل',
    ip_address  VARCHAR(45)    NULL,
    created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user   (user_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_date   (created_at),
    INDEX idx_audit_created_desc (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Job Queue (نظام المهام الخلفية)
-- ============================================================
CREATE TABLE IF NOT EXISTS job_queue (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    job_name     VARCHAR(100) NOT NULL,
    payload      JSON DEFAULT NULL,
    priority     TINYINT DEFAULT 0,
    status       ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    attempts     TINYINT DEFAULT 0,
    max_attempts TINYINT DEFAULT 3,
    last_error   TEXT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status_priority (status, priority DESC, id ASC),
    INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Inventory Events (أحداث المخزون — للتحديثات الحية SSE/WebSocket)
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory_events (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    action     ENUM('sale','purchase','adjust','delete') NOT NULL,
    quantity   DECIMAL(12,3) NOT NULL COMMENT 'الكمية الجديدة بعد التغيير',
    delta      DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'مقدار التغيير (+ أو -)',
    branch_id  INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Price History (تتبع تغييرات الأسعار)
-- ============================================================
CREATE TABLE IF NOT EXISTS price_history (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT         NOT NULL,
    old_price  DECIMAL(10,2) NOT NULL COMMENT 'سعر البيع القديم',
    new_price  DECIMAL(10,2) NOT NULL COMMENT 'سعر البيع الجديد',
    old_cost   DECIMAL(10,2) NOT NULL COMMENT 'التكلفة القديمة',
    new_cost   DECIMAL(10,2) NOT NULL COMMENT 'التكلفة الجديدة',
    changed_by INT         NULL      COMMENT 'معرف المستخدم الذي غيّر السعر',
    created_at TIMESTAMP   NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by)  REFERENCES users(id)   ON DELETE SET NULL,
    INDEX idx_product_date (product_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Loyalty Transactions (نظام نقاط الولاء)
-- ============================================================
CREATE TABLE IF NOT EXISTS loyalty_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    invoice_id INT NULL,
    points INT NOT NULL COMMENT 'موجب = اكتساب، سالب = استرداد',
    type ENUM('earn', 'redeem', 'adjust') NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_lt_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_lt_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    INDEX idx_lt_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- RBAC (نظام الصلاحيات والأدوار)
-- ============================================================
CREATE TABLE IF NOT EXISTS permissions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE COMMENT 'مثل: products.create, invoices.delete',
    description VARCHAR(255) DEFAULT '',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
    role       VARCHAR(20)  NOT NULL COMMENT 'admin, cashier, manager...',
    permission_id INT       NOT NULL,
    PRIMARY KEY (role, permission_id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Seed Data
-- ============================================================

-- Default branch
INSERT IGNORE INTO branches (id, name) VALUES (1, 'الفرع الرئيسي');

-- Interactive users are intentionally not seeded. Create the first administrator
-- locally with backend/cli/bootstrap-admin.php and a unique password.

-- Default settings
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('store_name', 'سوبر ماركت'),
('tax_enabled', '0'),
('tax_rate', '15'),
('prevent_negative_stock', '1'),
('loyalty_enabled', '0'),
('loyalty_points_per_rial', '1'),
('loyalty_rial_per_point', '0.01');

-- إدخال الصلاحيات الأساسية
INSERT IGNORE INTO permissions (name, description) VALUES
('products.view',    'عرض المنتجات'),
('products.create',  'إضافة منتجات'),
('products.update',  'تعديل المنتجات'),
('products.delete',  'حذف المنتجات'),
('invoices.view',    'عرض الفواتير'),
('invoices.create',  'إنشاء فواتير'),
('invoices.delete',  'حذف فواتير'),
('reports.view',     'عرض التقارير'),
('settings.view',    'عرض الإعدادات'),
('settings.update',  'تعديل الإعدادات'),
('users.manage',     'إدارة المستخدمين'),
('backup.manage',    'إدارة النسخ الاحتياطية'),
('audit.view',       'عرض سجلات التدقيق'),
('suppliers.view',       'عرض الموردين'),
('suppliers.create',     'إضافة موردين'),
('suppliers.update',     'تعديل موردين'),
('suppliers.delete',     'حذف موردين'),
('purchases.view',       'عرض المشتريات'),
('purchases.create',     'تسجيل مشتريات'),
('purchases.delete',     'حذف فاتورة مشتريات'),
('customers.view',       'عرض العملاء'),
('customers.create',     'إضافة عملاء'),
('customers.update',     'تعديل عملاء'),
('customers.delete',     'حذف عملاء'),
('customers.payment',    'تسجيل دفعات العملاء'),
('expenses.view',        'عرض المصروفات'),
('expenses.create',      'إضافة مصروفات'),
('expenses.update',      'تعديل مصروفات'),
('expenses.delete',      'حذف مصروفات'),
('inventory.view',       'عرض المخزون'),
('inventory.adjust',     'تعديل المخزون'),
('branches.view',        'عرض الفروع'),
('branches.create',      'إضافة فروع'),
('branches.update',      'تعديل فروع');

-- ربط الصلاحيات بالأدوار الافتراضية
-- Admin: كل الصلاحيات
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions;

-- Cashier: صلاحيات محدودة
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'cashier', id FROM permissions
WHERE name IN (
    'products.view', 'invoices.view', 'invoices.create', 'reports.view', 'settings.view',
    'customers.view', 'customers.create', 'customers.update', 'customers.payment',
    'suppliers.view', 'purchases.view', 'expenses.view', 'expenses.create',
    'inventory.view', 'branches.view'
);

-- ============================================================
-- Mark all existing migrations as executed
-- (يمنع إعادة تشغيل الـ migrations على قاعدة بيانات جديدة)
-- ============================================================
INSERT IGNORE INTO schema_versions (version) VALUES
('002_update_invoice_status.sql'),
('003_add_product_box_columns.sql'),
('004_add_product_units_per_box.sql'),
('005_add_invoice_amount_due.sql'),
('006_add_invoice_items_unit_cost.sql'),
('007_create_missing_tables.sql'),
('008_add_expenses_indexes.sql'),
('009_add_default_settings.sql'),
('010_add_force_password_change.sql'),
('011_create_audit_logs.sql'),
('012_add_soft_delete.sql'),
('013_ensure_schema_completeness.sql'),
('014_create_job_queue.sql'),
('015_add_purchase_invoices.sql'),
('016_add_refresh_tokens.sql'),
('017_create_inventory_events.sql'),
('018_create_price_history.sql'),
('019_add_performance_indexes.sql'),
('020_create_loyalty_system.sql'),
('021_multi_branch.sql'),
('022_randomize_default_passwords.sql'),
('023_review_indexes.sql'),
('024_create_rbac_tables.sql'),
('025_add_missing_permissions.sql');

-- QZ signing is an explicit capability, not an implicit side effect of login.
INSERT IGNORE INTO permissions (name, description)
VALUES ('printing.use', 'Use QZ Tray printing and signing');
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'cashier', id FROM permissions WHERE name = 'printing.use';
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'manager', id FROM permissions WHERE name = 'printing.use';
