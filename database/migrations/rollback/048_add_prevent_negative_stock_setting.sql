-- Roll back 048: remove the stock policy setting introduced by the migration.
DELETE FROM settings WHERE `key` = 'prevent_negative_stock';
