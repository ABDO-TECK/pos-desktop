<?php

declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Windows' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted') {
    fwrite(STDERR, "Verify installed inventory only on GitHub-hosted Windows Actions.\n");
    exit(1);
}

require_once __DIR__ . '/deployment-inventory.php';

$lab = getenv('RUNNER_TEMP') . '/setup13-shell';
$published = json_decode(
    (string) file_get_contents($lab . '/deployment-inventory.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);

if (($published['format'] ?? null) !== 1 || ($published['version'] ?? null) !== '0.0.13'
    || !is_array($published['files'] ?? null) || $published['files'] === []) {
    throw new RuntimeException('Expected published deployment inventory Format 1 for 0.0.13.');
}

$expectedFiles = $published['files'];
ksort($expectedFiles, SORT_STRING);
$verified = [];
foreach (['source', 'installed'] as $layout) {
    $actual = deploymentFiles($lab . '/' . $layout . '/resources/app.asar.unpacked');
    if ($actual !== $expectedFiles) {
        throw new RuntimeException("The complete publishable deployment inventory differs in {$layout}.");
    }
    $verified[$layout] = count($actual);
}

$evidence = [
    'version' => '0.0.13',
    'published_sha256' => hash_file('sha256', $lab . '/deployment-inventory.json'),
    'matched_managed_files' => $verified,
];
$encoded = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($lab . '/evidence/installed-deployment-inventory.json', $encoded) !== strlen($encoded)) {
    throw new RuntimeException('Could not record installed deployment inventory evidence.');
}
echo "All {$verified['installed']} publishable files matched both physical layouts.\n";
