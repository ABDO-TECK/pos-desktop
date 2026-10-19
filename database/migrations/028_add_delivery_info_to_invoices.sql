-- Migration: 028_add_delivery_info_to_invoices
-- Description: إضافة حقول التسليم لجدول المبيعات (الفواتير)

ALTER TABLE invoices
  ADD COLUMN driver_name VARCHAR(100) DEFAULT NULL AFTER status,
  ADD COLUMN vehicle_number VARCHAR(50) DEFAULT NULL AFTER driver_name,
  ADD COLUMN delivery_date DATE DEFAULT NULL AFTER vehicle_number,
  ADD COLUMN delivery_notes TEXT DEFAULT NULL AFTER delivery_date;
