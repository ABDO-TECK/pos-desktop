<?php
declare(strict_types=1);
// Loaded explicitly by the disposable fixture HTTP router. Never packaged.
if (getenv('GITHUB_ACTIONS')!=='true' || !getenv('POS_TRANSITION_FIXTURE')) throw new RuntimeException('Isolated transport requires ephemeral CI.');
$fixture=(string)getenv('POS_TRANSITION_FIXTURE');
$temporary=realpath((string)getenv('RUNNER_TEMP'));
if (!$temporary || !str_starts_with(strtolower(str_replace('\\','/',realpath($fixture))),strtolower(str_replace('\\','/',$temporary)).'/')) throw new RuntimeException('Fixture must belong to the runner temp root.');
$root=(string)getenv('APP_DEPLOY_ROOT');
if ($root==='') return; // Startup CLI supplies its own runtime configuration.
require_once 'phar://'.str_replace('\\','/',$root).'/backend/backend.phar/vendor/autoload.php';
if (!class_exists('App\Services\UpdateChannelPolicy')) require __DIR__.'/../../backend/Services/UpdateChannelPolicy.php';
if (!class_exists('App\Services\GitHubReleaseProvider',false)) {
    // Keep production provider discovery and policy, substituting only its I/O boundary.
    $source=file_get_contents(__DIR__.'/../../backend/Services/GitHubReleaseProvider.php');
    $source=str_replace('class GitHubReleaseProvider','class IsolatedChannelProviderBase',$source);
    eval(substr($source,5));
    require __DIR__.'/channel-transition-provider.php';
}
