<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\{ChannelTransitionManifestService,ManifestSignatureService,UpdateChannelPolicy,UpdateManifestService};

if (getenv('GITHUB_ACTIONS')!=='true' || getenv('GITHUB_REPOSITORY')!=='ABDO-TECK/pos-desktop-updates') throw new RuntimeException('Signed transition publishing requires the isolated release workflow.');
$options=getopt('',['target-root:','shell-root:','target-inventory:','contract:','output:']);
foreach (['target-root','shell-root','target-inventory','contract','output'] as $name) if (!is_string($options[$name]??null)) throw new RuntimeException('Missing builder input: '.$name);
$sourceRoot=realpath(__DIR__.'/..');$output=$options['output'];
$sourceRaw=file_get_contents($output.'/deployment-inventory.json');$targetRaw=file_get_contents($options['target-inventory']);
$source=json_decode($sourceRaw,true,64,JSON_THROW_ON_ERROR);$target=json_decode($targetRaw,true,64,JSON_THROW_ON_ERROR);
if (UpdateChannelPolicy::installedChannel($source['version'])!=='beta' || $target['version']!=='0.0.1') throw new RuntimeException('Only a beta carrier returning to the preserved stable baseline is approved.');
$signatureService=new ManifestSignatureService();$key=getenv('UPDATE_PRIVATE_KEY');
if (!is_string($key)||$key==='') throw new RuntimeException('CI signing key is required.');
$policy=new UpdateManifestService();
foreach ([[$sourceRoot,$source],[$options['target-root'],$target]] as [$root,$inventory]) {
    if (!$policy->validateManifestPaths(['baseline_files'=>array_map(static fn(array $file):string=>$file['sha256'],$inventory['files'])],$root)['ok']) throw new RuntimeException('Unsafe inventory path.');
    foreach ($inventory['files'] as $path=>$file) {
        $physical=$root.'/'.$path;
        if (!is_file($physical)||is_link($physical)||filesize($physical)!==$file['size']||!hash_equals($file['sha256'],hash_file('sha256',$physical))) throw new RuntimeException('Actual build or original Setup bytes differ from inventory: '.$path);
    }
}
$shell=[];foreach (['POS Desktop.exe','resources/app.asar'] as $file)$shell[$file]=hash_file('sha256',$options['shell-root'].'/'.$file);
$shellDigest=hash('sha256',json_encode($shell,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$contract=json_decode(file_get_contents($options['contract']),true,32,JSON_THROW_ON_ERROR);
$contract=array_intersect_key($contract,array_flip(['format','metadata_migration','schema_sha256','ledger_sha256']));
$installed=['version'=>$source['version'],'channel'=>'beta','shell_sha256'=>$shellDigest,'inventory_sha256'=>hash('sha256',$sourceRaw),'inventory'=>$source];
$destination=['version'=>$target['version'],'channel'=>'stable','repo'=>'pos-desktop','tag'=>'v0.0.1','inventory_sha256'=>hash('sha256',$targetRaw),'inventory'=>$target];
$carrier=['tag_name'=>'v'.$source['version'],'draft'=>false];$verifier=new ChannelTransitionManifestService($signatureService);$entries=[];
foreach (['delta','full'] as $type) {
    $files=$target['files'];
    if ($type==='delta') $files=array_filter($files,static fn(array $file,string $path):bool=>($source['files'][$path]??null)!==$file,ARRAY_FILTER_USE_BOTH);
    $stem='transition-'.$source['version'].'-to-stable-0.0.1-'.$type;
    $zip=new ZipArchive();if ($zip->open($output.'/'.$stem.'.zip',ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Transition ZIP already exists or cannot be created.');
    foreach ($files as $path=>$file) if (!$zip->addFile($options['target-root'].'/'.$path,$path)) throw new RuntimeException('Cannot package target artifact.');
    if (!$zip->close()) throw new RuntimeException('Cannot finalize transition ZIP.');
    $descriptor=['format'=>1,'operation'=>'channel_transition','carrier_tag'=>$carrier['tag_name'],'from_version'=>$source['version'],'from_channel'=>'beta','source_inventory_sha256'=>$installed['inventory_sha256'],'source_inventory'=>$source,'version'=>'0.0.1','channel'=>'stable','target_repo'=>'pos-desktop','target_tag'=>'v0.0.1','target_inventory_sha256'=>$destination['inventory_sha256'],'target_inventory'=>$target,'shell_sha256'=>$shellDigest,'type'=>$type,'files'=>array_map(static fn(string $path):array=>['path'=>$path,...$files[$path]],array_keys($files)),'deleted_files'=>array_keys(array_diff_key($source['files'],$target['files'])),'migrations'=>[],'package'=>['asset'=>$stem.'.zip','sha256'=>hash_file('sha256',$output.'/'.$stem.'.zip'),'size'=>filesize($output.'/'.$stem.'.zip')],'data_compatibility'=>$contract];
    $raw=json_encode($descriptor,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    $signature=$signatureService->signData($raw,$key);
    $checked=$verifier->verify($raw,$signature,$carrier,$installed,$destination);
    if (!$checked['ok']) throw new RuntimeException('Transition descriptor verification failed: '.$checked['reason_code']);
    file_put_contents($output.'/'.$stem.'.json',$raw);file_put_contents($output.'/'.$stem.'.sig',$signature);
    $entries[]=['from_version'=>$source['version'],'source_inventory_sha256'=>$installed['inventory_sha256'],'version'=>'0.0.1','channel'=>'stable','descriptor'=>$stem.'.json','signature'=>$stem.'.sig','descriptor_sha256'=>hash('sha256',$raw),'type'=>$type];
}
$index=json_encode(['format'=>1,'carrier_tag'=>$carrier['tag_name'],'entries'=>$entries],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
$indexSignature=$signatureService->signData($index,$key);
if (!$verifier->verifyIndex($index,$indexSignature,$carrier)['ok']) throw new RuntimeException('Transition index verification failed.');
file_put_contents($output.'/channel-transitions.json',$index);file_put_contents($output.'/channel-transitions.sig',$indexSignature);
echo "PASS signed beta carrier: exact original stable artifacts, changed-only delta and complete application bundle; no Setup or SQL payload\n";
