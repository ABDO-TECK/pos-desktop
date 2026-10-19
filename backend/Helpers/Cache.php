<?php

namespace App\Helpers;


/**
 * Simple file-based cache for read-heavy endpoints.
 *
 * يستخدم JSON بدلاً من serialize/unserialize لتفادي ثغرات
 * PHP Object Injection الأمنية.
 */
class Cache {
    private static string $dir = '';
    private static ?\Redis $redis = null;
    private static bool $redisChecked = false;

    private static function getRedis(): ?\Redis {
        if (self::$redisChecked) return self::$redis;
        self::$redisChecked = true;

        $host = EnvLoader::get('REDIS_HOST', '');
        if ($host === '' || !class_exists('Redis')) return null;

        try {
            $r = new \Redis();
            $r->connect($host, (int) EnvLoader::get('REDIS_PORT', '6379'), 2.0);
            $pass = EnvLoader::get('REDIS_PASSWORD', '');
            if ($pass !== '') $r->auth($pass);
            $r->setOption(\Redis::OPT_PREFIX, 'pos:');
            self::$redis = $r;
        } catch (\Throwable $e) {
            Logger::warning('Redis connection failed, falling back', Logger::exceptionContext($e));
            self::$redis = null;
        }
        return self::$redis;
    }

    public static function init(): void {
        if (self::$dir === '') {
            $storageDir = $_ENV['APP_STORAGE_DIR'] ?? getenv('APP_STORAGE_DIR');
            if ($storageDir) {
                self::$dir = $storageDir . '/cache/';
            } else {
                self::$dir = __DIR__ . '/../storage/cache/';
            }
        }
        if (!is_dir(self::$dir)) {
            $previousUmask = umask(0077);
            @mkdir(self::$dir, 0700, true);
            umask($previousUmask);
        }
        @chmod(self::$dir, 0700);
    }

    public static function get(string $key): mixed {
        // 1. Redis
        $redis = self::getRedis();
        if ($redis) {
            try {
                $val = $redis->get($key);
                if ($val !== false) return json_decode($val, true);
                return null;
            } catch (\Throwable $e) { /* fallthrough */ }
        }

        // 2. APCu
        if (self::apcuAvailable()) {
            $success = false;
            $data = apcu_fetch('pos_cache_' . $key, $success);
            if ($success) return $data;
        }

        // 3. File
        self::init();
        $file = self::path($key);
        if (!file_exists($file)) return null;

        $content = @file_get_contents($file);
        if ($content === false) return null;

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            @unlink($file);
            return null;
        }

        if ($data['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $data['value'];
    }

    public static function set(string $key, mixed $value, int $ttl = 60): void {
        // 1. Redis
        $redis = self::getRedis();
        if ($redis) {
            try {
                $redis->setex($key, $ttl, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return;
            } catch (\Throwable $e) { /* fallthrough */ }
        }

        // 2. APCu
        if (self::apcuAvailable() && apcu_store('pos_cache_' . $key, $value, $ttl)) {
            return;
        }

        // 3. File
        self::init();
        $payload = json_encode([
            'value'   => $value,
            'expires' => time() + $ttl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        @file_put_contents(self::path($key), $payload, LOCK_EX);
    }

    public static function forget(string $key): void {
        $redis = self::getRedis();
        if ($redis) { try { $redis->del($key); } catch (\Throwable $e) {} }
        if (self::apcuAvailable() && function_exists('apcu_delete')) apcu_delete('pos_cache_' . $key);
        $file = self::path($key);
        if (file_exists($file)) @unlink($file);
    }

    public static function flush(): void {
        $redis = self::getRedis();
        if ($redis) { try { $redis->flushDB(); } catch (\Throwable $e) {} }
        if (self::apcuAvailable() && function_exists('apcu_clear_cache')) apcu_clear_cache();
        self::init();
        array_map('unlink', glob(self::$dir . '*.cache') ?: []);
    }

    // ══ Tag-based Cache ══════════════════════════════════════

    /** حفظ مع tags — كل tag هو مجموعة مفاتيح يمكن مسحها دفعة واحدة */
    public static function setWithTags(string $key, mixed $value, int $ttl, array $tags): void
    {
        self::set($key, $value, $ttl);
        foreach ($tags as $tag) {
            $tagKey = 'tag_keys_' . $tag;
            $existing = self::get($tagKey) ?? [];
            if (!in_array($key, $existing, true)) {
                $existing[] = $key;
            }
            // حفظ قائمة المفاتيح بـ TTL طويل (ساعة)
            self::set($tagKey, $existing, 3600);
        }
    }

    /** مسح كل المفاتيح المرتبطة بـ tag معين */
    public static function forgetTag(string $tag): void
    {
        $tagKey = 'tag_keys_' . $tag;
        $keys = self::get($tagKey) ?? [];
        foreach ($keys as $k) {
            self::forget($k);
        }
        self::forget($tagKey);
    }

    private static function path(string $key): string {
        self::init();
        return self::$dir . md5($key) . '.cache';
    }

    private static function apcuAvailable(): bool {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_store')) {
            return false;
        }

        return !function_exists('apcu_enabled') || apcu_enabled();
    }
}
