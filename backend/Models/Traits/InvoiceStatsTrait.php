<?php
namespace App\Models\Traits;

use PDO;
use App\Services\AuthService;

trait InvoiceStatsTrait {
    public function getDailySummary(string $date): array {
        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS total_invoices,
                SUM(total) AS total_revenue,
                SUM(discount) AS total_discount,
                SUM(tax) AS total_tax,
                SUM(total - tax) AS net_revenue
             FROM invoices
             WHERE branch_id = ? AND created_at >= ? AND created_at < ? AND status = "completed"'
        );
        $stmt->execute([
            AuthService::getGlobalBranchId(),
            $date . ' 00:00:00',
            date('Y-m-d 00:00:00', strtotime($date . ' +1 day')),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $row['total_profit'] = $this->getTotalProfitForDate($date);
        $row['total_cost']   = $this->getTotalCostForDate($date);
        return $row;
    }

    /** إجمالي تكلفة البضاعة المباعة: unit_cost × الكمية — فواتير مكتملة */
    public function getTotalCostForDate(string $date): float {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(ii.unit_cost * ii.quantity), 0)
             FROM invoice_items ii
             INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE inv.branch_id = ? AND inv.created_at >= ? AND inv.created_at < ?'
        );
        $stmt->execute([
            AuthService::getGlobalBranchId(),
            $date . ' 00:00:00',
            date('Y-m-d 00:00:00', strtotime($date . ' +1 day')),
        ]);
        return (float)$stmt->fetchColumn();
    }

    public function getTotalCostForMonth(int $month, int $year): float {
        [$startDate, $endDate] = $this->getMonthDateRange($month, $year);
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(ii.unit_cost * ii.quantity), 0)
             FROM invoice_items ii
             INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE inv.branch_id = ? AND inv.created_at >= ? AND inv.created_at < ?'
        );
        $stmt->execute([AuthService::getGlobalBranchId(), $startDate, $endDate]);
        return (float)$stmt->fetchColumn();
    }

    /** صافي الربح: (سعر البيع − تكلفة لحظة البيع المخزنة في البند) × الكمية */
    public function getTotalProfitForDate(string $date): float {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM((ii.price - ii.unit_cost) * ii.quantity), 0)
             FROM invoice_items ii
             INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE inv.branch_id = ? AND inv.created_at >= ? AND inv.created_at < ?'
        );
        $stmt->execute([
            AuthService::getGlobalBranchId(),
            $date . ' 00:00:00',
            date('Y-m-d 00:00:00', strtotime($date . ' +1 day')),
        ]);
        return (float)$stmt->fetchColumn();
    }

    public function getTotalProfitForMonth(int $month, int $year): float {
        [$startDate, $endDate] = $this->getMonthDateRange($month, $year);
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM((ii.price - ii.unit_cost) * ii.quantity), 0)
             FROM invoice_items ii
             INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE inv.branch_id = ? AND inv.created_at >= ? AND inv.created_at < ?'
        );
        $stmt->execute([AuthService::getGlobalBranchId(), $startDate, $endDate]);
        return (float)$stmt->fetchColumn();
    }

    public function getMonthlySummary(int $month, int $year): array {
        [$startDate, $endDate] = $this->getMonthDateRange($month, $year);
        $stmt = $this->db->prepare(
            'SELECT
                DATE(created_at) AS date,
                COUNT(*) AS total_invoices,
                SUM(total) AS total_revenue
             FROM invoices
             WHERE branch_id = ? AND created_at >= ? AND created_at < ? AND status = "completed"
             GROUP BY DATE(created_at)
             ORDER BY date ASC'
        );
        $stmt->execute([AuthService::getGlobalBranchId(), $startDate, $endDate]);
        return $stmt->fetchAll();
    }

    public function getTopProducts(int $limit = 10, ?string $fromDate = null, ?string $toDate = null): array {
        $limit = max(1, min(100, $limit));
        $where  = ['i.branch_id = :branch_id'];
        $params = ['branch_id' => AuthService::getGlobalBranchId()];
        if ($fromDate) {
            $where[]           = 'i.created_at >= :from';
            $params['from']    = $fromDate . ' 00:00:00';
        }
        if ($toDate) {
            $where[]         = 'i.created_at <= :to';
            $params['to']    = $toDate . ' 23:59:59';
        }

        $stmt = $this->db->prepare(
            'SELECT p.id, p.name, p.barcode,
                    SUM(ii.quantity) AS total_sold,
                    SUM(ii.subtotal) AS total_revenue,
                    SUM((ii.price - ii.unit_cost) * ii.quantity) AS total_profit
             FROM invoice_items ii
             JOIN invoices i ON i.id = ii.invoice_id AND i.status = "completed"
             JOIN products p ON p.id = ii.product_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY p.id, p.name, p.barcode
             ORDER BY total_sold DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getProfitReportTotals(int $month, int $year): array {
        [$startDate, $endDate] = $this->getMonthDateRange($month, $year);
        $stmt = $this->db->prepare(
            'SELECT
                COALESCE(SUM(ii.price * ii.quantity), 0)                      AS total_revenue,
                COALESCE(SUM(ii.unit_cost * ii.quantity), 0)                  AS total_cost,
                COALESCE(SUM((ii.price - ii.unit_cost) * ii.quantity), 0)      AS total_profit
             FROM invoice_items ii
             JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE inv.branch_id = ? AND inv.created_at >= ? AND inv.created_at < ?'
        );
        $stmt->execute([AuthService::getGlobalBranchId(), $startDate, $endDate]);
        return $stmt->fetch() ?: ['total_revenue' => 0, 'total_cost' => 0, 'total_profit' => 0];
    }

    public function getTopProfitProducts(int $month, int $year, int $limit = 20): array {
        $limit = max(1, min(100, $limit));
        [$startDate, $endDate] = $this->getMonthDateRange($month, $year);
        $stmt = $this->db->prepare(
            'SELECT
                p.id,
                p.name,
                MAX(p.price) AS price,
                SUM(ii.quantity) AS total_sold,
                SUM(ii.price * ii.quantity) AS revenue,
                SUM(ii.unit_cost * ii.quantity) AS cost,
                SUM((ii.price - ii.unit_cost) * ii.quantity) AS profit,
                ROUND(
                    CASE WHEN SUM(ii.price * ii.quantity) > 0
                    THEN 100 * SUM((ii.price - ii.unit_cost) * ii.quantity) / SUM(ii.price * ii.quantity)
                    ELSE 0 END,
                    2
                ) AS margin_pct
             FROM invoice_items ii
             JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             JOIN products p   ON p.id  = ii.product_id
             WHERE inv.branch_id = ? AND inv.created_at >= ? AND inv.created_at < ?
             GROUP BY p.id, p.name
             ORDER BY profit DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute([AuthService::getGlobalBranchId(), $startDate, $endDate]);
        return $stmt->fetchAll();
    }

    public function getDailyProfitBreakdown(int $month, int $year): array {
        [$startDate, $endDate] = $this->getMonthDateRange($month, $year);
        $stmt = $this->db->prepare(
            'SELECT
                DATE(inv.created_at) AS date,
                COALESCE(SUM(ii.price * ii.quantity), 0) AS revenue,
                COALESCE(SUM(ii.unit_cost * ii.quantity), 0) AS cost,
                COALESCE(SUM((ii.price - ii.unit_cost) * ii.quantity), 0) AS profit
             FROM invoice_items ii
             JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE inv.branch_id = ? AND inv.created_at >= ? AND inv.created_at < ?
             GROUP BY DATE(inv.created_at)
             ORDER BY date ASC'
        );
        $stmt->execute([AuthService::getGlobalBranchId(), $startDate, $endDate]);
        return $stmt->fetchAll();
    }

    public function getTodayRevenue(): float {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(total),0) FROM invoices
             WHERE branch_id = ? AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY AND status = ?'
        );
        $stmt->execute([AuthService::getGlobalBranchId(), 'completed']);
        return (float) $stmt->fetchColumn();
    }

    public function getMonthRevenue(): float {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(total),0) FROM invoices
             WHERE branch_id = ?
               AND created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
               AND created_at < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, "%Y-%m-01")
               AND status = ?'
        );
        $stmt->execute([AuthService::getGlobalBranchId(), 'completed']);
        return (float) $stmt->fetchColumn();
    }

    public function getTodayInvoicesCount(): int {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM invoices
             WHERE branch_id = ? AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY AND status = ?'
        );
        $stmt->execute([AuthService::getGlobalBranchId(), 'completed']);
        return (int) $stmt->fetchColumn();
    }
}
