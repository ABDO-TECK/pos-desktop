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
    INDEX idx_audit_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
