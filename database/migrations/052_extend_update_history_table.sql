-- Migration 052: Extend update_history table with source, release_tag, and download_url
ALTER TABLE update_history
    ADD COLUMN source VARCHAR(50) DEFAULT 'github_release' AFTER type;

ALTER TABLE update_history
    ADD COLUMN release_tag VARCHAR(50) NULL AFTER source;

ALTER TABLE update_history
    ADD COLUMN download_url VARCHAR(255) NULL AFTER backup_path;
