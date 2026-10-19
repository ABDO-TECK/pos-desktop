<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ValidationException;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Repositories\ExpenseRepository;
use App\Requests\ExpenseRequest;
use App\Services\AuthService;
use App\Services\ExpenseService;
use Throwable;


class ExpenseController extends Controller
{
    private ExpenseRepository $expenseRepo;
    private ExpenseService $expenseService;
    private AuthService $authService;

    public function __construct(ExpenseRepository $expenseRepo, ExpenseService $expenseService, AuthService $authService)
    {
        $this->expenseRepo = $expenseRepo;
        $this->expenseService = $expenseService;
        $this->authService = $authService;
    }

    // ── Categories ───────────────────────────────────────────────────

    public function getCategories(): array
    {
        return Response::cacheable($this->expenseService->getAllCategories(), 300);
    }

    public function createCategory(): array
    {
        try {
            return $this->withTransaction(function () {
                $result = $this->expenseService->createCategory($this->getBody());
                if (!$result['ok']) {
                    return Response::error($result['error'], $result['code']);
                }
                return Response::success($result['category']);
            });
        } catch (Throwable $e) {
            Logger::error('Failed to create expense category', Logger::exceptionContext($e));
            return Response::error('حدث خطأ أثناء إضافة التصنيف', 500);
        }
    }

    public function updateCategory(string $id): array
    {
        $id = $this->resolveId($id);
        try {
            return $this->withTransaction(function () use ($id) {
                $result = $this->expenseService->updateCategory($id, $this->getBody());
                if (!$result['ok']) {
                    return Response::error($result['error'], $result['code']);
                }
                return Response::success($result['category']);
            });
        } catch (Throwable $e) {
            Logger::error('Failed to update expense category', Logger::exceptionContext($e));
            return Response::error('حدث خطأ أثناء تعديل التصنيف', 500);
        }
    }

    public function deleteCategory(string $id): array
    {
        $id = $this->resolveId($id);
        try {
            $result = $this->expenseService->deleteCategory($id);
            if (!$result['ok']) {
                return Response::error($result['error'], $result['code']);
            }
            return Response::success(['message' => 'تم الحذف بنجاح']);
        } catch (Throwable $e) {
            Logger::error('Failed to delete expense category', Logger::exceptionContext($e));
            return Response::error('حدث خطأ أثناء الحذف', 500);
        }
    }

    // ── Expenses ─────────────────────────────────────────────────────

    public function getExpenses(): array
    {
        $filters = [];
        if ($this->getParam('date')) $filters['date'] = $this->getParam('date');
        if ($this->getParam('month') && $this->getParam('year')) {
            $filters['month'] = $this->getParam('month');
            $filters['year'] = $this->getParam('year');
        }
        if ($this->getParam('category_id')) $filters['category_id'] = $this->getParam('category_id');
        if ($this->getParam('start_date')) $filters['start_date'] = $this->getParam('start_date');
        if ($this->getParam('end_date')) $filters['end_date'] = $this->getParam('end_date');

        $filters += $this->getPaginationParams();

        $result = $this->expenseRepo->all($filters);

        return Response::success($result['data'] ?? $result, 'success', 200, isset($result['pagination']) ? ['pagination' => $result['pagination']] : []);
    }

    public function createExpense(): array
    {
        try {
            $request = new ExpenseRequest($this->getBody());
            $data = $request->validated();
        } catch (ValidationException $e) {
            return Response::error('فشل التحقق من صحة البيانات', 422, $e->getErrors());
        }

        $user = $this->authService->user() ?? null;
        if (!$user) {
            return Response::error('غير مصرح', 401);
        }

        try {
            $id = $this->expenseService->createExpense($data, $user);
            return Response::success($this->expenseRepo->findById($id));
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            if ($code === 400) return Response::error($e->getMessage(), 400);
            Logger::error('Failed to create expense', Logger::exceptionContext($e));
            return Response::error('حدث خطأ أثناء تسجيل المصروف', 500);
        }
    }

    public function updateExpense(string $id): array
    {
        $id = $this->resolveId($id);
        try {
            $request = new ExpenseRequest($this->getBody());
            $data = $request->validated();
        } catch (ValidationException $e) {
            return Response::error('فشل التحقق من صحة البيانات', 422, $e->getErrors());
        }

        try {
            $this->expenseService->updateExpense($id, $data);
            return Response::success($this->expenseRepo->findById($id));
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            if ($code === 400) return Response::error($e->getMessage(), 400);
            if ($code === 404) return Response::error($e->getMessage(), 404);
            Logger::error('Failed to update expense', Logger::exceptionContext($e));
            return Response::error('حدث خطأ أثناء تعديل المصروف', 500);
        }
    }

    public function deleteExpense(string $id): array
    {
        $id = $this->resolveId($id);
        if (!$this->expenseRepo->findById($id)) {
            return Response::notFound('المصروف غير موجود');
        }
        try {
            return $this->withTransaction(function () use ($id) {
                $this->expenseRepo->delete($id);
                return Response::success(['message' => 'تم حذف المصروف بنجاح']);
            });
        } catch (Throwable $e) {
            Logger::error('Failed to delete expense', Logger::exceptionContext($e));
            return Response::error('حدث خطأ أثناء الحذف', 500);
        }
    }
}
