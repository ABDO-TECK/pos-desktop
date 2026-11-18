<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class ShellUpdateHealthService
{
    /** Read-only measurement over the application's actual credential and DB connection. */
    public static function databaseProof(): array
    {
        $port = filter_var(getenv('DB_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $database = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $password = getenv('DB_PASS');
        if ($port === false || !is_string($database) || !preg_match('/^[A-Za-z0-9_]+$/D', $database) ||
            !is_string($user) || $user === '' || !is_string($password) || $password === '') {
            throw new RuntimeException('The owned database connection cannot be verified.');
        }
        $db = new PDO("mysql:host=127.0.0.1;port={$port};dbname={$database};charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $contract = (new UpdateDataCompatibilityService())->describe($db);
        return [
            'format' => $contract['format'],
            'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
            'schema_sha256' => $contract['schema_sha256'],
            'ledger_sha256' => $contract['ledger_sha256'],
        ];
    }

    /** Enforce the write barrier before loading the normal HTTP application. */
    public static function restrictProbationTraffic(string $uri, string $phar): bool
    {
        $token = getenv('POS_SHELL_PROBATION_TOKEN');
        if (!is_string($token) || $token === '') {
            return false;
        }
        $gate = getenv('POS_SHELL_PROBATION_GATE');
        if (is_string($gate) && is_file($gate) && !is_link($gate)) {
            $allowed = @file_get_contents($gate, false, null, 0, 65);
            if (is_string($allowed) && hash_equals($token, $allowed)) {
                return false;
            }
        }

        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if ($uri !== '/api/health' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' ||
            !hash_equals($token, $_SERVER['HTTP_X_POS_SHELL_HEALTH'] ?? '')) {
            http_response_code(503);
            echo '{"status":"maintenance","critical_failed":true}';
            return true;
        }

        try {
            $version = json_decode(file_get_contents($phar . '/version.json'), true, 32, JSON_THROW_ON_ERROR)['version'];
            echo json_encode([
                'status' => 'ok', 'critical_failed' => false, 'version' => $version,
                'data_compatibility' => self::databaseProof(),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            http_response_code(503);
            echo '{"status":"database_unavailable","critical_failed":true}';
        }
        return true;
    }
}
