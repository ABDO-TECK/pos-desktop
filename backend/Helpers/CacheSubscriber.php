<?php

namespace App\Helpers;

use App\Repositories\CachedRepository;

/**
 * CacheSubscriber — يستمع للأحداث ويُبطل الكاش تلقائياً.
 *
 * هذا الملف هو المكان المركزي الوحيد لإبطال الكاش.
 * عند إضافة كيان جديد بحاجة كاش:
 *   1. أضف الأحداث في EventDispatcher (مثل: 'newentity.created')
 *   2. أضف قاعدة الإبطال هنا في register()
 */
class CacheSubscriber
{
    /**
     * تسجيل جميع قواعد إبطال الكاش.
     * يُستدعى مرة واحدة عند تشغيل التطبيق (في index.php).
     */
    public static function register(): void
    {
        // ملاحظة: حالياً يتم مسح كاش المنتجات بالكامل عند أي تغيير.
        // للتحسين المستقبلي: استخدم Cache::setWithTags() لتخزين كل منتج
        // بشكل فردي، ثم Cache::forgetTag('product_123') لمسح منتج واحد فقط.

        // ── Products ──────────────────────────────────────────
        $invalidateProducts = function (array $data = []): void {
            CachedRepository::invalidate('products');
        };
        EventDispatcher::listen('product.created', $invalidateProducts);
        EventDispatcher::listen('product.updated', $invalidateProducts);
        EventDispatcher::listen('product.deleted', $invalidateProducts);
        EventDispatcher::listen('inventory.adjusted', $invalidateProducts);

        // ── Categories ────────────────────────────────────────
        $invalidateCategories = function (array $data = []): void {
            Cache::forget('categories_all');
        };
        EventDispatcher::listen('category.created', $invalidateCategories);
        EventDispatcher::listen('category.updated', $invalidateCategories);
        EventDispatcher::listen('category.deleted', $invalidateCategories);

        // ── Settings ──────────────────────────────────────────
        EventDispatcher::listen('settings.updated', function (array $data = []): void {
            Cache::forget('settings_all');
        });

        // ── Sales (تُبطل كاش المنتجات أيضاً لأن المخزون يتغير) ──
        $invalidateAfterSale = function (array $data = []): void {
            CachedRepository::invalidate('products');
        };
        EventDispatcher::listen('sale.created', $invalidateAfterSale);
        EventDispatcher::listen('sale.deleted', $invalidateAfterSale);

        // ── Inventory alerts عبر Job Queue ─────────────────────
        EventDispatcher::listen('inventory.adjusted', function (array $data): void {
            $threshold = defined('LOW_STOCK_THRESHOLD') ? LOW_STOCK_THRESHOLD : 5;
            if (isset($data['quantity']) && $data['quantity'] <= $threshold) {
                \App\Helpers\JobQueue::dispatch('send_low_stock_alert', [
                    'product_id' => $data['product_id'] ?? 0,
                    'name'       => $data['name'] ?? '',
                    'quantity'   => $data['quantity'] ?? 0,
                ]);
            }
        });
    }
}
