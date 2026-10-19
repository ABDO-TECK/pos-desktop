<?php

namespace App\Services;

/**
 * Shared release-generation and version-metadata policy.
 *
 * A v0 client may only consume another v0 release. The policy is deliberately
 * independent from transport, manifest validation, or update execution so
 * every discovery and installation entry point can make the same decision.
 */
final class ReleaseVersionPolicy
{
    public const LEGACY_GENERATION_REASON = 'Release belongs to a legacy series and cannot be applied.';
    public const INVALID_VERSION_REASON = 'Release version metadata is invalid.';

    public static function normalize(mixed $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $normalized = ltrim(trim($version), 'vV');
        return self::isValid($normalized) ? $normalized : null;
    }

    public static function isValid(mixed $version): bool
    {
        return is_string($version)
            && preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', trim($version)) === 1;
    }

    /**
     * @return array{
     *   compatible: bool,
     *   reason_code: ?string,
     *   reason: ?string,
     *   current_version: ?string,
     *   candidate_version: ?string
     * }
     */
    public static function assess(mixed $currentVersion, mixed $candidateVersion): array
    {
        $current = self::normalize($currentVersion);
        $candidate = self::normalize($candidateVersion);

        if ($current === null || $candidate === null) {
            return [
                'compatible' => false,
                'reason_code' => 'invalid_version',
                'reason' => self::INVALID_VERSION_REASON,
                'current_version' => $current,
                'candidate_version' => $candidate,
            ];
        }

        if (version_compare($current, '1.0.0', '<') && version_compare($candidate, '1.0.0', '>=')) {
            return [
                'compatible' => false,
                'reason_code' => 'legacy_generation',
                'reason' => self::LEGACY_GENERATION_REASON,
                'current_version' => $current,
                'candidate_version' => $candidate,
            ];
        }

        return [
            'compatible' => true,
            'reason_code' => null,
            'reason' => null,
            'current_version' => $current,
            'candidate_version' => $candidate,
        ];
    }
}
