<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
$lab=sys_get_temp_dir().'/pos-delete-failure-'.bin2hex(random_bytes(6));
mkdir($lab.'/root/frontend/dist/obsolete.js',0700,true);mkdir($lab.'/payload/frontend/dist',0700,true);
$root=$lab.'/root';file_put_contents($root.'/version.json','{"version":"0.0.1"}');file_put_contents($root.'/frontend/dist/index.html','old');
file_put_contents($lab.'/payload/frontend/dist/index.html','new');
$manifest=['version'=>'0.0.2','files'=>[['path'=>'frontend/dist/index.html','sha256'=>hash('sha256','new'),'size'=>3]],'deleted_files'=>['frontend/dist/obsolete.js']];
putenv('APP_STORAGE_DIR='.$lab.'/state');$delta=new App\Services\DeltaUpdateService(null,$root,$lab.'/state');
$stage=$delta->stageFromLocalFiles($manifest,$lab.'/payload');$snapshot=$delta->createBackupSnapshot('0.0.1','0.0.2',$manifest);
$result=$delta->applyStagedFiles($manifest,$snapshot['snapshot_path']);
if($result['ok']||file_get_contents($root.'/frontend/dist/index.html')!=='old')throw new RuntimeException('Deletion failure reported success or left replaced files');
echo "PASS failed artifact deletion triggers failure and restores earlier file replacements\n";
