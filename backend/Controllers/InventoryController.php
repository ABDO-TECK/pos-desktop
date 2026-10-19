<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Helpers\Response;
use App\Helpers\AuditLog;
use App\Helpers\Logger;
use App\Models\Product;
use App\Services\AuthService;


class InventoryController extends Controller {

    private Product $productModel;
    private AuthService $authService;

    public function __construct(Product $productModel, AuthService $authService) {
        $this->productModel = $productModel;
        $this->authService = $authService;
    }

    public function index() {
        $filters = [
            'search'      => $this->getParam('search'),
            'category_id' => $this->getParam('category_id'),
        ];
        $filters += $this->getPaginationParams();

        $products = $this->productModel->all($filters);
        // المخزون يتغير بعد كل عملية شراء — لا نضع كاش هنا
        return Response::success($products);
    }

    public function lowStock() {
        $filters = [];
        $filters += $this->getPaginationParams();

        // المخزون المنخفض يتغير باستمرار — استجابة طازجة دائماً
        return Response::success($this->productModel->getLowStock($filters));
    }

    public function adjust(string $id) {
        $id = $this->resolveId($id);
        $request = new \App\Requests\InventoryAdjustRequest($this->getBody());
        $data = $request->validated();

        $product = $this->productModel->findById($id);
        if (!$product) return Response::notFound('Product not found');

        $newQty = (float) $data['quantity'];
        if ($newQty < 0) return Response::error('الكمية لا يمكن أن تكون سالبة', 400);

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Lock the row before applying the adjustment so the audit snapshot
            // and the write are based on the same branch-scoped state.
            $lockStmt = $db->prepare(
                'SELECT *
                 FROM products
                 WHERE id = ? AND branch_id = ? AND deleted_at IS NULL
                 FOR UPDATE'
            );
            $lockStmt->execute([$id, $this->authService->branchId()]);
            $lockedProduct = $lockStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$lockedProduct) {
                throw new \RuntimeException('Product changed concurrently');
            }

            $stmt = $db->prepare(
                'UPDATE products
                 SET quantity = ?
                 WHERE id = ? AND branch_id = ?'
            );
            $stmt->execute([$newQty, $id, $this->authService->branchId()]);

            AuditLog::logOrFail(
                $this->authService->id(),
                'adjust_inventory',
                'product',
                $id,
                $lockedProduct,
                $data
            );
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Logger::error('Inventory adjustment failed', Logger::exceptionContext($exception));
            return Response::serverError('Failed to adjust inventory');
        }

        return Response::success($this->productModel->findById($id), 'Inventory adjusted');
    }
}
