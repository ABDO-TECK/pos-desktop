<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Helpers\ErrorCodes;
use App\Helpers\Logger;
use App\Models\Invoice;
use App\Services\AuthService;
use App\Services\SaleService;
use App\Requests\SaleRequest;
use App\Requests\SaleStatusRequest;
use App\Middleware\PermissionMiddleware;


class SaleController extends Controller {

    private SaleService $saleService;
    private AuthService $authService;

    public function __construct(SaleService $saleService, AuthService $authService) {
        $this->saleService = $saleService;
        $this->authService = $authService;
    }

    public function index() {
        $filters = [
            'date'  => $this->getParam('date'),
            'month' => $this->getParam('month'),
            'year'  => $this->getParam('year'),
            ...$this->getPaginationParams(),
            'status' => $this->getParam('status'),
            'search' => $this->getParam('search'),
        ];

        $result = $this->saleService->getInvoiceRepository()->all($filters);

        if (isset($result['pagination'])) {
            return Response::success($result['data'], null, 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    public function show(string $id) {
        $id = $this->resolveId($id);
        $invoice = $this->saleService->getInvoiceRepository()->findById($id);
        if (!$invoice) return Response::notFound('Invoice not found');
        return Response::success($invoice);
    }

    public function store() {
        $request = new SaleRequest($this->getBody());
        $data = $request->validated();

        if (
            (int) ($data['invoice_id'] ?? 0) > 0
            && !PermissionMiddleware::allows(
                $this->authService,
                'invoices.update_reserved'
            )
        ) {
            return Response::forbidden('Reserved invoice update permission required');
        }

        $idempotencyKey = $data['idempotency_key'];
        $requestHash = $this->saleService->hashSaleRequest($data);
        $idempotency = $this->saleService->resolveIdempotency($idempotencyKey, $requestHash);

        if ($idempotency['status'] === 'replay') {
            $responseData = $idempotency['data'];
            $responseData['idempotency'] = [
                'key' => $idempotencyKey,
                'replayed' => true,
            ];
            return Response::success(
                $responseData,
                $idempotency['message'],
                $idempotency['code']
            );
        }

        if ($idempotency['status'] !== 'missing') {
            $code = (int) ($idempotency['code'] ?? 500);
            return Response::error(
                $idempotency['message'] ?? 'Unable to resolve sale idempotency key',
                $code,
                ['idempotency_key' => [$idempotency['message'] ?? 'Idempotency request failed']],
                $code === 409 ? ErrorCodes::IDEMPOTENCY_CONFLICT : ErrorCodes::SERVER_ERROR
            );
        }

        if (empty($data['items']) || !is_array($data['items'])) {
            return Response::error('السلة فارغة', 400, null, ErrorCodes::EMPTY_CART);
        }

        // 1. إثراء والتحقق من البنود
        $canOverridePrice = \App\Middleware\PermissionMiddleware::allows(
            $this->authService,
            'sales.override_price'
        );
        $enrichResult = $this->saleService->enrichItems($data['items'], $canOverridePrice);
        if (!$enrichResult['ok']) {
            return Response::error($enrichResult['error'], $enrichResult['code'], null, ErrorCodes::VALIDATION_FAILED);
        }
        $enrichedItems = $enrichResult['items'];

        // 2. حساب الإجماليات
        $discount = (float)($data['discount'] ?? 0);
        if (
            $discount > 0
            && !\App\Middleware\PermissionMiddleware::allows($this->authService, 'sales.discount')
        ) {
            return Response::error('Discount permission required', 403);
        }
        try {
            $totals = $this->saleService->calculateTotals($enrichedItems, $discount, $data);
        } catch (\InvalidArgumentException $exception) {
            return Response::error($exception->getMessage(), 422, null, ErrorCodes::VALIDATION_FAILED);
        }

        // 3. تنفيذ عملية البيع
        $auth   = $this->authService->user();
        $result = $this->saleService->processSale($enrichedItems, $totals, $data, $auth);

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            if (!empty($result['idempotency_error'])) {
                return Response::error(
                    $result['error'],
                    $code,
                    ['idempotency_key' => [$result['error']]],
                    $code === 409 ? ErrorCodes::IDEMPOTENCY_CONFLICT : ErrorCodes::SERVER_ERROR
                );
            }
            return $code === 404
                ? Response::notFound($result['error'], ErrorCodes::INVOICE_NOT_FOUND)
                : Response::error($result['error'], $code, null, ErrorCodes::SERVER_ERROR);
        }

        $responseData = $result['response_data'];
        $responseData['idempotency'] = [
            'key' => $idempotencyKey,
            'replayed' => (bool) ($result['replayed'] ?? false),
        ];
        return Response::success(
            $responseData,
            $result['response_message'],
            $result['response_code']
        );
    }

    public function updateStatus(string $id) {
        $id = $this->resolveId($id);
        $request = new SaleStatusRequest($this->getBody());
        $data = $request->validated();

        try {
            $this->saleService->changeStatus($id, $data['status']);
        } catch (\DomainException $exception) {
            $status = $exception->getCode() === 404 ? 404 : 409;
            return Response::error($exception->getMessage(), $status);
        }

        return Response::success(null, 'Invoice status updated successfully');
    }

    /**
     * Permanently delete invoice and its lines; restore product quantities to stock.
     */
    public function destroy(string $id) {
        $id = $this->resolveId($id);
        $result = $this->saleService->deleteInvoice($id, $this->authService->id());

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            return $code === 404
                ? Response::notFound($result['error'])
                : Response::serverError($result['error']);
        }

        return Response::success(null, 'Invoice deleted');
    }
}
