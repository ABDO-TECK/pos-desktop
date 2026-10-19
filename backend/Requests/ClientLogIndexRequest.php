<?php

declare(strict_types=1);

namespace App\Requests;

use App\Core\FormRequest;

final class ClientLogIndexRequest extends FormRequest
{
    private const DEFAULT_LIMIT = 10;

    public function rules(): array
    {
        return [
            'level' => 'string|in:all,debug,info,warning,error,critical',
            'limit' => 'integer|min_value:1|max_value:100',
            'cursor' => 'string|max:2048',
        ];
    }

    /**
     * @return array{level: string, limit: int, cursor: ?string}
     */
    public function normalized(): array
    {
        $data = $this->validated();

        return [
            'level' => strtolower((string) ($data['level'] ?? 'all')),
            'limit' => (int) ($data['limit'] ?? self::DEFAULT_LIMIT),
            'cursor' => isset($data['cursor']) && $data['cursor'] !== ''
                ? (string) $data['cursor']
                : null,
        ];
    }
}
