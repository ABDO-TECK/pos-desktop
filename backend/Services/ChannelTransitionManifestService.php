<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;
use Throwable;

/** Explicit transitions have separate authority from ordinary newer-only updates. */
final class ChannelTransitionManifestService
{
    public function __construct(private ManifestSignatureService $signatures) {}

    public function verifyIndex(string $raw, string $signature, array $carrier): array
    {
        try {
            $this->require(strlen($raw) <= 131072 && $this->signatures->verifySignature($raw,$signature), 'signature_invalid');
            $index = json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            $this->require(is_array($index) && ($index['format']??null)===1 && ($carrier['draft']??true)===false && ($index['carrier_tag']??null)===($carrier['tag_name']??null), 'index_invalid');
            $this->require(is_array($index['entries']??null) && array_is_list($index['entries']) && count($index['entries'])<=32, 'index_invalid');
            $seen = [];
            foreach ($index['entries'] as $entry) {
                $this->require(is_array($entry), 'index_invalid');
                foreach (['source_inventory_sha256','descriptor_sha256'] as $field) $this->require($this->hash($entry[$field]??null), 'index_invalid');
                foreach (['from_version','version'] as $field) $this->require(is_string($entry[$field]??null) && in_array(UpdateChannelPolicy::installedChannel($entry[$field]),UpdateChannelPolicy::active(),true), 'index_invalid');
                $this->require(($entry['channel']??null)===UpdateChannelPolicy::installedChannel($entry['version']) && in_array($entry['type']??null,['delta','full'],true), 'index_invalid');
                foreach (['descriptor'=>'json','signature'=>'sig'] as $field=>$extension) $this->require(is_string($entry[$field]??null) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\\.'.$extension.'$/D',$entry[$field])===1, 'index_invalid');
                $key = implode('|',[$entry['from_version'],$entry['source_inventory_sha256'],$entry['version'],$entry['channel'],$entry['type']]);
                $this->require(!isset($seen[$key]), 'index_ambiguous');
                $seen[$key] = true;
            }
            return ['ok'=>true,'index'=>$index,'reason_code'=>null];
        } catch (Throwable $error) {
            return ['ok'=>false,'index'=>null,'reason_code'=>$error instanceof RuntimeException?$error->getMessage():'index_invalid'];
        }
    }

    /** @return array{ok:bool,descriptor:?array,reason_code:?string} */
    public function verify(string $raw, string $signature, array $carrier, array $installed, array $target): array
    {
        try {
            $this->require(strlen($raw) <= 4 * 1024 * 1024 && $this->signatures->verifySignature($raw, $signature), 'signature_invalid');
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            $this->require(is_array($data) && ($data['format'] ?? null) === 1 && ($data['operation'] ?? null) === 'channel_transition', 'descriptor_invalid');
            $this->require(($carrier['draft'] ?? true) === false && ($data['carrier_tag'] ?? null) === ($carrier['tag_name'] ?? null), 'carrier_mismatch');
            foreach (['from_version'=>'version','from_channel'=>'channel','source_inventory_sha256'=>'inventory_sha256'] as $field=>$binding) {
                $this->require(isset($installed[$binding]) && ($data[$field] ?? null) === $installed[$binding], 'source_mismatch');
            }
            $this->require(isset($installed['shell_sha256']) && ($data['shell_sha256'] ?? null) === $installed['shell_sha256'], 'shell_mismatch');
            foreach (['version'=>'version','channel'=>'channel','target_repo'=>'repo','target_tag'=>'tag','target_inventory_sha256'=>'inventory_sha256'] as $field=>$binding) {
                $this->require(isset($target[$binding]) && ($data[$field] ?? null) === $target[$binding], 'target_mismatch');
            }
            $this->require(in_array($data['target_repo'], ['pos-desktop','pos-desktop-updates'], true), 'target_invalid');
            $this->require($data['target_tag'] === 'v'.$data['version'], 'target_invalid');
            foreach (['from_version'=>'from_channel','version'=>'channel'] as $version=>$channel) {
                $this->require(in_array($data[$channel], UpdateChannelPolicy::active(), true) && UpdateChannelPolicy::installedChannel($data[$version]) === $data[$channel], 'channel_invalid');
            }
            foreach (['source_inventory_sha256','target_inventory_sha256','shell_sha256'] as $field) $this->require($this->hash($data[$field]), 'digest_invalid');
            $source = $this->inventory($data['source_inventory'] ?? [], $data['from_version']);
            $destination = $this->inventory($data['target_inventory'] ?? [], $data['version']);
            $this->require($source === $this->inventory($installed['inventory'] ?? [], $installed['version']), 'source_mismatch');
            $this->require($destination === $this->inventory($target['inventory'] ?? [], $target['version']), 'target_mismatch');
            $this->require(in_array($data['type'] ?? null, ['delta','full'], true) && ($data['migrations'] ?? null) === [], 'operation_invalid');
            $expected = $destination;
            if ($data['type'] === 'delta') $expected = array_filter($destination, static fn(array $entry,string $path):bool => ($source[$path] ?? null) !== $entry, ARRAY_FILTER_USE_BOTH);
            $files = $data['files'] ?? null;
            $this->require(is_array($files) && array_is_list($files), 'files_invalid');
            $actual = [];
            foreach ($files as $entry) {
                $this->require(is_array($entry) && is_string($entry['path'] ?? null) && !isset($actual[$entry['path']]), 'files_invalid');
                $this->path($entry['path']);
                $actual[$entry['path']] = $this->file($entry);
            }
            ksort($actual); ksort($expected);
            $this->require($actual === $expected, 'payload_mismatch');
            $deleted = $data['deleted_files'] ?? null;
            $this->require(is_array($deleted) && array_is_list($deleted), 'deletions_invalid');
            foreach ($deleted as $path) { $this->require(is_string($path), 'deletions_invalid'); $this->path($path); }
            $expectedDeleted = array_keys(array_diff_key($source, $destination));
            sort($deleted); sort($expectedDeleted);
            $this->require($deleted === $expectedDeleted, 'deletions_invalid');
            $package = $data['package'] ?? [];
            $this->require(is_string($package['asset'] ?? null) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/D', $package['asset']) === 1, 'package_invalid');
            $this->file($package);
            $contract = $data['data_compatibility'] ?? [];
            $this->require(($contract['format'] ?? null) === 1 && ($contract['metadata_migration'] ?? null) === UpdateDataCompatibilityService::METADATA_MIGRATION && $this->hash($contract['schema_sha256'] ?? null) && $this->hash($contract['ledger_sha256'] ?? null), 'data_contract_invalid');
            return ['ok'=>true,'descriptor'=>$data,'reason_code'=>null];
        } catch (Throwable $error) {
            return ['ok'=>false,'descriptor'=>null,'reason_code'=>$error instanceof RuntimeException ? $error->getMessage() : 'descriptor_invalid'];
        }
    }

    private function inventory(array $inventory, string $version): array
    {
        $this->require(($inventory['version'] ?? null) === $version && is_array($inventory['files'] ?? null) && isset($inventory['files']['version.json']), 'inventory_invalid');
        $files = []; $aliases = [];
        foreach ($inventory['files'] as $path=>$entry) {
            $this->require(is_string($path) && is_array($entry), 'inventory_invalid');
            $this->path($path);
            $alias = strtolower($path);
            $this->require(!isset($aliases[$alias]), 'inventory_alias');
            $aliases[$alias] = true;
            $files[$path] = $this->file($entry);
        }
        ksort($files);
        return $files;
    }

    private function file(array $entry): array
    {
        $this->require($this->hash($entry['sha256'] ?? null) && is_int($entry['size'] ?? null) && $entry['size'] >= 0, 'file_invalid');
        return ['sha256'=>$entry['sha256'],'size'=>$entry['size']];
    }

    private function path(string $path): void
    {
        $this->require(strlen($path) <= 240 && preg_match('~^(?:version\.json|backend/backend\.phar|backend/certs/cacert\.pem|database/pos_schema\.sql|frontend/(?:dist|public)/[A-Za-z0-9_@+./ -]+)$~D', $path) === 1, 'path_protected');
        foreach (explode('/', $path) as $segment) {
            $this->require($segment !== '' && $segment !== '.' && $segment !== '..' && !preg_match('/[. ]$/D', $segment) && !preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment), 'path_invalid');
        }
        $this->require(!str_contains(strtolower($path), 'digital-certificate'), 'path_protected');
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function require(bool $condition, string $reason): void
    {
        if (!$condition) throw new RuntimeException($reason);
    }
}
