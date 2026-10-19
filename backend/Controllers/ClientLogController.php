<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Requests\ClientLogIndexRequest;
use App\Services\ClientLogReader;


/**
 * ClientLogController — يستقبل أخطاء الواجهة الأمامية (Frontend)
 * ويحفظها في ملفات اللوج باستخدام Logger.
 *
 * يُستخدم لتتبع أخطاء JavaScript/React التي تحدث عند المستخدمين
 * والتي عادةً ما تضيع بدون نظام تسجيل مركزي.
 */
class ClientLogController extends Controller
{
    public function __construct(private readonly ClientLogReader $reader)
    {
    }

    /**
     * POST /api/client-log
     *
     * Body المتوقع:
     * {
     *   "level": "error" | "warning" | "info",
     *   "message": "وصف الخطأ",
     *   "context": { ... بيانات إضافية اختيارية ... }
     * }
     */
    public function store()
    {
        $data = $this->getBody();
        $logs = $data['logs'] ?? [$data]; // يدعم الـ Batch و الـ Single (للتوافق)
        if (!is_array($logs)) {
            return Response::error('Invalid log payload', 422);
        }
        $logs = array_slice(array_values($logs), 0, 50);

        foreach ($logs as $logData) {
            if (!is_array($logData) || empty($logData['level']) || empty($logData['message'])) {
                continue;
            }

            $level = strtolower((string) ($logData['level'] ?? ''));
            if (!in_array($level, ['info', 'warning', 'error'], true)) {
                continue;
            }

            $message = '[CLIENT] ' . mb_substr((string) ($logData['message'] ?? 'Unknown error'), 0, 1000);

            $context = [];
            if (!empty($logData['context']) && is_array($logData['context'])) {
                $allowed = ['url', 'stack', 'component', 'userAgent', 'timestamp'];
                foreach ($allowed as $key) {
                    if (isset($logData['context'][$key])) {
                        $encoded = is_string($logData['context'][$key])
                            ? $logData['context'][$key]
                            : json_encode($logData['context'][$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $context[$key] = mb_substr((string) $encoded, 0, $key === 'stack' ? 8000 : 1000);
                    }
                }
            }

            if (isset($context['url'])) {
                $context['url'] = preg_replace(
                    '/([?&](?:token|key|secret|password)=)[^&]*/i',
                    '$1[REDACTED]',
                    (string) $context['url']
                ) ?? '[invalid-url]';
            }

            $context['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            match ($level) {
                'error'   => Logger::error($message, $context),
                'warning' => Logger::warning($message, $context),
                'info'    => Logger::info($message, $context),
            };
        }

        return Response::success(null, 'Logs received');
    }

    /**
     * GET /api/admin/client-logs
     * جلب السجلات لعرضها في لوحة التحكم
     */
    public function index()
    {
        return $this->respondWithLogs(false);
    }

    /**
     * GET /api/admin/error-logs
     * Return client, server, and PHP runtime entries for the maintenance console.
     */
    public function all()
    {
        return $this->respondWithLogs(true);
    }

    private function respondWithLogs(bool $includeAll)
    {
        $query = (new ClientLogIndexRequest($_GET))->normalized();
        $result = $this->reader->paginate($query['level'], $query['limit'], $query['cursor'], $includeAll);

        return Response::success(
            $result['data'],
            'success',
            200,
            ['pagination' => $result['pagination']]
        );
    }
}
