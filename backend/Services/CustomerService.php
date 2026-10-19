<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\Logger;
use Exception;
use Throwable;
use App\Contracts\CustomerServiceInterface;


class CustomerService implements CustomerServiceInterface {
    
    private \App\Repositories\CustomerRepository $customerRepo;

    public function __construct(\App\Repositories\CustomerRepository $customerRepo) {
        $this->customerRepo = $customerRepo;
    }

    public function createCustomer(array $data): int {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $id = $this->customerRepo->create($data);
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل إضافة العميل', Logger::exceptionContext($e));
            throw new Exception('فشل في إضافة العميل');
        }
    }

    public function addPayment(int $customerId, array $data, array $authUser): array {
        $customer = $this->customerRepo->findById($customerId);
        if (!$customer) {
            throw new Exception('العميل غير موجود', 404);
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception('يجب أن يكون المبلغ أكبر من صفر', 422);
        }

        $type = $data['type'] ?? 'credit';
        if (!in_array($type, ['debit', 'credit'], true)) {
            throw new Exception('نوع القيد غير صحيح', 422);
        }

        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $this->customerRepo->addLedgerEntry([
                'customer_id' => $customerId,
                'type'        => $type,
                'amount'      => $amount,
                'description' => $data['description'] ?? 'دفعة نقدية',
                'invoice_id'  => null,
                'created_by'  => $authUser['id'],
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new Exception('فشل تسجيل الدفعة', 500);
        }

        return $this->customerRepo->getLedger($customerId);
    }

    public function updateLedgerEntry(int $entryId, array $data): array {
        $entry = $this->customerRepo->getLedgerEntry($entryId);
        if (!$entry) {
            throw new Exception('القيد غير موجود', 404);
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception('يجب أن يكون المبلغ أكبر من صفر', 422);
        }

        $type = $data['type'] ?? $entry['type'];
        if (!in_array($type, ['debit', 'credit'], true)) {
            throw new Exception('نوع القيد غير صحيح', 422);
        }

        // Validate that the ledger entry's customer belongs to the current scope.
        $customer = $this->customerRepo->findById((int) $entry['customer_id']);
        if (!$customer) {
            throw new Exception('العميل المرتبط بهذا القيد غير موجود', 404);
        }

        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $this->customerRepo->updateLedgerEntry($entryId, [
                'type'        => $type,
                'amount'      => $amount,
                'description' => $data['description'] ?? $entry['description'],
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new Exception('فشل تحديث القيد', 500);
        }

        return $this->customerRepo->getLedger((int)$entry['customer_id']);
    }

    public function deleteLedgerEntry(int $entryId): array {
        $entry = $this->customerRepo->getLedgerEntry($entryId);
        if (!$entry) {
            throw new \Exception('القيد غير موجود', 404);
        }

        // Validate that the ledger entry's customer belongs to the current branch/scope
        $customer = $this->customerRepo->findById((int) $entry['customer_id']);
        if (!$customer) {
            throw new \Exception('العميل المرتبط بهذا القيد غير موجود', 404);
        }

        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $this->customerRepo->deleteLedgerEntry($entryId);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new \Exception('فشل حذف القيد', 500);
        }

        return $this->customerRepo->getLedger((int)$entry['customer_id']);
    }
}
