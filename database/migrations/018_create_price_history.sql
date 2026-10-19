-- Migration: إنشاء جدول تتبع تغييرات الأسعار
-- يُسجّل كل تغيير في سعر البيع أو التكلفة لأي منتج

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
