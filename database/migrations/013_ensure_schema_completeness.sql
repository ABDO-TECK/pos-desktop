-- Migration: 013_ensure_schema_completeness
-- Description: يضمن وجود جميع الأعمدة والجداول الجديدة التي قد تنقص
-- من النسخ الاحتياطية القديمة عند استعادتها.
-- آمن للتشغيل أكثر من مرة (أخطاء Duplicate column/table يتم تجاهلها تلقائياً).

-- ── إضافة customer_id للفواتير (نظام العملاء والبيع بالآجل) ──
ALTER TABLE invoices ADD COLUMN customer_id INT NULL COMMENT 'رابط العميل — فارغ للمبيعات النقدية' AFTER user_id;
ALTER TABLE invoices ADD INDEX idx_customer (customer_id);

-- ── إضافة amount_due للفواتير ──
ALTER TABLE invoices ADD COLUMN amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'المتبقي على ذمة العميل بعد خصم العربون';

-- ── إضافة unit_cost لبنود الفواتير ──
ALTER TABLE invoice_items ADD COLUMN unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'تكلفة الوحدة لحظة البيع (للتقارير)';

-- ── إضافة sell_by_weight و box_barcode للمنتجات ──
ALTER TABLE products ADD COLUMN box_barcode VARCHAR(100) NULL DEFAULT NULL AFTER barcode;
ALTER TABLE products ADD COLUMN sell_by_weight TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity;

-- ── إضافة force_password_change للمستخدمين ──
ALTER TABLE users ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

-- ── جدول الباركودات الإضافية ──
CREATE TABLE IF NOT EXISTS product_barcodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    barcode VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_product_barcodes_barcode (barcode),
    KEY idx_product_barcodes_product (product_id),
    CONSTRAINT fk_pb_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── جداول العملاء ──
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) NULL,
    address TEXT NULL,
    initial_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_name (name),
    INDEX idx_customers_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    type ENUM('debit','credit') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(500) NULL,
    invoice_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cl_customer (customer_id),
    INDEX idx_cl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    type ENUM('debit','credit') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(500) NULL,
    purchase_invoice_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sl_supplier (supplier_id),
    INDEX idx_sl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── جدول سجل المراجعة ──
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT            NULL,
    action      VARCHAR(50)    NOT NULL,
    entity_type VARCHAR(50)    NOT NULL,
    entity_id   INT            NULL,
    old_value   JSON           NULL,
    new_value   JSON           NULL,
    ip_address  VARCHAR(45)    NULL,
    created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user   (user_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── جدول المصروفات ──
CREATE TABLE IF NOT EXISTS expenses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category    VARCHAR(100)   NOT NULL,
    amount      DECIMAL(10,2)  NOT NULL,
    description TEXT           NULL,
    expense_date DATE          NOT NULL,
    created_by  INT UNSIGNED   NULL,
    created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expense_date (expense_date),
    INDEX idx_expense_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Soft Delete ──
ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE customers ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE suppliers ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE invoices ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;

-- ── الإعدادات الافتراضية ──
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('store_name', 'سوبر ماركت'),
('tax_enabled', '0'),
('tax_rate', '15');
