<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Services\CategoryService;
use App\Helpers\Messages;
use App\Helpers\Logger;

class CategoryController extends Controller {

    private CategoryService $categoryService;

    public function __construct(CategoryService $categoryService) {
        $this->categoryService = $categoryService;
    }

    public function index() {
        $filters = $this->getPaginationParams();
        $result = $this->categoryService->getAll($filters);

        if (isset($result['pagination'])) {
            return Response::cacheable($result['data'], 300, null, ['pagination' => $result['pagination']]);
        } else {
            $data = $result['data'] ?? $result;
            return Response::cacheable($data, 300);
        }
    }

    public function store() {
        $request = new \App\Requests\CategoryRequest($this->getBody());
        $data = $request->validated();

        try {
            $result = $this->categoryService->createCategory($data);
            return Response::success($result, Messages::CATEGORY_CREATED, 201);
        } catch (\Throwable $e) {
            Logger::error('Failed to create category', Logger::exceptionContext($e));
            return Response::error(Messages::CATEGORY_CREATE_FAIL, 500);
        }
    }

    public function update(string $id) {
        $id = $this->resolveId($id);
        $request = new \App\Requests\CategoryRequest($this->getBody());
        $data = $request->validated();

        try {
            $result = $this->categoryService->updateCategory($id, $data);
            return Response::success($result, Messages::CATEGORY_UPDATED);
        } catch (\Throwable $e) {
            Logger::error('Failed to update category', Logger::exceptionContext($e));
            return Response::error(Messages::CATEGORY_UPDATE_FAIL, 500);
        }
    }

    public function destroy(string $id) {
        $id = $this->resolveId($id);
        try {
            $this->categoryService->deleteCategory($id);
            return Response::success(null, Messages::CATEGORY_DELETED);
        } catch (\Throwable $e) {
            Logger::error('Failed to delete category', Logger::exceptionContext($e));
            return Response::error(Messages::CATEGORY_DELETE_FAIL, 500);
        }
    }
}

