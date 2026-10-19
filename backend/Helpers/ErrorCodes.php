<?php

namespace App\Helpers;


/**
 * دقات الأخطاء القياسية (Standard Error Codes).
 * تُستخدم هذه الثوابت لتوحيد أخطاء الـ API بدلاً من النصوص التي يصعب ترجمتها.
 * يمكن للـ Frontend التقاط الكود وترجمته محلياً.
 */
class ErrorCodes
{
    // ==========================================
    // أخطاء عامة (General Errors)
    // ==========================================
    public const VALIDATION_FAILED = 'ERR_VALIDATION_FAILED';
    public const NOT_FOUND         = 'ERR_NOT_FOUND';
    public const UNAUTHORIZED      = 'ERR_UNAUTHORIZED';
    public const FORBIDDEN         = 'ERR_FORBIDDEN';
    public const SERVER_ERROR      = 'ERR_SERVER_ERROR';
    public const MALFORMED_JSON    = 'ERR_MALFORMED_JSON';
    public const PAYLOAD_TOO_LARGE = 'ERR_PAYLOAD_TOO_LARGE';
    public const UNSUPPORTED_API_VERSION = 'ERR_UNSUPPORTED_API_VERSION';

    // ==========================================
    // أخطاء متعلقة بالمنتجات والمخزون
    // ==========================================
    public const PRODUCT_NOT_FOUND     = 'ERR_PRODUCT_NOT_FOUND';
    public const DUPLICATE_BARCODE     = 'ERR_DUPLICATE_BARCODE';
    public const INSUFFICIENT_STOCK    = 'ERR_INSUFFICIENT_STOCK';
    public const PRODUCT_IN_USE        = 'ERR_PRODUCT_IN_USE'; // لا يمكن الحذف لارتباطه بفواتير

    // ==========================================
    // أخطاء متعلقة بالمبيعات والفواتير
    // ==========================================
    public const INVOICE_NOT_FOUND     = 'ERR_INVOICE_NOT_FOUND';
    public const EMPTY_CART            = 'ERR_EMPTY_CART';
    public const INVALID_PAYMENT       = 'ERR_INVALID_PAYMENT';
    public const IDEMPOTENCY_CONFLICT  = 'ERR_IDEMPOTENCY_CONFLICT';

    // ==========================================
    // أخطاء متعلقة بالعملاء والموردين
    // ==========================================
    public const CUSTOMER_NOT_FOUND    = 'ERR_CUSTOMER_NOT_FOUND';
    public const SUPPLIER_NOT_FOUND    = 'ERR_SUPPLIER_NOT_FOUND';
    public const INVALID_AMOUNT        = 'ERR_INVALID_AMOUNT';
}
