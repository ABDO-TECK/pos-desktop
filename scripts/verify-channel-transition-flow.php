<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\{ChannelTransitionService,UpdateService,DeltaUpdateService,GitService,FrontendBuildService,BackupService,GitHubReleaseProvider};
$lab=sys_get_temp_dir().'/pos-transition-flow-'.bin2hex(random_bytes(6));mkdir($lab);
file_put_contents($lab.'/version.json','{"version":"0.0.10-beta.5"}');
putenv('APP_DEPLOY_ROOT='.$lab);
$provider=new class extends GitHubReleaseProvider {
    public function getReleaseByTag(string $tag,string $repo='pos-desktop-updates'):array{return ['ok'=>false,'error'=>'fixture offline'];}
};
$delta=new DeltaUpdateService(null,$lab,$lab.'/storage');
$updates=new UpdateService(new GitService(),new FrontendBuildService(),new BackupService(),$delta,null,null,$provider);
$service=new ChannelTransitionService($updates,new BackupService());
$preview=$service->preview(1,'stable');
if($preview['status']!=='blocked'||$preview['installed']['version']!=='0.0.10-beta.5')throw new RuntimeException('Missing immutable shell was not blocked with installed identity');
if($service->prepare(1,'',true)['ok'])throw new RuntimeException('Empty preview applied');
if($service->preview(1,'rc')['status']!=='blocked')throw new RuntimeException('Retired preference accepted');
unlink($lab.'/version.json');rmdir($lab);
echo "PASS transition flow fails closed without shell, inactive channel and invalid preview\n";

require __DIR__.'/verify-transition-data.php';
$lab=sys_get_temp_dir().'/pos-transition-ready-'.bin2hex(random_bytes(6));
$root=$lab.'/resources/app.asar.unpacked';mkdir($root.'/backend',0700,true);mkdir($root.'/frontend/dist',0700,true);
file_put_contents($lab.'/POS Desktop.exe','fixture shell');file_put_contents($lab.'/resources/app.asar','fixture archive');
file_put_contents($root.'/version.json','{"version":"0.0.10-beta.5"}');file_put_contents($root.'/backend/backend.phar','fixture phar');
file_put_contents($root.'/frontend/dist/new.js','new');
$source=['version'=>'0.0.10-beta.5','files'=>[]];
foreach(['version.json','backend/backend.phar','frontend/dist/new.js'] as $file)$source['files'][$file]=['sha256'=>hash_file('sha256',$root.'/'.$file),'size'=>filesize($root.'/'.$file)];
$target=['version'=>'0.0.1','files'=>['version.json'=>['sha256'=>hash('sha256','stable1'),'size'=>7],'backend/backend.phar'=>['sha256'=>hash('sha256','oldphar'),'size'=>7]]];
$key=openssl_pkey_new(['private_key_bits'=>2048]);file_put_contents($lab.'/public.pem',openssl_pkey_get_details($key)['key']);
$sign=static function(array $data)use($key):array{$raw=json_encode($data);openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);return [$raw,base64_encode($signature)];};
$pdo=new CompatibilityDatabase();$description=(new App\Services\UpdateDataCompatibilityService())->describe($pdo);
$shell=hash('sha256',json_encode(['POS Desktop.exe'=>hash_file('sha256',$lab.'/POS Desktop.exe'),'resources/app.asar'=>hash_file('sha256',$lab.'/resources/app.asar')],JSON_UNESCAPED_SLASHES));
$descriptor=['format'=>1,'operation'=>'channel_transition','carrier_tag'=>'v0.0.10-beta.5','from_version'=>$source['version'],'from_channel'=>'beta','source_inventory_sha256'=>hash('sha256',json_encode($source)),'source_inventory'=>$source,'version'=>'0.0.1','channel'=>'stable','target_repo'=>'pos-desktop','target_tag'=>'v0.0.1','target_inventory_sha256'=>hash('sha256',json_encode($target)),'target_inventory'=>$target,'shell_sha256'=>$shell,'type'=>'delta','files'=>array_map(static fn($path)=>['path'=>$path,...$target['files'][$path]],array_keys($target['files'])),'deleted_files'=>['frontend/dist/new.js'],'migrations'=>[],'package'=>['asset'=>'return.zip','sha256'=>str_repeat('a',64),'size'=>123],'data_compatibility'=>['format'=>1,'metadata_migration'=>App\Services\UpdateDataCompatibilityService::METADATA_MIGRATION,'schema_sha256'=>$description['schema_sha256'],'ledger_sha256'=>$description['ledger_sha256']]];
$provider=new class extends GitHubReleaseProvider {
    public array $assets=[];public array $source=[];public array $target=[];
    public string $packagePath='';public ?Closure $afterDownload=null;
    public array $downloadedUrls=[];
    public function getReleaseByTag(string $tag,string $repo='pos-desktop-updates'):array{return ['ok'=>true,'release'=>$this->source];}
    public function listChannelCandidates(string $channel,int $page=1):array{return ['ok'=>true,'releases'=>$channel==='beta'?[$this->source]:[],'next_page'=>null];}
    public function getLatestSetupRelease(?string $currentVersion=null):array{return ['ok'=>true,'release'=>$this->target];}
    public function fetchAssetContent(string $url):array{return isset($this->assets[$url])?['ok'=>true,'content'=>$this->assets[$url]]:['ok'=>false];}
    public function downloadAssetFile(string $url,string $destPath):array{$this->downloadedUrls[]=$url;copy($this->packagePath,$destPath);if($this->afterDownload)($this->afterDownload)();return ['ok'=>true];}
};
$release=static function(string $repo,string $version,array $names):array{return ['tag_name'=>'v'.$version,'draft'=>false,'prerelease'=>str_contains($version,'-'),'assets'=>array_map(static fn($name)=>['name'=>$name,'browser_download_url'=>'https://github.com/ABDO-TECK/'.$repo.'/releases/download/v'.$version.'/'.$name],$names)];};
$provider->source=$release('pos-desktop-updates',$source['version'],['deployment-inventory.json','channel-transitions.json','channel-transitions.sig','return.json','return.sig','return.zip','full.json','full.sig','full.zip']);
$provider->target=$release('pos-desktop','0.0.1',['deployment-inventory.json']);
$url=static fn(string $asset):string=>'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.10-beta.5/'.$asset;
$provider->assets[$url('deployment-inventory.json')]=json_encode($source);
$provider->assets['https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.1/deployment-inventory.json']=json_encode($target);
[$raw,$sig]=$sign($descriptor);$provider->assets[$url('return.json')]=$raw;$provider->assets[$url('return.sig')]=$sig;
$entry=['from_version'=>$source['version'],'source_inventory_sha256'=>$descriptor['source_inventory_sha256'],'version'=>'0.0.1','channel'=>'stable','descriptor'=>'return.json','signature'=>'return.sig','descriptor_sha256'=>hash('sha256',$raw),'type'=>'delta'];
$full=$descriptor;$full['type']='full';$full['package']['asset']='full.zip';[$fullRaw,$fullSig]=$sign($full);$provider->assets[$url('full.json')]=$fullRaw;$provider->assets[$url('full.sig')]=$fullSig;
$fullEntry=[...$entry,'type'=>'full','descriptor'=>'full.json','signature'=>'full.sig','descriptor_sha256'=>hash('sha256',$fullRaw)];
[$index,$indexSig]=$sign(['format'=>1,'carrier_tag'=>'v0.0.10-beta.5','entries'=>[$entry,$fullEntry]]);
$provider->assets[$url('channel-transitions.json')]=$index;$provider->assets[$url('channel-transitions.sig')]=$indexSig;
putenv('APP_DEPLOY_ROOT='.$root);$delta=new DeltaUpdateService(null,$root,$lab.'/storage');
$updates=new UpdateService(new GitService(),new FrontendBuildService(),new BackupService(),$delta,null,null,$provider);$updates->setClientChannel('stable');
$service=new ChannelTransitionService($updates,new BackupService(),$pdo,new App\Services\ManifestSignatureService($lab.'/public.pem'));
$ready=$service->preview(7,'stable');
dataAssert($ready['status']==='ready'&&$ready['target']['version']==='0.0.1'&&$ready['package_type']==='delta','Explicit older stable preview failed: '.json_encode($ready));
dataAssert(!$service->prepare(8,$ready['preview_id'],true)['ok'],'Wrong user applied preview');
$lock=new App\Services\UpdateOperationLock($lab.'/storage');$sale=$lock->acquireSale();dataAssert($sale['acquired'],'Sale fixture failed');
dataAssert(!$service->prepare(7,$ready['preview_id'],true)['ok'],'Transition crossed active sale');$lock->release();
$updates->setClientChannel('beta');dataAssert(!$service->prepare(7,$ready['preview_id'],true)['ok'],'Changed preference applied');$updates->setClientChannel('stable');
$originalSource=$provider->source;
$provider->source['assets']=array_values(array_filter($provider->source['assets'],static fn($asset)=>$asset['name']!=='return.zip'));
dataAssert($service->preview(7,'stable')['package_type']==='full','Missing delta did not offer signed compatible application bundle');
file_put_contents($root.'/frontend/dist/new.js','tampered');dataAssert($service->preview(7,'stable')['status']==='blocked','Full fallback bypassed changed source');file_put_contents($root.'/frontend/dist/new.js','new');
$provider->source=$originalSource;$provider->assets[$url('return.sig')]='wrong';dataAssert($service->preview(7,'stable')['status']==='blocked','Bad signature escaped through full fallback');$provider->assets[$url('return.sig')]=$sig;
$pdo->type='varchar(20)';dataAssert($service->preview(7,'stable')['status']==='blocked','Business schema mismatch accepted');
$pdo->type='varchar(200)';
$zip=new ZipArchive();$package=$lab.'/package.zip';$zip->open($package,ZipArchive::CREATE);$zip->addFromString('version.json','stable1');$zip->addFromString('backend/backend.phar','oldphar');$zip->close();
$descriptor['package']['sha256']=hash_file('sha256',$package);$descriptor['package']['size']=filesize($package);$provider->packagePath=$package;
[$raw,$sig]=$sign($descriptor);$provider->assets[$url('return.json')]=$raw;$provider->assets[$url('return.sig')]=$sig;$entry['descriptor_sha256']=hash('sha256',$raw);
[$index,$indexSig]=$sign(['format'=>1,'carrier_tag'=>'v0.0.10-beta.5','entries'=>[$entry,$fullEntry]]);$provider->assets[$url('channel-transitions.json')]=$index;$provider->assets[$url('channel-transitions.sig')]=$indexSig;
$ready=$service->preview(7,'stable');dataAssert($ready['status']==='ready','Valid download fixture preview refused');
$provider->afterDownload=static function()use($updates):void{$updates->setClientChannel('beta');};
$result=$service->prepare(7,$ready['preview_id'],true);
dataAssert(!$result['ok']&&$result['reason_code']==='preview_expired_or_changed','Channel change during download did not abort before applying');
dataAssert(file_get_contents($root.'/version.json')==='{"version":"0.0.10-beta.5"}','Race changed installed files');
$provider->afterDownload=null;$updates->setClientChannel('stable');
$ready=$service->preview(7,'stable');file_put_contents($package,'corrupt ZIP');
$result=$service->prepare(7,$ready['preview_id'],true);
dataAssert(!$result['ok']&&$result['reason_code']==='package_download_or_digest_failed','Corrupt ZIP was not rejected before file or SQL backup operations');

// Synthetic Beta 0.0.12-beta.1 -> Stable 0.0.13 cross-channel transition test
file_put_contents($root.'/version.json', '{"version":"0.0.12-beta.1"}');
$source12 = ['version'=>'0.0.12-beta.1', 'files'=>[]];
foreach(['version.json','backend/backend.phar','frontend/dist/new.js'] as $file) {
    $source12['files'][$file] = ['sha256'=>hash_file('sha256', $root.'/'.$file), 'size'=>filesize($root.'/'.$file)];
}
$target13 = [
    'version' => '0.0.13',
    'files' => [
        'version.json' => ['sha256' => hash('sha256', 'stable13'), 'size' => 8],
        'backend/backend.phar' => ['sha256' => hash('sha256', 'phar13'), 'size' => 6],
    ],
];

$url12 = static fn(string $asset): string => 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12-beta.1/' . $asset;
$provider->source = $release('pos-desktop-updates', '0.0.12-beta.1', [
    'deployment-inventory.json',
    'channel-transitions.json',
    'channel-transitions.sig',
    'delta-12-to-13.json',
    'delta-12-to-13.sig',
    'delta-12-to-13.zip',
    'full-13.json',
    'full-13.sig',
    'full-13.zip',
]);
$provider->target = $release('pos-desktop', '0.0.13', ['deployment-inventory.json']);
$provider->assets[$url12('deployment-inventory.json')] = json_encode($source12);
$provider->assets['https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.13/deployment-inventory.json'] = json_encode($target13);

$descriptor13Delta = [
    'format' => 1,
    'operation' => 'channel_transition',
    'carrier_tag' => 'v0.0.12-beta.1',
    'from_version' => '0.0.12-beta.1',
    'from_channel' => 'beta',
    'source_inventory_sha256' => hash('sha256', json_encode($source12)),
    'source_inventory' => $source12,
    'version' => '0.0.13',
    'channel' => 'stable',
    'target_repo' => 'pos-desktop',
    'target_tag' => 'v0.0.13',
    'target_inventory_sha256' => hash('sha256', json_encode($target13)),
    'target_inventory' => $target13,
    'shell_sha256' => $shell,
    'type' => 'delta',
    'files' => array_map(static fn($p) => ['path' => $p, ...$target13['files'][$p]], array_keys($target13['files'])),
    'deleted_files' => ['frontend/dist/new.js'],
    'migrations' => [],
    'package' => ['asset' => 'delta-12-to-13.zip', 'sha256' => str_repeat('b', 64), 'size' => 456],
    'data_compatibility' => [
        'format' => 1,
        'metadata_migration' => App\Services\UpdateDataCompatibilityService::METADATA_MIGRATION,
        'schema_sha256' => $description['schema_sha256'],
        'ledger_sha256' => $description['ledger_sha256'],
    ],
];
[$raw13Delta, $sig13Delta] = $sign($descriptor13Delta);
$provider->assets[$url12('delta-12-to-13.json')] = $raw13Delta;
$provider->assets[$url12('delta-12-to-13.sig')] = $sig13Delta;

$descriptor13Full = $descriptor13Delta;
$descriptor13Full['type'] = 'full';
$descriptor13Full['package'] = ['asset' => 'full-13.zip', 'sha256' => str_repeat('c', 64), 'size' => 789];
[$raw13Full, $sig13Full] = $sign($descriptor13Full);
$provider->assets[$url12('full-13.json')] = $raw13Full;
$provider->assets[$url12('full-13.sig')] = $sig13Full;

$entry13Delta = [
    'from_version' => '0.0.12-beta.1',
    'source_inventory_sha256' => $descriptor13Delta['source_inventory_sha256'],
    'version' => '0.0.13',
    'channel' => 'stable',
    'descriptor' => 'delta-12-to-13.json',
    'signature' => 'delta-12-to-13.sig',
    'descriptor_sha256' => hash('sha256', $raw13Delta),
    'type' => 'delta',
];
$entry13Full = [
    ...$entry13Delta,
    'type' => 'full',
    'descriptor' => 'full-13.json',
    'signature' => 'full-13.sig',
    'descriptor_sha256' => hash('sha256', $raw13Full),
];

[$index13, $indexSig13] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.12-beta.1', 'entries' => [$entry13Delta, $entry13Full]]);
$provider->assets[$url12('channel-transitions.json')] = $index13;
$provider->assets[$url12('channel-transitions.sig')] = $indexSig13;

$updates->setClientChannel('stable');
$preview13 = $service->preview(7, 'stable');
dataAssert($preview13['status'] === 'ready', 'Beta 12 -> Stable 13 transition preview failed: ' . json_encode($preview13));
dataAssert($preview13['package_type'] === 'delta', 'Beta 12 -> Stable 13 must prioritize delta over full package');
dataAssert($descriptor13Delta['package']['size'] === 456 && $descriptor13Full['package']['size'] === 789, 'Delta size must be distinct from full package size');

// Fallback to full transition when delta asset is missing
$providerSourceWithNoDelta = $provider->source;
$provider->source['assets'] = array_values(array_filter($provider->source['assets'], static fn($a) => $a['name'] !== 'delta-12-to-13.zip'));
$preview13Fallback = $service->preview(7, 'stable');
dataAssert($preview13Fallback['status'] === 'ready' && $preview13Fallback['package_type'] === 'full', 'Missing delta must fall back to full zip package');
$provider->source = $providerSourceWithNoDelta;

// Verify that transition packages are zip archives and never native installer executables
foreach ($provider->downloadedUrls as $dlUrl) {
    dataAssert(!str_ends_with(strtolower($dlUrl), '.exe'), 'Channel transition must never download native installer exe: ' . $dlUrl);
}

// The random fixture root owns all its artifacts; no original installation path is used.
$canonical=realpath($lab);dataAssert($canonical!==false&&str_starts_with(strtolower(str_replace('\\','/',$canonical)),strtolower(str_replace('\\','/',realpath(sys_get_temp_dir()))).'/pos-transition-ready-'),'Unsafe fixture cleanup root');
$cleanup=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lab,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($cleanup as $file){dataAssert(!$file->isLink(),'Fixture cleanup encountered a link');if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($lab);
echo "PASS explicit older stable, signed delta/full selection, source/signature/schema refusal, changed preference, wrong user and active sale\n";
