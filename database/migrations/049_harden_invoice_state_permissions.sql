INSERT IGNORE INTO permissions (name, description)
VALUES ('invoices.update_reserved', 'Replace reserved invoices');

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id
FROM permissions
WHERE name = 'invoices.update_reserved';
