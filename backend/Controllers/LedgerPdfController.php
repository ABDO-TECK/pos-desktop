<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Models\Customer;
use App\Models\SupplierLedger;
use App\Services\LedgerPdfService;
use PDO;

/**
 * LedgerPdfController — Thin controller, delegates to LedgerPdfService.
 */
class LedgerPdfController extends Controller {

    private PDO $db;
    private Customer $customerModel;
    private SupplierLedger $supplierModel;
    private LedgerPdfService $pdfService;

    public function __construct(Customer $customerModel, SupplierLedger $supplierModel, LedgerPdfService $pdfService) {
        $this->db = Database::getInstance();
        $this->customerModel = $customerModel;
        $this->supplierModel = $supplierModel;
        $this->pdfService    = $pdfService;
    }

    private function getStoreName(): string {
        $stmt = $this->db->prepare("SELECT `value` FROM settings WHERE `key` = 'store_name' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? $row['value'] : 'سوبر ماركت';
    }

    public function customerPdf(string $id) {
        $id = $this->resolveId($id);
        $data = $this->customerModel->getLedger($id);
        if (!$data['customer']) { http_response_code(404); echo 'العميل غير موجود'; exit; }

        $customer    = $data['customer'];
        $entries     = $data['entries'];
        $balance     = (float)$data['balance'];
        $totalDebit  = 0; $totalCredit = 0;
        foreach ($entries as $e) { $totalDebit += (float)($e['debit'] ?? 0); $totalCredit += (float)($e['credit'] ?? 0); }

        $html = $this->pdfService->buildLedgerHtml([
            'title' => 'كشف حساب عميل', 'entityLabel' => 'اسم العميل',
            'entity' => $customer, 'entries' => $entries, 'balance' => $balance,
            'totalDebit' => $totalDebit, 'totalCredit' => $totalCredit,
            'storeName' => $this->getStoreName(), 'balDebitWord' => 'مدين', 'balCreditWord' => 'دائن',
        ]);
        $this->pdfService->outputPdf($html, 'كشف_حساب_' . ($customer['name'] ?? 'عميل') . '_' . date('Y-m-d') . '.pdf');
    }

    public function supplierPdf(string $id) {
        $id = $this->resolveId($id);
        $data = $this->supplierModel->getLedger($id);
        if (!$data['supplier']) { http_response_code(404); echo 'المورد غير موجود'; exit; }

        $supplier    = $data['supplier'];
        $entries     = $data['entries'];
        $balance     = (float)$data['balance'];
        $totalDebit  = 0; $totalCredit = 0;
        foreach ($entries as $e) { $totalDebit += (float)($e['debit'] ?? 0); $totalCredit += (float)($e['credit'] ?? 0); }

        $html = $this->pdfService->buildLedgerHtml([
            'title' => 'كشف حساب مورد', 'entityLabel' => 'اسم المورد',
            'entity' => $supplier, 'entries' => $entries, 'balance' => $balance,
            'totalDebit' => $totalDebit, 'totalCredit' => $totalCredit,
            'storeName' => $this->getStoreName(), 'balDebitWord' => 'مستحق', 'balCreditWord' => 'مُسدَّد',
        ]);
        $this->pdfService->outputPdf($html, 'كشف_حساب_' . ($supplier['name'] ?? 'مورد') . '_' . date('Y-m-d') . '.pdf');
    }
}
