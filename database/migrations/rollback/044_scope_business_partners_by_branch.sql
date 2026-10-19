-- Manual rollback for 044_scope_business_partners_by_branch.sql.
-- Run during a maintenance window after verifying no branch-local duplicates
-- would become ambiguous when the branch columns are removed.

DROP EVENT IF EXISTS cleanup_inventory_events;

ALTER TABLE customer_ledger DROP FOREIGN KEY fk_ledger_customer;
ALTER TABLE customer_ledger ADD CONSTRAINT fk_ledger_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE;
ALTER TABLE supplier_ledger DROP FOREIGN KEY fk_sledger_supplier;
ALTER TABLE supplier_ledger ADD CONSTRAINT fk_sledger_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE;

ALTER TABLE customer_ledger DROP FOREIGN KEY fk_customer_ledger_branch;
ALTER TABLE supplier_ledger DROP FOREIGN KEY fk_supplier_ledger_branch;
ALTER TABLE customers DROP FOREIGN KEY fk_customer_branch;
ALTER TABLE suppliers DROP FOREIGN KEY fk_supplier_branch;

ALTER TABLE customer_ledger DROP INDEX idx_customer_ledger_branch_recent;
ALTER TABLE supplier_ledger DROP INDEX idx_supplier_ledger_branch_recent;
ALTER TABLE customers DROP INDEX idx_customers_branch_deleted_name;
ALTER TABLE customers DROP INDEX uq_customers_branch_id;
ALTER TABLE suppliers DROP INDEX idx_suppliers_branch_deleted_name;
ALTER TABLE suppliers DROP INDEX uq_suppliers_branch_id;

ALTER TABLE customer_ledger DROP COLUMN branch_id;
ALTER TABLE supplier_ledger DROP COLUMN branch_id;
ALTER TABLE customers DROP COLUMN branch_id;
ALTER TABLE suppliers DROP COLUMN branch_id;
