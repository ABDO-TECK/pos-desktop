<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: CLI only.');
}

require_once __DIR__ . '/../Helpers/EnvLoader.php';
require_once __DIR__ . '/../Helpers/Logger.php';
require_once __DIR__ . '/../Helpers/ErrorHandler.php';

\App\Helpers\ErrorHandler::register();

use App\Helpers\EnvLoader;
use App\Helpers\Logger;

EnvLoader::load(getenv('ENV_PATH') ?: __DIR__ . '/../.env');
$deleted = Logger::cleanup();
echo "[LogCleanup] Removed {$deleted} expired log file(s).\n";
