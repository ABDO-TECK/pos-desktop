<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\HealthService;


class HealthController {

    private HealthService $healthService;

    public function __construct(HealthService $healthService) {
        $this->healthService = $healthService;
    }

    public function check() {
        return Response::json($this->healthService->getLiveness());
    }

    public function diagnostics() {
        $result = $this->healthService->runHealthCheck();

        $statusCode = $result['critical_failed'] ? 503 : 200;
        return Response::json([
            'status'          => $result['status'],
            'critical_failed' => $result['critical_failed'],
            'checks'          => $result['checks'],
            'version'         => $result['version'],
        ], $statusCode);
    }

    /**
     * GET /api/health/metrics — مقاييس أداء متقدمة (للمدير فقط)
     */
    public function metrics() {
        $metrics = $this->healthService->getMetrics();
        return Response::cacheable($metrics, 30);
    }

    /**
     * GET /api/admin/health/slow-queries
     * تحليل EXPLAIN للاستعلامات الرئيسية.
     */
    public function slowQueries() {
        $results = $this->healthService->analyzeSlowQueries();
        return Response::success($results);
    }

    /**
     * GET /api/system/network-info — جلب معلومات عناوين الشبكة لتشغيل النظام من الهاتف
     */
    public function networkInfo() {
        $info = $this->healthService->getNetworkInfo();
        return Response::success($info);
    }
}
