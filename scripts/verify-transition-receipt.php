<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\ChannelTransitionReceiptService;
function receiptAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$lab=sys_get_temp_dir().'/pos-transition-receipt-'.bin2hex(random_bytes(6));mkdir($lab);mkdir($lab.'/storage');
file_put_contents($lab.'/version.json','old');
$inventory=['files'=>['version.json'=>['sha256'=>hash('sha256','old'),'size'=>3]]];
$service=new ChannelTransitionReceiptService($lab,$lab.'/storage');
receiptAssert($service->verifyFiles($inventory,[]),'Exact target refused');
receiptAssert(!$service->verifyFiles($inventory,['version.json']),'Removed artifact still exists');
file_put_contents($lab.'/version.json','new');receiptAssert(!$service->verifyFiles($inventory,[]),'Wrong target accepted');
receiptAssert(!$service->verifyFiles(['files'=>['../outside'=>['sha256'=>hash('sha256','old'),'size'=>3]]],[]),'Outside path accepted');
receiptAssert($service->read('../outside')['state']==='unknown','Unsafe receipt ID read');
unlink($lab.'/version.json');rmdir($lab.'/storage');rmdir($lab);
echo "PASS transition receipt rejects wrong, lingering and unsafe target files\n";
