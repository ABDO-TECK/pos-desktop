-- Migration: 042_add_sale_idempotency
-- Branch-scoped sale request claims and immutable response snapshots.
-- Reversible rollback: DROP TABLE IF EXISTS sale_idempotency_keys;

CREATE TABLE IF NOT EXISTS sale_idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    idempotency_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invoice_id INT NULL,
    response_code SMALLINT UNSIGNED NULL,
    response_message VARCHAR(255) NULL,
    response_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_sale_idempotency_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sale_idempotency_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    UNIQUE KEY uq_sale_idempotency_branch_key (branch_id, idempotency_key),
    KEY idx_sale_idempotency_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
