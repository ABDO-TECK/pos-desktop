<?php

declare(strict_types=1);

namespace App\Helpers;

use Throwable;

/**
 * Captures PHP runtime warnings and fatal shutdown errors in the same
 * structured log stream used by application exceptions.
 */
final class ErrorHandler
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        set_error_handler(static function (
            int $severity,
            string $message,
            string $file,
            int $line
        ): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            $level = in_array($severity, [
                E_WARNING,
                E_USER_WARNING,
                E_RECOVERABLE_ERROR,
                E_USER_ERROR,
            ], true) ? Logger::ERROR : Logger::WARNING;

            try {
                Logger::log($level, 'PHP runtime error: ' . self::cleanMessage($message), [
                    'reference' => bin2hex(random_bytes(8)),
                    'severity' => self::severityName($severity),
                    'file' => basename($file),
                    'line' => $line,
                ]);
            } catch (Throwable $loggingFailure) {
                error_log('[POS][ERROR] Failed to record PHP runtime error: ' . $loggingFailure->getMessage());
            }

            // Prevent PHP from leaking warning text into an API response.
            return true;
        });

        register_shutdown_function(static function (): void {
            $lastError = error_get_last();
            if (!is_array($lastError) || !isset($lastError['type'])) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
            if (!in_array((int) $lastError['type'], $fatalTypes, true)) {
                return;
            }

            try {
                Logger::critical('Fatal PHP error: ' . self::cleanMessage((string) ($lastError['message'] ?? 'Unknown error')), [
                    'reference' => bin2hex(random_bytes(8)),
                    'severity' => self::severityName((int) $lastError['type']),
                    'file' => basename((string) ($lastError['file'] ?? 'unknown')),
                    'line' => (int) ($lastError['line'] ?? 0),
                ]);
            } catch (Throwable $loggingFailure) {
                error_log('[POS][CRITICAL] Failed to record fatal PHP error: ' . $loggingFailure->getMessage());
            }
        });

        // Install a fallback before application bootstrap so exceptions thrown
        // while loading configuration (before the HTTP handler exists) are not
        // lost. The front controller replaces this with its JSON-aware handler.
        set_exception_handler(static function (Throwable $exception): void {
            try {
                Logger::critical('Unhandled application exception', Logger::exceptionContext($exception));
            } catch (Throwable $loggingFailure) {
                error_log('[POS][CRITICAL] Failed to record uncaught exception: ' . $loggingFailure->getMessage());
            }

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Unhandled application exception. Check the maintenance log.\n");
                exit(1);
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Internal server error',
                'data' => null,
                'errors' => ['code' => 'INTERNAL_ERROR'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }

    private static function cleanMessage(string $message): string
    {
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message) ?? $message;
        $message = preg_replace(
            '/((?:password|token|secret|api[_-]?key)\s*[=:]\s*)[^\s,;]+/i',
            '$1[REDACTED]',
            $message
        ) ?? $message;

        return function_exists('mb_substr')
            ? mb_substr($message, 0, 2000)
            : substr($message, 0, 2000);
    }

    private static function severityName(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_WARNING => 'E_WARNING',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_NOTICE => 'E_NOTICE',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_' . (string) $severity,
        };
    }
}
