<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\{GitHubReleaseProvider,ChannelTransitionReleaseCatalog};
function catalogAssert(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function catalogRelease(string $repo,string $version,bool $draft=false):array {
    return ['tag_name'=>'v'.$version,'prerelease'=>str_contains($version,'-'),'draft'=>$draft,'assets'=>[['name'=>'deployment-inventory.json','browser_download_url'=>'https://github.com/ABDO-TECK/'.$repo.'/releases/download/v'.$version.'/deployment-inventory.json']]];
}
class TransitionCatalogFixture extends GitHubReleaseProvider {
    public array $pages=[];public array $requests=[];
    protected function executeCurlGet(string $url,array $headers=[],bool $followRedirects=false):array {
        $this->requests[]=$url;
        if(!array_key_exists($url,$this->pages)) {
            if (str_contains($url, '/releases/tags/')) {
                return ['ok'=>false,'body'=>json_encode(['message'=>'Not Found']),'http_code'=>404,'curl_error'=>'','curl_errno'=>0,'response_headers'=>[]];
            }
            throw new RuntimeException('Unexpected catalog request: '.$url);
        }
        return ['ok'=>true,'body'=>json_encode($this->pages[$url]),'http_code'=>200,'curl_error'=>'','curl_errno'=>0,'response_headers'=>[]];
    }
    public function fetchAssetContent(string $url):array {
        preg_match('~/download/v([^/]+)/~',$url,$match);
        return ['ok'=>true,'content'=>json_encode(['format'=>1,'version'=>$match[1],'shell_sha256'=>str_repeat('a',64),'files'=>['version.json'=>['sha256'=>str_repeat('b',64),'size'=>32]]])];
    }
}
$provider=new TransitionCatalogFixture();
$base='https://api.github.com/repos/ABDO-TECK/';
$updates=$base.'pos-desktop-updates/releases?per_page=100&page=1';$setup=$base.'pos-desktop/releases?per_page=100&page=1';
$beta=catalogRelease('pos-desktop-updates','0.0.10-beta.5');
$provider->pages=[$updates=>[$beta,catalogRelease('pos-desktop-updates','0.0.11-rc.1'),catalogRelease('pos-desktop-updates','0.0.12-beta.1',true)],$setup=>[catalogRelease('pos-desktop','0.0.1')],$base.'pos-desktop-updates/releases/tags/v0.0.10-beta.5'=>$beta];
$catalog=new ChannelTransitionReleaseCatalog($provider);
$installed=['version'=>'0.0.10-beta.5','channel'=>'beta','shell_sha256'=>str_repeat('c',64)];
$result=$catalog->resolve('stable',$installed);
catalogAssert($result['ok']&&$result['target']['version']==='0.0.1'&&$result['target']['repo']==='pos-desktop','Original Setup was not found as stable destination');
catalogAssert(in_array($setup,$provider->requests,true),'Setup lookup silently redirected to updates repository');
$forward=$catalog->resolve('beta',['version'=>'0.0.1','channel'=>'stable','shell_sha256'=>str_repeat('c',64)]);
catalogAssert($forward['target']['version']==='0.0.10-beta.5','Draft or retired candidate entered beta');
$provider->pages[$updates][]=catalogRelease('pos-desktop-updates','0.0.11');
$newStable=$catalog->resolve('stable',$installed);
catalogAssert($newStable['target']['version']==='0.0.11','Newer stable metadata was replaced by older Setup');
$missing=catalogRelease('pos-desktop-updates','0.0.12');$missing['assets']=[];$provider->pages[$updates]=[$missing];
$unavailable=$catalog->resolve('stable',$installed);
catalogAssert($unavailable['ok']&&$unavailable['target']['version']==='0.0.12'&&$unavailable['reason_code']==='target_inventory_unavailable','Missing transport hid newest stable identity');
$provider->pages[$updates]=array_fill(0,100,$beta);$provider->pages[$base.'pos-desktop-updates/releases?per_page=100&page=2']=[catalogRelease('pos-desktop-updates','0.0.11')];
catalogAssert($catalog->resolve('stable',$installed)['target']['version']==='0.0.11','Stable destination hidden by first beta page');
$provider->pages[$updates]='invalid';
catalogAssert(!$catalog->resolve('stable',$installed)['ok'],'Malformed repository response treated as absence');

// =========================================================================
// TEST Bounded call counts & cache reuse in discoverCarriers
// =========================================================================
$provider->pages = [
    $updates => [$beta],
    $setup => [catalogRelease('pos-desktop', '0.0.1')],
    $base . 'pos-desktop/releases/tags/v0.0.1' => catalogRelease('pos-desktop', '0.0.1'),
    $base . 'pos-desktop-updates/releases/tags/v0.0.10-beta.5' => $beta,
];

$provider->requests = [];
$carriers = $catalog->discoverCarriers(
    ['version' => '0.0.1', 'repo' => 'pos-desktop', 'tag' => 'v0.0.1'],
    ['version' => '0.0.10-beta.5', 'repo' => 'pos-desktop-updates', 'channel' => 'beta']
);
catalogAssert(count($provider->requests) <= 4, 'discoverCarriers exceeded 4 canonical pair requests: ' . count($provider->requests));

// Cache reuse: when cached releases provided in target and installed
$provider->requests = [];
$carriersCached = $catalog->discoverCarriers(
    ['version' => '0.0.1', 'repo' => 'pos-desktop', 'tag' => 'v0.0.1', 'release' => catalogRelease('pos-desktop', '0.0.1')],
    ['version' => '0.0.10-beta.5', 'repo' => 'pos-desktop-updates', 'channel' => 'beta', 'release' => $beta]
);
catalogAssert(count($provider->requests) <= 2, 'discoverCarriers failed to reuse cached target/source releases: ' . count($provider->requests));

// =========================================================================
// TEST HTTP 500 error not masked by "not found" text
// =========================================================================
$provider->pages[$base . 'pos-desktop-updates/releases/tags/v0.0.1'] = [
    '__raw_http_code__' => 500,
    'message' => 'Internal error: release record not found in cluster',
];

class Http500CatalogFixture extends TransitionCatalogFixture {
    protected function executeCurlGet(string $url, array $headers = [], bool $followRedirects = false): array {
        $this->requests[] = $url;
        if (isset($this->pages[$url]['__raw_http_code__'])) {
            $code = $this->pages[$url]['__raw_http_code__'];
            return ['ok' => false, 'body' => json_encode($this->pages[$url]), 'http_code' => $code, 'curl_error' => 'HTTP 500', 'curl_errno' => 0, 'response_headers' => []];
        }
        return parent::executeCurlGet($url, $headers, $followRedirects);
    }
}

$http500Provider = new Http500CatalogFixture();
$http500Provider->pages = $provider->pages;
$catalog500 = new ChannelTransitionReleaseCatalog($http500Provider);

$swallowed500 = false;
try {
    $catalog500->discoverCarriers(
        ['version' => '0.0.1', 'repo' => 'pos-desktop-updates', 'tag' => 'v0.0.1'],
        ['version' => '0.0.10-beta.5', 'repo' => 'pos-desktop-updates', 'channel' => 'beta']
    );
} catch (RuntimeException $e) {
    $swallowed500 = true;
}
catalogAssert($swallowed500, 'HTTP 500 with "not found" message must NOT be swallowed as 404 absence');

echo "PASS transition catalog: real Setup authority, newest selected-channel target, pagination, drafts/RC exclusion, bounded call counts, cache reuse, and HTTP 500 unmasking\n";
