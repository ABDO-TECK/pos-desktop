-- ALTER TABLE supplier_ledger: ON DELETE CASCADE
ALTER TABLE supplier_ledger DROP FOREIGN KEY fk_sledger_pinvoice;
ALTER TABLE supplier_ledger 
    ADD CONSTRAINT fk_sledger_pinvoice 
    FOREIGN KEY (purchase_invoice_id) 
    REFERENCES purchase_invoices(id) 
    ON DELETE CASCADE;

-- ALTER TABLE customer_ledger: ON DELETE CASCADE
ALTER TABLE customer_ledger DROP FOREIGN KEY fk_ledger_invoice;
ALTER TABLE customer_ledger 
    ADD CONSTRAINT fk_ledger_invoice 
    FOREIGN KEY (invoice_id) 
    REFERENCES invoices(id) 
    ON DELETE CASCADE;
