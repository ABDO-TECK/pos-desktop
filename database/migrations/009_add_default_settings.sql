-- إضافة الإعدادات الافتراضية إلى جدول settings
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('store_name', 'سوبر ماركت'),
('tax_enabled', '0'),
('tax_rate', '15');
