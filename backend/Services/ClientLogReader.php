<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Helpers\Logger;
use Generator;

final class ClientLogReader
{
    private const READ_CHUNK_BYTES = 8192;
    private const MAX_LINE_BYTES = 65536;
    private const CURSOR_VERSION = 1;
    private const LEGACY_LOG_TIMEZONE = 'Europe/Berlin';

    private string $logDir;

    /** @var callable(string): void|null */
    private $onFileOpened;

    public function __construct(?string $logDir = null, ?callable $onFileOpened = null)
    {
        $this->logDir = $logDir ?? Logger::getLogDirectory();
        $this->onFileOpened = $onFileOpened;
    }

    /**
     * @param bool $includeAll When true, include server/runtime entries in addition to client entries.
     * @return array{
     *     data: list<array{id: string, created_at: mixed, level: mixed, message: mixed, context: mixed, source: string}>,
     *     pagination: array{page: int, limit: int, next_cursor: ?string, has_more: bool}
     * }
     */
    public function paginate(string $level, int $limit, ?string $cursor = null, bool $includeAll = false): array
    {
        $files = Logger::getLogFiles($this->logDir, true);
        $cursorState = $this->decodeCursor($cursor, $level, $files);
        $startFileIndex = $cursorState['file_index'];
        $page = $cursorState['page'];
        $logs = [];

        for ($fileIndex = $startFileIndex; $fileIndex < count($files); $fileIndex++) {
            $file = $files[$fileIndex];
            $offset = $fileIndex === $startFileIndex
                ? $cursorState['offset']
                : null;

            if ($offset === 0) {
                continue;
            }

            foreach ($this->readLinesReverse($file, $offset) as $lineData) {
                $entry = json_decode($lineData['line'], true);
                if (!is_array($entry)) {
                    continue;
                }

                $message = $entry['message'] ?? '';
                if (!is_string($message)) {
                    continue;
                }

                $isClientEntry = str_starts_with($message, '[CLIENT]');
                if (!$includeAll && !$isClientEntry) {
                    continue;
                }

                $rawLevel = $entry['level'] ?? null;
                if (!is_string($rawLevel)) {
                    continue;
                }
                $logLevel = strtolower($rawLevel);
                if ($level !== 'all' && $logLevel !== $level) {
                    continue;
                }

                $context = $entry['context'] ?? [];
                if (is_array($context)) {
                    $context = Logger::redactContext($context);
                }

                $logs[] = [
                    'id' => hash('sha256', basename($file) . ':' . $lineData['offset'] . ':' . $lineData['line']),
                    'created_at' => $this->normalizeTimestamp(
                        $entry['timestamp'] ?? null,
                        $entry['timezone'] ?? null
                    ),
                    'level' => $rawLevel,
                    'message' => $message,
                    'context' => $context,
                    'source' => $isClientEntry ? 'client' : 'server',
                ];

                if (count($logs) === $limit) {
                    $hasMore = $lineData['offset'] > 0 || $fileIndex < count($files) - 1;
                    return [
                        'data' => $logs,
                        'pagination' => [
                            'page' => $page,
                            'limit' => $limit,
                            'next_cursor' => $hasMore
                                ? $this->encodeCursor(basename($file), $lineData['offset'], $level, $page + 1)
                                : null,
                            'has_more' => $hasMore,
                        ],
                    ];
                }
            }
        }

        return [
            'data' => $logs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'next_cursor' => null,
                'has_more' => false,
            ],
        ];
    }

    private function normalizeTimestamp(mixed $timestamp, mixed $sourceTimezone): ?string
    {
        if (!is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        $targetTimezone = Logger::getTimezone();
        $sourceTimezoneName = is_string($sourceTimezone) && trim($sourceTimezone) !== ''
            ? trim($sourceTimezone)
            : self::LEGACY_LOG_TIMEZONE;

        try {
            $sourceTimezoneObject = new \DateTimeZone($sourceTimezoneName);
            $date = new \DateTimeImmutable($timestamp, $sourceTimezoneObject);
            return $date->setTimezone($targetTimezone)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            // Preserve an unparseable value for diagnostics instead of hiding it.
            return $timestamp;
        }
    }

    /**
     * @return Generator<int, array{line: string, offset: int}>
     */
    private function readLinesReverse(string $file, ?int $startOffset): Generator
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return;
        }

        if ($this->onFileOpened !== null) {
            ($this->onFileOpened)($file);
        }

        try {
            $stats = fstat($handle);
            $fileSize = (int) ($stats['size'] ?? 0);
            $position = min($startOffset ?? $fileSize, $fileSize);
            $buffer = '';
            $discardingOversizedLine = false;

            while ($position > 0) {
                $readStart = max(0, $position - self::READ_CHUNK_BYTES);
                $readLength = $position - $readStart;
                if (fseek($handle, $readStart) !== 0) {
                    break;
                }

                $chunk = fread($handle, $readLength);
                if ($chunk === false || strlen($chunk) !== $readLength) {
                    break;
                }
                $position = $readStart;

                if ($discardingOversizedLine) {
                    $newlinePosition = strrpos($chunk, "\n");
                    if ($newlinePosition === false) {
                        continue;
                    }
                    $buffer = substr($chunk, 0, $newlinePosition);
                    $discardingOversizedLine = false;
                } else {
                    $buffer = $chunk . $buffer;
                }

                while (($newlinePosition = strrpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, $newlinePosition + 1), "\r");
                    $nextOffset = $position + $newlinePosition;
                    $buffer = substr($buffer, 0, $newlinePosition);

                    if ($line !== '' && strlen($line) <= self::MAX_LINE_BYTES) {
                        yield ['line' => $line, 'offset' => $nextOffset];
                    }
                }

                if (strlen($buffer) > self::MAX_LINE_BYTES) {
                    $buffer = '';
                    $discardingOversizedLine = true;
                }
            }

            if (!$discardingOversizedLine && $buffer !== '' && strlen($buffer) <= self::MAX_LINE_BYTES) {
                yield ['line' => rtrim($buffer, "\r"), 'offset' => 0];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string> $files
     * @return array{file_index: int, offset: ?int, page: int}
     */
    private function decodeCursor(?string $cursor, string $level, array $files): array
    {
        if ($cursor === null || $cursor === '') {
            return ['file_index' => 0, 'offset' => null, 'page' => 1];
        }

        $padding = (4 - strlen($cursor) % 4) % 4;
        $decoded = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', $padding), true);
        $state = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($state)
            || ($state['v'] ?? null) !== self::CURSOR_VERSION
            || !is_string($state['file'] ?? null)
            || !is_int($state['offset'] ?? null)
            || ($state['offset'] ?? -1) < 0
            || !is_int($state['page'] ?? null)
            || ($state['page'] ?? 0) < 2
            || !is_string($state['level'] ?? null)
            || !hash_equals($level, $state['level'])) {
            throw new ValidationException(['cursor' => ['Invalid or expired log cursor.']]);
        }

        foreach ($files as $index => $file) {
            if (hash_equals(basename($file), $state['file'])) {
                $fileSize = filesize($file);
                if ($fileSize === false || $state['offset'] > $fileSize) {
                    break;
                }

                return [
                    'file_index' => $index,
                    'offset' => $state['offset'],
                    'page' => $state['page'],
                ];
            }
        }

        throw new ValidationException(['cursor' => ['Invalid or expired log cursor.']]);
    }

    private function encodeCursor(string $file, int $offset, string $level, int $page): string
    {
        $json = json_encode([
            'v' => self::CURSOR_VERSION,
            'file' => $file,
            'offset' => $offset,
            'level' => $level,
            'page' => $page,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
