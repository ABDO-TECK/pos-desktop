<?php
declare(strict_types=1);
require __DIR__.'/channel-transition-transport.php';
require 'phar://'.str_replace('\\','/',(string)getenv('APP_DEPLOY_ROOT')).'/backend/backend.phar/index.php';
