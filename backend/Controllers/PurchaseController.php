<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Helpers\ErrorCodes;
use App\Repositories\SupplierRepository;
use App\Services\InventoryService;
use App\Services\AuthService;
use App\Services\SupplierService;
use Throwable;
use App\Requests\PurchaseRequest;
use App\Requests\BulkPurchaseRequest;

class PurchaseController extends Controller
{
    private SupplierRepository $supplierRepo;
    private InventoryService   $inventoryService;
    private SupplierService    $supplierService;
    private AuthService        $authService;

    public function __construct(
        SupplierRepository $supplierRepo,
        InventoryService   $inventoryService,
        SupplierService    $supplierService,
        AuthService        $authService
    ) {
        $this->supplierRepo     = $supplierRepo;
        $this->inventoryService = $inventoryService;
        $this->supplierService  = $supplierService;
        $this->authService      = $authService;
    }

    /** Single-item purchase (legacy) */
    public function purchase() {
        $request = new PurchaseRequest($this->getBody());
        $data = $request->validated();

        try {
            $result = $this->supplierService->recordSinglePurchase($data);
            return Response::success($result, 'Purchase recorded and stock updated', 201);
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            return $code === 404 ? Response::notFound($e->getMessage()) : Response::serverError('Failed to record purchase');
        }
    }

    /** List purchase invoices (like sales list) */
    public function purchaseInvoices() {
        $filters = [
            'supplier_id' => $this->getParam('supplier_id'),
            'date'        => $this->getParam('date'),
            'month'       => $this->getParam('month'),
            'year'        => $this->getParam('year'),
            ...$this->getPaginationParams(),
            'search'      => $this->getParam('search'),
        ];
        
        $result = $this->supplierRepo->getPurchaseInvoices($filters);
        if (isset($result['pagination'])) {
            return Response::success($result['data'], null, 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    /** Get single purchase invoice detail (like sales detail) */
    public function purchaseInvoiceDetail(string $id) {
        $id = $this->resolveId($id);
        $invoice = $this->supplierRepo->getPurchaseInvoice($id);
        if (!$invoice) return Response::notFound('Purchase invoice not found');
        return Response::success($invoice);
    }

    /** Delete a purchase invoice and restore stock */
    public function purchaseInvoiceDelete(string $id) {
        $id = $this->resolveId($id);
        $result = $this->inventoryService->deletePurchaseInvoice(
            $id,
            $this->authService->id()
        );

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            return $code === 404
                ? Response::notFound($result['error'])
                : Response::serverError($result['error']);
        }

        return Response::success(null, 'Purchase invoice deleted and stock restored');
    }

    /** Legacy flat purchases list */
    public function purchases() {
        $filters = [
            'supplier_id' => $this->getParam('supplier_id'),
            'date_from'   => $this->getParam('date_from'),
            'date_to'     => $this->getParam('date_to'),
            ...$this->getPaginationParams(),
        ];
        $result = $this->supplierRepo->getPurchases($filters);
        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        }
        return Response::success($result);
    }

    /** Bulk purchase — creates a purchase invoice + items */
    public function purchaseBulk() {
        $request = new BulkPurchaseRequest($this->getBody());
        $data   = $request->validated();
        $auth   = $this->authService->user();
        $result = $this->inventoryService->processBulkPurchase($data, $auth);

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            return $code === 404
                ? Response::notFound($result['error'], ErrorCodes::INVOICE_NOT_FOUND)
                : Response::error($result['error'], $code, null, ErrorCodes::SERVER_ERROR);
        }

        $isUpdate = $result['is_update'] ?? false;
        return Response::success([
            'invoice_id'      => $result['invoice_id'],
            'items_processed' => $result['items_processed'],
        ], $isUpdate ? 'Purchase invoice updated' : 'Bulk purchase recorded', $isUpdate ? 200 : 201);
    }
}
