-- Make customer and supplier accounts branch-local. Existing single-branch data
-- remains in branch 1; records already referenced by branch data inherit the
-- lowest historical branch so the migration is deterministic.

ALTER TABLE customers ADD COLUMN branch_id INT NULL DEFAULT 1 AFTER id;
ALTER TABLE suppliers ADD COLUMN branch_id INT NULL DEFAULT 1 AFTER id;
ALTER TABLE customer_ledger ADD COLUMN branch_id INT NULL DEFAULT 1 AFTER id;
ALTER TABLE supplier_ledger ADD COLUMN branch_id INT NULL DEFAULT 1 AFTER id;

-- A shared historical account cannot be assigned to one branch without
-- changing financial ownership. Abort instead of silently moving or cloning
-- balances; operators must split such accounts explicitly and rerun.
CREATE TEMPORARY TABLE migration_044_scope_guard (
    conflict_detected TINYINT NOT NULL CHECK (conflict_detected = 0)
);

INSERT INTO migration_044_scope_guard (conflict_detected)
SELECT 1
FROM invoices
WHERE customer_id IS NOT NULL
GROUP BY customer_id
HAVING COUNT(DISTINCT branch_id) > 1
LIMIT 1;

INSERT INTO migration_044_scope_guard (conflict_detected)
SELECT 1
FROM purchase_invoices
GROUP BY supplier_id
HAVING COUNT(DISTINCT branch_id) > 1
LIMIT 1;

DROP TEMPORARY TABLE migration_044_scope_guard;

UPDATE customers c
LEFT JOIN (
    SELECT customer_id, MIN(branch_id) AS branch_id
    FROM invoices
    WHERE customer_id IS NOT NULL
    GROUP BY customer_id
) historical ON historical.customer_id = c.id
SET c.branch_id = COALESCE(historical.branch_id, c.branch_id, 1);

UPDATE suppliers s
LEFT JOIN (
    SELECT supplier_id, MIN(branch_id) AS branch_id
    FROM purchase_invoices
    GROUP BY supplier_id
) historical ON historical.supplier_id = s.id
SET s.branch_id = COALESCE(historical.branch_id, s.branch_id, 1);

UPDATE customer_ledger cl
JOIN customers c ON c.id = cl.customer_id
SET cl.branch_id = c.branch_id;

UPDATE supplier_ledger sl
JOIN suppliers s ON s.id = sl.supplier_id
SET sl.branch_id = s.branch_id;

ALTER TABLE customers MODIFY branch_id INT NOT NULL DEFAULT 1;
ALTER TABLE suppliers MODIFY branch_id INT NOT NULL DEFAULT 1;
ALTER TABLE customer_ledger MODIFY branch_id INT NOT NULL DEFAULT 1;
ALTER TABLE supplier_ledger MODIFY branch_id INT NOT NULL DEFAULT 1;

ALTER TABLE customers ADD INDEX idx_customers_branch_deleted_name (branch_id, deleted_at, name, id);
ALTER TABLE customers ADD UNIQUE INDEX uq_customers_branch_id (branch_id, id);
ALTER TABLE suppliers ADD INDEX idx_suppliers_branch_deleted_name (branch_id, deleted_at, name, id);
ALTER TABLE suppliers ADD UNIQUE INDEX uq_suppliers_branch_id (branch_id, id);
ALTER TABLE customer_ledger ADD INDEX idx_customer_ledger_branch_recent (branch_id, customer_id, created_at, id);
ALTER TABLE supplier_ledger ADD INDEX idx_supplier_ledger_branch_recent (branch_id, supplier_id, created_at, id);

ALTER TABLE customers ADD CONSTRAINT fk_customer_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE suppliers ADD CONSTRAINT fk_supplier_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE customer_ledger ADD CONSTRAINT fk_customer_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE supplier_ledger ADD CONSTRAINT fk_supplier_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;

-- Replace the original single-column ledger ownership FKs with composite FKs.
-- ON DELETE CASCADE is intentionally retained.
SET @migration_044_drop_customer_canonical_fk = (
    SELECT CASE WHEN COUNT(*) > 0
        THEN 'ALTER TABLE customer_ledger DROP FOREIGN KEY fk_ledger_customer'
        ELSE 'DO 1'
    END
    FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'customer_ledger'
      AND constraint_name = 'fk_ledger_customer'
);
PREPARE migration_044_customer_canonical_fk_stmt FROM @migration_044_drop_customer_canonical_fk;
EXECUTE migration_044_customer_canonical_fk_stmt;
DEALLOCATE PREPARE migration_044_customer_canonical_fk_stmt;
-- Older installations created this relation as fk_cl_customer. Drop it only
-- when present so this migration remains directly rerunnable.
SET @migration_044_drop_customer_fk = (
    SELECT CASE WHEN COUNT(*) > 0
        THEN 'ALTER TABLE customer_ledger DROP FOREIGN KEY fk_cl_customer'
        ELSE 'DO 1'
    END
    FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'customer_ledger'
      AND constraint_name = 'fk_cl_customer'
);
PREPARE migration_044_customer_fk_stmt FROM @migration_044_drop_customer_fk;
EXECUTE migration_044_customer_fk_stmt;
DEALLOCATE PREPARE migration_044_customer_fk_stmt;
ALTER TABLE customer_ledger ADD CONSTRAINT fk_ledger_customer FOREIGN KEY (branch_id, customer_id) REFERENCES customers(branch_id, id) ON DELETE CASCADE;
SET @migration_044_drop_supplier_canonical_fk = (
    SELECT CASE WHEN COUNT(*) > 0
        THEN 'ALTER TABLE supplier_ledger DROP FOREIGN KEY fk_sledger_supplier'
        ELSE 'DO 1'
    END
    FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'supplier_ledger'
      AND constraint_name = 'fk_sledger_supplier'
);
PREPARE migration_044_supplier_canonical_fk_stmt FROM @migration_044_drop_supplier_canonical_fk;
EXECUTE migration_044_supplier_canonical_fk_stmt;
DEALLOCATE PREPARE migration_044_supplier_canonical_fk_stmt;
-- Older installations created this relation as fk_sl_supplier.
SET @migration_044_drop_supplier_fk = (
    SELECT CASE WHEN COUNT(*) > 0
        THEN 'ALTER TABLE supplier_ledger DROP FOREIGN KEY fk_sl_supplier'
        ELSE 'DO 1'
    END
    FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'supplier_ledger'
      AND constraint_name = 'fk_sl_supplier'
);
PREPARE migration_044_supplier_fk_stmt FROM @migration_044_drop_supplier_fk;
EXECUTE migration_044_supplier_fk_stmt;
DEALLOCATE PREPARE migration_044_supplier_fk_stmt;
ALTER TABLE supplier_ledger ADD CONSTRAINT fk_sledger_supplier FOREIGN KEY (branch_id, supplier_id) REFERENCES suppliers(branch_id, id) ON DELETE CASCADE;

-- Cleanup is maintenance work, not request work. The migration runner tolerates
-- missing EVENT privileges; deployments without them can enqueue the
-- cleanup_inventory_events maintenance job from their scheduler.
CREATE EVENT IF NOT EXISTS cleanup_inventory_events
ON SCHEDULE EVERY 1 HOUR
DO DELETE FROM inventory_events WHERE created_at < NOW() - INTERVAL 24 HOUR;
