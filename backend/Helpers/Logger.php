<?php

namespace App\Helpers;

use Throwable;

/**
 * Logger — نظام تسجيل أحداث بمستويات متعددة.
 *
 * يكتب إلى ملفات يومية في مجلد logs/ مع دعم:
 * - مستويات: DEBUG, INFO, WARNING, ERROR, CRITICAL
 * - تدوير تلقائي للملفات (يحتفظ بآخر 30 يومًا)
 * - سياق إضافي (context array)
 * - تنسيق موحّد لكل سطر
 *
 * الاستخدام:
 *   Logger::info('عملية بيع جديدة', ['invoice_id' => 42]);
 *   Logger::error('فشل الاتصال بقاعدة البيانات', ['host' => 'localhost']);
 */
class Logger
{
    private const REDACTED_KEYS = [
        'authorization',
        'cookie',
        'password',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'api_key',
        'private_key',
        'update_private_key',
        'signing_key',
    ];

    /** مستويات التسجيل */
    public const DEBUG    = 'DEBUG';
    public const INFO     = 'INFO';
    public const WARNING  = 'WARNING';
    public const ERROR    = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    /** @var string مجلد الملفات */
    private static string $logDir = '';

    /** @var int عدد الأيام التي يُحتفظ بملفاتها */
    private static int $retainDays = 30;

    /** @var int الحد الأقصى لحجم ملف اللوج (5 ميجابايت) */
    private static int $maxFileSize = 5 * 1024 * 1024;

    /** @var string|null الحد الأدنى للتسجيل (null = تسجيل كل شيء) */
    private static ?string $minLevel = null;

    /** @var array<string, string> مسار ملف التدوير النشط لكل يوم */
    private static array $activeLogPaths = [];

    /** ترتيب المستويات (للمقارنة) */
    private const LEVEL_ORDER = [
        self::DEBUG    => 0,
        self::INFO     => 1,
        self::WARNING  => 2,
        self::ERROR    => 3,
        self::CRITICAL => 4,
    ];

    /**
     * تهيئة مجلد الملفات (يُستدعى تلقائيًا).
     */
    private static function init(): void
    {
        if (self::$logDir === '') {
            $logsPath = $_ENV['LOGS_PATH'] ?? getenv('LOGS_PATH');
            if (!$logsPath) {
                $storageDir = $_ENV['APP_STORAGE_DIR'] ?? getenv('APP_STORAGE_DIR');
                if ($storageDir) {
                    $logsPath = $storageDir . '/logs';
                } else {
                    $logsPath = __DIR__ . '/../logs';
                }
            }
            self::$logDir = $logsPath;
        }

        if (!is_dir(self::$logDir)) {
            $previousUmask = umask(0077);
            @mkdir(self::$logDir, 0700, true);
            umask($previousUmask);
        }
        @chmod(self::$logDir, 0700);
    }

    public static function getLogDirectory(): string
    {
        self::init();
        return self::$logDir;
    }

    public static function getTimezone(): \DateTimeZone
    {
        $configured = defined('APP_TIMEZONE')
            ? (string) APP_TIMEZONE
            : trim((string) (getenv('APP_TIMEZONE') ?: ''));

        try {
            return new \DateTimeZone($configured !== '' ? $configured : date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    /** @var string|null Active request correlation identifier */
    private static ?string $currentRequestId = null;

    public static function setRequestId(string $requestId): void
    {
        self::$currentRequestId = $requestId;
    }

    public static function getRequestId(): string
    {
        if (self::$currentRequestId === null) {
            self::$currentRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
        }
        return self::$currentRequestId;
    }

    /**
     * Return safe, structured diagnostic fields for an exception with full SQL/driver details.
     *
     * @param Throwable $exception
     * @param array $extraContext Additional sanitized payload or metadata
     * @return array
     */
    public static function exceptionContext(Throwable $exception, array $extraContext = []): array
    {
        $message = $exception->getMessage();
        // Redact any credential or secret patterns from exception messages
        $cleanMessage = preg_replace(
            '/((?:password|token|secret|api[_-]?key)\s*[=:]\s*)[^\s,;]+/i',
            '$1[REDACTED]',
            $message
        ) ?? $message;

        $context = [
            'request_id' => self::getRequestId(),
            'reference'  => bin2hex(random_bytes(8)),
            'exception'  => get_class($exception),
            'code'       => $exception->getCode(),
            'message'    => function_exists('mb_substr') ? mb_substr($cleanMessage, 0, 1000) : substr($cleanMessage, 0, 1000),
            'file'       => basename($exception->getFile()),
            'line'       => $exception->getLine(),
        ];

        if ($exception instanceof \PDOException) {
            $errorInfo = $exception->errorInfo ?? [];
            $context['sql_state']   = (string)($errorInfo[0] ?? $exception->getCode());
            $context['driver_code'] = (int)($errorInfo[1] ?? 0);
            $context['driver_msg']  = (string)($errorInfo[2] ?? $cleanMessage);
        }

        if (!empty($extraContext)) {
            $context['payload'] = self::redactContext($extraContext);
        }

        return $context;
    }

    /**
     * تعيين الحد الأدنى للتسجيل.
     * مثال: Logger::setMinLevel(Logger::WARNING) → يُسجَّل WARNING + ERROR + CRITICAL فقط.
     */
    public static function setMinLevel(string $level): void
    {
        self::$minLevel = $level;
    }

    /**
     * تسجيل رسالة بمستوى محدد.
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        // فحص الحد الأدنى
        if (self::$minLevel !== null) {
            $minOrder = self::LEVEL_ORDER[self::$minLevel] ?? 0;
            $curOrder = self::LEVEL_ORDER[$level] ?? 0;
            if ($curOrder < $minOrder) {
                return;
            }
        }

        self::init();

        $now      = new \DateTimeImmutable('now', self::getTimezone());
        $date     = $now->format('Y-m-d');
        $time     = $now->format('Y-m-d H:i:s');
        $filePath = self::resolveLogPath($date);

        // بناء السطر بتنسيق JSON (Structured Logging)
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message) ?? $message;
        $context = self::redactContext($context);
        $logData = [
            'timestamp' => $time,
            'timezone'  => $now->getTimezone()->getName(),
            'level'     => $level,
            'message'   => $message,
            'context'   => $context
        ];
        $line = json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // كتابة ذرية (مع قفل)
        @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
        @chmod($filePath, 0600);

        // أيضًا إرسال إلى error_log للتوافق مع أدوات المراقبة
        if (in_array($level, [self::ERROR, self::CRITICAL], true)) {
            $contextStr = !empty($context)
                ? ' - Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '';
            error_log("[POS][{$level}] {$message}{$contextStr}");
        }
    }

    public static function redactContext(array $context): array
    {
        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, self::REDACTED_KEYS, true)
                || str_ends_with($normalizedKey, '_token')
                || str_ends_with($normalizedKey, '_secret')) {
                $context[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $context[$key] = self::redactContext($value);
            } elseif (is_string($value)) {
                $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
                if (in_array($normalizedKey, ['url', 'referer'], true)) {
                    $cleaned = preg_replace(
                        '/([?&](?:token|key|secret|password)=)[^&]*/i',
                        '$1[REDACTED]',
                        $cleaned
                    ) ?? '[invalid-url]';
                }
                $context[$key] = $cleaned;
            }
        }

        return $context;
    }

    // ── Shorthand methods ─────────────────────────────────────────

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    // ── Maintenance ───────────────────────────────────────────────

    /**
     * تحديد مسار ملف اللوج مع تدوير تلقائي عند تجاوز الحد.
     * pos-2026-05-05.log → pos-2026-05-05.1.log → pos-2026-05-05.2.log ...
     */
    private static function resolveLogPath(string $date): string
    {
        if (isset(self::$activeLogPaths[$date])) {
            $cachedPath = self::$activeLogPaths[$date];
            if (self::hasCapacity($cachedPath)) {
                return $cachedPath;
            }

            $rotationIndex = self::rotationIndex($cachedPath) + 1;
            return self::$activeLogPaths[$date] = self::findAvailableRotation($date, $rotationIndex);
        }

        $basePath = self::$logDir . "/pos-{$date}.log";

        // إذا كان الملف أصغر من الحد، استخدمه مباشرة
        if (self::hasCapacity($basePath)) {
            return self::$activeLogPaths[$date] = $basePath;
        }

        // يتم المسح مرة واحدة فقط لكل عملية/يوم، ثم يُحفظ الملف النشط في الذاكرة.
        $rotationIndex = 0;
        $rotationPath = null;
        foreach (self::uniqueFiles([
            self::$logDir . "/pos-{$date}.*.log",
        ]) as $file) {
            $index = self::rotationIndex($file);
            if ($index > $rotationIndex) {
                $rotationIndex = $index;
                $rotationPath = $file;
            }
        }

        if ($rotationPath !== null && self::hasCapacity($rotationPath)) {
            return self::$activeLogPaths[$date] = $rotationPath;
        }

        return self::$activeLogPaths[$date] = self::findAvailableRotation($date, $rotationIndex + 1);
    }

    private static function findAvailableRotation(string $date, int $rotationIndex): string
    {
        do {
            $path = self::$logDir . "/pos-{$date}.{$rotationIndex}.log";
            if (self::hasCapacity($path)) {
                return $path;
            }
            $rotationIndex++;
        } while (true);
    }

    private static function hasCapacity(string $path): bool
    {
        if (!is_file($path)) {
            return true;
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        return $size !== false && $size < self::$maxFileSize;
    }

    private static function rotationIndex(string $path): int
    {
        return preg_match('/\.(\d+)\.log$/', basename($path), $matches)
            ? (int) $matches[1]
            : 0;
    }

    /**
     * حذف ملفات اللوج القديمة (أقدم من $retainDays يوم).
     * يمكن استدعاؤها دوريًا أو ضمن Migrations.
     */
    public static function cleanup(?string $logDir = null, ?int $retainDays = null): int
    {
        self::init();
        $deleted = 0;
        $directory = $logDir ?? self::$logDir;
        $files = self::getLogFiles($directory);
        $cutoff = time() - (($retainDays ?? self::$retainDays) * 86400);

        foreach ($files as $file) {
            $modifiedAt = filemtime($file);
            if ($modifiedAt !== false && $modifiedAt < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    public static function getLogFiles(?string $logDir = null, bool $newestFirst = false): array
    {
        self::init();
        $directory = rtrim($logDir ?? self::$logDir, '/\\');
        $files = self::uniqueFiles([
            $directory . '/pos-*.log',
            $directory . '/pos-*.*.log',
        ]);

        if ($newestFirst) {
            usort($files, static function (string $left, string $right): int {
                $leftParts = self::logFileSortParts($left);
                $rightParts = self::logFileSortParts($right);
                return $rightParts <=> $leftParts;
            });
        }

        return $files;
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    private static function uniqueFiles(array $patterns): array
    {
        $unique = [];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }

                $canonicalPath = realpath($file) ?: $file;
                $key = DIRECTORY_SEPARATOR === '\\'
                    ? strtolower($canonicalPath)
                    : $canonicalPath;
                $unique[$key] = $canonicalPath;
            }
        }

        return array_values($unique);
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private static function logFileSortParts(string $path): array
    {
        $name = basename($path);
        if (preg_match('/^pos-(\d{4}-\d{2}-\d{2})(?:\.(\d+))?\.log$/', $name, $matches)) {
            return [$matches[1], isset($matches[2]) ? (int) $matches[2] : 0, $name];
        }

        return ['', 0, $name];
    }
}
