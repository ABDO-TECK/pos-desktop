<?php

declare(strict_types=1);

namespace App\Config;

final class DeploymentSecurity
{
    private const MODES = ['desktop', 'web', 'lan'];

    public static function validate(
        string $mode,
        string $databaseUser,
        string $databasePassword,
        bool $forceHttps,
        bool $secureCookies,
        bool $appDebug,
        string $appEnvironment = 'development'
    ): void {
        $isProduction = strtolower(trim($appEnvironment)) === 'production';

        if (!in_array($mode, self::MODES, true)) {
            throw new \RuntimeException(
                'DEPLOYMENT_MODE must be one of: ' . implode(', ', self::MODES)
            );
        }

        // Desktop mode: loopback-only deployment (packaged Electron app).
        // Production desktop enforces APP_DEBUG=false but skips web-specific
        // checks (HTTPS, secure cookies, non-root DB) since the server is
        // bound to 127.0.0.1 and credentials are generated per-installation.
        if ($mode === 'desktop') {
            if ($isProduction && $appDebug) {
                throw new \RuntimeException(
                    'DESKTOP production deployment requires: APP_DEBUG=false'
                );
            }
            return;
        }

        $violations = [];
        if ($isProduction && self::isPlaceholderSecret($databasePassword)) {
            $violations[] = 'DB_PASS must be a deployment-specific secret';
        } elseif ($databasePassword === '') {
            $violations[] = 'DB_PASS must not be empty';
        }
        if (strtolower(trim($databaseUser)) === 'root') {
            $violations[] = 'DB_USER must be a dedicated non-root account';
        }
        if (!$forceHttps) {
            $violations[] = 'FORCE_HTTPS=true';
        }
        if (!$secureCookies) {
            $violations[] = 'SECURE_COOKIES=true';
        }
        if ($appDebug) {
            $violations[] = 'APP_DEBUG=false';
        }

        if ($violations !== []) {
            throw new \RuntimeException(
                strtoupper($mode) . ' deployment requires: ' . implode(', ', $violations)
            );
        }
    }

    private static function isPlaceholderSecret(string $secret): bool
    {
        return $secret === '' || preg_match(
            '/\A(?:CHANGE_ME|REPLACE_ME|YOUR_|EXAMPLE|TODO|replace[-_ ]?me|password|secret)(?:\z|[^a-z])/i',
            trim($secret)
        ) === 1;
    }
}
