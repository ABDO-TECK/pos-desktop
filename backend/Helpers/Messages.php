<?php

namespace App\Helpers;

/**
 * Messages — رسائل API الموحدة.
 * تُستخدم لتوحيد لغة الرسائل عبر جميع الـ Controllers.
 * الرسائل بالعربية لأن واجهة المستخدم عربية بالكامل.
 * الـ Frontend يمكنه ترجمة الرسائل بناءً على error_code إذا لزم الأمر.
 */
class Messages
{
    // ── عامة ──────────────────────────────────────
    public const VALIDATION_FAILED   = 'فشل التحقق من صحة البيانات';
    public const ACCESS_DENIED       = 'غير مصرح بالوصول';
    public const NOT_FOUND           = 'العنصر غير موجود';
    public const SERVER_ERROR        = 'حدث خطأ في الخادم';
    public const SERVICE_UNAVAILABLE = 'الخدمة غير متوفرة مؤقتاً. يرجى المحاولة مرة أخرى.';

    // ── المستخدمين ────────────────────────────────
    public const USER_CREATED        = 'تم إنشاء المستخدم بنجاح';
    public const USER_UPDATED        = 'تم تحديث المستخدم بنجاح';
    public const USER_DELETED        = 'تم حذف المستخدم بنجاح';
    public const CANNOT_DELETE_SELF   = 'لا يمكنك حذف حسابك الخاص';

    // ── الفواتير والمبيعات ────────────────────────
    public const INVOICE_NOT_FOUND   = 'الفاتورة غير موجودة';
    public const INVOICE_DELETED     = 'تم حذف الفاتورة بنجاح';
    public const INVOICE_UPDATED     = 'تم تحديث الفاتورة بنجاح';
    public const SALE_COMPLETED      = 'تمت عملية البيع بنجاح';
    public const STATUS_UPDATED      = 'تم تحديث حالة الفاتورة بنجاح';
    public const EMPTY_CART          = 'السلة فارغة';

    // ── المنتجات ──────────────────────────────────
    public const PRODUCT_NOT_FOUND   = 'المنتج غير موجود';
    public const PRODUCT_CREATED     = 'تم إنشاء المنتج بنجاح';
    public const PRODUCT_UPDATED     = 'تم تحديث المنتج بنجاح';
    public const PRODUCT_DELETED     = 'تم حذف المنتج بنجاح';

    // ── الموردين ──────────────────────────────────
    public const SUPPLIER_NOT_FOUND  = 'المورد غير موجود';
    public const SUPPLIER_CREATED    = 'تم إنشاء المورد بنجاح';
    public const SUPPLIER_UPDATED    = 'تم تحديث المورد بنجاح';
    public const SUPPLIER_DELETED    = 'تم حذف المورد بنجاح';

    // ── العملاء ───────────────────────────────────
    public const CUSTOMER_NOT_FOUND  = 'العميل غير موجود';
    public const CUSTOMER_CREATED    = 'تم إنشاء العميل بنجاح';
    public const CUSTOMER_UPDATED    = 'تم تحديث العميل بنجاح';
    public const CUSTOMER_DELETED    = 'تم حذف العميل بنجاح';

    // ── كشف الحساب ────────────────────────────────
    public const LEDGER_ENTRY_CREATED = 'تم تسجيل القيد بنجاح';
    public const LEDGER_ENTRY_UPDATED = 'تم تحديث القيد';
    public const LEDGER_ENTRY_DELETED = 'تم حذف القيد';
    public const PAYMENT_RECORDED     = 'تم تسجيل الدفعة';

    // ── التصنيفات ─────────────────────────────────
    public const CATEGORY_CREATED    = 'تم إنشاء التصنيف بنجاح';
    public const CATEGORY_UPDATED    = 'تم تحديث التصنيف بنجاح';
    public const CATEGORY_DELETED    = 'تم حذف التصنيف بنجاح';
    public const CATEGORY_CREATE_FAIL = 'حدث خطأ أثناء إضافة التصنيف';
    public const CATEGORY_UPDATE_FAIL = 'حدث خطأ أثناء تعديل التصنيف';
    public const CATEGORY_DELETE_FAIL = 'حدث خطأ أثناء حذف التصنيف';

    // ── المشتريات ─────────────────────────────────
    public const PURCHASE_CREATED    = 'تم تسجيل المشتريات وتحديث المخزون';
    public const PURCHASE_DELETED    = 'تم حذف فاتورة المشتريات واستعادة المخزون';
    public const PURCHASE_NOT_FOUND  = 'فاتورة المشتريات غير موجودة';

    // ── المصروفات ─────────────────────────────────
    public const EXPENSE_CREATE_FAIL = 'حدث خطأ أثناء تسجيل المصروف';
    public const EXPENSE_UPDATE_FAIL = 'حدث خطأ أثناء تعديل المصروف';
    public const EXPENSE_DELETE_FAIL = 'حدث خطأ أثناء الحذف';

    // ── الولاء ────────────────────────────────────
    public const INVALID_POINTS      = 'عدد النقاط غير صالح';

    // ── المخزون ───────────────────────────────────
    public const NEGATIVE_QUANTITY   = 'الكمية لا يمكن أن تكون سالبة';

    // ── الإعدادات ─────────────────────────────────
    public const SETTINGS_UPDATED    = 'تم تحديث الإعدادات بنجاح';
}
