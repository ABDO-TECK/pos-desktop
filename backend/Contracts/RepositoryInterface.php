<?php

namespace App\Contracts;

/**
 * RepositoryInterface — العقد الأساسي لجميع الـ Repositories.
 *
 * يفرض وجود الدوال الأساسية (CRUD) في كل Repository.
 * الـ Repositories التي لا تدعم بعض العمليات (مثل delete)
 * يمكنها رمي BadMethodCallException.
 */
interface RepositoryInterface
{
    /**
     * جلب جميع السجلات مع دعم الفلاتر والـ Pagination.
     *
     * @param  array  $filters  فلاتر البحث (search, page, limit, ...)
     * @return array  مصفوفة نتائج أو ['data' => [...], 'pagination' => [...]]
     */
    public function all(array $filters = []): array;

    /**
     * البحث عن سجل بالـ ID.
     *
     * @param  int  $id
     * @return array|null  السجل أو null إذا لم يوجد
     */
    public function findById(int $id): ?array;

    /**
     * إنشاء سجل جديد.
     *
     * @param  array  $data  بيانات السجل
     * @return int  الـ ID الجديد
     */
    public function create(array $data): int;

    /**
     * تحديث سجل.
     *
     * @param  int    $id
     * @param  array  $data  البيانات المحدثة
     */
    public function update(int $id, array $data): void;

    /**
     * حذف سجل.
     *
     * @param  int  $id
     */
    public function delete(int $id): void;
}
