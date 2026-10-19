<?php

declare(strict_types=1);

namespace App\Security;

final class QzRequestPolicy
{
    /**
     * qz-tray.js hashes the canonical call payload before invoking the
     * signature callback. The endpoint must therefore receive only the
     * expected SHA-256 digest, never an arbitrary command or JSON document.
     */
    public static function normalizeDigest(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : null;
    }
}
