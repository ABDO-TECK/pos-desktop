<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\Database;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Throwable;

/** Independent post-start verification survives replacing the deployed backend. */
final class ChannelTransitionReceiptService
{
    public function __construct(private string $root, private string $storage) {}

    public function isTargetHealthy(string $response, string $version): bool
    {
        $health=json_decode($response,true);
        return is_array($health) && ($health['status']??null)==='ok' && ($health['critical_failed']??true)===false && ($health['version']??null)===$version;
    }

    public function verifyFiles(array $inventory, array $deleted): bool
    {
        $validator = new UpdateManifestService();
        if (empty($inventory['files']) || !$validator->validateManifestPaths(['baseline_files'=>array_map(static fn(array $file):string=>$file['sha256'], $inventory['files']),'deleted_files'=>$deleted],$this->root)['ok']) return false;
        foreach ($inventory['files'] as $relative=>$file) {
            $path=$this->root.'/'.$relative;
            if (is_link($path) || !is_file($path) || filesize($path)!==$file['size'] || !hash_equals($file['sha256'],hash_file('sha256',$path))) return false;
        }
        foreach ($deleted as $relative) if (file_exists($this->root.'/'.$relative)) return false;
        $dist=$this->root.'/frontend/dist';
        if (is_link($dist)) return false;
        if (is_dir($dist)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dist,FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) return false;
            if (!$file->isFile() || $file->getFilename()==='digital-certificate.txt') continue;
            $relative='frontend/dist/'.substr(str_replace('\\','/',$file->getPathname()),strlen(str_replace('\\','/',$dist))+1);
            if (!isset($inventory['files'][$relative])) return false;
        }
        return true;
    }

    public function read(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) return ['state'=>'unknown'];
        $file=$this->storage.'/channel-transition-receipts/'.$id.'.json';
        if (is_link($file)) return ['state'=>'unknown'];
        $receipt=json_decode((string)@file_get_contents($file),true);
        return is_array($receipt)?$receipt:['state'=>'unknown'];
    }

    public function start(array $plan): array
    {
        $id=bin2hex(random_bytes(32));
        $directory=$this->storage.'/channel-transition-receipts';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory,0700,true))) throw new RuntimeException('Receipt storage unavailable.');
        $source=$plan['source_phar'];
        if (!is_file($source) || is_link($source)) throw new RuntimeException('Retained source verifier unavailable.');
        $plan=[...$plan,'receipt_id'=>$id,'root'=>$this->root,'storage'=>$this->storage,'source_sha256'=>hash_file('sha256',$source)];
        $planPath=$directory.'/'.$id.'.plan.json';
        $this->write($planPath,$plan);
        $script=$directory.'/'.$id.'.php';
        if (!copy(__DIR__.'/../cli/verify-channel-transition.php',$script)) throw new RuntimeException('Cannot retain verifier launcher.');
        $this->write($directory.'/'.$id.'.json',['state'=>'pending','receipt_id'=>$id,'updated_at'=>date(DATE_ATOM)]);
        $delta=new DeltaUpdateService(new UpdateManifestService(),$this->root,$this->storage);
        $delta->setUpdateState('desktop_handoff_pending',['transition_receipt'=>$id,'transition_verified'=>false]);
        $quote=static fn(string $value):string=>"'".str_replace("'","''",$value)."'";
        $ini=php_ini_loaded_file();
        $arguments=($ini?'-c "'.$ini.'" ':'').'-d auto_prepend_file= "'.$script.'" "'.$planPath.'"';
        $command='Start-Process -WindowStyle Hidden -FilePath '.$quote(PHP_BINARY).' -ArgumentList '.$quote($arguments);
        $process=proc_open(['powershell.exe','-NoProfile','-NonInteractive','-EncodedCommand',base64_encode(iconv('UTF-8','UTF-16LE',$command))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Independent verifier did not launch.');
        fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
        if (proc_close($process)!==0) throw new RuntimeException('Independent verifier launch failed.');
        $deadline=microtime(true)+5;
        do {
            if (($this->read($id)['state']??'')==='observing') return ['receipt_id'=>$id];
            usleep(100000);
        } while (microtime(true)<$deadline);
        throw new RuntimeException('Independent verifier did not acknowledge readiness.');
    }

    public function observe(array $plan): void
    {
        $path=$this->storage.'/channel-transition-receipts/'.$plan['receipt_id'].'.json';
        $this->write($path,['state'=>'observing','receipt_id'=>$plan['receipt_id'],'updated_at'=>date(DATE_ATOM)]);
        $delta=new DeltaUpdateService(new UpdateManifestService(),$this->root,$this->storage);
        $deadline=microtime(true)+240;
        $result=['state'=>'failed','reason_code'=>'handoff_timeout','requires_recovery'=>true];
        $verificationLock=new UpdateOperationLock($this->storage);
        $locked=false;
        try {
        do {
            $state=$delta->getUpdateState();
            if (!$this->ownsState($state,$plan)) {
                $result=['state'=>'failed','reason_code'=>'operation_superseded','requires_recovery'=>false];break;
            }
            if (in_array($state['state']??null,['completed','failed','rolled_back','database_recovery_failed'],true) && !$locked) {
                $lease=$verificationLock->acquire('channel_transition_verify',['receipt_id'=>$plan['receipt_id']]);
                if (!$lease['acquired']) { usleep(250000);continue; }
                $locked=true;
                $state=$delta->getUpdateState();
                if (!$this->ownsState($state,$plan)) { $result=['state'=>'failed','reason_code'=>'operation_superseded','requires_recovery'=>false];break; }
            }
            if (($state['to_version']??null)===$plan['target_version']) {
                if (in_array($state['state']??null,['failed','rolled_back','database_recovery_failed'],true)) {
                    try { $sourceMatches=is_array($plan['source_inventory']??null) && $this->verifyFiles($plan['source_inventory'],$plan['introduced_files']??[]); }
                    catch (Throwable) { $sourceMatches=false; }
                    $result=['state'=>'failed','reason_code'=>'handoff_failed','source_inventory_matches'=>$sourceMatches,'requires_recovery'=>!$sourceMatches];break;
                }
                if (($state['state']??null)==='completed') {
                    try {
                        if (!$this->verifyFiles($plan['target_inventory'],$plan['deleted_files'])) throw new RuntimeException('target_inventory_mismatch');
                        $port=filter_var(getenv('API_PORT'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
                        if (!$port) throw new RuntimeException('health_endpoint_unavailable');
                        $health=@file_get_contents('http://127.0.0.1:'.$port.'/api/health',false,stream_context_create(['http'=>['timeout'=>3]]));
                        if ($health===false || !$this->isTargetHealthy($health,$plan['target_version'])) { usleep(250000);continue; }
                        $assessment=(new UpdateDataCompatibilityService())->assess(Database::getInstance(),$plan['data_compatibility'],true);
                        if (!$assessment['compatible']) throw new RuntimeException($assessment['reason_code']);
                        $result=['state'=>'completed','reason_code'=>null,'target_inventory_matches'=>true,'data_compatible'=>true,'requires_recovery'=>false];
                    } catch (Throwable $error) {
                        $result=['state'=>'failed','reason_code'=>$error->getMessage(),'requires_recovery'=>true];
                    }
                    break;
                }
            }
            usleep(250000);
        } while (microtime(true)<$deadline);
        if (($result['reason_code']??null)!=='operation_superseded' && !$locked) $locked=$verificationLock->acquire('channel_transition_verify',['receipt_id'=>$plan['receipt_id']])['acquired'];
        if (!$this->ownsState($delta->getUpdateState(),$plan)) $result=['state'=>'failed','reason_code'=>'operation_superseded','requires_recovery'=>false];
        $this->write($path,[...$result,'receipt_id'=>$plan['receipt_id'],'updated_at'=>date(DATE_ATOM)]);
        if (($result['reason_code']??null)==='operation_superseded' || !$locked) return;
        if ($result['state']!=='completed') $delta->setUpdateState('failed',['operation'=>'channel_transition','transition_receipt'=>$plan['receipt_id'],'error'=>'Channel transition verification failed: '.$result['reason_code'],'requires_recovery'=>$result['requires_recovery']]);
        else $delta->setUpdateState('completed',['operation'=>'channel_transition','transition_receipt'=>$plan['receipt_id'],'transition_verified'=>true,'requires_recovery'=>false,'error'=>null]);
        } finally { if ($locked) $verificationLock->release(); }
    }

    private function ownsState(?array $state, array $plan): bool
    {
        return ($state['transition_receipt']??null)===$plan['receipt_id']
            && ($state['backup_snapshot']??null)===$plan['snapshot_path']
            && ($state['to_version']??null)===$plan['target_version']
            && in_array($state['operation']??null,['channel_transition','electron_delta_install'],true);
    }

    private function write(string $path, array $value): void
    {
        $temporary=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($temporary,json_encode($value,JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($temporary,$path)) throw new RuntimeException('Cannot publish transition receipt.');
    }
}
