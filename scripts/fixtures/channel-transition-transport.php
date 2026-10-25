<?php
declare(strict_types=1);
// Loaded explicitly by the disposable fixture HTTP router or CI scan dir. Never packaged.
if (defined('POS_TRANSITION_TRANSPORT_LOADED')) return;
define('POS_TRANSITION_TRANSPORT_LOADED', true);

if (getenv('GITHUB_ACTIONS')!=='true' || !getenv('POS_TRANSITION_FIXTURE')) throw new RuntimeException('Isolated transport requires ephemeral CI.');
$fixture=(string)getenv('POS_TRANSITION_FIXTURE');
$temporary=realpath((string)getenv('RUNNER_TEMP'));
if (!$temporary || !str_starts_with(strtolower(str_replace('\\','/',realpath($fixture))),strtolower(str_replace('\\','/',$temporary)).'/')) throw new RuntimeException('Fixture must belong to the runner temp root.');
$root=(string)getenv('APP_DEPLOY_ROOT');
if ($root==='') return; // Startup CLI supplies its own runtime configuration.

$pharRoot='phar://'.str_replace('\\','/',$root).'/backend/backend.phar';
require_once $pharRoot.'/vendor/autoload.php';

$policyFile=is_file($pharRoot.'/Services/UpdateChannelPolicy.php')
    ? $pharRoot.'/Services/UpdateChannelPolicy.php'
    : __DIR__.'/../../backend/Services/UpdateChannelPolicy.php';
if (!class_exists('App\Services\UpdateChannelPolicy') && is_file($policyFile)) {
    require $policyFile;
}

if (!class_exists('App\Services\GitHubReleaseProvider',false)) {
    // Keep production provider discovery and policy, substituting only its I/O boundary.
    $providerFile=is_file($pharRoot.'/Services/GitHubReleaseProvider.php')
        ? $pharRoot.'/Services/GitHubReleaseProvider.php'
        : __DIR__.'/../../backend/Services/GitHubReleaseProvider.php';
    $source=file_get_contents($providerFile);
    if ($source===false) throw new RuntimeException("Cannot load GitHubReleaseProvider source from {$providerFile}");
    $source=str_replace('class GitHubReleaseProvider','class IsolatedChannelProviderBase',$source);
    eval(substr($source,5));
    require __DIR__.'/channel-transition-provider.php';
}
