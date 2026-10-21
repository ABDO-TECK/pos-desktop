-- Read-only object definitions for safe update compatibility checks.
-- No business rows or credentials are exposed. The runtime keeps DML-only grants.
-- Rollback: DROP VIEW IF EXISTS pos_update_compatibility_metadata;
CREATE OR REPLACE ALGORITHM = TEMPTABLE SQL SECURITY DEFINER
VIEW pos_update_compatibility_metadata AS
SELECT 'view' AS object_type, TABLE_NAME AS object_name,
       VIEW_DEFINITION AS definition,
       CONCAT_WS('|', SECURITY_TYPE, CHECK_OPTION, IS_UPDATABLE) AS context,
       TABLE_NAME AS table_name
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME <> 'pos_update_compatibility_metadata'
UNION ALL
SELECT 'trigger', TRIGGER_NAME, ACTION_STATEMENT,
       CONCAT_WS('|', ACTION_TIMING, EVENT_MANIPULATION), EVENT_OBJECT_TABLE
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
UNION ALL
SELECT CONCAT('routine:', ROUTINE_TYPE), ROUTINE_NAME, ROUTINE_DEFINITION,
       CONCAT_WS('|', SECURITY_TYPE, SQL_DATA_ACCESS, IS_DETERMINISTIC), ''
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
UNION ALL
SELECT 'event', EVENT_NAME, EVENT_DEFINITION,
       CONCAT_WS('|', EVENT_TYPE, INTERVAL_VALUE, INTERVAL_FIELD, STATUS, TIME_ZONE), ''
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE();
