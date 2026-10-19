<?php

namespace App\Core;

use App\Helpers\ErrorCodes;
use JsonException;

final class RequestBody
{
    public const DEFAULT_MAX_BYTES = 1_048_576;
    private static bool $hasCachedJson = false;
    private static array $cachedJson = [];

    /**
     * Read and decode a JSON object without buffering more than maxBytes + 1.
     *
     * @param resource|null $stream
     */
    public static function readJson(
        mixed $stream = null,
        ?int $contentLength = null,
        ?int $maxBytes = null
    ): array {
        $isLiveRequest = $stream === null && $contentLength === null && $maxBytes === null;
        if ($isLiveRequest && self::$hasCachedJson) {
            return self::$cachedJson;
        }

        $limit = $maxBytes ?? self::configuredMaxBytes();
        $declaredLength = $contentLength ?? self::contentLength();

        if ($declaredLength !== null && $declaredLength > $limit) {
            throw self::payloadTooLarge($limit);
        }

        $shouldClose = false;
        if ($stream === null) {
            $stream = fopen('php://input', 'rb');
            $shouldClose = true;
        }

        if (!is_resource($stream)) {
            throw new HttpException(
                'Unable to read request body',
                400,
                ErrorCodes::MALFORMED_JSON
            );
        }

        $raw = '';
        try {
            while (!feof($stream)) {
                $remaining = $limit - strlen($raw);
                $chunk = fread($stream, min(8192, $remaining + 1));
                if ($chunk === false) {
                    throw new HttpException(
                        'Unable to read request body',
                        400,
                        ErrorCodes::MALFORMED_JSON
                    );
                }
                if ($chunk === '') {
                    break;
                }

                $raw .= $chunk;
                if (strlen($raw) > $limit) {
                    throw self::payloadTooLarge($limit);
                }
            }
        } finally {
            if ($shouldClose) {
                fclose($stream);
            }
        }

        if (trim($raw) === '') {
            if ($isLiveRequest) {
                self::$cachedJson = [];
                self::$hasCachedJson = true;
            }
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(
                'Malformed JSON request body',
                400,
                ErrorCodes::MALFORMED_JSON
            );
        }

        if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
            throw new HttpException(
                'JSON request body must be an object',
                400,
                ErrorCodes::MALFORMED_JSON
            );
        }

        if ($isLiveRequest) {
            self::$cachedJson = $data;
            self::$hasCachedJson = true;
        }

        return $data;
    }

    public static function configuredMaxBytes(): int
    {
        $configured = $_ENV['JSON_BODY_MAX_BYTES'] ?? getenv('JSON_BODY_MAX_BYTES');
        if (is_string($configured) && ctype_digit($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return self::DEFAULT_MAX_BYTES;
    }

    private static function contentLength(): ?int
    {
        $value = $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return ctype_digit((string) $value) ? (int) $value : null;
    }

    private static function payloadTooLarge(int $limit): HttpException
    {
        return new HttpException(
            'JSON request body exceeds the allowed size',
            413,
            ErrorCodes::PAYLOAD_TOO_LARGE,
            ['max_bytes' => $limit]
        );
    }
}
