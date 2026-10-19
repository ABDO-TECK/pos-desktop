CREATE TABLE IF NOT EXISTS inventory_events (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    action     ENUM('sale','purchase','adjust','delete') NOT NULL,
    quantity   DECIMAL(12,3) NOT NULL COMMENT 'الكمية الجديدة بعد التغيير',
    delta      DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'مقدار التغيير (+ أو -)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
