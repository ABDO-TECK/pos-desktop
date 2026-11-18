<?php
declare(strict_types=1);

namespace App\Requests;

use App\Core\FormRequest;
use App\Core\ValidationException;
use App\Services\ShellDeltaDeliveryService;

final class ShellUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return ['shell_version' => 'required|string|max:64'];
    }

    public function version(): string
    {
        $version = $this->validated()['shell_version'];
        if (!ShellDeltaDeliveryService::isVersionSelector($version)) {
            throw new ValidationException(['shell_version' => ['إصدار تحديث تطبيق سطح المكتب غير صالح.']]);
        }
        return $version;
    }
}
