<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Requests\DailyReportRequest;
use App\Services\ReportService;

class ReportController extends Controller {

    private ReportService $reportService;

    public function __construct(ReportService $reportService) {
        $this->reportService = $reportService;
    }

    public function daily() {
        $request = new DailyReportRequest([
            'date' => $this->getParam('date', date('Y-m-d')),
            'page' => $this->getParam('page', 1),
            'limit' => $this->getParam('limit', 100),
        ]);
        $params = $request->validated();
        $data = $this->reportService->getDailySummary(
            $params['date'],
            (int) $params['page'],
            (int) $params['limit']
        );
        $pagination = $data['pagination'];
        unset($data['pagination']);

        return Response::success($data, 'success', 200, ['pagination' => $pagination]);
    }

    public function monthly() {
        $month = (int)$this->getParam('month', date('n'));
        $year  = (int)$this->getParam('year', date('Y'));
        $data  = $this->reportService->getMonthlySummary($month, $year);
        return Response::success($data);
    }

    public function topProducts() {
        $limit = filter_var(
            $this->getParam('limit', 10),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]]
        );
        $limit = $limit ?: 10;
        $fromDate = $this->getParam('from');
        $toDate   = $this->getParam('to');
        $products = $this->reportService->getTopProducts($limit, $fromDate, $toDate);
        return Response::success($products);
    }

    public function profitReport() {
        $month = (int)$this->getParam('month', date('n'));
        $year  = (int)$this->getParam('year', date('Y'));
        $data = $this->reportService->getProfitReport($month, $year);
        return Response::success($data);
    }

    public function summary() {
        $summary = $this->reportService->getDashboardSummary();
        return Response::cacheable($summary, 120);
    }
}

