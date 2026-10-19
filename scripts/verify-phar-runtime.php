<?php

declare(strict_types=1);

/**
 * Verify that a built backend PHAR contains the production runtime tree and
 * can load its Composer autoloader without consulting a development vendor
 * directory.
 */

$options = getopt('', ['phar:', 'root:']);
$pharPath = $options['phar'] ?? (__DIR__ . '/../backend/backend.phar');
$rootDir = $options['root'] ?? dirname(__DIR__);

function failVerification(string $message): never
{
    fwrite(STDERR, "PHAR runtime verification failed: {$message}\n");
    exit(1);
}

function archiveHasPrefix(Phar $archive, string $prefix): bool
{
    $needle = '/'.trim(str_replace('\\', '/', $prefix), '/').'/';
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
        $path = '/' . ltrim(str_replace('\\', '/', $entry->getPathName()), '/');
        if (str_contains($path, $needle)) {
            return true;
        }
    }

    return false;
}

if (!is_file($pharPath)) {
    failVerification("PHAR does not exist: {$pharPath}");
}

try {
    $archive = new Phar($pharPath);
} catch (Throwable $exception) {
    failVerification('PHAR cannot be opened: ' . $exception->getMessage());
}

foreach ([
    'vendor/autoload.php',
    'vendor/composer/autoload_real.php',
    'cli/migrate.php',
    'cli/verify-database.php',
    'cli/initialize-admin.php',
    'router.php',
    'version.json',
    'certs/cacert.pem',
] as $entry) {
    if (!isset($archive[$entry])) {
        failVerification("required PHAR entry is missing: {$entry}");
    }
}

$composerPath = rtrim((string) $rootDir, '/\\') . '/backend/composer.json';
if (!is_file($composerPath)) {
    failVerification("Composer manifest is missing: {$composerPath}");
}

$composer = json_decode((string) file_get_contents($composerPath), true);
if (!is_array($composer)) {
    failVerification('Composer manifest is invalid JSON.');
}

foreach (array_keys($composer['require'] ?? []) as $packageName) {
    if (!is_string($packageName) || !str_contains($packageName, '/')) {
        continue;
    }

    if (!archiveHasPrefix($archive, 'vendor/' . $packageName)) {
        failVerification("required Composer package is missing from the PHAR: {$packageName}");
    }
}

$archivePath = realpath($pharPath);
if ($archivePath === false) {
    failVerification('PHAR path cannot be resolved.');
}

$autoloadUri = 'phar://' . str_replace('\\', '/', $archivePath) . '/vendor/autoload.php';
try {
    require_once $autoloadUri;
} catch (Throwable $exception) {
    failVerification('packaged Composer autoload failed: ' . $exception->getMessage());
}

if (!class_exists('Composer\\Autoload\\ClassLoader')) {
    failVerification('packaged Composer ClassLoader did not load.');
}

if (isset($composer['require']['mpdf/mpdf']) && !class_exists('Mpdf\\Mpdf')) {
    failVerification('the required mPDF runtime class did not autoload from the PHAR.');
}

echo "PHAR runtime verification passed: {$pharPath}\n";
