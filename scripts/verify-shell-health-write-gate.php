<?php
declare(strict_types=1);

use App\Services\ShellUpdateHealthService;

if (getenv('GITHUB_ACTIONS') !== 'true' || !is_dir((string) getenv('RUNNER_TEMP'))) {
    throw new RuntimeException('The shell write gate verification requires a GitHub Actions runner.');
}

require __DIR__ . '/../backend/Services/ShellUpdateHealthService.php';

$dir = getenv('RUNNER_TEMP') . '/shell-health-gate-' . bin2hex(random_bytes(16));
mkdir($dir);
$probationGate = $dir . '/release';
$sourceGate = $dir . '/active.json';
$token = bin2hex(random_bytes(32));

putenv('POS_SHELL_PROBATION_TOKEN=' . $token);
putenv('POS_SHELL_PROBATION_GATE=' . $probationGate);
putenv('POS_SHELL_SOURCE_GATE=' . $sourceGate);

$allow = static function (string $method, string $uri) use ($dir): bool {
    $_SERVER['REQUEST_METHOD'] = $method;
    ob_start();
    try {
        return !ShellUpdateHealthService::restrictHttpTraffic($uri, $dir);
    } finally {
        ob_end_clean();
    }
};

try {
    if ($allow('POST', '/api/sales')) {
        throw new RuntimeException('A probationary PHP server accepted a write.');
    }
    file_put_contents($probationGate, $token);
    if (!$allow('POST', '/api/sales')) {
        throw new RuntimeException('A PHP server with a completed health gate refused normal traffic.');
    }
    file_put_contents($sourceGate, '{"phase":"prepared"}');
    if ($allow('POST', '/api/sales') || !$allow('GET', '/api/health')) {
        throw new RuntimeException('A PHP server admitted by one update did not drain safely for the next.');
    }
} finally {
    foreach ([$probationGate, $sourceGate] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}

echo "Verified probation traffic, normal admission and the next update's source write gate.\n";
