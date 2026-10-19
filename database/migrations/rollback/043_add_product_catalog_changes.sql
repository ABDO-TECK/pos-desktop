-- Manual rollback for 043_add_product_catalog_changes.sql.
DROP TRIGGER IF EXISTS trg_products_catalog_delete;
DROP TRIGGER IF EXISTS trg_products_catalog_update;
DROP TRIGGER IF EXISTS trg_products_catalog_insert;
DROP TABLE IF EXISTS product_catalog_changes;
