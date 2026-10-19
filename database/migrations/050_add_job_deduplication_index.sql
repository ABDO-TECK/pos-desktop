-- 050: Support locked, bounded lookup when coalescing long-running jobs.
ALTER TABLE job_queue
    ADD COLUMN failure_code SMALLINT NULL AFTER last_error;

ALTER TABLE job_queue
    ADD INDEX idx_job_name_status (job_name, status, id);
