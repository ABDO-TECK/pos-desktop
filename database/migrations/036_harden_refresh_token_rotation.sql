ALTER TABLE refresh_tokens
    ADD COLUMN family_id CHAR(64) NULL AFTER token;

ALTER TABLE refresh_tokens
    ADD COLUMN used_at TIMESTAMP NULL AFTER family_id;

ALTER TABLE refresh_tokens
    ADD COLUMN revoked_at TIMESTAMP NULL AFTER used_at;

ALTER TABLE refresh_tokens
    ADD COLUMN replaced_by_token VARCHAR(128) NULL AFTER revoked_at;

UPDATE refresh_tokens
SET family_id = SHA2(CONCAT(id, ':', token, ':', created_at), 256)
WHERE family_id IS NULL;

ALTER TABLE refresh_tokens
    MODIFY family_id CHAR(64) NOT NULL;

ALTER TABLE refresh_tokens
    ADD INDEX idx_refresh_family (family_id, revoked_at);
