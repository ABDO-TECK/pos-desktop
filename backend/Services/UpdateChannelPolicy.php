<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

final class UpdateChannelPolicy
{
    public static function active(): array { return ['stable', 'beta']; }

    public static function normalizePreference(?string $channel): string
    {
        $channel = strtolower(trim($channel ?? ''));
        return in_array($channel, self::active(), true) ? $channel : 'stable';
    }

    public static function installedChannel(string $version): string
    {
        if (preg_match('/^\d+\.\d+\.\d+-beta\.\d+$/', $version)) return 'beta';
        return preg_match('/^\d+\.\d+\.\d+$/', $version) ? 'stable' : 'retired';
    }

    /** Change only an explicitly retired preference; preserve malformed evidence. */
    public static function migratePreference(string $file): array
    {
        if (!is_file($file)) return ['channel'=>'stable', 'migrated_from'=>null];
        return self::withLock($file, static function () use ($file): array {
            $raw = file_get_contents($file);
            if ($raw === false) throw new RuntimeException('Cannot read channel preference.');
            $data = json_decode($raw, true);
            if (!is_array($data) || !is_string($data['channel'] ?? null)) return ['channel'=>'stable', 'migrated_from'=>null];
            if (strtolower(trim($data['channel'])) === 'rc') {
                $data['channel'] = 'stable'; $data['migrated_from'] = 'rc'; $data['updated_at'] = date(DATE_ATOM);
                self::writeAtomic($file, $data);
            }
            return ['channel'=>self::normalizePreference($data['channel']), 'migrated_from'=>($data['migrated_from'] ?? null) === 'rc' ? 'rc' : null];
        });
    }

    public static function savePreference(string $file, string $channel): void
    {
        if (!in_array($channel, self::active(), true)) throw new RuntimeException('Only stable and beta channels are active.');
        self::withLock($file, static function () use ($file, $channel): array {
            $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
            if (!is_array($data)) $data = [];
            unset($data['migrated_from']);
            $data['channel'] = $channel; $data['updated_at'] = date(DATE_ATOM);
            self::writeAtomic($file, $data);
            return [];
        });
    }

    private static function withLock(string $file, callable $operation): array
    {
        if (is_link($file) || is_link($file.'.migration.lock')) throw new RuntimeException('Unsafe channel preference path.');
        $lock = fopen($file.'.migration.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock channel preference.');
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private static function writeAtomic(string $file, array $data): void
    {
        $temporary = $file.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false || !rename($temporary, $file)) {
            throw new RuntimeException('Cannot save channel preference.');
        }
    }
}
