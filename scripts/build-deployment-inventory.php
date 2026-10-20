<?php
declare(strict_types=1);
require_once __DIR__ . '/deployment-inventory.php';
$options = getopt('', ['root:', 'source-root:', 'output:']);
$root = realpath($options['root'] ?? (__DIR__ . '/..'));
$source = realpath($options['source-root'] ?? (__DIR__ . '/..'));
if (!$root || !$source || empty($options['output'])) throw new RuntimeException('Provide --root, --source-root and --output.');
$inventory = createDeploymentInventory($root, $source);
file_put_contents($options['output'], json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Deployment inventory: ' . count($inventory['files']) . " artifacts for {$inventory['version']}\n";
