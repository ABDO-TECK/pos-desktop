-- The runtime account can SELECT this non-updatable metadata view without TRIGGER privileges.
-- The migration account remains the definer; only the three catalog trigger names are exposed.
-- Rollback: DROP VIEW IF EXISTS product_catalog_trigger_metadata.
CREATE OR REPLACE ALGORITHM = TEMPTABLE SQL SECURITY DEFINER
VIEW product_catalog_trigger_metadata AS
SELECT TRIGGER_NAME
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND EVENT_OBJECT_TABLE = 'products'
  AND TRIGGER_NAME IN (
      'trg_products_catalog_insert',
      'trg_products_catalog_update',
      'trg_products_catalog_delete'
  );
