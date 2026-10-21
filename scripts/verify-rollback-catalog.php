<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\{DeltaUpdateService, UpdateRollbackPlanner};

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
if (isset($argv[1])) {
    [$script,$root,$storage,$target] = $argv;
    putenv('APP_STORAGE_DIR='.$storage);
    $delta=new DeltaUpdateService(null,$root,$storage);
    $planner=new UpdateRollbackPlanner($delta);
    $available=$planner->availableSnapshots();
    check(array_column($available,'from_version')===($target==='none'?[]:[$target]),'Incorrect destinations after rollback');
    if ($target==='none') { echo json_encode(['ok'=>true]); exit; }
    $prepared=$planner->prepare($available[0]['snapshot_path']);
    check($prepared['ok'],'Second preparation failed');
    check($delta->prepareDesktopHandoff($prepared['manifest'],$prepared['safety_snapshot'])['ok'],'Second handoff failed');
    echo json_encode($prepared); exit;
}
$lab=sys_get_temp_dir().'/pos-catalog-'.bin2hex(random_bytes(6));
$root=$lab.'/root';$storage=$lab.'/state';
mkdir($root.'/backend',0700,true);mkdir($root.'/frontend/dist',0700,true);mkdir($storage,0700,true);
putenv('APP_STORAGE_DIR='.$storage);
$versions=['0.0.1','0.0.10-beta.1','0.0.10-beta.2'];
$write=function(int $n)use($root,$versions):void{
    file_put_contents($root.'/backend/backend.phar','phar-'.$n);
    file_put_contents($root.'/version.json',json_encode(['version'=>$versions[$n]]));
    file_put_contents($root.'/frontend/dist/feature-'.$n.'.js','feature-'.$n);
};
$write(0);file_put_contents($root.'/frontend/dist/keep.js','unchanged');
file_put_contents($storage.'/business.db','preserved customer fixture');
$delta=new DeltaUpdateService(null,$root,$storage);$planner=new UpdateRollbackPlanner($delta);
check($planner->availableSnapshots()===[],'Fresh install offers rollback');
$snapshots=[];
for($n=1;$n<=2;$n++) {
    $inventory=[];
    foreach (['backend/backend.phar','version.json','frontend/dist/keep.js','frontend/dist/feature-'.($n-1).'.js'] as $path) $inventory[$path]=hash_file('sha256',$root.'/'.$path);
    $manifest=['baseline_files'=>$inventory,'files'=>[['path'=>'backend/backend.phar'],['path'=>'version.json'],['path'=>'frontend/dist/feature-'.$n.'.js']],
        'deleted_files'=>['frontend/dist/feature-'.($n-1).'.js']];
    $snapshot=$delta->createBackupSnapshot($versions[$n-1],$versions[$n],$manifest);
    check($snapshot['ok'],'Cannot create fixture snapshot');$snapshots[]=$snapshot['snapshot_path'];
    unlink($root.'/frontend/dist/feature-'.($n-1).'.js');$write($n);
}
$available=$planner->availableSnapshots();
check(array_column($available,'from_version')===[$versions[1]],'Historical or future destination advertised');
file_put_contents($root.'/frontend/dist/keep.js','unexpected drift');
check($planner->availableSnapshots()===[],'Mixed target inventory advertised');
check(!$planner->prepare($snapshots[1])['ok'],'Mixed target inventory accepted');
file_put_contents($root.'/frontend/dist/keep.js','unchanged');
$before=glob($delta->getBackupsDir().'/*');$planner->availableSnapshots();
check(glob($delta->getBackupsDir().'/*')===$before,'Listing creates backup points');
$metadata=file_get_contents($snapshots[1].'/metadata.json');
foreach ([['timestamp'=>[]],['db_backup_path'=>'database.sql'],['to_version'=>$versions[0]],['from_version'=>$versions[2]],['from_version'=>'0.0.10-beta.9']] as $change) {
    $bad=array_replace(json_decode($metadata,true),$change);file_put_contents($snapshots[1].'/metadata.json',json_encode($bad));
    check($planner->availableSnapshots()===[],'Invalid metadata advertised: '.json_encode($change));
    check(!$planner->prepare($snapshots[1])['ok'],'Invalid metadata accepted');
}
file_put_contents($snapshots[1].'/metadata.json',$metadata);
$duplicate=$delta->getBackupsDir().'/patch_duplicate';mkdir($duplicate.'/files',0700,true);
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($snapshots[1].'/files',FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile()) continue;
    $dest=$duplicate.'/files/'.substr($file->getPathname(),strlen($snapshots[1].'/files')+1);
    if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);copy($file->getPathname(),$dest);
}
file_put_contents($duplicate.'/metadata.json',$metadata);
check(count($planner->availableSnapshots())===1,'Duplicate versions advertised');
$bad=json_decode($metadata,true);$bad['timestamp']=[];file_put_contents($duplicate.'/metadata.json',json_encode($bad));
check(count($planner->availableSnapshots())===1,'Malformed duplicate breaks catalog');
$bad=json_decode($metadata,true);$bad['purpose']='rollback_safety';file_put_contents($snapshots[1].'/metadata.json',json_encode($bad));
check($planner->availableSnapshots()===[],'Internal safety point advertised');
check(!$planner->prepare($snapshots[1])['ok'],'Internal safety point accepted by API');
file_put_contents($snapshots[1].'/metadata.json',$metadata);
$backup=$snapshots[1].'/files/backend/backend.phar';$bytes=file_get_contents($backup);file_put_contents($backup,'corrupt');
check($planner->availableSnapshots()===[],'Corrupt backup advertised');file_put_contents($backup,$bytes);
$prepared=$planner->prepare($snapshots[1]);check($prepared['ok'],'Prepare failed');
$safety=json_decode(file_get_contents($prepared['safety_snapshot'].'/metadata.json'),true);
check(($safety['purpose']??null)==='rollback_safety','Safety point is not classified');
check(count($planner->availableSnapshots())===1,'Preparing rollback polluted destinations');
$handoff=$delta->prepareDesktopHandoff($prepared['manifest'],$prepared['safety_snapshot']);check($handoff['ok'],'Handoff failed');
echo json_encode(['ok'=>true,'root'=>$root,'storage'=>$storage,'version'=>$versions[1]]);
