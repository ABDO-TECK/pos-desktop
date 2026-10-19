-- Cover the branch predicates enforced by user, product, invoice, and SSE reads.
ALTER TABLE users
    ADD INDEX idx_users_branch_id (branch_id, id);

ALTER TABLE products
    ADD INDEX idx_products_branch_parent (branch_id, deleted_at, parent_product_id, id);

ALTER TABLE invoices
    ADD INDEX idx_invoices_branch_deleted_created (branch_id, deleted_at, created_at, id);

ALTER TABLE inventory_events
    ADD INDEX idx_inventory_events_branch_id (branch_id, id);
