-- ============================================================
-- 025: إضافة صلاحيات ناقصة لتغطية كل الـ endpoints
-- ============================================================

-- صلاحيات إضافية
INSERT IGNORE INTO permissions (name, description) VALUES
('suppliers.view',       'عرض الموردين'),
('suppliers.create',     'إضافة موردين'),
('suppliers.update',     'تعديل موردين'),
('suppliers.delete',     'حذف موردين'),
('purchases.view',       'عرض المشتريات'),
('purchases.create',     'تسجيل مشتريات'),
('purchases.delete',     'حذف فاتورة مشتريات'),
('customers.view',       'عرض العملاء'),
('customers.create',     'إضافة عملاء'),
('customers.update',     'تعديل عملاء'),
('customers.delete',     'حذف عملاء'),
('customers.payment',    'تسجيل دفعات العملاء'),
('expenses.view',        'عرض المصروفات'),
('expenses.create',      'إضافة مصروفات'),
('expenses.update',      'تعديل مصروفات'),
('expenses.delete',      'حذف مصروفات'),
('inventory.view',       'عرض المخزون'),
('inventory.adjust',     'تعديل المخزون'),
('branches.view',        'عرض الفروع'),
('branches.create',      'إضافة فروع'),
('branches.update',      'تعديل فروع');

-- ربط الصلاحيات الجديدة بدور Admin (يحصل على كل الصلاحيات)
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions
WHERE name NOT IN (SELECT p.name FROM permissions p JOIN role_permissions rp ON p.id = rp.permission_id WHERE rp.role = 'admin');

-- ربط صلاحيات إضافية بدور Cashier
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'cashier', id FROM permissions
WHERE name IN (
    'customers.view',
    'customers.create',
    'customers.update',
    'customers.payment',
    'suppliers.view',
    'purchases.view',
    'expenses.view',
    'expenses.create',
    'inventory.view',
    'branches.view'
);
