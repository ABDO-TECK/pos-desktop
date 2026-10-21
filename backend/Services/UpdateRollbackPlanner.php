<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;
use Throwable;

/** Verify a local recovery point and stage its inverse for the existing desktop protocol. */
final class UpdateRollbackPlanner
{
    public function __construct(private DeltaUpdateService $delta) {}

    public function prepare(string $snapshot): array
    {
        try {
            $base=realpath($this->delta->getBackupsDir());
            $resolved=realpath($snapshot);
            $storage=realpath($this->delta->getStorageDir());
            if (!$storage || !$base || strcasecmp(dirname($base),$storage)!==0 || !$resolved || strcasecmp(dirname($resolved),$base)!==0 || is_link($snapshot) || is_link($resolved.'/metadata.json')) {
                throw new RuntimeException('Recovery point must be a direct child of the application backup directory.');
            }
            $metadata=json_decode((string)file_get_contents($resolved.'/metadata.json'),true,512,JSON_THROW_ON_ERROR);
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
            $staged=$this->delta->stageFromLocalFiles($reverse,$source);
            if (!$staged['ok']) throw new RuntimeException(implode('; ',$staged['errors']));
            $safety=$this->delta->createBackupSnapshot($current,$target,$reverse);
            if (!$safety['ok']) throw new RuntimeException($safety['error']);
            return ['ok'=>true,'manifest'=>$reverse,'safety_snapshot'=>$safety['snapshot_path'],'snapshot'=>$resolved,'logs'=>['تم فحص نقطة الاسترجاع وتجهيز ملفاتها دون استبدال ملفات البرنامج العامل.']];
        } catch (Throwable $error) {
            return ['ok'=>false,'error'=>$error->getMessage(),'code'=>422,'logs'=>[$error->getMessage()]];
        }
    }
}
