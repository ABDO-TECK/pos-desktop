-- Migration: 027_add_delivery_info_to_purchase_invoices
-- Description: إضافة حقول التسليم لجدول فواتير المشتريات

ALTER TABLE purchase_invoices
  ADD COLUMN driver_name VARCHAR(100) DEFAULT NULL AFTER notes,
  ADD COLUMN vehicle_number VARCHAR(50) DEFAULT NULL AFTER driver_name,
  ADD COLUMN delivery_date DATE DEFAULT NULL AFTER vehicle_number,
  ADD COLUMN delivery_notes TEXT DEFAULT NULL AFTER delivery_date;
