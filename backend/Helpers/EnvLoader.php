<?php

namespace App\Helpers;


/**
 * محمّل ملفات البيئة (.env) — خفيف ولا يعتمد على مكتبات خارجية.
 *
 * يقرأ ملف .env ويحقن المتغيرات في $_ENV و getenv().
 * يتجاهل الأسطر الفارغة والتعليقات (#).
 * يدعم القيم بين علامات اقتباس مزدوجة أو مفردة.
 *
 * الاستخدام:
 *   EnvLoader::load(__DIR__ . '/../.env');
 *   $val = EnvLoader::get('DB_HOST', 'localhost');
 */
class EnvLoader
{
    /** @var array<string, string> القيم المحمّلة */
    private static array $values = [];

    /** @var bool هل جرى التحميل مسبقاً */
    private static bool $loaded = false;

    /**
     * تحميل ملف .env.
     * إذا كان الملف غير موجود يتم تجاهله بصمت (مناسب للإنتاج).
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // تجاهل التعليقات والأسطر الفارغة
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // يجب أن يحتوي على =
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // إزالة علامات الاقتباس المحيطة
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // تحويل القيم الخاصة
            $value = match (strtolower($value)) {
                'true'   => '1',
                'false'  => '0',
                'null'   => '',
                '(empty)' => '',
                default  => $value,
            };

            self::$values[$key] = $value;

            // تعيين في البيئة (لا نتجاوز متغيرات النظام الموجودة). بعض PHP
            // builds تستخدم variables_order لا يتضمن E، لذلك يجب فحص getenv()
            // قبل ملء $_ENV وإلا ستتغلب قيم ملف .env على متغيرات التشغيل.
            $environmentValue = getenv($key);
            if (!array_key_exists($key, $_ENV) && $environmentValue === false) {
                $_ENV[$key] = $value;
            }
            if ($environmentValue === false) {
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    /**
     * قراءة قيمة من البيئة — تعطي متغيرات التشغيل الخارجية الأولوية على ملف .env.
     */
    public static function get(string $key, string $default = ''): string
    {
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }

        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        return self::$values[$key] ?? $default;
    }

    /**
     * قراءة كرقم صحيح.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::get($key, '');
        return $val !== '' ? (int) $val : $default;
    }

    /**
     * قراءة كعدد عشري.
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        $val = self::get($key, '');
        return $val !== '' ? (float) $val : $default;
    }

    /**
     * قراءة كقيمة منطقية.
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key, '');
        if ($val === '') {
            return $default;
        }
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * التحقق من وجود المتغيرات الإجبارية.
     * إذا كان أي متغير مفقوداً، يتوقف التطبيق برسالة واضحة.
     *
     * @param string[] $requiredKeys قائمة بأسماء المتغيرات المطلوبة
     */
    public static function validate(array $requiredKeys): void
    {
        $missing = [];
        foreach ($requiredKeys as $key) {
            $val = self::get($key, '');
            if ($val === '') {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            $list = implode(', ', $missing);
            $msg  = "❌ متغيرات بيئة مفقودة: [{$list}]\n"
                   . "تأكد من وجودها في ملف backend/.env\n"
                   . "يمكنك نسخ .env.example إلى .env وتعديل القيم.";

            if (php_sapi_name() === 'cli') {
                fwrite(STDERR, $msg . "\n");
                exit(1);
            }

            // في بيئة الويب: نرسل JSON واضح
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'status'  => 'error',
                'message' => "متغيرات بيئة مفقودة: {$list}. تحقق من ملف .env",
            ], JSON_UNESCAPED_UNICODE);
            exit(1);
        }
    }
}
