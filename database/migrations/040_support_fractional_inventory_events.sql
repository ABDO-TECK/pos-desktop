ALTER TABLE inventory_events
    MODIFY COLUMN quantity DECIMAL(12,3) NOT NULL
        COMMENT 'الكمية الجديدة بعد التغيير',
    MODIFY COLUMN delta DECIMAL(12,3) NOT NULL DEFAULT 0
        COMMENT 'مقدار التغيير (+ أو -)';
