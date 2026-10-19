<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use PDO;

class AuditLogController extends Controller
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/admin/audit-logs
     * Query params: ?page=1&per_page=50&action=delete_invoice&entity_type=invoice&user_id=1&from=2024-01-01&to=2024-12-31
     */
    public function index()
    {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $where  = [];
        $params = [];

        if (!empty($_GET['action'])) {
            $where[]           = 'al.action = :action';
            $params[':action'] = $_GET['action'];
        }
        if (!empty($_GET['entity_type'])) {
            $where[]                = 'al.entity_type = :entity_type';
            $params[':entity_type'] = $_GET['entity_type'];
        }
        if (!empty($_GET['user_id'])) {
            $where[]              = 'al.user_id = :user_id';
            $params[':user_id']   = (int)$_GET['user_id'];
        }
        if (!empty($_GET['from'])) {
            $where[]           = 'al.created_at >= :from_date';
            $params[':from_date'] = $_GET['from'] . ' 00:00:00';
        }
        if (!empty($_GET['to'])) {
            $where[]         = 'al.created_at <= :to_date';
            $params[':to_date'] = $_GET['to'] . ' 23:59:59';
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // العدد الإجمالي
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_logs al {$whereSQL}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // البيانات
        $sql = "SELECT al.*, u.name as user_name
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id
                {$whereSQL}
                ORDER BY al.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        return Response::success($logs, 'success', 200, [
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int)ceil($total / $perPage),
            ]
        ]);
    }
}
