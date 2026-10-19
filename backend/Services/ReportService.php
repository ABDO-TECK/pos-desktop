<?php

namespace App\Services;

use App\Repositories\InvoiceRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;

/**
 * ReportService — منطق الأعمال لتوليد التقارير والإحصائيات.
 *
 * يستخرج المنطق المتقدم من ReportController.
 */
class ReportService {

    private InvoiceRepository $invoiceRepo;
    private ExpenseRepository $expenseRepo;
    private ProductRepository $productRepo;
    private SupplierRepository $supplierRepo;

    public function __construct(
        InvoiceRepository $invoiceRepo,
        ExpenseRepository $expenseRepo,
        ProductRepository $productRepo,
        SupplierRepository $supplierRepo
    ) {
        $this->invoiceRepo = $invoiceRepo;
        $this->expenseRepo = $expenseRepo;
        $this->productRepo = $productRepo;
        $this->supplierRepo = $supplierRepo;
    }

    /**
     * جلب ملخص تقرير المبيعات اليومي.
     *
     * @param string $date التاريخ بصيغة Y-m-d
     * @return array تفاصيل التقرير
     */
    public function getDailySummary(string $date, int $page = 1, int $limit = 100): array {
        $summary = $this->invoiceRepo->getDailySummary($date);
        $invoicePage = $this->invoiceRepo->all([
            'date' => $date,
            'page' => $page,
            'limit' => $limit,
        ]);
        $totalExpenses = $this->expenseRepo->getTotalExpensesForDate($date);

        if ($summary) {
            $summary['total_expenses'] = $totalExpenses;
            $summary['net_profit'] = (float)($summary['total_profit'] ?? 0) - $totalExpenses;
        }

        return [
            'date'       => $date,
            'summary'    => $summary,
            'invoices'   => $invoicePage['data'],
            'pagination' => $invoicePage['pagination'],
        ];
    }

    /**
     * جلب تقرير المبيعات الشهري.
     *
     * @param int $month الشهر
     * @param int $year السنة
     * @return array تفاصيل التقرير
     */
    public function getMonthlySummary(int $month, int $year): array {
        $data  = $this->invoiceRepo->getMonthlySummary($month, $year);

        $totalRevenue  = array_sum(array_column($data, 'total_revenue'));
        $totalInvoices = array_sum(array_column($data, 'total_invoices'));
        $totalProfit   = $this->invoiceRepo->getTotalProfitForMonth($month, $year);
        $totalCost     = $this->invoiceRepo->getTotalCostForMonth($month, $year);
        $totalExpenses = $this->expenseRepo->getTotalExpensesForMonth($month, $year);
        $netProfit     = $totalProfit - $totalExpenses;

        return [
            'month'           => $month,
            'year'            => $year,
            'total_revenue'   => $totalRevenue,
            'total_cost'      => $totalCost,
            'total_invoices'  => $totalInvoices,
            'total_profit'    => $totalProfit,
            'total_expenses'  => $totalExpenses,
            'net_profit'      => $netProfit,
            'daily_breakdown' => $data,
        ];
    }

    /**
     * جلب المنتجات الأكثر مبيعاً في فترة زمنية.
     *
     * @param int $limit الحد الأقصى للمنتجات
     * @param string|null $fromDate من تاريخ
     * @param string|null $toDate إلى تاريخ
     * @return array قائمة المنتجات
     */
    public function getTopProducts(int $limit = 10, ?string $fromDate = null, ?string $toDate = null): array {
        return $this->invoiceRepo->getTopProducts($limit, $fromDate, $toDate);
    }

    /**
     * جلب تقرير الأرباح الشهري المفصل.
     *
     * @param int $month الشهر
     * @param int $year السنة
     * @return array تفاصيل تقرير الأرباح
     */
    public function getProfitReport(int $month, int $year): array {
        $totals = $this->invoiceRepo->getProfitReportTotals($month, $year);
        $topProfit = $this->invoiceRepo->getTopProfitProducts($month, $year, 20);
        $dailyBreakdown = $this->invoiceRepo->getDailyProfitBreakdown($month, $year);

        $revenue = (float)$totals['total_revenue'];
        $cost    = (float)$totals['total_cost'];
        $profit  = (float)$totals['total_profit'];
        $expenses = $this->expenseRepo->getTotalExpensesForMonth($month, $year);
        $netProfit = $profit - $expenses;
        $margin  = $revenue > 0 ? round($netProfit / $revenue * 100, 2) : 0;

        return [
            'month'          => $month,
            'year'           => $year,
            'total_revenue'  => $revenue,
            'total_cost'     => $cost,
            'total_profit'   => $profit,
            'total_expenses' => $expenses,
            'net_profit'     => $netProfit,
            'profit_margin'  => $margin,
            'top_products'   => $topProfit,
            'daily_breakdown'=> $dailyBreakdown,
        ];
    }

    /**
     * جلب ملخص عام لجميع المؤشرات ليوم وشهر محددين (للوحة التحكم).
     *
     * @return array ملخص الإحصائيات
     */
    public function getDashboardSummary(): array {
        $todayRevenue   = $this->invoiceRepo->getTodayRevenue();
        $monthRevenue   = $this->invoiceRepo->getMonthRevenue();
        $todayInvoices  = $this->invoiceRepo->getTodayInvoicesCount();
        
        $totalProducts  = $this->productRepo->getTotalProductsCount();
        $lowStockCount  = $this->productRepo->getLowStockProductsCount();
        $totalSuppliers = $this->supplierRepo->getTotalSuppliersCount();

        $monthProfit = $this->invoiceRepo->getTotalProfitForMonth((int)date('n'), (int)date('Y'));
        $todayProfit = $this->invoiceRepo->getTotalProfitForDate(date('Y-m-d'));
        $todayCost   = $this->invoiceRepo->getTotalCostForDate(date('Y-m-d'));
        $monthCost   = $this->invoiceRepo->getTotalCostForMonth((int)date('n'), (int)date('Y'));
        
        $todayExpenses = $this->expenseRepo->getTotalExpensesForDate(date('Y-m-d'));
        $monthExpenses = $this->expenseRepo->getTotalExpensesForMonth((int)date('n'), (int)date('Y'));

        return [
            'today_revenue'  => (float)$todayRevenue,
            'month_revenue'  => (float)$monthRevenue,
            'today_cost'     => $todayCost,
            'month_cost'     => $monthCost,
            'today_profit'   => $todayProfit,
            'month_profit'   => $monthProfit,
            'today_expenses' => $todayExpenses,
            'month_expenses' => $monthExpenses,
            'today_net_profit' => $todayProfit - $todayExpenses,
            'month_net_profit' => $monthProfit - $monthExpenses,
            'today_invoices' => (int)$todayInvoices,
            'total_products' => (int)$totalProducts,
            'low_stock_count'=> (int)$lowStockCount,
            'total_suppliers'=> (int)$totalSuppliers,
        ];
    }
}
