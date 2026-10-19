CREATE TABLE IF NOT EXISTS job_queue (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    job_name     VARCHAR(100) NOT NULL,
    payload      JSON DEFAULT NULL,
    priority     TINYINT DEFAULT 0,
    status       ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    attempts     TINYINT DEFAULT 0,
    max_attempts TINYINT DEFAULT 3,
    last_error   TEXT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status_priority (status, priority DESC, id ASC),
    INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
