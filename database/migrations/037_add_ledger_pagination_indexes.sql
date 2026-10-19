-- Support bounded newest-first ledger reads without scanning every entry.
ALTER TABLE customer_ledger
    ADD INDEX idx_customer_ledger_recent (customer_id, created_at, id);

ALTER TABLE supplier_ledger
    ADD INDEX idx_supplier_ledger_recent (supplier_id, created_at, id);
