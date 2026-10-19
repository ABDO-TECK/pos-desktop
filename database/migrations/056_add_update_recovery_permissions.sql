-- Migration 056: Add Update Recovery & Self-Healing Permissions
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
('updates.recovery.view', 'View update recovery status, diagnostics, and audit logs'),
('updates.recovery.manage', 'Execute manual update recovery, rollbacks, and self-healing actions');

-- Automatically assign recovery permissions to the admin role
INSERT IGNORE INTO `role_permissions` (`role`, `permission_id`)
SELECT 'admin', id 
FROM `permissions` 
WHERE `name` IN ('updates.recovery.view', 'updates.recovery.manage');
