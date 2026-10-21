<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\{ChannelTransitionManifestService,ManifestSignatureService,UpdateManifestService,UpdateDataCompatibilityService};
function manifestAssert(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$lab=sys_get_temp_dir().'/pos-transition-signature-'.bin2hex(random_bytes(6));mkdir($lab);
$key=openssl_pkey_new(['private_key_bits'=>2048]);file_put_contents($lab.'/public.pem',openssl_pkey_get_details($key)['key']);
$sign=static function(array $data)use($key):array{$raw=json_encode($data);openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);return [$raw,base64_encode($signature)];};
$file=static fn(string $bytes):array=>['sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes)];
$source=['version'=>'0.0.10-beta.5','files'=>['version.json'=>$file('beta5'),'frontend/dist/new.js'=>$file('new'),'frontend/dist/keep.js'=>$file('keep')]];
$targetInventory=['version'=>'0.0.1','files'=>['version.json'=>$file('stable1'),'frontend/dist/old.js'=>$file('old'),'frontend/dist/keep.js'=>$file('keep')]];
$sourceHash=hash('sha256',json_encode($source));$targetHash=hash('sha256',json_encode($targetInventory));
$installed=['version'=>'0.0.10-beta.5','channel'=>'beta','shell_sha256'=>str_repeat('a',64),'inventory_sha256'=>$sourceHash,'inventory'=>$source];
$target=['version'=>'0.0.1','channel'=>'stable','repo'=>'pos-desktop','tag'=>'v0.0.1','inventory_sha256'=>$targetHash,'inventory'=>$targetInventory];
$descriptor=['format'=>1,'operation'=>'channel_transition','carrier_tag'=>'v0.0.10-beta.5','from_version'=>$installed['version'],'from_channel'=>'beta','source_inventory_sha256'=>$sourceHash,'source_inventory'=>$source,'version'=>'0.0.1','channel'=>'stable','target_repo'=>'pos-desktop','target_tag'=>'v0.0.1','target_inventory_sha256'=>$targetHash,'target_inventory'=>$targetInventory,'shell_sha256'=>str_repeat('a',64),'type'=>'delta','files'=>[['path'=>'version.json',...$file('stable1')],['path'=>'frontend/dist/old.js',...$file('old')]],'deleted_files'=>['frontend/dist/new.js'],'migrations'=>[],'package'=>['asset'=>'transition-to-stable.zip','sha256'=>str_repeat('b',64),'size'=>123],'data_compatibility'=>['format'=>1,'metadata_migration'=>UpdateDataCompatibilityService::METADATA_MIGRATION,'schema_sha256'=>str_repeat('c',64),'ledger_sha256'=>str_repeat('d',64)]];
$service=new ChannelTransitionManifestService(new ManifestSignatureService($lab.'/public.pem'));
$carrier=['tag_name'=>'v0.0.10-beta.5','draft'=>false];
[$raw,$signature]=$sign($descriptor);manifestAssert($service->verify($raw,$signature,$carrier,$installed,$target)['ok'],'Valid explicit downgrade rejected');
manifestAssert(!$service->verify($raw.' ',$signature,$carrier,$installed,$target)['ok'],'Tampered signature accepted');
$otherKey=openssl_pkey_new(['private_key_bits'=>2048]);openssl_sign($raw,$wrongSignature,$otherKey,OPENSSL_ALGO_SHA256);
manifestAssert(!$service->verify($raw,base64_encode($wrongSignature),$carrier,$installed,$target)['ok'],'Wrong signing authority accepted');
foreach(['carrier_tag'=>'v0.0.10-beta.4','target_tag'=>'v0.0.2','target_repo'=>'another-repo','from_version'=>'0.0.10-beta.4','shell_sha256'=>str_repeat('e',64),'from_channel'=>'rc','source_inventory_sha256'=>str_repeat('f',64)] as $field=>$value){[$bad,$sig]=$sign(array_replace($descriptor,[$field=>$value]));manifestAssert(!$service->verify($bad,$sig,$carrier,$installed,$target)['ok'],'Invalid binding accepted: '.$field);}
$bad=$descriptor;$bad['files'][]=$bad['files'][0];[$raw,$sig]=$sign($bad);manifestAssert(!$service->verify($raw,$sig,$carrier,$installed,$target)['ok'],'Duplicate payload path accepted');
$bad=$descriptor;$bad['deleted_files'][]='backend/storage/customer.db';[$raw,$sig]=$sign($bad);manifestAssert(!$service->verify($raw,$sig,$carrier,$installed,$target)['ok'],'Protected path accepted');
$bad=$descriptor;$bad['files'][0]['sha256']=str_repeat('1',64);[$raw,$sig]=$sign($bad);manifestAssert(!$service->verify($raw,$sig,$carrier,$installed,$target)['ok'],'Wrong target bytes accepted');
$full=$descriptor;$full['type']='full';$full['files']=array_map(static fn($p)=>['path'=>$p,...$targetInventory['files'][$p]],array_keys($targetInventory['files']));[$raw,$sig]=$sign($full);manifestAssert($service->verify($raw,$sig,$carrier,$installed,$target)['ok'],'Compatible complete application bundle rejected');
$incomplete=$full;array_pop($incomplete['files']);[$raw,$sig]=$sign($incomplete);manifestAssert(!$service->verify($raw,$sig,$carrier,$installed,$target)['ok'],'Incomplete full bundle accepted');
$alias=$descriptor;$alias['target_inventory']['files']['frontend/dist/KEEP.js']=$file('keep');[$raw,$sig]=$sign($alias);manifestAssert(!$service->verify($raw,$sig,$carrier,$installed,$target)['ok'],'Windows case alias accepted');
manifestAssert(!(new UpdateManifestService())->checkVersionCompatibility('0.0.10-beta.5',['version'=>'0.0.1'])['compatible'],'Ordinary downgrade bypass introduced');
unlink($lab.'/public.pem');rmdir($lab);
echo "PASS transition signatures: exact source/carrier/target/shell bindings, delta/full inventory sets, protected paths and strict ordinary downgrade policy\n";
