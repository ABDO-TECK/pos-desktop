CREATE INDEX idx_invoices_branch_status_created
    ON invoices (branch_id, status, created_at);

CREATE INDEX idx_expenses_branch_date
    ON expenses (branch_id, expense_date);

CREATE INDEX idx_purchase_invoices_branch_created
    ON purchase_invoices (branch_id, created_at);
