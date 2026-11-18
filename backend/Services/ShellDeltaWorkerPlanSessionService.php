<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * ShellDeltaWorkerPlanSessionService
 *
 * PRODUCTION PURE IN-MEMORY WORKER PLAN SESSION PROTOCOL SERVICE.
 * Translates incoming single-frame canonical JSON request into ShellDeltaWorkerFilePlanService
 * invocation and emits bounded canonical JSON replies over authenticated worker transport.
 *
 * ARCHITECTURAL TRUST BOUNDARIES & CONTRACTUAL LIMITS:
 * 1. PURE IN-MEMORY EXECUTION:
 *    - Performs ZERO filesystem lookups, file reads, directory scans, or path resolution.
 *    - Performs ZERO database queries, PDO calls, or state mutations.
 *    - Performs ZERO process creation, shell execution, IPC configuration, or network calls.
 *    - Operates purely on immutable in-memory request strings.
 *
 * 2. UNCONDITIONAL CAN_APPLY = FALSE:
 *    - Every response unconditionally declares can_apply = false.
 *    - Generating or returning a plan projection NEVER authorizes file application.
 *
 * 3. NATIVE BROKER BINDING GATE MANDATORY BEFORE LAUNCH:
 *    - The caller (NativeRoot / C# host) MUST separately verify strict RSA signatures,
 *      pinned source image hashes, and context bindings BEFORE consuming any reply.
 *    - The source_context and target_context provided in the PHP request frame are NOT
 *      physical observations; the native host is the sole authority for physical verification.
 *    - Existing PHP version_compare wide integer limitations remain governed by the mandatory
 *      Native host binding gate before launch.
 *
 * 4. CANONICAL REQUEST & RESPONSE WIRE FORMAT:
 *    - Enforces strictly canonical request JSON: exact 5 keys, exact types, no extra whitespace,
 *      no non-standard escapes, and sorted object keys. Re-encoded bytes must match wire bytes.
 *    - Emits deterministic canonical JSON replies with sorted keys.
 *    - Sanitizes error messages to ensure no raw manifest, signature, or private keys leak.
 */
final class ShellDeltaWorkerPlanSessionService
{
    public const MAX_REQUEST_BYTES = 25165824; // 24 MiB
    public const MAX_MANIFEST_BYTES = ShellDeltaWorkerFilePlanService::MAX_MANIFEST_SIZE; // 16 MiB
    public const MAX_SIGNATURE_BYTES = ShellDeltaWorkerFilePlanService::MAX_SIGNATURE_SIZE; // 16 KiB
    public const MAX_ERROR_BYTES = 500;
    public const MAX_JSON_DEPTH = 16;

    private ShellDeltaWorkerFilePlanService $filePlanService;

    /**
     * Constructor requires injected ShellDeltaWorkerFilePlanService.
     * No ambient key discovery, no DB connection, no file lookup.
     */
    public function __construct(ShellDeltaWorkerFilePlanService $filePlanService)
    {
        $this->filePlanService = $filePlanService;
    }

    /**
     * Handle incoming raw JSON request frame and return a bounded canonical JSON reply.
     *
     * @param string $requestJson Raw wire request frame (<= 24 MiB)
     * @return string Canonical JSON reply
     */
    public function handleRequest(string $requestJson): string
    {
        try {
            $requestLen = strlen($requestJson);
            if ($requestLen === 0 || $requestLen > self::MAX_REQUEST_BYTES) {
                throw new RuntimeException("Request size exceeds allowed limits (1.." . self::MAX_REQUEST_BYTES . " bytes).");
            }

            if (str_starts_with($requestJson, "\xEF\xBB\xBF")) {
                throw new RuntimeException("Byte order mark (BOM) is prohibited.");
            }

            if (preg_match('//u', $requestJson) !== 1) {
                throw new RuntimeException("Invalid UTF-8 encoding in request.");
            }

            try {
                $decoded = json_decode($requestJson, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new RuntimeException("Malformed request JSON: " . $e->getMessage());
            }

            if (!is_array($decoded)) {
                throw new RuntimeException("Request body must be a JSON object.");
            }

            $topKeys = array_keys($decoded);
            sort($topKeys, SORT_STRING);
            $expectedTopKeys = [
                'expected_manifest_sha256',
                'raw_manifest_base64',
                'signature',
                'source_context',
                'target_context',
            ];
            if ($topKeys !== $expectedTopKeys) {
                throw new RuntimeException("Request must contain exact 5 keys: raw_manifest_base64, signature, expected_manifest_sha256, source_context, target_context.");
            }

            // 1. expected_manifest_sha256: 64-char lowercase hex
            $expectedManifestSha256 = $decoded['expected_manifest_sha256'];
            if (!is_string($expectedManifestSha256) || preg_match('/^[a-f0-9]{64}$/D', $expectedManifestSha256) !== 1) {
                throw new RuntimeException("expected_manifest_sha256 must be a 64-character lowercase ASCII hex string.");
            }

            // 2. raw_manifest_base64: canonical base64, decoded 1..16 MiB
            $rawManifestBase64 = $decoded['raw_manifest_base64'];
            if (!is_string($rawManifestBase64)) {
                throw new RuntimeException("raw_manifest_base64 must be a string.");
            }
            $rawManifest = base64_decode($rawManifestBase64, true);
            if ($rawManifest === false || base64_encode($rawManifest) !== $rawManifestBase64) {
                throw new RuntimeException("raw_manifest_base64 must be strictly canonical base64.");
            }
            $rawLen = strlen($rawManifest);
            if ($rawLen < 1 || $rawLen > self::MAX_MANIFEST_BYTES) {
                throw new RuntimeException("Decoded raw manifest length must be between 1 and " . self::MAX_MANIFEST_BYTES . " bytes.");
            }

            // 3. signature: canonical base64, 1..16 KiB
            $signature = $decoded['signature'];
            if (!is_string($signature)) {
                throw new RuntimeException("signature must be a string.");
            }
            $sigLen = strlen($signature);
            if ($sigLen < 1 || $sigLen > self::MAX_SIGNATURE_BYTES) {
                throw new RuntimeException("signature length must be between 1 and " . self::MAX_SIGNATURE_BYTES . " bytes.");
            }
            $sigDecoded = base64_decode($signature, true);
            if ($sigDecoded === false || base64_encode($sigDecoded) !== $signature) {
                throw new RuntimeException("signature must be strictly canonical base64.");
            }

            // Context shape and values are validated by the file plan service.
            $sourceContext = $decoded['source_context'];
            $targetContext = $decoded['target_context'];
            if (!is_array($sourceContext) || !is_array($targetContext)) {
                throw new RuntimeException("source_context and target_context must be JSON objects.");
            }

            // 5. Canonical request JSON check (exact raw bytes compare with reencoded after sorting)
            $canonicalArray = $decoded;
            $this->recursiveKsort($canonicalArray);
            $reencoded = json_encode($canonicalArray, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($requestJson !== $reencoded) {
                throw new RuntimeException("Request JSON is not in canonical format (formatting, whitespace, key ordering, or duplicate keys detected).");
            }

            // 6. Invoke FilePlanService purely in memory
            $planResult = $this->filePlanService->build(
                $rawManifest,
                $signature,
                $expectedManifestSha256,
                $sourceContext,
                $targetContext
            );

            // 7. Assemble canonical success reply
            $response = [
                'can_apply' => false,
                'manifest_sha256' => $expectedManifestSha256,
                'ok' => true,
                'plan_json' => $planResult['plan_json'],
                'plan_sha256' => $planResult['plan_sha256'],
            ];
            $wireReply = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($wireReply) > ShellDeltaWorkerPipeTransportService::MAX_FRAME_SIZE) {
                throw new RuntimeException("Encoded plan reply exceeds the maximum transport frame size.");
            }
            return $wireReply;
        } catch (Throwable $e) {
            $rawMsg = $e->getMessage();
            $cleanMsg = preg_replace('/[A-Za-z]:\\\\[^\r\n:]+/', '[path]', $rawMsg) ?? $rawMsg;
            $cleanMsg = preg_replace('/phar:\/\/[^\r\n:]+/', '[phar-path]', $cleanMsg) ?? $cleanMsg;
            $printableMessage = preg_replace('/[^\x20-\x7E]/', '?', $cleanMsg) ?? 'Internal error';
            $boundedError = substr($printableMessage, 0, self::MAX_ERROR_BYTES);

            $errorResponse = [
                'can_apply' => false,
                'error' => $boundedError,
                'ok' => false,
            ];
            $errorJson = json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($errorJson === false) {
                return '{"can_apply":false,"error":"Internal error","ok":false}';
            }
            return $errorJson;
        }
    }

    /**
     * Recursively sort all object keys using string comparison.
     *
     * @param array<string, mixed> $arr
     */
    private function recursiveKsort(array &$arr): void
    {
        ksort($arr, SORT_STRING);
        foreach ($arr as &$value) {
            if (is_array($value)) {
                $this->recursiveKsort($value);
            }
        }
        unset($value);
    }
}
