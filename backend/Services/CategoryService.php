<?php

namespace App\Services;

use App\Repositories\CategoryRepository;
use App\Helpers\Cache;
use App\Helpers\EventDispatcher;
use PDO;
use Throwable;
use Exception;

/**
 * CategoryService — منطق الأعمال لإدارة الفئات (Categories).
 *
 * يستخرج Business Logic من CategoryController.
 */
class CategoryService {

    public function __construct(
        private CategoryRepository $categoryRepo,
        private PDO $db,
    ) {
    }

    /**
     * جلب جميع الفئات مع دعم التصفية والتخزين المؤقت (Caching).
     *
     * @param array $filters عوامل التصفية
     * @return array مصفوفة تحتوي على البيانات ومعلومات الترقيم (Pagination) إن وجدت
     */
    public function getAll(array $filters = []): array {
        $cacheKey = empty($filters) ? 'categories_all' : null;

        if ($cacheKey) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $this->categoryRepo->all($filters);

        if ($cacheKey) {
            Cache::set($cacheKey, $result, 300);
        }

        return $result;
    }

    /**
     * إنشاء فئة جديدة وتوزيع حدث الإنشاء.
     *
     * @param array $data بيانات الفئة
     * @return array بيانات الفئة المنشأة
     * @throws Exception في حال فشل الإنشاء
     */
    public function createCategory(array $data): array {
        $this->db->beginTransaction();
        try {
            $id = $this->categoryRepo->create(['name' => $data['name']]);
            EventDispatcher::dispatch('category.created', ['id' => $id]);
            $this->db->commit();
            return ['id' => $id, 'name' => $data['name']];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new Exception('فشل إنشاء الفئة', 500, $e);
        }
    }

    /**
     * تحديث بيانات الفئة وتوزيع الحدث.
     *
     * @param int $id معرّف الفئة
     * @param array $data البيانات المحدثة
     * @return array بيانات الفئة المحدثة
     * @throws Exception في حال فشل التحديث
     */
    public function updateCategory(int $id, array $data): array {
        $this->db->beginTransaction();
        try {
            $this->categoryRepo->update($id, ['name' => $data['name']]);
            EventDispatcher::dispatch('category.updated', ['id' => $id]);
            $this->db->commit();
            return ['id' => $id, 'name' => $data['name']];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new Exception('فشل تحديث الفئة', 500, $e);
        }
    }

    /**
     * حذف الفئة وتوزيع الحدث.
     *
     * @param int $id معرّف الفئة
     * @return void
     * @throws Exception في حال فشل الحذف
     */
    public function deleteCategory(int $id): void {
        $this->db->beginTransaction();
        try {
            $this->categoryRepo->delete($id);
            EventDispatcher::dispatch('category.deleted', ['id' => $id]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new Exception('فشل حذف الفئة', 500, $e);
        }
    }
}
