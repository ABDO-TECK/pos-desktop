DELETE rp
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.name = 'printing.use';

DELETE FROM permissions WHERE name = 'printing.use';
