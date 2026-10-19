-- بيانات تجريبية (Demo Data)

-- التصنيفات
INSERT INTO categories (name) VALUES 
('إلكترونيات'), 
('إكسسوارات'), 
('قطع غيار');

-- الموردين
INSERT INTO suppliers (name, initial_balance) VALUES 
('شركة المورد الأول', 0), 
('محلات الجملة', -500);

-- المنتجات
INSERT INTO products (name, barcode, category_id, cost, price, stock, min_stock, sell_by_weight) VALUES 
('شاحن سريع 20 وات', '1234567890123', 2, 50, 100, 20, 5, 0),
('شاشة 24 بوصة', '9876543210987', 1, 2000, 2500, 5, 2, 0),
('كابل بيانات Type-C', '1112223334445', 2, 20, 45, 50, 10, 0);

-- العملاء
INSERT INTO customers (name, phone, initial_balance) VALUES 
('أحمد محمد', '01000000000', 1000), 
('عميل نقدي', '', 0);

-- تصنيفات المصروفات
INSERT INTO expenses_categories (name) VALUES 
('إيجار'), 
('كهرباء'), 
('بوفيه'),
('رواتب');
