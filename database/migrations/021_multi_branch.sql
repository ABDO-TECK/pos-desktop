-- Migration: 021_multi_branch
-- دعم الفروع المتعددة

CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO branches (id, name) VALUES (1, 'الفرع الرئيسي');

ALTER TABLE users ADD COLUMN branch_id INT DEFAULT 1;
ALTER TABLE products ADD COLUMN branch_id INT DEFAULT 1;
ALTER TABLE invoices ADD COLUMN branch_id INT DEFAULT 1;
ALTER TABLE expenses ADD COLUMN branch_id INT DEFAULT 1;
ALTER TABLE inventory_events ADD COLUMN branch_id INT DEFAULT 1;
ALTER TABLE purchase_invoices ADD COLUMN branch_id INT DEFAULT 1;

ALTER TABLE users ADD CONSTRAINT fk_user_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
