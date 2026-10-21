<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\ChannelTransitionReceiptService;
function receiptAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$lab=sys_get_temp_dir().'/pos-transition-receipt-'.bin2hex(random_bytes(6));mkdir($lab);mkdir($lab.'/storage');
file_put_contents($lab.'/version.json','old');
$inventory=['files'=>['version.json'=>['sha256'=>hash('sha256','old'),'size'=>3]]];
$service=new ChannelTransitionReceiptService($lab,$lab.'/storage');
receiptAssert($service->isTargetHealthy('{"status":"ok","critical_failed":false,"version":"0.0.1"}','0.0.1'),'Target health refused');
receiptAssert(!$service->isTargetHealthy('{"status":"ok","version":"0.0.10-beta.5"}','0.0.1'),'Old process health accepted as target');
receiptAssert(!$service->isTargetHealthy('not JSON','0.0.1'),'Invalid health accepted');
receiptAssert($service->verifyFiles($inventory,[]),'Exact target refused');
receiptAssert(!$service->verifyFiles($inventory,['version.json']),'Removed artifact still exists');
file_put_contents($lab.'/version.json','new');receiptAssert(!$service->verifyFiles($inventory,[]),'Wrong target accepted');
receiptAssert(!$service->verifyFiles(['files'=>['../outside'=>['sha256'=>hash('sha256','old'),'size'=>3]]],[]),'Outside path accepted');
file_put_contents($lab.'/version.json','old');mkdir($lab.'/frontend',0700);mkdir($lab.'/frontend/dist',0700);file_put_contents($lab.'/frontend/dist/orphan.js','leftover');
receiptAssert(!$service->verifyFiles($inventory,[]),'Unlisted newer application artifact accepted');
unlink($lab.'/frontend/dist/orphan.js');rmdir($lab.'/frontend/dist');rmdir($lab.'/frontend');
receiptAssert($service->read('../outside')['state']==='unknown','Unsafe receipt ID read');
unlink($lab.'/version.json');rmdir($lab.'/storage');rmdir($lab);
echo "PASS transition receipt rejects wrong, lingering and unsafe target files\n";
