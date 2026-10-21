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
        if(!array_key_exists($url,$this->pages))throw new RuntimeException('Unexpected catalog request: '.$url);
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
echo "PASS transition catalog: real Setup authority, newest selected-channel target, pagination, drafts/RC exclusion and malformed-feed distinction\n";
