<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;
use Throwable;

/** Verify a local recovery point and stage its inverse for the existing desktop protocol. */
final class UpdateRollbackPlanner
{
    public function __construct(private DeltaUpdateService $delta) {}

    /** Read-only catalog. Every returned destination passes the execution checks. */
    public function availableSnapshots(): array
    {
        $destinations = [];
        foreach (glob($this->delta->getBackupsDir().'/patch_*', GLOB_ONLYDIR) ?: [] as $snapshot) {
            $result = $this->inspect($snapshot);
            if (!$result['ok']) continue;
            $metadata = $result['metadata'];
            $target = $metadata['from_version'];
            $entry = [
                'snapshot_name' => basename($snapshot),
                'snapshot_path' => str_replace('\\', '/', $snapshot),
                'from_version' => $target,
                'to_version' => $metadata['to_version'],
                'timestamp' => $metadata['timestamp'] ?? date(DATE_ATOM, filemtime($snapshot)),
                'files_count' => count($result['manifest']['files']),
                'has_db_backup' => false,
                'db_backup_path' => null,
            ];
            if (!isset($destinations[$target]) || strtotime($entry['timestamp']) > strtotime($destinations[$target]['timestamp'])) {
                $destinations[$target] = $entry;
            }
        }
        $destinations = array_values($destinations);
        usort($destinations, static fn(array $a, array $b): int => version_compare($b['from_version'], $a['from_version']));
        return $destinations;
    }

    public function prepare(string $snapshot): array
    {
        $checked = $this->inspect($snapshot);
        if (!$checked['ok']) return $checked;
        try {
            $reverse = $checked['manifest'];
            $staged = $this->delta->stageFromLocalFiles($reverse, $checked['snapshot'].'/files');
            if (!$staged['ok']) throw new RuntimeException(implode('; ', $staged['errors']));
            $safety = $this->delta->createBackupSnapshot($reverse['from_version'], $reverse['version'], $reverse, null, 'rollback_safety');
            if (!$safety['ok']) throw new RuntimeException($safety['error']);
            return ['ok'=>true, 'manifest'=>$reverse, 'safety_snapshot'=>$safety['snapshot_path'], 'snapshot'=>$checked['snapshot'], 'logs'=>['تم فحص نقطة الاسترجاع وتجهيز ملفاتها دون استبدال ملفات البرنامج العامل.']];
        } catch (Throwable $error) {
            return ['ok'=>false, 'error'=>$error->getMessage(), 'code'=>422, 'logs'=>[$error->getMessage()]];
        }
    }

    private function inspect(string $snapshot): array
    {
        try {
            $base=realpath($this->delta->getBackupsDir());
            $resolved=realpath($snapshot);
            $storage=realpath($this->delta->getStorageDir());
            if (!$storage || !$base || strcasecmp(dirname($base),$storage)!==0 || !$resolved || strcasecmp(dirname($resolved),$base)!==0 || is_link($snapshot) || is_link($resolved.'/metadata.json')) {
                throw new RuntimeException('Recovery point must be a direct child of the application backup directory.');
            }
            if (!is_file($resolved.'/metadata.json')) throw new RuntimeException('Recovery metadata is missing.');
            $metadata=json_decode((string)file_get_contents($resolved.'/metadata.json'),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || !is_string($metadata['from_version'] ?? null) || !is_string($metadata['to_version'] ?? null)) throw new RuntimeException('Invalid recovery versions.');
            if (isset($metadata['timestamp']) && (!is_string($metadata['timestamp']) || strtotime($metadata['timestamp']) === false)) throw new RuntimeException('Invalid recovery timestamp.');
            foreach (['files', 'deleted_files', 'new_files'] as $key) {
                if (!is_array($metadata[$key] ?? null)) throw new RuntimeException('Invalid recovery file list.');
            }
            if (($metadata['purpose'] ?? 'update') !== 'update') {
                throw new RuntimeException('هذه نسخة أمان داخلية للعملية وليست وجهة رجوع متاحة.');
            }
            if (!empty($metadata['db_recovery']) || !empty($metadata['db_backup_path'])) {
                throw new RuntimeException('هذه النقطة تتضمن استعادة قاعدة بيانات. لم تتغير بياناتك؛ الاسترجاع الآمن للملفات متاح للنقاط التي لا تتضمن ترحيلات قاعدة بيانات.');
            }
            $root=$this->delta->getRootDir();
            $current=json_decode((string)file_get_contents($root.'/version.json'),true,512,JSON_THROW_ON_ERROR)['version']??'';
            $target=$metadata['from_version']??'';
            if (($metadata['to_version']??null)!==$current) {
                throw new RuntimeException('هذه اللقطة تخص تحديثًا أقدم ولا تمثل الحالة السابقة مباشرة لإصدارك الحالي. اختر نقطة استرجاع التحديث الحالي لتجنب خلط ملفات إصدارات مختلفة.');
            }
            if (!ReleaseVersionPolicy::assess($current,$target)['compatible'] || version_compare($target,$current,'>=')) {
                throw new RuntimeException('Recovery point is not an earlier compatible application version.');
            }
            $files=[];$seen=[];
            foreach (array_merge($metadata['files']??[],$metadata['deleted_files']??[]) as $entry) {
                $path=$entry['path']??'';
                if (isset($seen[$path])) throw new RuntimeException('Duplicate recovery artifact.');
                $seen[$path]=true;
                $files[]=['path'=>$path,'sha256'=>$entry['sha256']??'','size'=>$entry['size']??0,'action'=>'replace'];
            }
            if (!isset($seen['version.json'])) throw new RuntimeException('Recovery point is missing the original version.json artifact.');
            $reverse=['version'=>$target,'from_version'=>$current,'channel'=>str_contains($target,'-beta.')?'beta':'stable',
                'type'=>'delta','files'=>$files,'deleted_files'=>$metadata['new_files']??[],'migrations'=>[],'baseline_files'=>[]];
            $policy=new UpdateManifestService();
            if (!$policy->validateManifest($reverse)['valid'] || !$policy->validateManifestPaths($reverse,$root)['ok']) throw new RuntimeException('Unsafe or invalid recovery metadata.');
            foreach (array_merge(array_keys($seen),$reverse['deleted_files']) as $path) {
                if ($this->delta->isProtectedFile($path)) throw new RuntimeException('Recovery point targets protected data.');
                $candidate=$root.'/'.$path;
                // Resolve existing ancestors too, so missing files below junctions cannot escape.
                $ancestor=$candidate;
                while (!file_exists($ancestor) && dirname($ancestor)!==$ancestor) $ancestor=dirname($ancestor);
                $canonical=realpath($ancestor);
                $canonicalRoot=realpath($root);
                if (!$canonical || !$canonicalRoot || (strcasecmp($canonical,$canonicalRoot)!==0 && !str_starts_with(strtolower(str_replace('\\','/',$canonical)),strtolower(str_replace('\\','/',$canonicalRoot)).'/'))) throw new RuntimeException('Recovery destination escapes the deployed application.');
                if (is_file($candidate)) $reverse['baseline_files'][$path]=hash_file('sha256',$candidate);
            }
            $source=$resolved.'/files';$canonicalSource=realpath($source);
            if (!$canonicalSource || strcasecmp(dirname($canonicalSource),$resolved)!==0 || is_link($source)) throw new RuntimeException('Recovery payload is missing or redirected.');
            foreach ($files as $entry) {
                $candidate=$source.'/'.$entry['path'];$canonical=realpath($candidate);
                if (!$canonical || !str_starts_with(strtolower(str_replace('\\','/',$canonical)),strtolower(str_replace('\\','/',$canonicalSource)).'/') || !$policy->verifyFileHash($candidate,$entry['sha256']) || filesize($candidate)!==(int)$entry['size']) throw new RuntimeException('Recovery payload is missing or corrupt: '.$entry['path']);
            }
            $version=json_decode((string)file_get_contents($source.'/version.json'),true,512,JSON_THROW_ON_ERROR);
            if (($version['version']??null)!==$target) throw new RuntimeException('Recovery version does not match its metadata.');
            // New snapshots retain the signed baseline inventory. Check the complete
            // proposed target, including files untouched by this particular update.
            $inventory = $metadata['baseline_files'] ?? [];
            $inventoryManifest = $reverse;
            $inventoryManifest['baseline_files'] = $inventory;
            if (!$policy->validateManifest($inventoryManifest)['valid'] || !$policy->validateManifestPaths($inventoryManifest, $root)['ok']) throw new RuntimeException('Invalid recovery inventory.');
            foreach ($inventory as $path => $expected) {
                if ($this->delta->isProtectedFile($path) || in_array($path, $reverse['deleted_files'], true)) throw new RuntimeException('Recovery inventory conflicts with protected or removed files.');
                $candidate = isset($seen[$path]) ? $source.'/'.$path : $root.'/'.$path;
                if (!$policy->verifyFileHash($candidate, $expected)) throw new RuntimeException('Recovery target inventory mismatch: '.$path);
                if (is_file($root.'/'.$path)) $reverse['baseline_files'][$path] = hash_file('sha256', $root.'/'.$path);
            }
            return ['ok'=>true,'manifest'=>$reverse,'snapshot'=>$resolved,'metadata'=>$metadata];
        } catch (Throwable $error) {
            return ['ok'=>false,'error'=>$error->getMessage(),'code'=>422,'logs'=>[$error->getMessage()]];
        }
    }
}
