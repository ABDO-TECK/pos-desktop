-- Alter settings table value column to MEDIUMTEXT to prevent truncation of base64 logo strings
ALTER TABLE settings MODIFY COLUMN value MEDIUMTEXT;
