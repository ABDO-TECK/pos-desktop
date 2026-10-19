<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ValidationException;
use App\Helpers\Response;
use App\Repositories\CustomerRepository;
use App\Requests\CustomerRequest;
use App\Services\AuthService;
use App\Services\CustomerService;
use App\Helpers\Messages;
use Throwable;


class CustomerController extends Controller {

    private CustomerRepository $customerRepo;
    private CustomerService $service;
    private AuthService $authService;

    public function __construct(CustomerRepository $customerRepo, CustomerService $service, AuthService $authService) {
        $this->customerRepo = $customerRepo;
        $this->service = $service;
        $this->authService = $authService;
    }

    /** GET /api/customers */
    public function index() {
        $filters = [];
        if ($this->getParam('search'))  $filters['search']  = $this->getParam('search');
        $filters += $this->getPaginationParams();

        $result = $this->customerRepo->all($filters);

        // إذا أُرجع pagination — إرسال مع metadata
        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    /** GET /api/customers/{id} — العميل + كشف الحساب */
    public function show(string $id) {
        $id = $this->resolveId($id);
        $data = $this->customerRepo->getLedger($id);
        if (!$data['customer']) {
            return Response::notFound('العميل غير موجود');
        }
        return Response::success($data);
    }

    /** POST /api/customers */
    public function store() {
        try {
            $request = new CustomerRequest($this->getBody());
            $data = $request->validated();
            
            $id = $this->service->createCustomer($data);
            return Response::success($this->customerRepo->findById($id), Messages::CUSTOMER_CREATED, 201);
        } catch (ValidationException $e) {
            return Response::error(Messages::VALIDATION_FAILED, 422, $e->getErrors());
        }
    }



    /** PUT /api/customers/{id} */
    public function update(string $id) {
        try {
            $id = $this->resolveId($id);
            $request = new CustomerRequest($this->getBody());
            $data = $request->validated();
            
            if (!$this->customerRepo->findById($id)) {
                return Response::notFound('العميل غير موجود');
            }
            return $this->withTransaction(function () use ($id, $data) {
                $this->customerRepo->update($id, $data);
                return Response::success($this->customerRepo->findById($id), Messages::CUSTOMER_UPDATED);
            });
        } catch (ValidationException $e) {
            return Response::error(Messages::VALIDATION_FAILED, 422, $e->getErrors());
        }
    }

    /** DELETE /api/customers/{id} */
    public function destroy(string $id) {
        $id = $this->resolveId($id);
        if (!$this->customerRepo->findById($id)) {
            return Response::notFound('العميل غير موجود');
        }
        return $this->withTransaction(function () use ($id) {
            $this->customerRepo->delete($id);
            return Response::success(null, Messages::CUSTOMER_DELETED);
        });
    }

    /**
     * POST /api/customers/{id}/payment
     * body: { amount: float, description?: string }
     * تسجيل دفعة (قيد دائن) في كشف حساب العميل
     */
    public function addPayment(string $id) {
        $id = $this->resolveId($id);
        $request = new \App\Requests\PaymentRequest($this->getBody());
        $data = $request->validated();
        $auth = $this->authService->user();

        try {
            $ledger = $this->service->addPayment($id, $data, $auth);
            return Response::success($ledger, Messages::PAYMENT_RECORDED);
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            return $code === 404 ? Response::notFound($e->getMessage()) : Response::error($e->getMessage(), $code);
        }
    }

    /**
     * PUT /api/customers/ledger/{entryId}
     * body: { type: 'debit'|'credit', amount: float, description?: string }
     */
    public function updateLedgerEntry(string $entryId) {
        $eid  = (int)$entryId;
        $request = new \App\Requests\LedgerEntryRequest($this->getBody());
        $data = $request->validated();

        try {
            $ledger = $this->service->updateLedgerEntry($eid, $data);
            return Response::success($ledger, Messages::LEDGER_ENTRY_UPDATED);
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            return $code === 404 ? Response::notFound($e->getMessage()) : Response::error($e->getMessage(), $code);
        }
    }

    /**
     * DELETE /api/customers/ledger/{entryId}
     */
    public function deleteLedgerEntry(string $entryId) {
        $eid  = (int)$entryId;

        try {
            $ledger = $this->service->deleteLedgerEntry($eid);
            return Response::success($ledger, Messages::LEDGER_ENTRY_DELETED);
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            return $code === 404 ? Response::notFound($e->getMessage()) : Response::error($e->getMessage(), $code);
        }
    }
}


