<?php
declare(strict_types=1);

/** Physical artifacts managed by the PHP delta engine (never user data). */
function deploymentFiles(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $paths = ['backend/backend.phar', 'backend/certs/cacert.pem', 'database/pos_schema.sql', 'version.json'];
    $dist = $root . '/frontend/dist';
    if (!is_dir($dist)) {
        throw new RuntimeException('Built frontend/dist is required.');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dist, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) throw new RuntimeException('Deployment symlinks are not supported.');
        if ($file->isFile()) $paths[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
    }
    sort($paths, SORT_STRING);
    $files = [];
    foreach ($paths as $relative) {
        $file = $root . '/' . $relative;
        if (!is_file($file) || is_link($file)) throw new RuntimeException("Missing physical artifact: {$relative}");
        $files[$relative] = ['sha256' => hash_file('sha256', $file), 'size' => filesize($file)];
    }
    return $files;
}

function desktopShellFingerprint(string $sourceRoot): string
{
    $command = 'git -C ' . escapeshellarg($sourceRoot) . ' ls-files -z -- electron build package.json package-lock.json scripts/desktop-runtime.json';
    $listed = shell_exec($command);
    if (!is_string($listed) || $listed === '') throw new RuntimeException('Tracked desktop shell inputs are missing.');
    $paths = array_values(array_filter(explode("\0", $listed)));
    sort($paths, SORT_STRING);
    $hashes = [];
    foreach ($paths as $relative) $hashes[$relative] = hash_file('sha256', $sourceRoot . '/' . $relative);
    return hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function createDeploymentInventory(string $root, string $sourceRoot): array
{
    $version = json_decode((string) file_get_contents($root . '/version.json'), true, 512, JSON_THROW_ON_ERROR);
    return ['format' => 1, 'version' => $version['version'], 'shell_sha256' => desktopShellFingerprint($sourceRoot), 'files' => deploymentFiles($root)];
}
