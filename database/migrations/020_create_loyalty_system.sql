-- Migration: 020_create_loyalty_system
-- نظام نقاط الولاء

-- إعدادات الولاء في جدول settings
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('loyalty_enabled', '0'),
('loyalty_points_per_rial', '1'),
('loyalty_rial_per_point', '0.01');

-- إضافة عمود النقاط للعملاء
ALTER TABLE customers ADD COLUMN loyalty_points INT DEFAULT 0;

-- سجل حركات النقاط
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
