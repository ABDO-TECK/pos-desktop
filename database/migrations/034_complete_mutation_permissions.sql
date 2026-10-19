INSERT IGNORE INTO permissions (name, description) VALUES
('invoices.update_status', 'Update invoice status'),
('customers.ledger.update', 'Update customer ledger entries'),
('customers.ledger.delete', 'Delete customer ledger entries'),
('suppliers.payment', 'Record supplier payments'),
('suppliers.ledger.update', 'Update supplier ledger entries'),
('suppliers.ledger.delete', 'Delete supplier ledger entries'),
('loyalty.redeem', 'Redeem customer loyalty points');

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id
FROM permissions
WHERE name IN (
    'invoices.update_status',
    'customers.ledger.update',
    'customers.ledger.delete',
    'suppliers.payment',
    'suppliers.ledger.update',
    'suppliers.ledger.delete',
    'loyalty.redeem'
);

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'cashier', id
FROM permissions
WHERE name IN (
    'invoices.update_status',
    'customers.ledger.update',
    'customers.ledger.delete',
    'suppliers.payment',
    'suppliers.ledger.update',
    'suppliers.ledger.delete',
    'loyalty.redeem'
);
