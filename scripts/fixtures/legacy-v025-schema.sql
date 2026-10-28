-- POS Database Legacy Schema (Migration 025) Synthetic Fixture
-- Derived structure from historical migration 025 backup (27 tables)
-- Generic deterministic synthetic data for acceptance regression testing.

SET FOREIGN_KEY_CHECKS=0;

-- Table: audit_logs
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL COMMENT 'مثال: delete_invoice, update_stock, update_price',
  `entity_type` varchar(50) NOT NULL COMMENT 'مثال: invoice, product, inventory',
  `entity_id` int(11) DEFAULT NULL COMMENT 'ID العنصر المتأثر',
  `old_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'القيمة قبل التعديل' CHECK (json_valid(`old_value`)),
  `new_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'القيمة بعد التعديل' CHECK (json_valid(`new_value`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_date` (`created_at`),
  KEY `idx_audit_created_desc` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: branches
DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `branches` (`id`, `name`, `address`, `phone`, `is_active`, `created_at`) VALUES
(1, 'الفرع الرئيسي', NULL, NULL, 1, '2000-01-01 00:00:00');

-- Table: categories
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `categories` (`id`, `name`, `created_at`) VALUES
(1, 'codex_legacy_v025_category', '2000-01-01 00:00:00');

-- Table: customer_ledger
DROP TABLE IF EXISTS `customer_ledger`;
CREATE TABLE `customer_ledger` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) NOT NULL,
  `type` enum('debit','credit') NOT NULL COMMENT 'debit=مدين (مبيعات آجلة), credit=دائن (دفعات)',
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(500) DEFAULT NULL COMMENT 'البيان: فاتورة بيع / دفعة نقدية / رصيد مبدئي...',
  `invoice_id` int(10) DEFAULT NULL COMMENT 'رابط للفاتورة إن وجدت',
  `created_by` int(10) DEFAULT NULL COMMENT 'معرف المستخدم الذي سجّل القيد',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_ledger_invoice` (`invoice_id`),
  KEY `idx_customer_ledger` (`customer_id`),
  KEY `idx_ledger_created` (`created_at`),
  KEY `idx_customer_type` (`customer_id`,`type`),
  KEY `idx_cl_customer_type` (`customer_id`,`type`),
  CONSTRAINT `fk_ledger_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ledger_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `customer_ledger` (`id`, `customer_id`, `type`, `amount`, `description`, `invoice_id`, `created_by`, `created_at`) VALUES
(1, 1, 'debit', 10.00, 'codex_legacy_v025_invoice', 1, 1, '2000-01-01 00:00:00');

-- Table: customers
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `initial_balance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'رصيد مبدئي — لعميل قديم له دين مسبق',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `loyalty_points` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`),
  KEY `idx_customers_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `customers` (`id`, `name`, `phone`, `address`, `initial_balance`, `created_at`, `deleted_at`, `loyalty_points`) VALUES
(1, 'codex_legacy_v025_customer', NULL, 'Codex Address', 0.00, '2000-01-01 00:00:00', NULL, 0);

-- Table: expense_categories
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `expense_categories` (`id`, `name`, `created_at`) VALUES
(1, 'نثريات', '2000-01-01 00:00:00');

-- Table: expenses
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `expense_date` datetime NOT NULL,
  `branch_id` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_expense_user` (`user_id`),
  KEY `idx_exp_date` (`expense_date`),
  KEY `idx_exp_cat_date` (`category_id`,`expense_date`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `idx_expenses_category_id` (`category_id`),
  CONSTRAINT `fk_expense_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_expense_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `expenses` (`id`, `category_id`, `user_id`, `amount`, `notes`, `expense_date`, `branch_id`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 25.00, 'codex_legacy_v025_expense', '2000-01-01 00:00:00', 1, '2000-01-01 00:00:00', '2000-01-01 00:00:00');

-- Table: inventory_events
DROP TABLE IF EXISTS `inventory_events`;
CREATE TABLE `inventory_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `action` enum('sale','purchase','adjust','delete') NOT NULL,
  `quantity` int(11) NOT NULL COMMENT 'الكمية الجديدة بعد التغيير',
  `delta` int(11) NOT NULL DEFAULT 0 COMMENT 'مقدار التغيير (+ أو -)',
  `branch_id` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `inventory_events` (`id`, `product_id`, `action`, `quantity`, `delta`, `branch_id`, `created_at`) VALUES
(1, 1, 'sale', 50, -1, 1, '2000-01-01 00:00:00');

-- Table: invoice_items
DROP TABLE IF EXISTS `invoice_items`;
CREATE TABLE `invoice_items` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) NOT NULL,
  `product_id` int(10) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'تكلفة الوحدة لحظة البيع',
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_invoice_product` (`invoice_id`,`product_id`),
  KEY `idx_ii_product_invoice` (`product_id`,`invoice_id`),
  KEY `idx_ii_product_qty` (`product_id`,`quantity`),
  CONSTRAINT `fk_item_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `invoice_items` (`id`, `invoice_id`, `product_id`, `quantity`, `price`, `unit_cost`, `subtotal`) VALUES
(1, 1, 1, 1, 10.00, 5.00, 10.00);

-- Table: invoices
DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) NOT NULL,
  `customer_id` int(11) DEFAULT NULL COMMENT 'رابط العميل — فارغ للمبيعات النقدية',
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','card','vodafone_cash','instapay','other_wallet','credit') NOT NULL DEFAULT 'cash',
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `change_due` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_due` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'المتبقي على ذمة العميل بعد خصم العربون',
  `status` varchar(20) NOT NULL DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `branch_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_invoices_deleted` (`deleted_at`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_inv_date_status` (`created_at`,`status`),
  KEY `idx_inv_payment` (`payment_method`),
  CONSTRAINT `fk_invoice_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `invoices` (`id`, `user_id`, `customer_id`, `subtotal`, `discount`, `tax`, `total`, `payment_method`, `amount_paid`, `change_due`, `amount_due`, `status`, `created_at`, `deleted_at`, `branch_id`) VALUES
(1, 1, 1, 10.00, 0.00, 0.00, 10.00, 'cash', 10.00, 0.00, 0.00, 'completed', '2000-01-01 00:00:00', NULL, 1);

-- Table: job_queue
DROP TABLE IF EXISTS `job_queue`;
CREATE TABLE `job_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_name` varchar(100) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `priority` tinyint(4) DEFAULT 0,
  `status` enum('pending','processing','completed','failed') DEFAULT 'pending',
  `attempts` tinyint(4) DEFAULT 0,
  `max_attempts` tinyint(4) DEFAULT 3,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_priority` (`status`,`priority`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: loyalty_transactions
DROP TABLE IF EXISTS `loyalty_transactions`;
CREATE TABLE `loyalty_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `points` int(11) NOT NULL COMMENT 'موجب = اكتساب، سالب = استرداد',
  `type` enum('earn','redeem','adjust') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_lt_invoice` (`invoice_id`),
  KEY `idx_lt_customer` (`customer_id`),
  CONSTRAINT `fk_lt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lt_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: migrations
DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `migrations` (`id`, `name`, `applied_at`) VALUES
(1, '001_payment_method_enum', '2000-01-01 00:00:00'),
(2, '002_product_barcodes', '2000-01-01 00:00:00'),
(3, '003_payment_method_credit', '2000-01-01 00:00:00'),
(4, '004_invoice_items_unit_cost', '2000-01-01 00:00:00'),
(5, '005_suppliers_initial_balance', '2000-01-01 00:00:00'),
(6, '006_supplier_ledger', '2000-01-01 00:00:00'),
(7, '010_products_box_barcode', '2000-01-01 00:00:00'),
(8, '007_token_cleanup_event', '2000-01-01 00:00:00'),
(9, '008_report_indexes', '2000-01-01 00:00:00'),
(10, '009_cleanup_setup', '2000-01-01 00:00:00'),
(11, '011_invoices_credit_columns', '2000-01-01 00:00:00'),
(12, '012_purchases_invoice_link', '2000-01-01 00:00:00');

-- Table: permissions
DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'مثل: products.create, invoices.delete',
  `description` varchar(255) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `permissions` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'products.view', 'عرض المنتجات', '2000-01-01 00:00:00'),
(2, 'products.create', 'إضافة منتجات', '2000-01-01 00:00:00'),
(3, 'products.update', 'تعديل المنتجات', '2000-01-01 00:00:00'),
(4, 'products.delete', 'حذف المنتجات', '2000-01-01 00:00:00'),
(5, 'invoices.view', 'عرض الفواتير', '2000-01-01 00:00:00'),
(6, 'invoices.create', 'إنشاء فواتير', '2000-01-01 00:00:00'),
(7, 'invoices.delete', 'حذف فواتير', '2000-01-01 00:00:00'),
(8, 'reports.view', 'عرض التقارير', '2000-01-01 00:00:00'),
(9, 'settings.view', 'عرض الإعدادات', '2000-01-01 00:00:00'),
(10, 'settings.update', 'تعديل الإعدادات', '2000-01-01 00:00:00'),
(11, 'users.manage', 'إدارة المستخدمين', '2000-01-01 00:00:00'),
(12, 'backup.manage', 'إدارة النسخ الاحتياطية', '2000-01-01 00:00:00'),
(13, 'audit.view', 'عرض سجلات التدقيق', '2000-01-01 00:00:00'),
(14, 'suppliers.view', 'عرض الموردين', '2000-01-01 00:00:00'),
(15, 'suppliers.create', 'إضافة موردين', '2000-01-01 00:00:00'),
(16, 'suppliers.update', 'تعديل موردين', '2000-01-01 00:00:00'),
(17, 'suppliers.delete', 'حذف موردين', '2000-01-01 00:00:00'),
(18, 'purchases.view', 'عرض المشتريات', '2000-01-01 00:00:00'),
(19, 'purchases.create', 'تسجيل مشتريات', '2000-01-01 00:00:00'),
(20, 'purchases.delete', 'حذف فاتورة مشتريات', '2000-01-01 00:00:00'),
(21, 'customers.view', 'عرض العملاء', '2000-01-01 00:00:00'),
(22, 'customers.create', 'إضافة عملاء', '2000-01-01 00:00:00'),
(23, 'customers.update', 'تعديل عملاء', '2000-01-01 00:00:00'),
(24, 'customers.delete', 'حذف عملاء', '2000-01-01 00:00:00'),
(25, 'customers.payment', 'تسجيل دفعات العملاء', '2000-01-01 00:00:00'),
(26, 'expenses.view', 'عرض المصروفات', '2000-01-01 00:00:00'),
(27, 'expenses.create', 'إضافة مصروفات', '2000-01-01 00:00:00'),
(28, 'expenses.update', 'تعديل مصروفات', '2000-01-01 00:00:00'),
(29, 'expenses.delete', 'حذف مصروفات', '2000-01-01 00:00:00'),
(30, 'inventory.view', 'عرض المخزون', '2000-01-01 00:00:00'),
(31, 'inventory.adjust', 'تعديل المخزون', '2000-01-01 00:00:00'),
(32, 'branches.view', 'عرض الفروع', '2000-01-01 00:00:00'),
(33, 'branches.create', 'إضافة فروع', '2000-01-01 00:00:00'),
(34, 'branches.update', 'تعديل فروع', '2000-01-01 00:00:00');

-- Table: price_history
DROP TABLE IF EXISTS `price_history`;
CREATE TABLE `price_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `old_price` decimal(10,2) NOT NULL COMMENT 'سعر البيع القديم',
  `new_price` decimal(10,2) NOT NULL COMMENT 'سعر البيع الجديد',
  `old_cost` decimal(10,2) NOT NULL COMMENT 'التكلفة القديمة',
  `new_cost` decimal(10,2) NOT NULL COMMENT 'التكلفة الجديدة',
  `changed_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي غيّر السعر',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `changed_by` (`changed_by`),
  KEY `idx_product_date` (`product_id`,`created_at`),
  CONSTRAINT `price_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `price_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `price_history` (`id`, `product_id`, `old_price`, `new_price`, `old_cost`, `new_cost`, `changed_by`, `created_at`) VALUES
(1, 1, 8.00, 10.00, 4.00, 5.00, 1, '2000-01-01 00:00:00');

-- Table: product_barcodes
DROP TABLE IF EXISTS `product_barcodes`;
CREATE TABLE `product_barcodes` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `product_id` int(10) NOT NULL,
  `barcode` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_barcodes_barcode` (`barcode`),
  KEY `idx_product_barcodes_product` (`product_id`),
  CONSTRAINT `fk_pb_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `product_barcodes` (`id`, `product_id`, `barcode`) VALUES
(1, 1, '6220000000002');

-- Table: products
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `barcode` varchar(100) NOT NULL,
  `box_barcode` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `sell_by_weight` tinyint(1) NOT NULL DEFAULT 0,
  `low_stock_threshold` int(11) NOT NULL DEFAULT 5,
  `units_per_box` int(11) NOT NULL DEFAULT 1 COMMENT 'عدد القطع في الصندوق الواحد — للبيع بالكرتون',
  `category_id` int(10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `branch_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`),
  UNIQUE KEY `box_barcode` (`box_barcode`),
  KEY `idx_barcode` (`barcode`),
  KEY `idx_name` (`name`),
  KEY `idx_category` (`category_id`),
  KEY `idx_products_deleted` (`deleted_at`),
  KEY `idx_prod_low_stock` (`quantity`,`low_stock_threshold`,`deleted_at`),
  KEY `idx_prod_deleted` (`deleted_at`),
  CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `products` (`id`, `name`, `barcode`, `box_barcode`, `price`, `cost`, `quantity`, `sell_by_weight`, `low_stock_threshold`, `units_per_box`, `category_id`, `created_at`, `updated_at`, `deleted_at`, `branch_id`) VALUES
(1, 'codex_legacy_v025_product', '6220000000001', NULL, 10.00, 5.00, 50, 0, 5, 1, 1, '2000-01-01 00:00:00', '2000-01-01 00:00:00', NULL, 1);

-- Table: purchase_invoices
DROP TABLE IF EXISTS `purchase_invoices`;
CREATE TABLE `purchase_invoices` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(10) NOT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `items_count` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `branch_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_pinv_supplier` (`supplier_id`),
  KEY `idx_pinv_created` (`created_at`),
  CONSTRAINT `fk_pinv_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `purchase_invoices` (`id`, `supplier_id`, `total`, `items_count`, `notes`, `created_at`, `branch_id`) VALUES
(1, 1, 50.00, 1, NULL, '2000-01-01 00:00:00', 1);

-- Table: purchases
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE `purchases` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `purchase_invoice_id` int(11) DEFAULT NULL,
  `supplier_id` int(10) NOT NULL,
  `product_id` int(10) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_purchase_product` (`product_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_created_at_purchase` (`created_at`),
  KEY `idx_supplier_created` (`supplier_id`,`created_at`),
  KEY `idx_purchase_invoice` (`purchase_invoice_id`),
  KEY `idx_pur_product` (`product_id`),
  CONSTRAINT `fk_purchase_pinv` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_purchase_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `purchases` (`id`, `purchase_invoice_id`, `supplier_id`, `product_id`, `quantity`, `cost`, `total`, `notes`, `created_at`) VALUES
(1, 1, 1, 1, 10, 5.00, 50.00, NULL, '2000-01-01 00:00:00');

-- Table: refresh_tokens
DROP TABLE IF EXISTS `refresh_tokens`;
CREATE TABLE `refresh_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `fk_refresh_token_user` (`user_id`),
  KEY `idx_refresh_token` (`token`),
  CONSTRAINT `fk_refresh_token_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: role_permissions
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role` varchar(20) NOT NULL COMMENT 'admin, cashier, manager...',
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `role_permissions` (`role`, `permission_id`) VALUES
('admin', 1),
('admin', 2),
('admin', 3),
('admin', 4),
('admin', 5),
('admin', 6),
('admin', 7),
('admin', 8),
('admin', 9),
('admin', 10),
('admin', 11),
('admin', 12),
('admin', 13),
('admin', 14),
('admin', 15),
('admin', 16),
('admin', 17),
('admin', 18),
('admin', 19),
('admin', 20),
('admin', 21),
('admin', 22),
('admin', 23),
('admin', 24),
('admin', 25),
('admin', 26),
('admin', 27),
('admin', 28),
('admin', 29),
('admin', 30),
('admin', 31),
('admin', 32),
('admin', 33),
('admin', 34),
('cashier', 1),
('cashier', 5),
('cashier', 6),
('cashier', 8),
('cashier', 9),
('cashier', 14),
('cashier', 18),
('cashier', 21),
('cashier', 22),
('cashier', 23),
('cashier', 25),
('cashier', 26),
('cashier', 27),
('cashier', 30),
('cashier', 32);

-- Table: schema_versions
DROP TABLE IF EXISTS `schema_versions`;
CREATE TABLE `schema_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(255) NOT NULL,
  `executed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `schema_versions` (`id`, `version`, `executed_at`) VALUES
(1, '002_update_invoice_status.sql', '2000-01-01 00:00:00'),
(2, '003_add_product_box_columns.sql', '2000-01-01 00:00:00'),
(3, '004_add_product_units_per_box.sql', '2000-01-01 00:00:00'),
(4, '005_add_invoice_amount_due.sql', '2000-01-01 00:00:00'),
(5, '006_add_invoice_items_unit_cost.sql', '2000-01-01 00:00:00'),
(6, '007_create_missing_tables.sql', '2000-01-01 00:00:00'),
(7, '008_add_expenses_indexes.sql', '2000-01-01 00:00:00'),
(8, '009_add_default_settings.sql', '2000-01-01 00:00:00'),
(9, '010_add_force_password_change.sql', '2000-01-01 00:00:00'),
(10, '011_create_audit_logs.sql', '2000-01-01 00:00:00'),
(11, '012_add_soft_delete.sql', '2000-01-01 00:00:00'),
(12, '013_ensure_schema_completeness.sql', '2000-01-01 00:00:00'),
(13, '014_create_job_queue.sql', '2000-01-01 00:00:00'),
(14, '015_add_purchase_invoices.sql', '2000-01-01 00:00:00'),
(15, '016_add_refresh_tokens', '2000-01-01 00:00:00'),
(16, '016_add_refresh_tokens.sql', '2000-01-01 00:00:00'),
(17, '017_create_inventory_events.sql', '2000-01-01 00:00:00'),
(18, '018_create_price_history.sql', '2000-01-01 00:00:00'),
(19, '019_add_performance_indexes.sql', '2000-01-01 00:00:00'),
(20, '020_create_loyalty_system.sql', '2000-01-01 00:00:00'),
(21, '021_multi_branch.sql', '2000-01-01 00:00:00'),
(22, '022_randomize_default_passwords.sql', '2000-01-01 00:00:00'),
(23, '023_review_indexes.sql', '2000-01-01 00:00:00'),
(24, '024_create_rbac_tables.sql', '2000-01-01 00:00:00'),
(25, '025_add_missing_permissions.sql', '2000-01-01 00:00:00');

-- Table: settings
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES
('loyalty_enabled', '0', '2000-01-01 00:00:00'),
('loyalty_points_per_rial', '1', '2000-01-01 00:00:00'),
('loyalty_rial_per_point', '0.01', '2000-01-01 00:00:00'),
('store_name', 'codex_legacy_v025_store', '2000-01-01 00:00:00'),
('tax_enabled', '0', '2000-01-01 00:00:00'),
('tax_rate', '15', '2000-01-01 00:00:00');

-- Table: supplier_ledger
DROP TABLE IF EXISTS `supplier_ledger`;
CREATE TABLE `supplier_ledger` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(10) NOT NULL,
  `type` enum('debit','credit') NOT NULL COMMENT 'debit=مدين (مشتريات آجلة), credit=دائن (دفعات للمورد)',
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(500) DEFAULT NULL COMMENT 'البيان: فاتورة شراء / دفعة نقدية / رصيد مبدئي...',
  `purchase_invoice_id` int(10) DEFAULT NULL COMMENT 'رابط لفاتورة المشتريات إن وجدت',
  `created_by` int(10) DEFAULT NULL COMMENT 'معرف المستخدم الذي سجّل القيد',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sledger_pinvoice` (`purchase_invoice_id`),
  KEY `idx_supplier_ledger` (`supplier_id`),
  KEY `idx_sledger_created` (`created_at`),
  KEY `idx_supplier_type` (`supplier_id`,`type`),
  KEY `idx_sl_supplier_type` (`supplier_id`,`type`),
  CONSTRAINT `fk_sledger_pinvoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sledger_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `supplier_ledger` (`id`, `supplier_id`, `type`, `amount`, `description`, `purchase_invoice_id`, `created_by`, `created_at`) VALUES
(1, 1, 'credit', 50.00, 'codex_legacy_v025_purchase', 1, 1, '2000-01-01 00:00:00');

-- Table: suppliers
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `initial_balance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'رصيد مبدئي — لمورد قديم له دين مسبق',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_suppliers_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `suppliers` (`id`, `name`, `phone`, `email`, `address`, `initial_balance`, `created_at`, `deleted_at`) VALUES
(1, 'codex_legacy_v025_supplier', NULL, 'codex_supplier@example.invalid', 'Codex Address', 0.00, '2000-01-01 00:00:00', NULL);

-- Table: tokens
DROP TABLE IF EXISTS `tokens`;
CREATE TABLE `tokens` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `fk_token_user` (`user_id`),
  KEY `idx_token` (`token`),
  KEY `idx_token_expires` (`expires_at`),
  CONSTRAINT `fk_token_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','cashier') NOT NULL DEFAULT 'cashier',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `branch_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `fk_user_branch` (`branch_id`),
  CONSTRAINT `fk_user_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `is_active`, `force_password_change`, `created_at`, `branch_id`) VALUES
(1, 'Legacy Admin', '__ADMIN_EMAIL__', '__ADMIN_PASSWORD_HASH__', 'admin', 1, 0, '2000-01-01 00:00:00', 1);

SET FOREIGN_KEY_CHECKS=1;
