-- 048: Make the stock policy configurable while preserving the guarded default.
INSERT IGNORE INTO settings (`key`, `value`)
VALUES ('prevent_negative_stock', '1');
