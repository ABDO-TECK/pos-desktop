-- Migration: 026_add_unit_type_and_sizes
-- Description: إضافة وحدة اللتر ودعم المقاسات المتعددة للمنتجات

-- 1. إضافة عمود unit_type للمنتجات لتحديد نوع الوحدة (قطعة، وزن، لتر)
ALTER TABLE products ADD COLUMN unit_type ENUM('piece', 'weight', 'liter') NOT NULL DEFAULT 'piece' AFTER sell_by_weight;

-- 2. تحديث المنتجات الحالية التي تباع بالوزن لتأخذ القيمة المناسبة في unit_type
UPDATE products SET unit_type = 'weight' WHERE sell_by_weight = 1;

-- 3. إضافة parent_product_id لربط مقاسات المنتجات بالمنتج الأساسي (الأب)
ALTER TABLE products ADD COLUMN parent_product_id INT NULL DEFAULT NULL AFTER category_id;
ALTER TABLE products ADD COLUMN size_name VARCHAR(100) NULL DEFAULT NULL AFTER parent_product_id;

-- 4. إضافة مفتاح أجنبي وفهرس لـ parent_product_id
ALTER TABLE products ADD INDEX idx_parent_product (parent_product_id);
ALTER TABLE products ADD CONSTRAINT fk_products_parent FOREIGN KEY (parent_product_id) REFERENCES products(id) ON DELETE CASCADE;
