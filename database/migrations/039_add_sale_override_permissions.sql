INSERT IGNORE INTO permissions (name, description) VALUES
    ('sales.override_price', 'Override the catalog price on a sale line'),
    ('sales.discount', 'Apply a manual discount to a sale');

-- Administrators bypass permission checks. Cashiers receive neither permission
-- by default; deployments can grant either permission explicitly.
