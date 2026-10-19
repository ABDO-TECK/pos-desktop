-- ============================================================
-- 024: إنشاء نظام صلاحيات RBAC
-- ============================================================

CREATE TABLE IF NOT EXISTS permissions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE COMMENT 'مثل: products.create, invoices.delete',
    description VARCHAR(255) DEFAULT '',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
    role       VARCHAR(20)  NOT NULL COMMENT 'admin, cashier, manager...',
    permission_id INT       NOT NULL,
    PRIMARY KEY (role, permission_id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- صلاحيات أساسية
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
('audit.view',       'عرض سجلات التدقيق');

-- ربط الصلاحيات بالأدوار الافتراضية
-- Admin: كل الصلاحيات
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions;

-- Cashier: صلاحيات محدودة
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'cashier', id FROM permissions
WHERE name IN ('products.view', 'invoices.view', 'invoices.create', 'reports.view', 'settings.view');
