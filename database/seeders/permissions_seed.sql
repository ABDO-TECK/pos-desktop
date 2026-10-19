-- ============================================================
-- Database Seeder — بيانات البذر لصلاحيات النظام (RBAC)
-- ============================================================
-- يستخدم INSERT IGNORE لعدم الكتابة فوق بيانات موجودة.
-- ============================================================

-- 1. إدخال جميع الصلاحيات
INSERT IGNORE INTO permissions (name, description) VALUES
('products.view',    'عرض المنتجات'),
('products.create',  'إضافة منتجات'),
('products.update',  'تعديل المنتجات'),
('products.delete',  'حذف المنتجات'),
('invoices.view',    'عرض الفواتير'),
('invoices.create',  'إنشاء فواتير'),
('invoices.delete',  'حذف فواتير'),
('reports.view',     'عرض التقارير'),
('settings.view',    'عرض الإعدادات'),
('settings.update',  'تعديل الإعدادات'),
('users.manage',     'إدارة المستخدمين'),
('backup.manage',    'إدارة النسخ الاحتياطية'),
('audit.view',       'عرض سجلات التدقيق'),
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

-- 2. ربط الصلاحيات بالأدوار الافتراضية
-- Admin: كل الصلاحيات
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions;

-- Cashier: صلاحيات محدودة
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'cashier', id FROM permissions
WHERE name IN (
    'products.view', 
    'invoices.view', 
    'invoices.create', 
    'reports.view', 
    'settings.view',
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
