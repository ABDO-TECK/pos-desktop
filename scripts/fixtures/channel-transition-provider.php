<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

/** Copied, signed transport fixture; production authentication/signature paths stay intact. */
class GitHubReleaseProvider extends IsolatedChannelProviderBase
{
    private function fixture(): array { return json_decode(file_get_contents((string)getenv('POS_TRANSITION_FIXTURE')),true,64,JSON_THROW_ON_ERROR); }
    protected function executeCurlGet(string $url,array $headers=[],bool $followRedirects=false):array
    {
        $fixture=$this->fixture();$response=$fixture['responses'][$url]??null;
        if (!is_array($response)) throw new RuntimeException('Unexpected isolated discovery URL.');
        if (($fixture['mode']??'')==='full') {
            $filter=static function(array $release):array {
                if (isset($release['assets'])) $release['assets']=array_values(array_filter($release['assets'],static fn(array $asset):bool=>!str_contains($asset['name'],'-to-stable-0.0.1-delta.zip')));
                return $release;
            };
            $response=array_is_list($response)?array_map($filter,$response):$filter($response);
        }
        return ['ok'=>true,'body'=>json_encode($response),'http_code'=>200,'curl_error'=>'','curl_errno'=>0,'response_headers'=>[]];
    }
    public function fetchAssetContent(string $url):array
    {
        $fixture=$this->fixture();$path=$fixture['assets'][$url]??null;
        if (!is_string($path)||!is_file($path)) return ['ok'=>false,'error'=>'Isolated asset missing.'];
        if (($fixture['mode']??'')==='corrupt_signature' && str_ends_with($path,'-delta.sig')) return ['ok'=>true,'content'=>'invalid fixture signature'];
        return ['ok'=>true,'content'=>file_get_contents($path)];
    }
    public function downloadAssetFile(string $url,string $destPath):array
    {
        $fixture=$this->fixture();$source=$fixture['assets'][$url]??null;
        if (!is_string($source)||!is_file($source)||!copy($source,$destPath)) return ['ok'=>false,'error'=>'Isolated package copy failed.'];
        return ['ok'=>true,'error'=>null];
    }
}
