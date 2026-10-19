<?php

namespace App\Models;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\Traits\ProductBarcodeTrait;
use Exception;
use PDO;

class Product {
    use ProductBarcodeTrait;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * جلب المنتجات مع دعم pagination اختياري.
     * إذا لم يُرسل page، تُرجع كل النتائج (backward-compatible).
     * إذا أُرسل page، تُرجع مصفوفة { data, pagination }.
     */
    public function all(array $filters = []): array {
        $branchId = \App\Services\AuthService::getGlobalBranchId();
        $where  = ['p.deleted_at IS NULL', 'p.branch_id = :branch_id'];
        $params = ['branch_id' => $branchId];

        if (!empty($filters['search'])) {
            $searchTerm = trim($filters['search']);
            // Use FULLTEXT search for name (index-friendly, replaces LIKE '%term%')
            // Fall back to LIKE for very short terms (< 3 chars) since FULLTEXT ft_min_word_len defaults to 3
            // Barcode lookups: always use exact match + prefix (index-friendly)
            if (mb_strlen($searchTerm, 'UTF-8') >= 3) {
                $where[] = '(
                    MATCH(p.name) AGAINST(:search_ft IN BOOLEAN MODE)
                    OR p.barcode = :search_exact
                    OR p.barcode LIKE :search_prefix
                    OR p.box_barcode = :search_exact2
                    OR p.box_barcode LIKE :search_prefix2
                    OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id = p.id AND (pb.barcode = :search_exact3 OR pb.barcode LIKE :search_prefix3))
                )';
                // Append * for prefix matching in BOOLEAN MODE (e.g., "حلي" matches "حليب")
                $params['search_ft']      = $searchTerm . '*';
            } else {
                // Short search terms: fall back to LIKE (FULLTEXT ignores words shorter than ft_min_word_len)
                $where[] = '(
                    p.name LIKE :search_wild
                    OR p.barcode = :search_exact
                    OR p.barcode LIKE :search_prefix
                    OR p.box_barcode = :search_exact2
                    OR p.box_barcode LIKE :search_prefix2
                    OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id = p.id AND (pb.barcode = :search_exact3 OR pb.barcode LIKE :search_prefix3))
                )';
                $params['search_wild']    = '%' . $searchTerm . '%';
            }
            $params['search_exact']   = $searchTerm;
            $params['search_prefix']  = $searchTerm . '%';
            $params['search_exact2']  = $searchTerm;
            $params['search_prefix2'] = $searchTerm . '%';
            $params['search_exact3']  = $searchTerm;
            $params['search_prefix3'] = $searchTerm . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[]              = 'p.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }
        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $where[] = 'p.quantity <= p.low_stock_threshold';
        }
        
        // تصفية المنتجات الأبناء (المقاسات) إلا إذا تم تحديد البحث عنها
        if (isset($filters['parent_product_id'])) {
            if ($filters['parent_product_id'] !== 'all') {
                $where[] = 'p.parent_product_id = :parent_product_id';
                $params['parent_product_id'] = $filters['parent_product_id'];
            }
        } else {
            $where[] = 'p.parent_product_id IS NULL';
        }

        $whereClause = implode(' AND ', $where);

        // ── Pagination (اختياري) ──
        $page  = isset($filters['page'])  ? max(1, (int) $filters['page'])  : null;
        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : null;

        if ($page !== null && $limit !== null) {
            // عدّ النتائج أولاً
            $countSql = "SELECT COUNT(*) FROM products p WHERE $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $offset = ($page - 1) * $limit;
            $sql = "SELECT p.*, c.name AS category_name
                    FROM products p
                    LEFT JOIN categories c ON c.id = p.category_id
                    WHERE $whereClause
                    ORDER BY p.name ASC, p.id ASC
                    LIMIT :pag_limit OFFSET :pag_offset";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $this->attachAdditionalBarcodes($rows);

            // جلب المقاسات لكل منتج رئيسي (batch query — no N+1)
            $this->attachSizes($rows);

            $pages = (int) ceil($total / $limit);
            return [
                'data' => $rows,
                'pagination' => [
                    'type'      => 'page',
                    'page'      => $page,
                    'limit'     => $limit,
                    'total'     => $total,
                    'pages'     => $pages,
                    'has_more'  => $page < $pages,
                    'truncated' => $total > count($rows),
                ],
            ];
        }

        // ── بدون pagination — للاستخدام الداخلي المتوافق مع الإصدارات السابقة ──
        // Internal unpaginated callers retain this path. HTTP offline catalog
        // synchronization uses the cursor methods below.
        $sql = "SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE $whereClause
                ORDER BY p.name ASC, p.id ASC
                LIMIT :fallback_limit";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':fallback_limit', $limit ?? 100, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $this->attachAdditionalBarcodes($rows);

        // جلب المقاسات لكل منتج رئيسي (batch query — no N+1)
        $this->attachSizes($rows);

        return $rows;
    }

    /**
     * Return one stable initial-catalog page without a COUNT(*) query.
     *
     * @return array{data: array, has_more: bool, last_id: int}
     */
    public function getCatalogSnapshotPage(int $afterId, int $limit): array
    {
        $branchId = \App\Services\AuthService::getGlobalBranchId();
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.branch_id = :branch_id
               AND p.deleted_at IS NULL
               AND p.id > :after_id
             ORDER BY p.id ASC
             LIMIT :catalog_limit'
        );
        $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':catalog_limit', $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $this->attachAdditionalBarcodes($rows);

        return [
            'data' => $rows,
            'has_more' => $hasMore,
            'last_id' => $rows === [] ? $afterId : (int) end($rows)['id'],
        ];
    }

    public function getCatalogVersion(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(id), 0)
             FROM product_catalog_changes
             WHERE branch_id = ?'
        );
        $stmt->execute([\App\Services\AuthService::getGlobalBranchId()]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{data: array, has_more: bool, last_sequence: int}
     */
    public function getCatalogChangePage(int $afterSequence, int $limit): array
    {
        $branchId = \App\Services\AuthService::getGlobalBranchId();
        $stmt = $this->db->prepare(
            'SELECT
                changes.id AS change_sequence,
                changes.product_id AS catalog_product_id,
                p.*,
                c.name AS category_name
             FROM product_catalog_changes changes
             LEFT JOIN products p
               ON p.id = changes.product_id
              AND p.branch_id = changes.branch_id
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE changes.branch_id = :branch_id
               AND changes.id > :after_sequence
             ORDER BY changes.id ASC
             LIMIT :catalog_limit'
        );
        $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        $stmt->bindValue(':after_sequence', $afterSequence, PDO::PARAM_INT);
        $stmt->bindValue(':catalog_limit', $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($events) > $limit;
        if ($hasMore) {
            array_pop($events);
        }

        $lastSequence = $afterSequence;
        $productsById = [];
        foreach ($events as $event) {
            $lastSequence = (int) $event['change_sequence'];
            $productId = (int) $event['catalog_product_id'];
            if ($event['id'] === null) {
                $productsById[$productId] = [
                    'id' => $productId,
                    '_deleted' => true,
                    'deleted_at' => null,
                ];
                continue;
            }

            unset($event['change_sequence'], $event['catalog_product_id']);
            $event['_deleted'] = $event['deleted_at'] !== null;
            $productsById[$productId] = $event;
        }

        $rows = array_values($productsById);
        $activeRows = [];
        foreach ($rows as $index => $row) {
            if (!$row['_deleted']) {
                $activeRows[$index] = $row;
            }
        }
        $this->attachAdditionalBarcodes($activeRows);
        foreach ($activeRows as $index => $row) {
            $rows[$index] = $row;
        }

        return [
            'data' => $rows,
            'has_more' => $hasMore,
            'last_sequence' => $lastSequence,
        ];
    }

    public function findById(int $id): ?array {
        $qb = new \App\Core\QueryBuilder($this->db);
        $product = $qb->table('products p')
            ->select('p.*', 'c.name AS category_name')
            ->leftJoin('categories c', 'c.id', '=', 'p.category_id')
            ->where('p.id', '=', $id)
            ->where('p.branch_id', '=', \App\Services\AuthService::getGlobalBranchId())
            ->whereNull('p.deleted_at')
            ->first();

        if (!$product) {
            return null;
        }
        $product['additional_barcodes'] = $this->getAdditionalBarcodesList($id);

        // جلب المقاسات المرتبطة
        $stmt = $this->db->prepare(
            'SELECT * FROM products
             WHERE parent_product_id = ? AND branch_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id, \App\Services\AuthService::getGlobalBranchId()]);
        $sizes = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $this->attachAdditionalBarcodes($sizes);
        $product['sizes'] = $sizes;

        return $product;
    }

    /**
     * Batch-fetch multiple products by their IDs in a single query.
     * Eliminates N+1 when enriching cart items or restoring invoice quantities.
     *
     * @param  int[]  $ids
     * @return array<int, array>  Keyed by product ID for O(1) lookup
     */
    public function findByIds(array $ids): array {
        if (empty($ids)) return [];
        $ids = array_map('intval', $ids);
        $ids = array_unique($ids);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $this->db->prepare(
            "SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id IN ($placeholders) AND p.branch_id = ? AND p.deleted_at IS NULL"
        );
        $stmt->execute([...array_values($ids), \App\Services\AuthService::getGlobalBranchId()]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Key by product ID for O(1) lookup
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $row;
        }
        return $result;
    }

    /**
     * Fetch only the current quantities for the given product IDs.
     * Lightweight alternative to findByIds() when only quantity is needed.
     *
     * @param  int[]  $ids
     * @return array<int, float>  product_id => quantity
     */
    public function getQuantitiesByIds(array $ids): array {
        if (empty($ids)) return [];
        $ids = array_map('intval', $ids);
        $ids = array_unique($ids);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $this->db->prepare(
            "SELECT id, quantity FROM products WHERE id IN ($placeholders) AND branch_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([...array_values($ids), \App\Services\AuthService::getGlobalBranchId()]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (float) $row['quantity'];
        }
        return $result;
    }

    /**
     * Batch-load sizes for multiple parent products in a single query.
     * Eliminates the N+1 problem where each product triggers a separate SELECT.
     */
    private function attachSizes(array &$rows): void
    {
        if (empty($rows)) return;

        $parentIds = array_column($rows, 'id');
        if (empty($parentIds)) return;

        $allSizes = [];
        foreach (array_chunk($parentIds, 500) as $chunk) {
            $placeholders = str_repeat('?,', count($chunk) - 1) . '?';
            $stmt = $this->db->prepare(
                "SELECT * FROM products
                 WHERE parent_product_id IN ($placeholders) AND branch_id = ? AND deleted_at IS NULL"
            );
            $stmt->execute([...$chunk, \App\Services\AuthService::getGlobalBranchId()]);
            $allSizes = array_merge($allSizes, $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        // Attach additional barcodes to all size rows in one batch
        $this->attachAdditionalBarcodes($allSizes);

        // Group sizes by parent_product_id
        $grouped = [];
        foreach ($allSizes as $size) {
            $grouped[$size['parent_product_id']][] = $size;
        }

        // Assign grouped sizes to each parent row
        foreach ($rows as &$row) {
            $row['sizes'] = $grouped[$row['id']] ?? [];
        }
        unset($row);
    }

    public function findByBarcode(string $barcode): ?array {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.branch_id = ?
               AND p.deleted_at IS NULL
               AND (
                    p.barcode = ?
                    OR p.box_barcode = ?
                    OR p.id IN (SELECT product_id FROM product_barcodes WHERE barcode = ?)
               )
             LIMIT 1'
        );
        $stmt->execute([
            \App\Services\AuthService::getGlobalBranchId(),
            $barcode,
            $barcode,
            $barcode,
        ]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }
        $product['additional_barcodes'] = $this->getAdditionalBarcodesList((int) $product['id']);
        return $product;
    }



    public function create(array $data): int {
        $unitType = $data['unit_type'] ?? 'piece';
        $sellByWeight = ($unitType === 'weight') ? 1 : 0;

        $stmt = $this->db->prepare(
            'INSERT INTO products (branch_id, name, barcode, box_barcode, price, cost, quantity, low_stock_threshold, category_id, parent_product_id, size_name, units_per_box, sell_by_weight, unit_type)
             VALUES (:branch_id, :name, :barcode, :box_barcode, :price, :cost, :quantity, :low_stock_threshold, :category_id, :parent_product_id, :size_name, :units_per_box, :sell_by_weight, :unit_type)'
        );
        $stmt->execute([
            'branch_id'           => \App\Services\AuthService::getGlobalBranchId(),
            'name'                => $data['name'],
            'barcode'             => $data['barcode'],
            'box_barcode'         => !empty($data['box_barcode']) ? $data['box_barcode'] : null,
            'price'               => (float) $data['price'],
            'cost'                => (isset($data['cost']) && $data['cost'] !== '') ? (float) $data['cost'] : 0.0,
            'quantity'            => (isset($data['quantity']) && $data['quantity'] !== '') ? (float) $data['quantity'] : 0.0,
            'low_stock_threshold' => (isset($data['low_stock_threshold']) && $data['low_stock_threshold'] !== '') ? (int) $data['low_stock_threshold'] : (defined('LOW_STOCK_THRESHOLD') ? LOW_STOCK_THRESHOLD : 5),
            'category_id'         => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'parent_product_id'   => !empty($data['parent_product_id']) ? (int)$data['parent_product_id'] : null,
            'size_name'           => !empty($data['size_name']) ? $data['size_name'] : null,
            'units_per_box'       => max(1, (int)($data['units_per_box'] ?? 1)),
            'sell_by_weight'      => $sellByWeight,
            'unit_type'           => $unitType,
        ]);
        return (int) $this->db->lastInsertId();
    }



    public function update(int $id, array $data): void {
        $unitType = $data['unit_type'] ?? 'piece';
        $sellByWeight = ($unitType === 'weight') ? 1 : 0;

        $stmt = $this->db->prepare(
            'UPDATE products SET
                name = :name,
                barcode = :barcode,
                box_barcode = :box_barcode,
                price = :price,
                cost = :cost,
                quantity = :quantity,
                low_stock_threshold = :low_stock_threshold,
                category_id = :category_id,
                parent_product_id = :parent_product_id,
                size_name = :size_name,
                units_per_box = :units_per_box,
                sell_by_weight = :sell_by_weight,
                unit_type = :unit_type
             WHERE id = :id AND branch_id = :branch_id'
        );
        $stmt->execute([
            'name'                => $data['name'],
            'barcode'             => $data['barcode'],
            'box_barcode'         => !empty($data['box_barcode']) ? $data['box_barcode'] : null,
            'price'               => (float) $data['price'],
            'cost'                => (isset($data['cost']) && $data['cost'] !== '') ? (float) $data['cost'] : 0.0,
            'quantity'            => (isset($data['quantity']) && $data['quantity'] !== '') ? (float) $data['quantity'] : 0.0,
            'low_stock_threshold' => (isset($data['low_stock_threshold']) && $data['low_stock_threshold'] !== '') ? (int) $data['low_stock_threshold'] : (defined('LOW_STOCK_THRESHOLD') ? LOW_STOCK_THRESHOLD : 5),
            'category_id'         => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'parent_product_id'   => !empty($data['parent_product_id']) ? (int)$data['parent_product_id'] : null,
            'size_name'           => !empty($data['size_name']) ? $data['size_name'] : null,
            'units_per_box'       => max(1, (int)($data['units_per_box'] ?? 1)),
            'sell_by_weight'      => $sellByWeight,
            'unit_type'           => $unitType,
            'id'                  => $id,
            'branch_id'           => \App\Services\AuthService::getGlobalBranchId(),
        ]);
    }

    /**
     * أسطر تمنع حذف المنتج بسبب مفاتيح أجنبية (فواتير، مشتريات).
     *
     * @return array{invoice_items: int, purchases: int}
     */
    public function referenceCounts(int $id): array {
        $stmt = $this->db->prepare(
            'SELECT
                (SELECT COUNT(*) FROM invoice_items WHERE product_id = ?) AS invoice_items,
                (SELECT COUNT(*) FROM purchases WHERE product_id = ?) AS purchases'
        );
        $stmt->execute([$id, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'invoice_items' => (int) ($row['invoice_items'] ?? 0),
            'purchases'     => (int) ($row['purchases'] ?? 0),
        ];
    }

    public function delete(int $id): void {
        // تفريغ الباركودات عند الحذف الناعم حتى يمكن إعادة استخدامها لمنتجات جديدة.
        // نحفظ الباركود القديم في الاسم كمرجع.
        $branchId = \App\Services\AuthService::getGlobalBranchId();
        $stmt = $this->db->prepare('SELECT barcode FROM products WHERE id = ? AND branch_id = ?');
        $stmt->execute([$id, $branchId]);
        $oldBarcode = $stmt->fetchColumn();
        if ($oldBarcode === false) {
            return;
        }

        $deletedBarcode = '__deleted_' . $id . '_' . time();
        $this->db->prepare(
            'UPDATE products SET deleted_at = NOW(), barcode = ?, box_barcode = NULL
             WHERE id = ? AND branch_id = ?'
        )->execute([$deletedBarcode, $id, $branchId]);

        // حذف الباركودات الإضافية من جدول product_barcodes
        $this->db->prepare('DELETE FROM product_barcodes WHERE product_id = ?')->execute([$id]);
    }

    public function decrementQuantity(int $id, float $qty): void {
        $stmt = $this->db->prepare(
            'UPDATE products SET quantity = quantity - ?
             WHERE id = ? AND branch_id = ? AND quantity >= ?'
        );
        $stmt->execute([$qty, $id, \App\Services\AuthService::getGlobalBranchId(), $qty]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Insufficient stock or out-of-scope product');
        }
    }

    public function incrementQuantity(int $id, float $qty): void {
        $stmt = $this->db->prepare(
            'UPDATE products SET quantity = quantity + ? WHERE id = ? AND branch_id = ?'
        );
        $stmt->execute([$qty, $id, \App\Services\AuthService::getGlobalBranchId()]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Out-of-scope product');
        }
    }

    /**
     * Batch-increment quantities for multiple branch-scoped products.
     *
     * @param list<array{product_id:int,quantity:float}> $increments
     */
    public function batchIncrementQuantity(array $increments): void
    {
        if ($increments === []) {
            return;
        }

        $cases = [];
        $ids = [];
        $params = ['branch_id' => \App\Services\AuthService::getGlobalBranchId()];
        foreach ($increments as $index => $item) {
            $cases[] = "WHEN id = :case_id_{$index} THEN quantity + :quantity_{$index}";
            $ids[] = ":where_id_{$index}";
            $params["case_id_{$index}"] = (int) $item['product_id'];
            $params["quantity_{$index}"] = (float) $item['quantity'];
            $params["where_id_{$index}"] = (int) $item['product_id'];
        }

        $stmt = $this->db->prepare(
            'UPDATE products SET quantity = CASE '
            . implode(' ', $cases)
            . ' ELSE quantity END WHERE id IN (' . implode(',', $ids) . ')'
            . ' AND branch_id = :branch_id AND deleted_at IS NULL'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() !== count($increments)) {
            throw new \RuntimeException('Out-of-scope product');
        }
    }

    /**
     * Update selected product costs in one branch-scoped statement.
     *
     * @param list<array{product_id:int,cost:float}> $updates
     */
    public function batchUpdateCosts(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $cases = [];
        $ids = [];
        $params = ['branch_id' => \App\Services\AuthService::getGlobalBranchId()];
        foreach ($updates as $index => $item) {
            $cases[] = "WHEN id = :case_id_{$index} THEN :cost_{$index}";
            $ids[] = ":where_id_{$index}";
            $params["case_id_{$index}"] = (int) $item['product_id'];
            $params["cost_{$index}"] = (float) $item['cost'];
            $params["where_id_{$index}"] = (int) $item['product_id'];
        }

        $stmt = $this->db->prepare(
            'UPDATE products SET cost = CASE '
            . implode(' ', $cases)
            . ' ELSE cost END WHERE id IN (' . implode(',', $ids) . ')'
            . ' AND branch_id = :branch_id AND deleted_at IS NULL'
        );
        $stmt->execute($params);
    }

    /**
     * Batch-decrement quantities for multiple products in a single query.
     * Uses CASE WHEN to update all rows atomically.
     *
     * @param array $decrements Array of ['product_id' => int, 'quantity' => float]
     */
    public function batchDecrementQuantity(array $decrements, bool $preventNegativeStock = true): void
    {
        if (empty($decrements)) return;

        $ids = [];
        $cases = [];
        $stockChecks = [];
        $params = [];
        $i = 0;

        foreach ($decrements as $item) {
            $pidParam = ":pid_{$i}";
            $qtyParam = ":qty_{$i}";
            $wherePidParam = ":where_pid_{$i}";
            $checkPidParam = ":check_pid_{$i}";
            $checkQtyParam = ":check_qty_{$i}";

            $cases[] = "WHEN id = {$pidParam} THEN quantity - {$qtyParam}";

            $params[$pidParam] = (int) $item['product_id'];
            $params[$qtyParam] = (float) $item['quantity'];
            $params[$wherePidParam] = (int) $item['product_id'];
            if ($preventNegativeStock) {
                $stockChecks[] = "WHEN id = {$checkPidParam} THEN {$checkQtyParam}";
                $params[$checkPidParam] = (int) $item['product_id'];
                $params[$checkQtyParam] = (float) $item['quantity'];
            }

            $ids[] = $wherePidParam;
            $i++;
        }

        $stockConstraint = $preventNegativeStock
            ? " AND quantity >= CASE " . implode(' ', $stockChecks) . " ELSE 0 END"
            : '';

        $sql = "UPDATE products SET quantity = CASE "
             . implode(' ', $cases)
             . " ELSE quantity END"
             . " WHERE id IN (" . implode(',', $ids) . ")"
             . " AND branch_id = :branch_id"
             . $stockConstraint;

        $params[':branch_id'] = \App\Services\AuthService::getGlobalBranchId();
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() !== count($decrements)) {
            throw new \RuntimeException('Insufficient stock or out-of-scope product');
        }
    }

    public function getLowStock(array $filters = []): array {
        $branchId = \App\Services\AuthService::getGlobalBranchId();
        $baseWhere = 'p.quantity <= p.low_stock_threshold AND p.deleted_at IS NULL AND p.branch_id = :branch_id';
        $baseParams = ['branch_id' => $branchId];

        $hasPagination = false;
        if (isset($filters['page']) && isset($filters['limit'])) {
            $page  = max(1, (int)$filters['page']);
            $limit = max(1, min(100, (int)$filters['limit']));
            $offset = ($page - 1) * $limit;
            $hasPagination = true;

            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM products p WHERE $baseWhere");
            $countStmt->execute($baseParams);
            $total = (int) $countStmt->fetchColumn();

            $limitStr = "LIMIT :pag_limit OFFSET :pag_offset";
        } else {
            $limitStr = "LIMIT 100";
        }

        $stmt = $this->db->prepare(
            "SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE $baseWhere
             ORDER BY p.quantity ASC
             $limitStr"
        );
        $stmt->bindValue(':branch_id', $branchId, \PDO::PARAM_INT);
        if ($hasPagination) {
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $this->attachAdditionalBarcodes($rows);

        if ($hasPagination) {
            return [
                'data' => $rows,
                'pagination' => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit)
                ]
            ];
        }

        return $rows;
    }

    public function getLowStockByProductIds(array $ids): array {
        if (empty($ids)) return [];
        // Ensure all IDs are integers to prevent any issues
        $ids = array_map('intval', $ids);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $this->db->prepare(
            "SELECT * FROM products 
             WHERE id IN ($placeholders)
               AND branch_id = ?
               AND quantity <= low_stock_threshold
               AND deleted_at IS NULL"
        );
        $stmt->execute([...$ids, \App\Services\AuthService::getGlobalBranchId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTotalProductsCount(): int {
        $branchId = \App\Services\AuthService::getGlobalBranchId();
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND branch_id = ?');
        $stmt->execute([$branchId]);
        return (int) $stmt->fetchColumn();
    }

    public function getLowStockProductsCount(): int {
        $branchId = \App\Services\AuthService::getGlobalBranchId();
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE quantity <= low_stock_threshold AND deleted_at IS NULL AND branch_id = ?');
        $stmt->execute([$branchId]);
        return (int) $stmt->fetchColumn();
    }
}
