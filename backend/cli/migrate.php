<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: This script can only be run from the command line.');
}

require_once __DIR__ . '/../vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require_once __DIR__ . '/../Config/config.php';

echo "Running Migrations...\n";
echo "=====================\n";

require_once __DIR__ . '/../Services/MigrationService.php';

$force = in_array('--force', $argv, true);
$declared = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--migrations=')) {
        $value = substr($argument, strlen('--migrations='));
        $declared = $value === '' ? [] : explode(',', $value);
        break;
    }
}
$service = new App\Services\MigrationService();
$result = $service->runMigrations($declared, $force);

if ($result['skipped']) {
    echo "No changes detected in migration files. Use --force to run anyway.\n";
    exit(0);
}

if ($result['executed'] > 0) {
    echo "Successfully executed {$result['executed']} migrations.\n";
} else {
    echo "No new migrations to execute.\n";
}

if (!empty($result['errors'])) {
    echo "\nErrors encountered:\n";
    foreach ($result['errors'] as $error) {
        echo " - $error\n";
    }
    exit(1);
}

echo "Done.\n";
