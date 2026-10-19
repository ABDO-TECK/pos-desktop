#!/bin/sh
set -eu

echo "Applying database migrations before starting Apache..."
php /var/www/html/backend/cli/migrate.php

exec apache2-foreground
