<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\ChannelTransitionPreviewStore;
function previewAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$binding=['source'=>'sha1','target'=>'sha2','channel'=>'stable','schema'=>'sha3'];
if(($argv[1]??'')==='claim') {
    echo json_encode((new ChannelTransitionPreviewStore($argv[2],static fn():int=>1000))->claim($argv[3],7,$binding));exit;
}
$root=sys_get_temp_dir().'/pos-transition-preview-'.bin2hex(random_bytes(6));
$clock=1000;$store=new ChannelTransitionPreviewStore($root,static function()use(&$clock):int{return $clock;});
$preview=$store->issue(7,$binding);
previewAssert((bool)preg_match('/^[a-f0-9]{64}$/',$preview['preview_id']),'Preview ID is not unguessable');
previewAssert($preview['expires_at']===1600,'Preview TTL is not 600 seconds');
previewAssert(!$store->claim('',7,$binding)['ok'],'Empty preview accepted');
previewAssert(!$store->claim('../outside',7,$binding)['ok'],'Path ID accepted');
previewAssert(!$store->claim($preview['preview_id'],8,$binding)['ok'],'Wrong owner accepted');
previewAssert(!$store->claim($preview['preview_id'],7,[...$binding,'target'=>'changed'])['ok'],'Changed target accepted');
previewAssert($store->claim($preview['preview_id'],7,$binding)['ok'],'Matching preview refused');
previewAssert(!$store->claim($preview['preview_id'],7,$binding)['ok'],'Preview reused');
$preview=$store->issue(7,$binding);$clock=1600;
previewAssert(!$store->claim($preview['preview_id'],7,$binding)['ok'],'Expired preview accepted');
$clock=1000;$preview=$store->issue(7,$binding);$workers=[];
for($i=0;$i<2;$i++){
    $process=proc_open([PHP_BINARY,__FILE__,'claim',$root,$preview['preview_id']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    previewAssert(is_resource($process),'Worker did not start');fclose($pipes[0]);$workers[]=[$process,$pipes];
}
$wins=0;
foreach($workers as [$process,$pipes]){
    $result=json_decode(stream_get_contents($pipes[1]),true);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
    previewAssert(proc_close($process)===0,'Claim worker failed: '.$errors);$wins+=(int)($result['ok']??false);
}
previewAssert($wins===1,'Concurrent preview was consumed more than once');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);
echo "PASS preview ownership, exact binding, 600-second expiry, path rejection and two-process single consumption\n";
