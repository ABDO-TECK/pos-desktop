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
        if(isset($fixture['trace']))file_put_contents($fixture['trace'],json_encode(['metadata_url'=>$url,'transport'=>'frozen_metadata','pid'=>getmypid()])."\n",FILE_APPEND|LOCK_EX);
        if (!is_array($response)) throw new RuntimeException('Unexpected isolated discovery URL.');
        if (isset($response['http_code']) && (int)$response['http_code'] !== 200) {
            $code = (int)$response['http_code'];
            $body = isset($response['body']) ? (is_string($response['body']) ? $response['body'] : json_encode($response['body'])) : json_encode(['message' => 'Not Found']);
            return ['ok' => false, 'body' => $body, 'http_code' => $code, 'curl_error' => 'HTTP ' . $code, 'curl_errno' => 0, 'response_headers' => []];
        }
        if (($fixture['mode']??'')==='full') {
            $filter=static function(array $release):array {
                if (isset($release['assets'])) $release['assets']=array_values(array_filter($release['assets'],static fn(array $asset):bool=>!str_ends_with($asset['name'],'-delta.zip')));
                return $release;
            };
            $response=array_is_list($response)?array_map($filter,$response):$filter($response);
        }
        return ['ok'=>true,'body'=>json_encode($response),'http_code'=>200,'curl_error'=>'','curl_errno'=>0,'response_headers'=>[]];
    }
    public function fetchAssetContent(string $url):array
    {
        $fixture=$this->fixture();$path=$fixture['assets'][$url]??null;
        if (!is_string($path)||!is_file($path)) return ['ok'=>false,'content'=>null,'error'=>'Isolated asset missing or forbidden URL.'];
        if(isset($fixture['trace']))file_put_contents($fixture['trace'],json_encode(['asset'=>basename($url),'transport'=>'copied_signed_text','pid'=>getmypid()])."\n",FILE_APPEND|LOCK_EX);
        if (($fixture['mode']??'')==='corrupt_signature' && str_ends_with($path,'-delta.sig')) return ['ok'=>true,'content'=>'invalid fixture signature','error'=>null];
        return ['ok'=>true,'content'=>file_get_contents($path),'error'=>null];
    }
    public function downloadAssetFile(string $url,string $destPath):array
    {
        $fixture=$this->fixture();$source=$fixture['assets'][$url]??null;
        if (!is_string($source)||!is_file($source)) return ['ok'=>false,'error'=>'Isolated package missing or forbidden URL.'];
        if(isset($fixture['trace']))file_put_contents($fixture['trace'],json_encode(['asset'=>basename($url),'transport'=>'copied_signed_package','pid'=>getmypid()])."\n",FILE_APPEND|LOCK_EX);
        if (!copy($source,$destPath)) return ['ok'=>false,'error'=>'Isolated package copy failed.'];
        return ['ok'=>true,'error'=>null];
    }
}
