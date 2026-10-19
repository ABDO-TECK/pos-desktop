<?php
namespace App\Repositories;

use App\Helpers\Cache;

/**
 * CachedRepository — Repository وسيط يضيف Caching تلقائي.
 *
 * الاستخدام:
 *   بدلاً من: $this->model->all($filters)
 *   استخدم:  CachedRepository::wrap('products', fn() => $this->model->all($filters))
 *
 *   عند التعديل: CachedRepository::invalidate('products')
 */
class CachedRepository
{
    /**
     * جلب من الكاش أو تنفيذ الـ callback وحفظ النتيجة.
     *
     * @param string   $tag      اسم الكيان (مثل: 'products', 'categories')
     * @param callable $callback الدالة التي تجلب البيانات
     * @param int      $ttl      مدة الكاش بالثواني (افتراضي: 5 دقائق)
     * @param string   $keySuffix لاحقة فريدة للمفتاح (مثل: serialize الفلاتر)
     */
    public static function wrap(string $tag, callable $callback, int $ttl = 300, string $keySuffix = ''): mixed
    {
        $key = $tag . '_' . md5($keySuffix);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }
        $result = $callback();
        Cache::setWithTags($key, $result, $ttl, [$tag]);
        return $result;
    }

    /**
     * مسح كل الكاش المرتبط بكيان معين.
     * يُستدعى تلقائياً عند create/update/delete.
     */
    public static function invalidate(string $tag): void
    {
        Cache::forgetTag($tag);
    }
}
