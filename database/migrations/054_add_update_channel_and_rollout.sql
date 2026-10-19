-- Migration 054: Add update channel management permission and history tracking
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
('updates.manage_channel', 'Change release update channel (stable/beta/rc)');

-- Grant channel permission to admin role
INSERT IGNORE INTO `role_permissions` (`role`, `permission_id`)
SELECT 'admin', id FROM `permissions` WHERE `name` = 'updates.manage_channel';

-- Add channel & rollout columns to update_history
ALTER TABLE update_history
    ADD COLUMN channel VARCHAR(20) DEFAULT 'stable' AFTER type;

ALTER TABLE update_history
    ADD COLUMN rollout_percentage INT DEFAULT 100 AFTER channel;
