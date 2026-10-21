-- Extend the existing auxiliary view so historical backup code still skips it.
-- Legacy TRIGGER_NAME readers keep finding the same required catalog triggers.
-- New columns expose definitions only, never business rows or credentials.
-- Rollback: reapply 058_add_catalog_trigger_diagnostics_view.sql.
CREATE OR REPLACE ALGORITHM = TEMPTABLE SQL SECURITY DEFINER
VIEW product_catalog_trigger_metadata AS
SELECT CASE WHEN EVENT_OBJECT_TABLE = 'products' AND TRIGGER_NAME IN (
           'trg_products_catalog_insert', 'trg_products_catalog_update', 'trg_products_catalog_delete'
       ) THEN TRIGGER_NAME ELSE NULL END AS TRIGGER_NAME,
       'trigger' AS object_type, TRIGGER_NAME AS object_name,
       ACTION_STATEMENT AS definition,
       CONCAT_WS('|', ACTION_TIMING, EVENT_MANIPULATION) AS context,
       EVENT_OBJECT_TABLE AS table_name
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
UNION ALL
SELECT NULL, 'view', TABLE_NAME,
       VIEW_DEFINITION AS definition,
       CONCAT_WS('|', SECURITY_TYPE, CHECK_OPTION, IS_UPDATABLE) AS context,
       TABLE_NAME AS table_name
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME <> 'product_catalog_trigger_metadata'
UNION ALL
SELECT NULL, CONCAT('routine:', ROUTINE_TYPE), ROUTINE_NAME, ROUTINE_DEFINITION,
       CONCAT_WS('|', SECURITY_TYPE, SQL_DATA_ACCESS, IS_DETERMINISTIC), ''
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
UNION ALL
SELECT NULL, 'event', EVENT_NAME, EVENT_DEFINITION,
       CONCAT_WS('|', EVENT_TYPE, INTERVAL_VALUE, INTERVAL_FIELD, STATUS, TIME_ZONE), ''
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE();
