-- Migration 051: Create update_history table for tracking update transactions and rollbacks
CREATE TABLE IF NOT EXISTS `update_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `from_version` VARCHAR(50) NOT NULL,
    `to_version` VARCHAR(50) NOT NULL,
    `type` ENUM('delta', 'full') DEFAULT 'delta',
    `source` VARCHAR(50) DEFAULT 'github_release',
    `release_tag` VARCHAR(50) NULL,
    `status` ENUM('success', 'failed', 'rolled_back') NOT NULL,
    `channel` VARCHAR(20) DEFAULT 'stable',
    `rollout_percentage` INT DEFAULT 100,
    `files_count` INT DEFAULT 0,
    `backup_path` VARCHAR(255) NULL,
    `download_url` VARCHAR(255) NULL,
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
