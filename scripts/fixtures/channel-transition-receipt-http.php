<?php
declare(strict_types=1);
require __DIR__.'/../../backend/vendor/autoload.php';
use App\Services\{ChannelTransitionReceiptService,DeltaUpdateService,UpdateManifestService,UpdateOperationLock};
$plan=json_decode(file_get_contents((string)getenv('POS_RECEIPT_LAB_PLAN')),true,32,JSON_THROW_ON_ERROR);
$delta=new DeltaUpdateService(new UpdateManifestService(),$plan['root'],$plan['storage']);
$delta->setUpdateState('desktop_handoff_pending',['operation'=>'channel_transition','backup_snapshot'=>$plan['snapshot_path'],'to_version'=>$plan['target_version']]);
$lease=empty($plan['hold_lease'])?null:(new UpdateOperationLock($plan['storage']))->acquire('receipt_fixture');
$receipt=(new ChannelTransitionReceiptService($plan['root'],$plan['storage']))->start($plan);
header('Content-Type: application/json');echo json_encode([...$receipt,'lease'=>$lease],JSON_THROW_ON_ERROR);
