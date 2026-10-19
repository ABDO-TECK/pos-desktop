DELETE rp
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.role = 'admin'
  AND p.name = 'invoices.update_reserved';

DELETE FROM permissions
WHERE name = 'invoices.update_reserved';
