<?php

namespace App\Core;

use PDO;

abstract class Controller {
    private ?PDO $transactionDb = null;

    protected function setTransactionDatabase(PDO $db): void
    {
        $this->transactionDb = $db;
    }

    protected function getBody(): array {
        return RequestBody::readJson();
    }

    protected function getParam(string $key, mixed $default = null): mixed {
        return $_GET[$key] ?? $default;
    }

    /**
     * Validate and cast a route parameter to a positive integer.
     * Throws ValidationException if the value is not a valid numeric ID.
     *
     * @param string $value The raw route parameter value
     * @param string $name  The parameter name for error messages (default: 'id')
     * @return int The validated integer ID
     * @throws ValidationException If the value is not a positive integer
     */
    protected function resolveId(string $value, string $name = 'id'): int
    {
        if (!ctype_digit($value)) {
            throw new ValidationException(
                [$name => ["{$name} must be a valid positive integer"]],
                'Invalid route parameter'
            );
        }
        return (int) $value;
    }

    /**
     * التحقق من صحة البيانات — يدعم القواعد التالية:
     *  required    — الحقل مطلوب (لا يقبل فارغ أو null)
     *  min:N       — الحد الأدنى لعدد الأحرف
     *  max:N       — الحد الأقصى لعدد الأحرف
     *  numeric     — قيمة رقمية
     *  integer     — قيمة عدد صحيح
     *  email       — بريد إلكتروني صالح
     *  in:a,b,c    — يجب أن يكون ضمن قائمة محددة
     *  array       — يجب أن يكون مصفوفة
     *  min_value:N — الحد الأدنى للقيمة الرقمية
     *  max_value:N — الحد الأقصى للقيمة الرقمية
     *  string      — يجب أن يكون نص
     *  date        — تاريخ صالح Y-m-d
     *
     * @param array $data    البيانات المُراد التحقق منها
     * @param array $rules   قواعد بصيغة ['field' => 'required|numeric|min:1']
     * @return array         مصفوفة أخطاء (فارغة = بيانات صحيحة)
     */
    protected function validate(array $data, array $rules): array {
        $errors = Validator::validate($data, $rules);
        if (!empty($errors)) {
            throw new ValidationException($errors, 'فشل التحقق من صحة البيانات');
        }
        
        return $errors;
    }


    /**
     * استخراج معاملات الترقيم (Pagination) من الطلب مع قيم افتراضية موحدة.
     *
     * @param int $defaultLimit الحد الافتراضي لعدد النتائج (20)
     * @param int $maxLimit     الحد الأقصى المسموح (500)
     * @return array{page: int, limit: int}
     */
    protected function getPaginationParams(int $defaultLimit = 20, int $maxLimit = 100): array
    {
        $page  = $this->getParam('page', 1);
        $limit = $this->getParam('limit', $defaultLimit);
        return [
            'page'  => max(1, min(1000, (int) ($page ?? 1))),
            'limit' => max(1, min($maxLimit, (int) ($limit ?? $defaultLimit))),
        ];
    }

    /**
     * تنفيذ عملية داخل Database Transaction.
     * إذا نجحت الدالة يُعمل commit، وإذا فشلت يُعمل rollback.
     *
     * @param callable $callback الدالة التي تحتوي على عمليات قاعدة البيانات
     * @return mixed نتيجة الدالة
     * @throws \Throwable يُعيد رمي الاستثناء بعد الـ rollback
     */
    protected function withTransaction(callable $callback): mixed
    {
        $db = $this->transactionDb ?? \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $result = $callback($db);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
