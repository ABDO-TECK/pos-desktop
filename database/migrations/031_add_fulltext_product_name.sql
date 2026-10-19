-- ============================================================
-- Migration 031: Add FULLTEXT index on products.name for fast text search
-- ============================================================
-- This replaces LIKE '%term%' (full table scan) with MATCH...AGAINST (index-based).
-- Note: MySQL FULLTEXT has a default minimum word length of 3 characters (ft_min_word_len).
-- For shorter search terms, the barcode exact-match paths will still match correctly.
-- ============================================================

ALTER TABLE products ADD FULLTEXT INDEX ft_product_name (name);
