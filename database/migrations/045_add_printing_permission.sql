-- 045: Scope QZ signing to an explicit printing permission.

INSERT IGNORE INTO permissions (name, description)
VALUES ('printing.use', 'Use QZ Tray printing and signing');

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions WHERE name = 'printing.use';

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'manager', id FROM permissions WHERE name = 'printing.use';

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'cashier', id FROM permissions WHERE name = 'printing.use';
