<?php

namespace App\Core;


/**
 * الفئة الأساسية (Base Class) لطلبات الـ DTO (Data Transfer Objects).
 * تفصل منطق الـ Validation عن الكنترولر وتعزز التنظيم.
 */
abstract class FormRequest
{
    private array $data;
    private array $validatedData = [];

    /**
     * @param array $data بيانات الطلب الواردة عادة عبر getBody()
     */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->validate();
    }

    /**
     * تحديد القواعد كما في مصفوفة قواعد Controller::validate.
     */
    abstract public function rules(): array;

    /**
     * تنفيذ التحقق ورمي استثناء في حالة الفشل.
     */
    private function validate(): void
    {
        // استدعاء التحقق الثابت (يمكننا لاحقًا نقل محرك التحقق من كلاس Controller إلى كلاس Validator مستقل)
        // حالياً سنستخدم نسخة محمولة من دالة التحقق
        $rules = $this->rules();
        $errors = $this->runValidation($this->data, $rules);

        if (!empty($errors)) {
            throw new ValidationException($errors, 'فشل التحقق من صحة البيانات');
        }

        // حفظ البيانات الصالحة
        foreach (array_keys($rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $this->validatedData[$field] = $this->data[$field];
            }
        }
    }

    /**
     * إرجاع البيانات السليمة المستخرجة فقط.
     */
    public function validated(): array
    {
        return $this->validatedData;
    }

    /**
     * محرك التحقق المعزول. مطابق للموجود في Controller.
     */
    private function runValidation(array $data, array $rules): array
    {
        return Validator::validate($data, $rules);
    }
}
