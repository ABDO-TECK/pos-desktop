-- Migration: 015_add_purchase_invoices
-- Description: إنشاء جدول فواتير المشتريات وإضافة عمود purchase_invoice_id

CREATE TABLE IF NOT EXISTS purchase_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    items_count INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pinv_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    INDEX idx_pinv_supplier (supplier_id),
    INDEX idx_pinv_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE purchases ADD COLUMN purchase_invoice_id INT NULL AFTER id;
ALTER TABLE purchases ADD CONSTRAINT fk_purchase_pinv FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL;
ALTER TABLE purchases ADD INDEX idx_purchase_invoice (purchase_invoice_id);
