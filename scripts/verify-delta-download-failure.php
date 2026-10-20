<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\{UpdateService, GitHubReleaseProvider, ManifestSignatureService, GitService, FrontendBuildService, BackupService};
$lab = sys_get_temp_dir().'/pos-failed-download-'.bin2hex(random_bytes(5));
mkdir($lab.'/backend', 0700, true);
file_put_contents($lab.'/backend/backend.phar', 'packaged marker');
file_put_contents($lab.'/version.json', '{"version":"0.0.1"}');
putenv('APP_DEPLOY_ROOT='.$lab); putenv('APP_STORAGE_DIR='.$lab.'/state'); putenv('DEPLOYMENT_MODE=lan');
class FailedDownloadProvider extends GitHubReleaseProvider
{
    public function fetchAssetContent(string $url): array
    {
        $manifest = ['version'=>'0.0.10-beta.2','from_version'=>'0.0.1','minimum_version'=>'0.0.1','channel'=>'beta',
            'files'=>[['path'=>'version.json','sha256'=>str_repeat('a',64),'size'=>10]],'deleted_files'=>[], 'migrations'=>[]];
        return ['ok'=>true,'content'=>str_ends_with($url,'.sig')?'fixture':json_encode($manifest),'error'=>null];
    }
    public function downloadAssetFile(string $url, string $destPath): array
    { return ['ok'=>false,'error'=>'cURL 28: Operation timed out (HTTP 200; 100 bytes)']; }
}
class FixtureSignature extends ManifestSignatureService
{ public function verifySignature(string $data, string $signature, ?string $customPublicKey = null): bool { return true; } }
class DownloadFailureUpdate extends UpdateService
{
    public function fetchRemoteVersion(?string $channel = null, bool $force = false): ?array
    { return ['version'=>'0.0.10-beta.2','tag_name'=>'v0.0.10-beta.2','manifest_url'=>'https://github.com/m.json','signature_url'=>'https://github.com/m.sig','delta_url'=>'https://github.com/d.zip']; }
}
$service = new DownloadFailureUpdate(new GitService(),new FrontendBuildService(),new BackupService(),githubProvider:new FailedDownloadProvider(token:''),signatureService:new FixtureSignature());
$service->setClientChannel('beta');
$result = $service->applyUpdate(false);
if ($result['ok'] || !str_contains($result['error'], 'cURL 28') || str_contains(json_encode($result), 'Full Update')) {
    throw new RuntimeException('Original transport failure lost to Git fallback: '.json_encode($result));
}
if (file_get_contents($lab.'/version.json') !== '{"version":"0.0.1"}') throw new RuntimeException('Failed download modified version');
echo "PASS packaged LAN download failure preserves real cause and installed version; no Git fallback\n";
