ALTER TABLE job_queue
    DROP COLUMN failure_code;

ALTER TABLE job_queue
    DROP INDEX idx_job_name_status;
