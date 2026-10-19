<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cross-process coordination for backend and Electron update operations.
 *
 * Updates use an exclusive JSON lease. Sales use independent JSON leases so
 * concurrent sale requests can still reach the database idempotency and row
 * locking rules, while the exclusive update lease cannot cross an active sale
 * boundary.
 */
final class UpdateOperationLock
{
    public const TTL_SECONDS = 300;

    private const COORDINATION_GATE_TTL_SECONDS = 15;
    private const COORDINATION_GATE_WAIT_MILLISECONDS = 2000;
    private const SALE_LOCK_PREFIX = 'sale-operation-';

    private string $storageDir;
    private string $lockPath;
    private string $gatePath;
    private int $ttlSeconds;
    private ?string $ownerId = null;
    private ?string $ownerPath = null;

    public function __construct(string $storageDir, int $ttlSeconds = self::TTL_SECONDS)
    {
        $this->storageDir = rtrim(str_replace('\\', '/', $storageDir), '/');
        $this->lockPath = $this->storageDir . '/update-operation.lock';
        $this->gatePath = $this->storageDir . '/update-coordination.gate';
        $this->ttlSeconds = max(1, $ttlSeconds);
    }

    /**
     * Acquire an exclusive update lease.
     *
     * The historical sale_transaction operation is kept as an alias for
     * callers that already use acquire(), but new sale callers should use
     * acquireSale() so concurrent sales do not serialize on this lease.
     *
     * @return array{acquired:bool, owner_id?:string, operation?:string, lock_path?:string, reason_code?:string, message?:string, owner?:array|null, recovered_from?:array|null}
     */
    public function acquire(string $operation, array $context = []): array
    {
        if ($operation === 'sale_transaction') {
            return $this->acquireSale($context);
        }

        if (!$this->ensureStorageDirectory()) {
            return $this->unavailable('Unable to create the shared update coordination directory.');
        }

        return $this->withCoordinationGate(
            fn (): array => $this->acquireExclusiveLocked($operation, $context)
        );
    }

    /**
     * Acquire a shared sale lease. Multiple sale leases may coexist; an
     * exclusive update lease is rejected while any one of them is active.
     *
     * @return array{acquired:bool, owner_id?:string, operation?:string, lock_path?:string, reason_code?:string, message?:string, owner?:array|null, recovered_from?:array|null}
     */
    public function acquireSale(array $context = []): array
    {
        if (!$this->ensureStorageDirectory()) {
            return $this->unavailable('Unable to create the shared update coordination directory.');
        }

        return $this->withCoordinationGate(
            fn (): array => $this->acquireSaleLocked($context)
        );
    }

    public function release(): bool
    {
        if ($this->ownerId === null) {
            return false;
        }

        $ownerPath = $this->ownerPath ?? $this->lockPath;
        if (!is_file($ownerPath)) {
            return false;
        }

        $owner = $this->readJsonFile($ownerPath);
        if ($owner === null || ($owner['owner_id'] ?? null) !== $this->ownerId) {
            return false;
        }

        $released = @unlink($ownerPath);
        if ($released) {
            $this->ownerId = null;
            $this->ownerPath = null;
        }
        return $released;
    }

    public function heartbeat(string $phase, array $context = []): bool
    {
        if ($this->ownerId === null) {
            return false;
        }

        $ownerPath = $this->ownerPath ?? $this->lockPath;
        $owner = $this->readJsonFile($ownerPath);
        if ($owner === null || ($owner['owner_id'] ?? null) !== $this->ownerId) {
            return false;
        }

        $owner['phase'] = $phase;
        $owner['updated_at'] = gmdate('c');
        $owner['time'] = time();
        $owner['context'] = array_merge(
            is_array($owner['context'] ?? null) ? $owner['context'] : [],
            $context
        );
        $encoded = json_encode(
            $owner,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        return @file_put_contents($ownerPath, $encoded, LOCK_EX) !== false;
    }

    public static function heartbeatOwner(
        string $storageDir,
        string $ownerId,
        string $phase,
        array $context = []
    ): bool {
        $lock = new self($storageDir);
        $lock->ownerId = $ownerId;
        $lock->ownerPath = rtrim(str_replace('\\', '/', $storageDir), '/') . '/update-operation.lock';
        return $lock->heartbeat($phase, $context);
    }

    public function currentOwner(): ?array
    {
        $owner = $this->readJsonFile($this->lockPath);
        if ($owner !== null) {
            return $this->safeOwner($owner);
        }

        foreach ($this->saleLockPaths() as $salePath) {
            $saleOwner = $this->readJsonFile($salePath);
            if ($saleOwner !== null && $this->ageSeconds($saleOwner) <= $this->ttlSeconds) {
                return $this->safeOwner($saleOwner);
            }
        }

        return null;
    }

    public static function isActiveState(?array $state, int $ttlSeconds = self::TTL_SECONDS): bool
    {
        if (!is_array($state) || !in_array($state['state'] ?? null, [
            'backing_up',
            'downloading',
            'verifying',
            'applying',
            'migrating',
            'desktop_handoff_pending',
            'full_ready_to_install',
            'installing',
        ], true)) {
            return false;
        }

        $updatedAt = isset($state['updated_at']) ? strtotime((string) $state['updated_at']) : false;
        if ($updatedAt === false) {
            return true;
        }

        return (time() - $updatedAt) <= max(1, $ttlSeconds);
    }

    /** @return array{acquired:bool, owner_id?:string, operation?:string, lock_path?:string, reason_code?:string, message?:string, owner?:array|null, recovered_from?:array|null} */
    private function acquireExclusiveLocked(string $operation, array $context): array
    {
        $recoveredFrom = null;
        $existing = $this->readJsonFile($this->lockPath);
        if ($existing !== null && $this->ageSeconds($existing) <= $this->ttlSeconds) {
            return $this->busy($existing);
        }

        if (is_file($this->lockPath)) {
            if ($existing === null && $this->fileAgeSeconds($this->lockPath) <= $this->ttlSeconds) {
                return $this->unavailable('The shared update coordination lock is unreadable.');
            }
            if (!$this->quarantine($this->lockPath)) {
                return $this->unavailable('The stale shared update coordination lock could not be recovered.');
            }
            $recoveredFrom = $existing;
        }

        $saleCheck = $this->findActiveSaleOwner();
        if ($saleCheck['unavailable']) {
            return $this->unavailable('An active sale coordination lease is unreadable.');
        }
        if ($saleCheck['owner'] !== null) {
            return $this->busy($saleCheck['owner']);
        }
        if ($recoveredFrom === null) {
            $recoveredFrom = $saleCheck['recovered_from'];
        }

        $owner = $this->createOwnerPayload($operation, $context);
        if (!$this->writeExclusiveOwner($owner, $this->lockPath)) {
            return $this->unavailable('Unable to persist shared update ownership.');
        }

        $this->ownerId = (string) $owner['owner_id'];
        $this->ownerPath = $this->lockPath;
        $result = [
            'acquired' => true,
            'owner_id' => $this->ownerId,
            'operation' => $operation,
            'lock_path' => $this->lockPath,
        ];
        if ($recoveredFrom !== null) {
            $result['recovered_from'] = $this->safeOwner($recoveredFrom);
        }
        return $result;
    }

    /** @return array{acquired:bool, owner_id?:string, operation?:string, lock_path?:string, reason_code?:string, message?:string, owner?:array|null, recovered_from?:array|null} */
    private function acquireSaleLocked(array $context): array
    {
        $recoveredFrom = null;
        $existing = $this->readJsonFile($this->lockPath);
        if ($existing !== null && $this->ageSeconds($existing) <= $this->ttlSeconds) {
            return $this->busy($existing);
        }

        if (is_file($this->lockPath)) {
            if ($existing === null && $this->fileAgeSeconds($this->lockPath) <= $this->ttlSeconds) {
                return $this->unavailable('The shared update coordination lock is unreadable.');
            }
            if (!$this->quarantine($this->lockPath)) {
                return $this->unavailable('The stale shared update coordination lock could not be recovered.');
            }
            $recoveredFrom = $existing;
        }

        $owner = $this->createOwnerPayload('sale_transaction', $context);
        $ownerPath = $this->storageDir . '/' . self::SALE_LOCK_PREFIX . $owner['owner_id'] . '.lock';
        if (!$this->writeExclusiveOwner($owner, $ownerPath)) {
            return $this->unavailable('Unable to persist sale coordination ownership.');
        }

        $this->ownerId = (string) $owner['owner_id'];
        $this->ownerPath = $ownerPath;
        $result = [
            'acquired' => true,
            'owner_id' => $this->ownerId,
            'operation' => 'sale_transaction',
            'lock_path' => $ownerPath,
        ];
        if ($recoveredFrom !== null) {
            $result['recovered_from'] = $this->safeOwner($recoveredFrom);
        }
        return $result;
    }

    /** @return array{owner:?array, recovered_from:?array, unavailable:bool} */
    private function findActiveSaleOwner(): array
    {
        $recoveredFrom = null;
        foreach ($this->saleLockPaths() as $salePath) {
            $owner = $this->readJsonFile($salePath);
            if ($owner !== null && $this->ageSeconds($owner) <= $this->ttlSeconds) {
                return [
                    'owner' => $owner,
                    'recovered_from' => $recoveredFrom,
                    'unavailable' => false,
                ];
            }

            if ($owner === null && $this->fileAgeSeconds($salePath) <= $this->ttlSeconds) {
                return [
                    'owner' => null,
                    'recovered_from' => $recoveredFrom,
                    'unavailable' => true,
                ];
            }

            if (!$this->quarantine($salePath)) {
                return [
                    'owner' => null,
                    'recovered_from' => $recoveredFrom,
                    'unavailable' => true,
                ];
            }
            if ($recoveredFrom === null) {
                $recoveredFrom = $owner;
            }
        }

        return [
            'owner' => null,
            'recovered_from' => $recoveredFrom,
            'unavailable' => false,
        ];
    }

    /** @return list<string> */
    private function saleLockPaths(): array
    {
        $entries = @scandir($this->storageDir);
        if ($entries === false) {
            return [];
        }

        $paths = [];
        foreach ($entries as $entry) {
            if (
                str_starts_with($entry, self::SALE_LOCK_PREFIX)
                && str_ends_with($entry, '.lock')
            ) {
                $paths[] = $this->storageDir . '/' . $entry;
            }
        }
        sort($paths);
        return $paths;
    }

    /** @return array<string,mixed> */
    private function createOwnerPayload(string $operation, array $context): array
    {
        $now = gmdate('c');
        return [
            'owner_id' => bin2hex(random_bytes(16)),
            'operation' => $operation,
            'pid' => getmypid(),
            'time' => time(),
            'started_at' => $now,
            'updated_at' => $now,
            'context' => $context,
        ];
    }

    /** @param array<string,mixed> $owner */
    private function writeExclusiveOwner(array $owner, string $path): bool
    {
        $encoded = json_encode(
            $owner,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) {
            return false;
        }

        $written = @fwrite($handle, $encoded);
        @fflush($handle);
        @fclose($handle);
        if ($written !== strlen($encoded)) {
            @unlink($path);
            return false;
        }
        return true;
    }

    /** @return array{acquired:bool, owner_id?:string, operation?:string, lock_path?:string, reason_code?:string, message?:string, owner?:array|null, recovered_from?:array|null} */
    private function withCoordinationGate(callable $callback): array
    {
        $gateId = $this->acquireCoordinationGate();
        if ($gateId === null) {
            return $this->unavailable('The update coordination gate is unavailable.');
        }

        try {
            return $callback();
        } finally {
            $this->releaseCoordinationGate($gateId);
        }
    }

    private function acquireCoordinationGate(): ?string
    {
        $gateId = bin2hex(random_bytes(16));
        $payload = json_encode([
            'gate_id' => $gateId,
            'pid' => getmypid(),
            'time' => time(),
            'updated_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $deadline = microtime(true) + (self::COORDINATION_GATE_WAIT_MILLISECONDS / 1000);

        do {
            $handle = @fopen($this->gatePath, 'x');
            if (is_resource($handle)) {
                $written = @fwrite($handle, $payload);
                @fflush($handle);
                @fclose($handle);
                if ($written === strlen($payload)) {
                    return $gateId;
                }
                @unlink($this->gatePath);
                return null;
            }

            $gate = $this->readJsonFile($this->gatePath);
            if (
                ($gate !== null && $this->ageSeconds($gate) > self::COORDINATION_GATE_TTL_SECONDS)
                || ($gate === null && is_file($this->gatePath) && $this->fileAgeSeconds($this->gatePath) > self::COORDINATION_GATE_TTL_SECONDS)
            ) {
                $this->quarantine($this->gatePath);
                continue;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function releaseCoordinationGate(string $gateId): void
    {
        $gate = $this->readJsonFile($this->gatePath);
        if ($gate !== null && ($gate['gate_id'] ?? null) === $gateId) {
            @unlink($this->gatePath);
        }
    }

    private function ensureStorageDirectory(): bool
    {
        return is_dir($this->storageDir)
            || (@mkdir($this->storageDir, 0755, true) && is_dir($this->storageDir));
    }

    /** @return array<string,mixed>|null */
    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        $decoded = json_decode((string) $content, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function fileAgeSeconds(string $path): int
    {
        $modifiedAt = @filemtime($path);
        return $modifiedAt === false ? $this->ttlSeconds + 1 : max(0, time() - (int) $modifiedAt);
    }

    /** @param array<string,mixed> $owner */
    private function ageSeconds(array $owner): int
    {
        if (isset($owner['time'])) {
            return max(0, time() - (int) $owner['time']);
        }
        $updatedAt = isset($owner['updated_at']) ? strtotime((string) $owner['updated_at']) : false;
        return $updatedAt === false ? $this->ttlSeconds + 1 : max(0, time() - $updatedAt);
    }

    private function quarantine(string $path): bool
    {
        if (!is_file($path)) {
            return true;
        }
        $stalePath = $path . '.stale.' . bin2hex(random_bytes(8));
        if (!@rename($path, $stalePath)) {
            return false;
        }
        @unlink($stalePath);
        return true;
    }

    /** @param array<string,mixed> $owner */
    private function busy(array $owner): array
    {
        return [
            'acquired' => false,
            'reason_code' => 'update_in_progress',
            'message' => 'Another update or sale operation is in progress.',
            'owner' => $this->safeOwner($owner),
        ];
    }

    /** @return array{acquired:false, reason_code:string, message:string, owner:null} */
    private function unavailable(string $message): array
    {
        return [
            'acquired' => false,
            'reason_code' => 'update_lock_unavailable',
            'message' => $message,
            'owner' => null,
        ];
    }

    /** @param array<string,mixed> $owner @return array<string,mixed> */
    private function safeOwner(array $owner): array
    {
        return [
            'owner_id' => (string) ($owner['owner_id'] ?? ''),
            'operation' => (string) ($owner['operation'] ?? 'unknown'),
            'pid' => isset($owner['pid']) ? (int) $owner['pid'] : null,
            'phase' => isset($owner['phase']) ? (string) $owner['phase'] : null,
            'time' => isset($owner['time']) ? (int) $owner['time'] : null,
            'updated_at' => isset($owner['updated_at']) ? (string) $owner['updated_at'] : null,
            'context' => is_array($owner['context'] ?? null) ? $owner['context'] : [],
            'age_seconds' => $this->ageSeconds($owner),
        ];
    }
}
