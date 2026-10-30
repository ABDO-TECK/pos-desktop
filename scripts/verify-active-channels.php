<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\{UpdateChannelPolicy,UpdateManifestService};
function expectActive(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
expectActive(UpdateChannelPolicy::active()===['stable','beta'],'Unexpected active channels');
expectActive(UpdateChannelPolicy::normalizePreference('rc')==='stable','Retired preference not normalized');
expectActive(UpdateChannelPolicy::installedChannel('0.0.10-beta.5')==='beta','Installed beta identity changed');
expectActive(UpdateChannelPolicy::installedChannel('0.0.1')==='stable','Stable identity changed');
expectActive(UpdateChannelPolicy::installedChannel('0.0.9-rc.1')==='retired','Historical RC reclassified');
$lab=sys_get_temp_dir().'/pos-active-channels-'.bin2hex(random_bytes(6));mkdir($lab);
$file=$lab.'/update_channel.json';file_put_contents($file,json_encode(['channel'=>'rc','keep'=>'unrelated']));
$result=UpdateChannelPolicy::migratePreference($file);
expectActive($result['channel']==='stable'&&$result['migrated_from']==='rc','RC preference migration failed');
$stored=json_decode(file_get_contents($file),true);
expectActive($stored['keep']==='unrelated'&&$stored['channel']==='stable','Migration discarded unrelated fields');
$bytes=file_get_contents($file);UpdateChannelPolicy::migratePreference($file);expectActive(file_get_contents($file)===$bytes,'Migration is not idempotent');
UpdateChannelPolicy::savePreference($file,'beta');
$saved=json_decode(file_get_contents($file),true);
expectActive($saved['channel']==='beta'&&$saved['keep']==='unrelated'&&!isset($saved['migrated_from']),'Explicit preference lost or retained stale notice');
file_put_contents($file,'malformed json');UpdateChannelPolicy::migratePreference($file);expectActive(file_get_contents($file)==='malformed json','Corrupt preference evidence overwritten');
$policy=new UpdateManifestService();
expectActive(!$policy->checkChannelCompatibility('beta','rc')['compatible'],'Beta accepts retired RC release');
expectActive(!$policy->checkChannelCompatibility('stable','beta')['compatible'],'Stable accepts beta');
expectActive(!$policy->checkChannelCompatibility('beta','stable')['compatible'],'Beta accepts stable release');
expectActive(!$policy->checkChannelCompatibility('beta','nonsense')['compatible'],'Unknown release treated as stable');
mkdir($lab.'/root');file_put_contents($lab.'/root/version.json','{"version":"0.0.10-beta.5","update_channel":"beta"}');
putenv('APP_DEPLOY_ROOT='.$lab.'/root');
file_put_contents($lab.'/remote_version_cache_beta.json',json_encode(['ok'=>true,'data'=>['version'=>'0.0.11-rc.1','channel'=>'rc']]));
$provider=new class extends App\Services\GitHubReleaseProvider {
    public function getLatestRelease(string $channel='stable',?string $currentVersion=null):array {throw new RuntimeException('retired_cache_rejected');}
};
$delta=new App\Services\DeltaUpdateService(null,$lab.'/root',$lab);
$service=new App\Services\UpdateService(new App\Services\GitService(),new App\Services\FrontendBuildService(),new App\Services\BackupService(),$delta,null,null,$provider);
try {$service->fetchRemoteVersion('beta',false);throw new RuntimeException('Retired cached release offered');}
catch(RuntimeException $error){expectActive($error->getMessage()==='retired_cache_rejected',$error->getMessage());}
file_put_contents($lab.'/remote_version_cache_beta.json',json_encode(['ok'=>true,'data'=>['version'=>'0.0.13','channel'=>'stable']]));
try {$service->fetchRemoteVersion('beta',false);throw new RuntimeException('Stable cached release offered to beta');}
catch(RuntimeException $error){expectActive($error->getMessage()==='retired_cache_rejected',$error->getMessage());}
// Negative fixture: cached declared beta but stable version (contradiction)
file_put_contents($lab.'/remote_version_cache_beta.json',json_encode(['ok'=>true,'data'=>['version'=>'0.0.13','channel'=>'beta']]));
try {$service->fetchRemoteVersion('beta',false);throw new RuntimeException('Contradictory cached release (declared beta, stable version) offered');}
catch(RuntimeException $error){expectActive($error->getMessage()==='retired_cache_rejected',$error->getMessage());}
// Negative fixture: cached declared stable but beta version (contradiction)
file_put_contents($lab.'/remote_version_cache_stable.json',json_encode(['ok'=>true,'data'=>['version'=>'0.0.10-beta.6','channel'=>'stable']]));
try {$service->fetchRemoteVersion('stable',false);throw new RuntimeException('Contradictory cached release (declared stable, beta version) offered');}
catch(RuntimeException $error){expectActive($error->getMessage()==='retired_cache_rejected',$error->getMessage());}
// Positive fixture: valid matching cached beta release
file_put_contents($lab.'/remote_version_cache_beta.json',json_encode(['ok'=>true,'data'=>['version'=>'0.0.10-beta.6','channel'=>'beta']]));
$validCached = $service->fetchRemoteVersion('beta',false);
expectActive($validCached!==null&&$validCached['version']==='0.0.10-beta.6','Valid same-channel cached release ignored');
echo "PASS active channels: stable/beta only, retired preference migration, installed identity, preserved history and strict manifest channel policy\n";
