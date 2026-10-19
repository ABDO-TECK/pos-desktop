-- Migration 055: Create update_telemetry table and telemetry permissions
CREATE TABLE IF NOT EXISTS `update_telemetry` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `device_id` VARCHAR(64) NOT NULL,
    `current_version` VARCHAR(32) NOT NULL,
    `target_version` VARCHAR(32) NULL,
    `channel` VARCHAR(16) NOT NULL DEFAULT 'stable',
    `event_type` VARCHAR(64) NOT NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 1,
    `error_code` VARCHAR(64) NULL,
    `duration_ms` INT UNSIGNED NULL,
    `metadata` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_telemetry_device` (`device_id`),
    INDEX `idx_telemetry_event` (`event_type`),
    INDEX `idx_telemetry_created_at` (`created_at`),
    INDEX `idx_telemetry_channel` (`channel`),
    INDEX `idx_telemetry_success` (`success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions for telemetry
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
('updates.telemetry.view', 'View fleet updates telemetry and analytics'),
('updates.telemetry.manage', 'Manage and purge update telemetry data');

-- Grant permissions to admin role
INSERT IGNORE INTO `role_permissions` (`role`, `permission_id`)
SELECT 'admin', id FROM `permissions` WHERE `name` IN ('updates.telemetry.view', 'updates.telemetry.manage');
