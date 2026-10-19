<?php

namespace App\Core;

use Exception;


/**
 * استثناء مخصص لأخطاء التحقق من صحة البيانات (Validation).
 * يمكن التقاطه في الـ Error Handler الرئيسي لتحويله إلى 422 Unprocessable Entity.
 */
class ValidationException extends Exception
{
    private array $errors;

    public function __construct(array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, 422);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
