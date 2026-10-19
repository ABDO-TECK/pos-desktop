<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ValidationException;
use App\Helpers\ErrorCodes;
use App\Helpers\EventDispatcher;
use App\Helpers\Response;
use App\Models\Product;
use App\Models\PriceHistory;
use App\Requests\ProductRequest;
use App\Requests\ProductCatalogSyncRequest;
use App\Services\ProductService;
use App\Services\AuthService;


class ProductController extends Controller {

    private ProductService $productService;
    private Product        $productModel;
    private PriceHistory   $priceHistory;
    private AuthService    $authService;

    public function __construct(ProductService $productService, PriceHistory $priceHistory, AuthService $authService) {
        $this->productService = $productService;
        $this->productModel   = $productService->getProductModel();
        $this->priceHistory   = $priceHistory;
        $this->authService    = $authService;
    }

    public function index() {
        $filters = [
            'search'      => $this->getParam('search'),
            'category_id' => $this->getParam('category_id'),
            'low_stock'   => $this->getParam('low_stock'),
            'page'        => max(1, (int) $this->getParam('page', 1)),
            'limit'       => max(1, min(100, (int) $this->getParam('limit', 100))),
        ];

        $result = $this->productModel->all($filters);

        // إذا كانت النتيجة paginated (تحتوي data + pagination)
        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    public function catalogSync() {
        $request = new ProductCatalogSyncRequest([
            'checkpoint' => $this->getParam('checkpoint'),
            'limit' => $this->getParam('limit', 500),
        ]);
        $params = $request->validated();

        try {
            $result = $this->productService->syncCatalog(
                $params['checkpoint'] ?? null,
                (int) $params['limit']
            );
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success(
            $result['data'],
            'success',
            200,
            [
                'catalog_scope' => $result['catalog_scope'],
                'catalog_version' => $result['catalog_version'],
                'pagination' => $result['pagination'],
            ]
        );
    }

    public function show(string $id) {
        // Support lookup by barcode via ?barcode=xxx
        $barcode = $this->getParam('barcode');
        if ($id === 'barcode' && $barcode) {
            $product = $this->productModel->findByBarcode($barcode);
        } else {
            $id = $this->resolveId($id);
            $product = $this->productModel->findById($id);
        }

        if (!$product) {
            return Response::notFound('Product not found');
        }
        return Response::success($product);
    }

    public function store() {
        try {
            $body = $this->getBody();
            $request = new ProductRequest($body);
            $data = $request->validated();
            
            $result = $this->productService->createProduct($data);

            if (!$result['ok']) {
                $code = $result['code'] ?? 500;
                $errorCode = $code >= 500
                    ? ErrorCodes::SERVER_ERROR
                    : ErrorCodes::VALIDATION_FAILED;
                return Response::error($result['error'], $code, null, $errorCode);
            }
            return Response::success($result['product'], 'Product created', 201);
        } catch (ValidationException $e) {
            return Response::error($this->productValidationMessage($e->getErrors()), 422, $e->getErrors(), ErrorCodes::VALIDATION_FAILED);
        }
    }

    public function update(string $id) {
        try {
            $id = $this->resolveId($id);
            $body = $this->getBody();
            $request = new ProductRequest($body);
            $data = $request->validated();

            $result = $this->productService->updateProduct(
                $id,
                $data,
                $this->authService->id()
            );

            if (!$result['ok']) {
                $code = $result['code'] ?? 500;
                if ($code === 404) {
                    return Response::notFound($result['error'], ErrorCodes::PRODUCT_NOT_FOUND);
                }
                $errorCode = $code >= 500
                    ? ErrorCodes::SERVER_ERROR
                    : ErrorCodes::VALIDATION_FAILED;
                return Response::error($result['error'], $code, null, $errorCode);
            }
            return Response::success($result['product'], 'Product updated');
        } catch (ValidationException $e) {
            return Response::error($this->productValidationMessage($e->getErrors()), 422, $e->getErrors(), ErrorCodes::VALIDATION_FAILED);
        }
    }

    /** رسالة عربية بدل "Validation failed" + أسماء حقول إنجليزية */
    private function productValidationMessage(array $errors): string {
        $parts = [];
        foreach ($errors as $field => $msgs) {
            $list = is_array($msgs) ? $msgs : [$msgs];
            foreach ($list as $m) {
                $msg = (string)$m;
                $parts[] = match ($field) {
                    'name'  => str_contains($msg, 'required') ? 'اسم المنتج مطلوب.' : ('اسم المنتج: ' . $msg),
                    'price' => str_contains($msg, 'required')
                        ? 'سعر البيع مطلوب.'
                        : (str_contains($msg, 'numeric') ? 'سعر البيع يجب أن يكون رقماً.' : ('سعر البيع: ' . $msg)),
                    default => $field . ': ' . $msg,
                };
            }
        }
        return $parts !== [] ? implode(' ', $parts) : 'تحقق من الحقول المطلوبة.';
    }

    public function lowStock() {
        return Response::success($this->productService->getLowStockProducts());
    }

    /**
     * GET /api/products/{id}/price-history
     * Product price change history
     */
    public function priceHistory(string $id) {
        $id = $this->resolveId($id);
        $product = $this->productModel->findById($id);
        if (!$product) {
            return Response::notFound('المنتج غير موجود');
        }

        $history = $this->priceHistory->getByProductId($id);

        return Response::success($history);
    }

    public function destroy(string $id) {
        $id = $this->resolveId($id);
        $result = $this->productService->deleteProduct(
            $id,
            $this->authService->id()
        );

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            if ($code === 404) {
                return Response::notFound($result['error'], ErrorCodes::PRODUCT_NOT_FOUND);
            }
            $errorCode = $code >= 500
                ? ErrorCodes::SERVER_ERROR
                : ErrorCodes::PRODUCT_IN_USE;
            return Response::error($result['error'], $code, null, $errorCode);
        }
        return Response::success(null, 'Product deleted');
    }
}
