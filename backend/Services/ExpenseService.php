<?php

namespace App\Services;

use App\Models\Expense;
use App\Repositories\ExpenseCategoryRepository;
use Exception;
use PDO;
use PDOException;

class ExpenseService {
    
    private Expense $expenseModel;
    private ExpenseCategoryRepository $categoryRepo;
    private PDO $db;

    public function __construct(Expense $expenseModel, ExpenseCategoryRepository $categoryRepo, PDO $db) {
        $this->expenseModel = $expenseModel;
        $this->categoryRepo = $categoryRepo;
        $this->db = $db;
    }

    // ── Expense Categories ─────────────────────────────────────

    /**
     * إنشاء تصنيف مصروفات جديد.
     *
     * @param array $data ['name' => string]
     * @return array ['ok' => true, 'category' => [...]] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function createCategory(array $data): array
    {
        if (empty($data['name'])) {
            return ['ok' => false, 'error' => 'اسم التصنيف مطلوب', 'code' => 400];
        }
        try {
            $id = $this->categoryRepo->create($data);
            return ['ok' => true, 'category' => $this->categoryRepo->findById($id)];
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || $e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'هذا التصنيف موجود مسبقاً', 'code' => 422];
            }
            throw $e;
        }
    }

    /**
     * تحديث تصنيف مصروفات.
     *
     * @return array ['ok' => true, 'category' => [...]] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function updateCategory(int $id, array $data): array
    {
        if (empty($data['name'])) {
            return ['ok' => false, 'error' => 'اسم التصنيف مطلوب', 'code' => 400];
        }
        if (!$this->categoryRepo->findById($id)) {
            return ['ok' => false, 'error' => 'التصنيف غير موجود', 'code' => 404];
        }
        try {
            $this->categoryRepo->update($id, $data);
            return ['ok' => true, 'category' => $this->categoryRepo->findById($id)];
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || $e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'هذا التصنيف موجود مسبقاً', 'code' => 422];
            }
            throw $e;
        }
    }

    /**
     * حذف تصنيف مصروفات.
     *
     * @return array ['ok' => true] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function deleteCategory(int $id): array
    {
        if (!$this->categoryRepo->findById($id)) {
            return ['ok' => false, 'error' => 'التصنيف غير موجود', 'code' => 404];
        }
        $this->db->beginTransaction();
        try {
            $this->categoryRepo->delete($id);
            $this->db->commit();
            return ['ok' => true];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getCode() == 23000 || $e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'لا يمكن حذف هذا التصنيف لوجود مصروفات مرتبطة به', 'code' => 422];
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * جلب جميع تصنيفات المصروفات.
     */
    public function getAllCategories(): array
    {
        return $this->categoryRepo->all();
    }

    // ── Expenses ────────────────────────────────────────────────

    /**
     * تسجيل مصروف جديد في النظام.
     *
     * @param array $data بيانات المصروف (المبلغ، التصنيف، التاريخ، الوصف)
     * @param array $authUser بيانات المستخدم الحالي المنفذ للعملية
     * @return int معرّف المصروف الجديد
     * @throws \Exception إذا كانت البيانات غير مكتملة أو فشل الحفظ
     */
    public function createExpense(array $data, array $authUser): int {
        if (empty($data['category_id']) || empty($data['amount']) || empty($data['expense_date'])) {
            throw new Exception('البيانات المطلوبة غير مكتملة', 400);
        }

        $data['user_id'] = $authUser['id'];

        $this->db->beginTransaction();
        try {
            $id = $this->expenseModel->create($data);
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new Exception('فشل في حفظ المصروف', 500, $e);
        }
    }

    /**
     * تحديث بيانات مصروف موجود.
     *
     * @param int $id معرّف المصروف
     * @param array $data البيانات المحدثة
     * @return void
     * @throws \Exception إذا كانت البيانات غير مكتملة أو المصروف غير موجود
     */
    public function updateExpense(int $id, array $data): void {
        if (empty($data['category_id']) || empty($data['amount']) || empty($data['expense_date'])) {
            throw new Exception('البيانات المطلوبة غير مكتملة', 400);
        }

        if (!$this->expenseModel->findById($id)) {
            throw new Exception('المصروف غير موجود', 404);
        }

        $this->db->beginTransaction();
        try {
            $this->expenseModel->update($id, $data);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new Exception('فشل في تحديث المصروف', 500, $e);
        }
    }
}
