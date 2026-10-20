<?php
declare(strict_types=1);

$source = $argv[1] ?? dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $source . '/backend/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
$lab = sys_get_temp_dir() . '/pos-http-budget-' . bin2hex(random_bytes(6));
mkdir($lab, 0700, true);
file_put_contents($lab . '/version.json', '{"version":"0.0.2"}');
putenv('APP_DEPLOY_ROOT=' . $lab);
putenv('APP_STORAGE_DIR=' . $lab . '/storage');
putenv('ENABLE_UPDATE_CHECKS=true');
putenv('UPDATE_SERVER_URL=https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/latest');

class SlowReleaseLookup extends App\Services\UpdateService
{
    public ?int $observedLimit = null;

    public function fetchRemoteVersion(?string $channel = null, bool $force = false): ?array
    {
        $this->observedLimit = (int) ini_get('max_execution_time');
        if ($this->observedLimit < 60 || $this->observedLimit >= App\Services\UpdateOperationLock::TTL_SECONDS) {
            throw new RuntimeException('Update inherits short HTTP budget: ' . $this->observedLimit);
        }
        usleep(1200000);
        return null;
    }
}

set_time_limit(1);
$service = new SlowReleaseLookup(
    new App\Services\GitService(),
    new App\Services\FrontendBuildService(),
    new App\Services\BackupService()
);
$result = $service->applyUpdate(false, true);
if (($result['code'] ?? null) !== 502) {
    throw new RuntimeException('Expected controlled unavailable-release response');
}
if ((int) ini_get('max_execution_time') !== 1) {
    throw new RuntimeException('Normal request budget not restored');
}
echo json_encode(['pass' => true, 'operationLimit' => $service->observedLimit, 'restoredLimit' => 1]) . "\n";
