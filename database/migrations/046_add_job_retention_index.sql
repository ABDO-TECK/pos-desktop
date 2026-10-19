ALTER TABLE job_queue
    ADD INDEX idx_status_created (status, created_at);
