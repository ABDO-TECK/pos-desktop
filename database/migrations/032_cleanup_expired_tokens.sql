-- ============================================================
-- Migration 032: Clean up expired tokens and add scheduled purge
-- ============================================================
-- 1. Delete all currently expired tokens (one-time cleanup)
-- 2. Drop legacy cleanup event if present
-- ============================================================

-- One-time cleanup of existing expired tokens
DELETE FROM tokens WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP();
DELETE FROM refresh_tokens WHERE expires_at < UTC_TIMESTAMP();

-- Drop legacy database event if it exists (least-privilege cleanup)
DROP EVENT IF EXISTS cleanup_expired_tokens;
