<?php

namespace App\Models\Traits;

use PDO;

/**
 * Reusable pagination trait for models.
 * Eliminates duplicated LIMIT/OFFSET + COUNT logic across models.
 *
 * Usage in a model's all() method:
 *   return $this->paginate($sql, $params, $filters, $whereClause);
 *
 * Requires: $this->db (a PDO instance) to be available in the consuming class.
 */
trait PaginationTrait
{
    /**
     * Execute a paginated query.
     *
     * @param string $baseSql    The SELECT query WITHOUT LIMIT/OFFSET (e.g. "SELECT p.*, c.name FROM products p JOIN ...")
     * @param array  $params     Named parameters for the query (e.g. ['branch_id' => 1])
     * @param array  $filters    Request filters containing optional 'page' and 'limit' keys
     * @param string $countTable The FROM clause for the COUNT query (e.g. "products p" or "invoices i")
     * @param string $whereClause The WHERE clause string (e.g. "p.deleted_at IS NULL AND p.branch_id = :branch_id")
     * @return array Either ['data' => [...], 'pagination' => [...]] or just the rows array
     */
    protected function paginate(string $baseSql, array $params, array $filters, string $countTable, string $whereClause): array
    {
        $page  = isset($filters['page'])  ? max(1, (int) $filters['page'])  : null;
        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : 100;

        if ($page !== null && $limit !== null) {
            // Count total rows
            $countSql = "SELECT COUNT(*) FROM {$countTable} WHERE {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            // Add LIMIT/OFFSET to base query
            $offset = ($page - 1) * $limit;
            $paginatedSql = $baseSql . " LIMIT :pag_limit OFFSET :pag_offset";

            $stmt = $this->db->prepare($paginatedSql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':pag_limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            return [
                'data' => $stmt->fetchAll(),
                'pagination' => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ];
        }

        // No pagination — return all rows
        $stmt = $this->db->prepare($baseSql . ' LIMIT :fallback_limit');
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':fallback_limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
