<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\Database;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Coordinates explicit channel changes through the existing file handoff engine. */
final class ChannelTransitionService
{
    private DeltaUpdateService $delta;
    private GitHubReleaseProvider $provider;
    private ChannelTransitionManifestService $manifests;
    private ChannelTransitionPreviewStore $previews;
    private ChannelTransitionReceiptService $receipts;
    private UpdateDataCompatibilityService $compatibility;

    public function __construct(private UpdateService $updates, private BackupService $backup, private ?PDO $db = null, ?ManifestSignatureService $signatures = null)
    {
        $this->delta=$updates->getDeltaUpdateService();
        $this->provider=$updates->getGitHubProvider();
        $this->manifests=new ChannelTransitionManifestService($signatures??new ManifestSignatureService());
        $this->previews=new ChannelTransitionPreviewStore($this->delta->getStorageDir().'/channel-transition-previews');
        $this->receipts=new ChannelTransitionReceiptService($this->delta->getRootDir(),$this->delta->getStorageDir());
        $this->compatibility=new UpdateDataCompatibilityService();
    }

    public function preview(int $userId, string $selectedChannel): array
    {
        $context=$this->resolve($selectedChannel);
        $public=$context['preview'];
        if ($public['status']==='ready') $public=[...$public,...$this->previews->issue($userId,$context['binding'])];
        return $public;
    }

    public function prepare(int $userId, string $previewId, bool $deltaCapable): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$previewId)) return $this->failure('preview_expired_or_changed');
        if (!$deltaCapable) return $this->failure('desktop_handoff_required',426);
        $lock=new UpdateOperationLock($this->delta->getStorageDir());
        if (UpdateOperationLock::isActiveState($this->delta->getUpdateState())) return $this->failure('update_operation_busy');
        $lease=$lock->acquire('channel_transition');
        if (!$lease['acquired']) return $this->failure($lease['reason_code']??'update_operation_busy');
        $timeLimit=(int)ini_get('max_execution_time');
        try {
            if ($timeLimit>0 && $timeLimit<1200) set_time_limit(1200);
            $context=$this->resolve($this->updates->getClientChannel());
            if ($context['preview']['status']!=='ready') return $this->failure($context['preview']['reason_code']??'transition_unavailable');
            if (!$this->previews->claim($previewId,$userId,$context['binding'])['ok']) return $this->failure('preview_expired_or_changed');
            $descriptor=$context['descriptor'];
            $this->delta->setUpdateState('downloading',['operation'=>'channel_transition','owner_id'=>$lease['owner_id'],'to_version'=>$descriptor['version'],'transition_receipt'=>null,'transition_verified'=>false,'requires_recovery'=>false,'error'=>null,'backup_snapshot'=>null,'handoff_plan'=>null]);
            if (!$this->delta->checkDiskSpace(max(104857600,$descriptor['package']['size']*3+filesize($this->delta->getRootDir().'/backend/backend.phar')*2))['ok']) throw new RuntimeException('insufficient_disk_space');
            $manifest=$this->handoffManifest($descriptor);
            $this->stage($descriptor,$context['package']);
            $fresh=$this->resolve($this->updates->getClientChannel());
            if ($fresh['preview']['status']!=='ready' || ($fresh['binding']??null)!==$context['binding']) throw new RuntimeException('preview_expired_or_changed');
            // Recheck the schema and every source byte after potentially slow network I/O.
            $compatCheck = $this->compatibility->assess($this->database(),$descriptor['data_compatibility'],version_compare($descriptor['version'],$descriptor['from_version'],'<'));
            if (!$compatCheck['compatible']) {
                \App\Helpers\Logger::warning('Channel transition prepare data assessment blocked', [
                    'reason_code' => $compatCheck['reason_code'],
                    'error' => $compatCheck['error'] ?? null,
                ]);
                throw new RuntimeException('data_schema_incompatible');
            }
            if (!(new UpdateManifestService())->verifyBaselineFiles($this->delta->getRootDir(),$manifest)) throw new RuntimeException('source_inventory_mismatch');
            $recovery=(new MigrationSafetyBackupService($this->backup,$this->delta->getStorageDir()))->createMigrationSafetyBackup($descriptor['from_version'],$descriptor['version'],'transition-'.bin2hex(random_bytes(8)));
            if (!$recovery['ok']) throw new RuntimeException('manual_recovery_backup_failed');
            // Retain SQL for manual recovery only: never attach automatic SQL restore to a files-only transition.
            $snapshot=$this->delta->createBackupSnapshot($descriptor['from_version'],$descriptor['version'],$manifest,null,'rollback_safety');
            if (!$snapshot['ok']) throw new RuntimeException('file_safety_backup_failed');
            $source=$snapshot['snapshot_path'].'/transition-verifier.phar';
            if (!copy($this->delta->getRootDir().'/backend/backend.phar',$source)) throw new RuntimeException('retained_verifier_failed');
            $handoff=$this->delta->prepareDesktopHandoff($manifest,$snapshot['snapshot_path']);
            if (!$handoff['ok']) throw new RuntimeException($handoff['error']);
            $receipt=$this->receipts->start(['source_phar'=>$source,'snapshot_path'=>$snapshot['snapshot_path'],'source_inventory'=>$descriptor['source_inventory'],'introduced_files'=>array_keys(array_diff_key($descriptor['target_inventory']['files'],$descriptor['source_inventory']['files'])),'target_version'=>$descriptor['version'],'target_inventory'=>$descriptor['target_inventory'],'deleted_files'=>$descriptor['deleted_files'],'data_compatibility'=>$descriptor['data_compatibility']]);
            return ['ok'=>true,'code'=>200,'requires_desktop_handoff'=>true,'handoff_version'=>$handoff['version'],'receipt_id'=>$receipt['receipt_id'],'logs'=>['تم التحقق من توقيع الحزمة والملفات وتوافق البيانات.','تم الاحتفاظ بنسخة بيانات للاسترداد اليدوي.','التحديث جاهز لإعادة التشغيل والتحقق النهائي.']];
        } catch (Throwable $error) {
            $this->delta->setUpdateState('failed',['operation'=>'channel_transition','error'=>$error->getMessage()]);
            return $this->failure($error->getMessage(),500);
        } finally {
            $lock->release();
            if ($timeLimit>0 && $timeLimit<1200) set_time_limit($timeLimit);
        }
    }

    public function receipt(string $id): array { return $this->receipts->read($id); }

    private function resolve(string $channel): array
    {
        $version=$this->updates->getLocalVersion()['version']??'0.0.0';
        $installed=['version'=>$version,'channel'=>UpdateChannelPolicy::installedChannel($version),'shell_sha256'=>''];
        $preview=['status'=>'blocked','installed'=>$installed,'selected_channel'=>$channel,'target'=>null,'package_type'=>null,'preview_id'=>null,'expires_at'=>null,'reason_code'=>null,'message'=>''];
        try {
            if (!in_array($channel,UpdateChannelPolicy::active(),true)) throw new RuntimeException('inactive_channel');
            $installed['shell_sha256']=$this->shellDigest();$preview['installed']=$installed;
            if ($installed['channel']==='retired') throw new RuntimeException('retired_installed_release');
            $sourceRepo = 'pos-desktop-updates';
            $source = $this->provider->getReleaseByTag('v'.$version, 'pos-desktop-updates');
            if (!$source['ok']) {
                $httpCode = (int)($source['http_code'] ?? 0);
                $error = (string)($source['error'] ?? '');
                $is404 = ($httpCode === 404) || ($httpCode === 0 && ($error === 'Release is unavailable.' || str_contains(strtolower($error), 'not found')));
                if (!$is404) {
                    throw new RuntimeException('discovery_failed');
                }
                if ($installed['channel'] === 'stable') {
                    $source = $this->provider->getReleaseByTag('v'.$version, 'pos-desktop');
                    $sourceRepo = 'pos-desktop';
                }
            }
            if (!$source['ok']) throw new RuntimeException('source_release_unavailable');
            $rawInventory=$this->content($source['release'],'deployment-inventory.json');
            $inventory=json_decode($rawInventory,true,64,JSON_THROW_ON_ERROR);
            if (($inventory['version']??null)!==$version || empty($inventory['files']['version.json'])) throw new RuntimeException('source_inventory_unavailable');
            $installed=[...$installed,'source_repo'=>$sourceRepo,'repo'=>$sourceRepo,'inventory_sha256'=>hash('sha256',$rawInventory),'inventory'=>$inventory,'release'=>$source['release']];
            if (!$this->receipts->verifyFiles($inventory,[])) throw new RuntimeException('source_inventory_mismatch');
            $catalog=(new ChannelTransitionReleaseCatalog($this->provider))->resolve($channel,$installed);
            if (!$catalog['ok']) { $preview['status']='unreachable';throw new RuntimeException('discovery_failed'); }
            if (!$catalog['target']) { $preview['status']='waiting';throw new RuntimeException('no_target'); }
            $target=$catalog['target'];
            $preview['target']=array_intersect_key($target,array_flip(['version','channel','repo','tag','inventory_sha256']));
            if ($target['version']===$version && $target['inventory_sha256']===$installed['inventory_sha256']) {
                return ['preview'=>[...$preview,'status'=>'same_release','reason_code'=>'same_release','message'=>'النسخة المثبتة هي آخر إصدار للقناة المختارة.']];
            }
            if ($catalog['reason_code'] && $catalog['reason_code'] !== 'transition_package_unavailable') throw new RuntimeException($catalog['reason_code']);
            $carriers = $catalog['carriers'] ?? ($catalog['carrier'] ? [$catalog['carrier']] : []);
            if (!$carriers) throw new RuntimeException('transition_package_unavailable');

            $validCandidates = [];
            $seenAuthority = [];
            $hasShellMismatch = false;

            foreach ($carriers as $carrier) {
                $candidates = $this->validateCarrierEntries($carrier, $installed, $target, $version, $channel, $seenAuthority, $hasShellMismatch);
                foreach ($candidates as $cand) {
                    $validCandidates[] = $cand;
                }
            }

            if (empty($validCandidates)) {
                if ($hasShellMismatch) {
                    throw new RuntimeException('shell_mismatch');
                }
                throw new RuntimeException('transition_package_unavailable');
            }

            return $this->selectCandidate($validCandidates, $installed, $target, $preview, $version);
        } catch (Throwable $error) {
            return ['preview'=>[...$preview,'reason_code'=>$error instanceof RuntimeException?$error->getMessage():'transition_unverifiable','message'=>'تعذر تجهيز انتقال آمن حاليًا. لم تتغير النسخة المثبتة أو بيانات العمل.']];
        }
    }

    private function validateCarrierEntries(
        array $carrier,
        array $installed,
        array $target,
        string $version,
        string $channel,
        array &$seenAuthority,
        bool &$hasShellMismatch
    ): array {
        if (!ChannelTransitionReleaseCatalog::asset($carrier, 'channel-transitions.json') || !ChannelTransitionReleaseCatalog::asset($carrier, 'channel-transitions.sig')) {
            return [];
        }

        $rawIndex = $this->content($carrier, 'channel-transitions.json');
        $rawIndexSig = $this->content($carrier, 'channel-transitions.sig');
        $index = $this->manifests->verifyIndex($rawIndex, $rawIndexSig, $carrier);
        if (!$index['ok']) throw new RuntimeException($index['reason_code']);

        $entries = array_values(array_filter($index['index']['entries'], static fn(array $entry): bool =>
            $entry['from_version'] === $version &&
            $entry['source_inventory_sha256'] === $installed['inventory_sha256'] &&
            $entry['version'] === $target['version'] &&
            $entry['channel'] === $channel
        ));
        if (!$entries) return [];

        $approvedCandidates = [];
        foreach ($entries as $entry) {
            if (!ChannelTransitionReleaseCatalog::asset($carrier, $entry['descriptor']) || !ChannelTransitionReleaseCatalog::asset($carrier, $entry['signature'])) {
                continue;
            }
            $raw = $this->content($carrier, $entry['descriptor']);
            if (!hash_equals($entry['descriptor_sha256'], hash('sha256', $raw))) {
                throw new RuntimeException('descriptor_digest_mismatch');
            }
            $sig = $this->content($carrier, $entry['signature']);

            // Parse descriptor JSON to discover declared shell profile for complete validation
            $descJson = json_decode($raw, true);
            if (!is_array($descJson)) throw new RuntimeException('descriptor_invalid');
            $declaredShell = $descJson['shell_sha256'] ?? null;
            if (!is_string($declaredShell) || !preg_match('/^[a-f0-9]{64}$/D', $declaredShell)) {
                throw new RuntimeException('digest_invalid');
            }

            // Always run complete cryptographic, path, inventory, target, and contract verification against declared profile
            $installedForValidation = $installed;
            $installedForValidation['shell_sha256'] = $declaredShell;
            $verified = $this->manifests->verify($raw, $sig, $carrier, $installedForValidation, $target);
            if (!$verified['ok']) {
                // Fails closed immediately on signature_invalid, path_invalid, target_mismatch, data_contract_invalid, etc.
                throw new RuntimeException($verified['reason_code']);
            }

            $descriptor = $verified['descriptor'];
            if ($descriptor['type'] !== $entry['type']) {
                throw new RuntimeException('package_type_mismatch');
            }

            // Compare canonical signed operation authority across carriers (excluding carrier wrapper)
            $authKey = $this->canonicalOperationKey($descriptor);
            $authDigest = $this->canonicalOperationDigest($descriptor);
            if (isset($seenAuthority[$authKey]) && $seenAuthority[$authKey] !== $authDigest) {
                throw new RuntimeException('index_ambiguous');
            }
            $seenAuthority[$authKey] = $authDigest;

            // Check if this descriptor matches the ACTUAL installed runtime shell
            if (!hash_equals($installed['shell_sha256'], $declaredShell)) {
                $hasShellMismatch = true;
                // Exclude foreign runtime profile from candidates installable on this machine
                continue;
            }

            $package = ChannelTransitionReleaseCatalog::asset($carrier, $descriptor['package']['asset']);
            if (!$package) {
                // Missing package allows legitimate fallback to full bundle or alternate carrier
                continue;
            }

            $approvedCandidates[] = [
                'carrier' => $carrier,
                'entry' => $entry,
                'descriptor' => $descriptor,
                'package' => $package,
                'raw' => $raw,
            ];
        }

        return $approvedCandidates;
    }

    private function canonicalOperationKey(array $descriptor): string
    {
        return ($descriptor['from_version'] ?? '') . '->' . ($descriptor['version'] ?? '') . ':' . ($descriptor['type'] ?? '') . '@' . ($descriptor['shell_sha256'] ?? '');
    }

    private function canonicalOperationDigest(array $descriptor): string
    {
        $files = $descriptor['files'] ?? [];
        $normalizedFiles = [];
        foreach ($files as $file) {
            if (is_array($file)) {
                ksort($file);
                $normalizedFiles[] = $file;
            }
        }
        usort($normalizedFiles, static fn(array $a, array $b): int => strcmp($a['path'] ?? '', $b['path'] ?? ''));

        $deleted = $descriptor['deleted_files'] ?? [];
        sort($deleted);

        $contract = $descriptor['data_compatibility'] ?? [];
        ksort($contract);

        $canonical = [
            'operation' => $descriptor['operation'] ?? 'channel_transition',
            'from_version' => $descriptor['from_version'] ?? '',
            'from_channel' => $descriptor['from_channel'] ?? '',
            'source_inventory_sha256' => $descriptor['source_inventory_sha256'] ?? '',
            'version' => $descriptor['version'] ?? '',
            'channel' => $descriptor['channel'] ?? '',
            'target_repo' => $descriptor['target_repo'] ?? '',
            'target_tag' => $descriptor['target_tag'] ?? '',
            'target_inventory_sha256' => $descriptor['target_inventory_sha256'] ?? '',
            'shell_sha256' => $descriptor['shell_sha256'] ?? '',
            'type' => $descriptor['type'] ?? '',
            'files' => $normalizedFiles,
            'deleted_files' => $deleted,
            'migrations' => $descriptor['migrations'] ?? [],
            'data_compatibility' => $contract,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function selectCandidate(array $candidates, array $installed, array $target, array $preview, string $version): array
    {
        usort($candidates, static fn(array $a, array $b): int => ($a['descriptor']['type'] === 'delta' ? 0 : 1) <=> ($b['descriptor']['type'] === 'delta' ? 0 : 1));
        $selected = $candidates[0];
        $descriptor = $selected['descriptor'];
        $package = $selected['package'];
        $raw = $selected['raw'];

        $assessment = $this->compatibility->assess($this->database(), $descriptor['data_compatibility'], version_compare($target['version'], $version, '<'));
        if (!$assessment['compatible']) {
            \App\Helpers\Logger::warning('Channel transition data assessment blocked', [
                'reason_code' => $assessment['reason_code'],
                'error' => $assessment['error'] ?? null,
            ]);
            throw new RuntimeException($assessment['reason_code']);
        }

        $binding = [
            'installed_version' => $version,
            'source_inventory' => $installed['inventory_sha256'],
            'shell' => $installed['shell_sha256'],
            'channel' => $preview['selected_channel'],
            'target' => $preview['target'],
            'descriptor' => hash('sha256', $raw),
            'schema' => $assessment['schema_sha256'],
            'ledger' => $assessment['ledger_sha256']
        ];

        return [
            'preview' => [
                ...$preview,
                'status' => 'ready',
                'package_type' => $descriptor['type'],
                'reason_code' => null,
                'message' => 'يمكن تثبيت إصدار القناة المختارة بعد تأكيدك. الرجوع يعيد واجهة الإصدار القديم؛ بيانات العمل تُحفظ.'
            ],
            'descriptor' => $descriptor,
            'package' => $package,
            'binding' => $binding
        ];
    }

    private function content(array $release, string $name): string
    {
        $asset=ChannelTransitionReleaseCatalog::asset($release,$name);
        if (!$asset) throw new RuntimeException('transition_package_unavailable');
        $result=$this->provider->fetchAssetContent($asset['browser_download_url']);
        if (!$result['ok'] || !is_string($result['content']??null)) throw new RuntimeException('metadata_download_failed');
        return $result['content'];
    }

    private function shellDigest(): string
    {
        $root=$this->delta->getRootDir();
        $paths=['POS Desktop.exe'=>dirname($root,2).'/POS Desktop.exe','resources/app.asar'=>dirname($root).'/app.asar'];
        $map=[];
        foreach ($paths as $name=>$path) {
            if (!is_file($path) || is_link($path)) throw new RuntimeException('immutable_shell_unavailable');
            $map[$name]=hash_file('sha256',$path);
        }
        return hash('sha256',json_encode($map,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    private function handoffManifest(array $descriptor): array
    {
        $files=array_values(array_filter($descriptor['files'],static fn(array $file):bool=>($descriptor['source_inventory']['files'][$file['path']]??null)!==['sha256'=>$file['sha256'],'size'=>$file['size']]));
        return ['version'=>$descriptor['version'],'channel'=>$descriptor['channel'],'type'=>'delta','files'=>$files,'deleted_files'=>$descriptor['deleted_files'],'migrations'=>[],'baseline_files'=>array_map(static fn(array $file):string=>$file['sha256'],$descriptor['source_inventory']['files'])];
    }

    private function stage(array $descriptor, array $asset): void
    {
        $version=$descriptor['version'];$this->delta->cleanStaging($version);
        $directory=$this->delta->getStagingDir($version);
        if (!mkdir($directory,0700,true)) throw new RuntimeException('staging_unavailable');
        $zipPath=$directory.'/transition.zip';
        $this->provider->setDownloadProgress(function(int $bytes = 0, int $total = 0, int $attempt = 1):void{
            $this->delta->recordDownloadProgress($bytes, $total, $attempt);
        });
        try { $download=$this->provider->downloadAssetFile($asset['browser_download_url'],$zipPath); }
        finally { $this->provider->setDownloadProgress(null); }
        if (!$download['ok'] || !is_file($zipPath) || filesize($zipPath)!==$descriptor['package']['size'] || !hash_equals($descriptor['package']['sha256'],hash_file('sha256',$zipPath))) throw new RuntimeException('package_download_or_digest_failed');
        $zip=new ZipArchive();if ($zip->open($zipPath)!==true) throw new RuntimeException('package_zip_invalid');
        try {
            $expected=array_column($descriptor['files'],'path');$actual=[];$aliases=[];
            for($i=0;$i<$zip->numFiles;$i++) {
                $name=$zip->getNameIndex($i);
                if (!is_string($name) || isset($aliases[strtolower($name)])) throw new RuntimeException('package_member_duplicate');
                $aliases[strtolower($name)]=true;
                if (str_ends_with($name,'/')) {
                    if (!array_filter($expected,static fn(string $file):bool=>str_starts_with($file,$name))) throw new RuntimeException('package_member_unexpected');
                } else $actual[]=$name;
            }
            sort($actual);sort($expected);if ($actual!==$expected) throw new RuntimeException('package_member_mismatch');
        } finally { $zip->close(); }
        $extracted=$this->delta->extractZipToStaging($zipPath,$directory);unlink($zipPath);
        if (!$extracted['ok'] || !(new UpdateManifestService())->verifyStagedFiles($directory,$descriptor['files'])['ok']) throw new RuntimeException('staged_inventory_mismatch');
    }

    private function database(): PDO { return $this->db??=Database::getInstance(); }
    private function failure(string $reason, int $code=409): array { return ['ok'=>false,'code'=>$code,'error'=>$reason,'reason_code'=>$reason,'logs'=>[]]; }
}
