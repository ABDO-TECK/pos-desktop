<?php
declare(strict_types=1);
require __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\UpdateAssetDownloader;

function expectDownload(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
// Stub the transport boundary; retry policy and final diagnostics stay real.
class InterruptedAssetDownload extends UpdateAssetDownloader
{
    public int $calls = 0;
    protected function transfer(string $url, $file, int $remainingSeconds, ?Closure $progress): array
    {
        $this->calls++;
        fwrite($file, 'partial');
        return ['success'=>false,'http_code'=>200,'errno'=>28,'error'=>'Operation timed out','bytes'=>7,'seconds'=>60.0];
    }
}
$dir = sys_get_temp_dir() . '/pos-download-' . bin2hex(random_bytes(5));
mkdir($dir);
$download = new InterruptedAssetDownload(__DIR__.'/../backend/certs/cacert.pem', '', 900, 3, 0);
$result = $download->download('https://github.com/test/asset.zip', $dir.'/asset.zip');
expectDownload(!$result['ok'], 'Interrupted body was accepted');
expectDownload($download->calls === 3, 'Transient transfer must retry three times');
expectDownload(!is_file($dir.'/asset.zip'), 'Partial file survived failure');
expectDownload(str_contains($result['error'], 'cURL 28') && str_contains($result['error'], 'Operation timed out'), 'HTTP 200 masked the transport error');
expectDownload(str_contains($result['error'], '7 bytes'), 'Missing received-byte diagnostics');
class RecoveredAssetDownload extends InterruptedAssetDownload
{
    protected function transfer(string $url, $file, int $remainingSeconds, ?Closure $progress): array
    {
        if ($this->calls < 1) return parent::transfer($url, $file, $remainingSeconds, $progress);
        $this->calls++;
        fwrite($file, 'complete');
        return ['success'=>true,'http_code'=>200,'errno'=>0,'error'=>'','bytes'=>8,'seconds'=>1.0];
    }
}
$recovered = new RecoveredAssetDownload(__DIR__.'/../backend/certs/cacert.pem', '', 900, 3, 0);
expectDownload($recovered->download('https://github.com/test/asset.zip', $dir.'/asset.zip')['ok'], 'Retry did not recover');
expectDownload(file_get_contents($dir.'/asset.zip') === 'complete', 'Retry appended to partial bytes');
unlink($dir.'/asset.zip'); rmdir($dir);
echo "PASS asset downloader: retries, partial cleanup, HTTP 200 transport diagnostics\n";
