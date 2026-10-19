<?php
namespace App\Middleware;

class CompressionMiddleware
{
    /** الحد الأدنى لحجم الـ body بالبايت قبل تفعيل الضغط */
    private const MIN_SIZE_BYTES = 1024;

    public function handle(callable $next): mixed
    {
        $response = $next();

        // تجاهل الاستجابات غير المصفوفة أو SSE
        if (!is_array($response) || !isset($response['body'])) {
            return $response;
        }

        // فحص هل العميل يدعم gzip
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        if (stripos($acceptEncoding, 'gzip') === false) {
            return $response;
        }

        $json = json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) < self::MIN_SIZE_BYTES) {
            return $response;
        }

        $compressed = gzencode($json, 6);
        if ($compressed === false) {
            return $response;
        }

        // استبدال الـ body بالنسخة المضغوطة وإضافة الـ headers
        $response['compressed_body'] = $compressed;
        $response['headers'] = $response['headers'] ?? [];
        $response['headers']['Content-Encoding'] = 'gzip';
        $response['headers']['Vary'] = 'Accept-Encoding';
        $response['headers']['Content-Length'] = (string) strlen($compressed);

        return $response;
    }
}
