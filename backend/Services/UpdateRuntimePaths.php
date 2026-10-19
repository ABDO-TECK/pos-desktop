<?php

namespace App\Services;

/**
 * Resolves the directory containing deployed, update-managed artifacts.
 *
 * In a packaged desktop build the PHP entrypoint is
 * app.asar.unpacked/backend/backend.phar. The PHAR itself is executable
 * payload, not the directory that delta updates may target.
 */
final class UpdateRuntimePaths
{
    public static function deployedRoot(?string $developmentRoot = null): string
    {
        $configuredRoot = getenv('APP_DEPLOY_ROOT');
        if (is_string($configuredRoot) && trim($configuredRoot) !== '') {
            return self::normalize($configuredRoot);
        }

        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            return self::normalize(dirname($pharPath, 2));
        }

        return self::normalize($developmentRoot ?: (realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2)));
    }

    public static function isPackagedPharDeployment(string $root): bool
    {
        return is_file(self::normalize($root) . '/backend/backend.phar');
    }

    public static function backendHealthEntrypoint(string $root): string
    {
        $normalizedRoot = self::normalize($root);
        return self::isPackagedPharDeployment($normalizedRoot)
            ? $normalizedRoot . '/backend/backend.phar'
            : $normalizedRoot . '/backend/index.php';
    }

    /**
     * Resolves the physical filesystem path to a certificate inside backend/certs.
     *
     * Returns null if no physical file exists or if the path is within a phar:// stream wrapper.
     */
    public static function getBackendCertPath(string $certName = 'cacert.pem', ?string $root = null): ?string
    {
        $normalizedRoot = self::deployedRoot($root);
        $candidates = [
            $normalizedRoot . '/backend/certs/' . $certName,
            $normalizedRoot . '/certs/' . $certName,
        ];

        // If running from source (non-PHAR) and no custom root was specified, allow backend-relative lookups
        if ($root === null && \Phar::running(false) === '') {
            $sourceBackendDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
            $candidates[] = self::normalize($sourceBackendDir) . '/certs/' . $certName;
        }

        foreach ($candidates as $candidate) {
            $candidate = self::normalize($candidate);
            if (!str_starts_with($candidate, 'phar://') && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolves the physical CA bundle path for cURL TLS operations.
     */
    public static function getCaBundlePath(?string $root = null): ?string
    {
        return self::getBackendCertPath('cacert.pem', $root);
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
