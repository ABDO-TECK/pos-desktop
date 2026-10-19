<?php

declare(strict_types=1);

namespace App\Helpers;

final class PasswordHasher
{
    private const OPTIONS = [
        'memory_cost' => 65_536,
        'time_cost' => 3,
        'threads' => 1,
    ];

    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$b2dHRkhSRVNZT2xaeXV0Rw$20UM+k/LSkH98tw1yefC44ZA4njYJVs07DOWd+7/KRs';

    public static function hash(string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
        if ($hash === false) {
            throw new \RuntimeException('Unable to hash password securely');
        }

        return $hash;
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public static function dummyHash(): string
    {
        return self::DUMMY_HASH;
    }
}
