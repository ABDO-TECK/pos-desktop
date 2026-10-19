<?php

namespace App\Helpers;

/**
 * EventDispatcher — نظام أحداث بسيط (Observer Pattern).
 *
 * الاستخدام:
 *   // تسجيل مستمع:
 *   EventDispatcher::listen('product.updated', function (array $data) { ... });
 *
 *   // إطلاق حدث:
 *   EventDispatcher::dispatch('product.updated', ['id' => 42]);
 *
 * الأحداث المتاحة:
 *   product.created, product.updated, product.deleted
 *   category.created, category.updated, category.deleted
 *   settings.updated
 *   sale.created, sale.deleted
 *   supplier.created, supplier.updated, supplier.deleted
 *   customer.created, customer.updated, customer.deleted
 *   inventory.adjusted
 */
class EventDispatcher
{
    /** @var array<string, callable[]> */
    private static array $listeners = [];

    /**
     * تسجيل مستمع لحدث معين.
     *
     * @param string   $event    اسم الحدث (مثل: 'product.updated')
     * @param callable $callback الدالة التي تُستدعى عند إطلاق الحدث
     */
    public static function listen(string $event, callable $callback): void
    {
        self::$listeners[$event][] = $callback;
    }

    /**
     * إطلاق حدث — يستدعي جميع المستمعين المسجلين.
     *
     * @param string $event اسم الحدث
     * @param array  $data  بيانات الحدث (اختيارية)
     */
    public static function dispatch(string $event, array $data = []): void
    {
        if (empty(self::$listeners[$event])) {
            return;
        }

        foreach (self::$listeners[$event] as $callback) {
            try {
                $callback($data);
            } catch (\Throwable $e) {
                // لا نوقف التطبيق بسبب خطأ في مستمع
                Logger::warning("Event listener error for '{$event}'", Logger::exceptionContext($e));
            }
        }
    }

    /**
     * مسح جميع المستمعين (للاختبارات).
     */
    public static function clearAll(): void
    {
        self::$listeners = [];
    }
}
