-- ============================================================
-- Database Seeder — بيانات البذر الافتراضية
-- ============================================================
-- يمكن تشغيله بشكل مستقل: php backend/cli/seed.php
-- يستخدم INSERT IGNORE لعدم الكتابة فوق بيانات موجودة.
-- ============================================================

-- Default branch
INSERT IGNORE INTO branches (id, name) VALUES (1, 'الفرع الرئيسي');

-- Interactive users are intentionally not seeded. Create the first administrator
-- locally with backend/cli/bootstrap-admin.php and a unique password.

-- Default settings
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('store_name', 'سوبر ماركت'),
('tax_enabled', '0'),
('tax_rate', '15'),
('prevent_negative_stock', '1'),
('loyalty_enabled', '0'),
('loyalty_points_per_rial', '1'),
('loyalty_rial_per_point', '0.01');

-- Default categories (أمثلة)
INSERT IGNORE INTO categories (id, name) VALUES
(1, 'مواد غذائية'),
(2, 'مشروبات'),
(3, 'منظفات'),
(4, 'أخرى');
