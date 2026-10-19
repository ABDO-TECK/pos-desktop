-- Migration 053: Add update permissions for RBAC
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
('updates.view', 'View system update status and history'),
('updates.check', 'Check for new system updates'),
('updates.apply', 'Install and apply system updates'),
('updates.rollback', 'Rollback system updates from snapshots');

-- Grant update permissions to admin role
INSERT IGNORE INTO `role_permissions` (`role`, `permission_id`)
SELECT 'admin', id FROM `permissions` WHERE `name` IN ('updates.view', 'updates.check', 'updates.apply', 'updates.rollback');
