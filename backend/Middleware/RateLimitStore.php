<?php

namespace App\Middleware;

use App\Helpers\Logger;

/**
 * Shared SQLite storage for rate limiting.
 * Used by both RateLimiter and LoginRateLimiter to avoid code duplication.
 */
class RateLimitStore
{
    private const EMERGENCY_CAPACITY = 512;
    private const SQLITE_RETRY_SECONDS = 5;
    private const LOG_INTERVAL_SECONDS = 60;
    private const LOG_OPERATIONS = [
        'apcu_increment',
        'sqlite_init',
        'sqlite_increment',
        'sqlite_cleanup',
        'shared_storage',
        'emergency_capacity',
    ];

    private static ?\PDO $db = null;
    private static int $sqliteRetryAt = 0;

    /** @var array<string, array{count: int, expires_at: int}> */
    private static array $emergencyCounters = [];

    /** @var array<string, int> */
    private static array $lastLogAt = [];

    private static ?bool $forceApcuFailure = null;
    private static ?bool $forceSqliteFailure = null;
    private static ?int $emergencyCapacityOverride = null;
    private static ?string $logThrottleDirectoryOverride = null;

    /**
     * Get or create the shared SQLite connection for rate limiting.
     */
    public static function getDB(): ?\PDO
    {
        if (self::$db !== null) return self::$db;
        if (self::$forceSqliteFailure === true) return null;
        if (time() < self::$sqliteRetryAt) return null;

        try {
            if (defined('PHPUNIT_TEST_SUITE')) {
                self::$db = new \PDO('sqlite::memory:');
                self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::$db->exec('PRAGMA busy_timeout=5000');
                self::$db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                    key_name TEXT PRIMARY KEY,
                    request_count INTEGER NOT NULL DEFAULT 1,
                    expires_at INTEGER NOT NULL
                )");
                return self::$db;
            }
            $storageDir = $_ENV['APP_STORAGE_DIR'] ?? (getenv('APP_STORAGE_DIR') ?: null) ?? (__DIR__ . '/../storage');
            $dbPath = $storageDir . '/rate_limit.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            self::$db = new \PDO('sqlite:' . $dbPath);
            self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::$db->exec('PRAGMA busy_timeout=5000');
            self::$db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                key_name TEXT PRIMARY KEY,
                request_count INTEGER NOT NULL DEFAULT 1,
                expires_at INTEGER NOT NULL
            )");
            // WAL mode for better concurrent read/write performance
            self::$db->exec("PRAGMA journal_mode=WAL");
            return self::$db;
        } catch (\Throwable) {
            self::$db = null;
            self::$sqliteRetryAt = time() + self::SQLITE_RETRY_SECONDS;
            self::logStorageFailure('sqlite_init');
            return null;
        }
    }

    /**
     * Atomically initialize or increment an APCu counter.
     *
     * Null means APCu is unavailable and the SQLite fallback should be used.
     */
    public static function incrementApcu(string $key, int $ttl): ?int
    {
        if (self::$forceApcuFailure === true) {
            return null;
        }
        if (!function_exists('apcu_add') || !function_exists('apcu_inc')) {
            return null;
        }
        if (function_exists('apcu_enabled') && !apcu_enabled()) {
            return null;
        }

        try {
            if (apcu_add($key, 1, $ttl)) {
                return 1;
            }

            $success = false;
            $count = apcu_inc($key, 1, $success);
            if ($success) {
                return (int) $count;
            }

            // The key may expire between add() and inc(); retry once.
            if (apcu_add($key, 1, $ttl)) {
                return 1;
            }

            $success = false;
            $count = apcu_inc($key, 1, $success);
            if ($success) {
                return (int) $count;
            }
        } catch (\Throwable) {
            self::logStorageFailure('apcu_increment');
            return null;
        }

        self::logStorageFailure('apcu_increment');
        return null;
    }

    /**
     * Atomically initialize or increment a SQLite counter and return its value.
     */
    public static function increment(string $key, int $expiresAt): ?int
    {
        if (self::$forceSqliteFailure === true) {
            return null;
        }

        $db = self::getDB();
        if ($db === null) {
            return null;
        }

        $startedTransaction = false;
        try {
            if (!$db->inTransaction()) {
                $db->exec('BEGIN IMMEDIATE');
                $startedTransaction = true;
            }

            $stmt = $db->prepare(
                'INSERT INTO rate_limits (key_name, request_count, expires_at)
                 VALUES (:key, 1, :expires_at)
                 ON CONFLICT(key_name) DO UPDATE SET
                    request_count = rate_limits.request_count + 1,
                    expires_at = excluded.expires_at'
            );
            $stmt->execute([
                ':key' => $key,
                ':expires_at' => $expiresAt,
            ]);

            $stmt = $db->prepare(
                'SELECT request_count FROM rate_limits WHERE key_name = :key'
            );
            $stmt->execute([':key' => $key]);
            $count = $stmt->fetchColumn();
            if ($count === false) {
                throw new \RuntimeException('Rate limit counter was not persisted');
            }

            if ($startedTransaction) {
                $db->exec('COMMIT');
            }

            return (int) $count;
        } catch (\Throwable) {
            if ($startedTransaction && $db->inTransaction()) {
                try {
                    $db->exec('ROLLBACK');
                } catch (\Throwable) {
                    self::$db = null;
                }
            }
            self::logStorageFailure('sqlite_increment');
            return null;
        }
    }

    /**
     * Increment a bounded process-local counter when shared stores are down.
     *
     * Null means the selected pool has no room for a new key. Existing keys
     * remain enforceable even while the pool is full.
     */
    public static function incrementEmergency(
        string $key,
        int $expiresAt,
        ?int $now = null
    ): ?int {
        $now ??= time();

        foreach (self::$emergencyCounters as $storedKey => $counter) {
            if ($counter['expires_at'] <= $now) {
                unset(self::$emergencyCounters[$storedKey]);
            }
        }

        if (isset(self::$emergencyCounters[$key])) {
            return ++self::$emergencyCounters[$key]['count'];
        }

        $capacity = self::$emergencyCapacityOverride ?? self::EMERGENCY_CAPACITY;
        if (count(self::$emergencyCounters) >= $capacity) {
            return null;
        }

        self::$emergencyCounters[$key] = [
            'count' => 1,
            'expires_at' => $expiresAt,
        ];
        return 1;
    }

    /**
     * Emit at most one sanitized diagnostic per storage operation and interval.
     */
    public static function logStorageFailure(string $operation): void
    {
        $operation = in_array($operation, self::LOG_OPERATIONS, true)
            ? $operation
            : 'unknown';
        $now = time();
        $lastProcessLogAt = self::$lastLogAt[$operation] ?? 0;
        if ($lastProcessLogAt <= $now
            && ($now - $lastProcessLogAt) < self::LOG_INTERVAL_SECONDS) {
            return;
        }

        $storageDirectory = $_ENV['APP_STORAGE_DIR']
            ?? (getenv('APP_STORAGE_DIR') ?: null)
            ?? (__DIR__ . '/../storage');
        $directory = self::$logThrottleDirectoryOverride ?? $storageDirectory;
        $marker = rtrim($directory, '/\\')
            . DIRECTORY_SEPARATOR
            . 'pos-rate-limit-'
            . substr(hash('sha256', __DIR__), 0, 12)
            . '-'
            . $operation
            . '.stamp';
        $handle = @fopen($marker, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return;
        }

        $lastLoggedAt = (int) stream_get_contents($handle);
        if ($lastLoggedAt > $now) {
            $lastLoggedAt = 0;
        }
        if (($now - $lastLoggedAt) < self::LOG_INTERVAL_SECONDS) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        rewind($handle);
        $updated = ftruncate($handle, 0)
            && fwrite($handle, (string) $now) !== false
            && fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if (!$updated) {
            return;
        }

        self::$lastLogAt[$operation] = $now;
        Logger::warning('Rate-limit storage unavailable; emergency policy active', [
            'operation' => $operation,
        ]);
    }

    /**
     * Deterministic backend controls for the focused limiter tests only.
     */
    public static function configureFailuresForTesting(
        ?bool $apcuFailure,
        ?bool $sqliteFailure,
        ?int $emergencyCapacity = null,
        ?string $logThrottleDirectory = null
    ): void {
        if (!defined('PHPUNIT_TEST_SUITE')) {
            throw new \LogicException('Rate-limit failure controls are test-only');
        }

        self::$forceApcuFailure = $apcuFailure;
        self::$forceSqliteFailure = $sqliteFailure;
        self::$emergencyCapacityOverride = $emergencyCapacity === null
            ? null
            : max(1, min(self::EMERGENCY_CAPACITY, $emergencyCapacity));
        self::$logThrottleDirectoryOverride = $logThrottleDirectory;
    }

    public static function useDatabaseForTesting(\PDO $database): void
    {
        if (!defined('PHPUNIT_TEST_SUITE')) {
            throw new \LogicException('Rate-limit database override is test-only');
        }

        self::$db = $database;
        self::$forceSqliteFailure = false;
    }

    /**
     * Reset the connection (useful for testing).
     */
    public static function reset(): void
    {
        self::$db = null;
        self::$sqliteRetryAt = 0;
        self::$emergencyCounters = [];
        self::$lastLogAt = [];
        self::$forceApcuFailure = null;
        self::$forceSqliteFailure = null;
        self::$emergencyCapacityOverride = null;
        self::$logThrottleDirectoryOverride = null;
    }
}
