-- Migration 043: monotonic, branch-scoped catalog change sequence for offline delta sync.
CREATE TABLE IF NOT EXISTS product_catalog_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_catalog_changes_branch_sequence (branch_id, id),
    KEY idx_catalog_changes_product (product_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_products_catalog_insert;
CREATE TRIGGER trg_products_catalog_insert
AFTER INSERT ON products
FOR EACH ROW
INSERT INTO product_catalog_changes (branch_id, product_id)
VALUES (NEW.branch_id, NEW.id);

DROP TRIGGER IF EXISTS trg_products_catalog_update;
CREATE TRIGGER trg_products_catalog_update
AFTER UPDATE ON products
FOR EACH ROW
INSERT INTO product_catalog_changes (branch_id, product_id)
VALUES (NEW.branch_id, NEW.id);

DROP TRIGGER IF EXISTS trg_products_catalog_delete;
CREATE TRIGGER trg_products_catalog_delete
AFTER DELETE ON products
FOR EACH ROW
INSERT INTO product_catalog_changes (branch_id, product_id)
VALUES (OLD.branch_id, OLD.id);
