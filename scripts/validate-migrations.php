<?php
declare(strict_types=1);

/**
 * Migration Validator Tool
 *
 * Validates all SQL migration files against syntax defects:
 * - Empty migrations
 * - Accidental JSON wrappers
 * - Escaped quotes or string wrappers (\"ALTER...)
 * - Broken multi-statement constructs with embedded semicolons
 * - Invalid non-SQL wrappers
 */

$GREEN = "\033[32m";
$RED = "\033[31m";
$YELLOW = "\033[33m";
$CYAN = "\033[36m";
$BOLD = "\033[1m";
$RESET = "\033[0m";

echo "\n{$CYAN}{$BOLD}================================================================================{$RESET}\n";
echo "{$CYAN}{$BOLD}POS DATABASE MIGRATION INTEGRITY VALIDATOR{$RESET}\n";
echo "{$CYAN}{$BOLD}================================================================================{$RESET}\n\n";

$migrationsDir = __DIR__ . '/../database/migrations';
if (!is_dir($migrationsDir)) {
    echo "{$RED}Error: Migrations directory not found at {$migrationsDir}{$RESET}\n";
    exit(1);
}

$files = scandir($migrationsDir) ?: [];
$sqlFiles = array_filter($files, fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'sql');
sort($sqlFiles);

$totalFiles = count($sqlFiles);
$passedFiles = 0;
$failedFiles = 0;
$errors = [];

echo "Scanning {$totalFiles} migration files in database/migrations/...\n\n";

foreach ($sqlFiles as $file) {
    $filePath = $migrationsDir . '/' . $file;
    $content = (string) file_get_contents($filePath);
    $trimmed = trim($content);
    $fileErrors = [];

    // 1. Check for empty files
    if ($trimmed === '') {
        $fileErrors[] = 'File is completely empty.';
    }

    // 2. Check for accidental JSON formatting
    if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
        $fileErrors[] = 'File contains JSON wrappers instead of raw SQL.';
    }

    // 3. Check for escaped quotes / malformed strings
    if (preg_match('/\\\\"[A-Z_]/', $content) || preg_match('/\\\\"ALTER/i', $content) || preg_match('/\\\\"SELECT/i', $content)) {
        $fileErrors[] = 'File contains escaped quote sequences (\\").';
    }

    // 4. Check for broken embedded quotes with semicolons (e.g. prepared statement wrapper trap)
    if (preg_match('/SET\s+@preparedStatement\s*=\s*\(/i', $content) && preg_match('/"[^"]*;[^"]*"/m', $content)) {
        $fileErrors[] = 'File contains prepared statement wrapper with embedded semicolons that breaks SQL statement splitters.';
    }

    // 5. Check statement validity after comment removal
    $cleanSql = preg_replace('/--.*$/m', '', $content);
    $cleanSql = trim((string) preg_replace('/\/\*.*?\*\//s', '', (string) $cleanSql));
    
    if ($cleanSql !== '') {
        $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
        foreach ($statements as $stmt) {
            // Must start with valid SQL keyword
            if (!preg_match('/^(ALTER|CREATE|INSERT|UPDATE|DELETE|DROP|SET|SELECT|REPLACE|TRUNCATE|GRANT|REVOKE|START|COMMIT|ROLLBACK|USE|LOCK|UNLOCK|RENAME|PREPARE|EXECUTE|DEALLOCATE)\b/i', $stmt)) {
                $fileErrors[] = "Unrecognized statement header: '" . mb_substr($stmt, 0, 40) . "...'";
                break;
            }
        }
    }

    if (empty($fileErrors)) {
        $passedFiles++;
        echo "  {$GREEN}✔ [PASS]{$RESET} {$file}\n";
    } else {
        $failedFiles++;
        echo "  {$RED}✖ [FAIL]{$RESET} {$file}\n";
        foreach ($fileErrors as $err) {
            echo "      {$RED}↳ {$err}{$RESET}\n";
        }
        $errors[$file] = $fileErrors;
    }
}

echo "\n{$CYAN}{$BOLD}================================================================================{$RESET}\n";
echo "{$CYAN}{$BOLD}VALIDATION RESULTS SUMMARY{$RESET}\n";
echo "{$CYAN}{$BOLD}================================================================================{$RESET}\n";
echo "  Total Migration Files : {$totalFiles}\n";
echo "  Passed Files          : {$GREEN}{$passedFiles}{$RESET}\n";
echo "  Failed Files          : " . ($failedFiles > 0 ? "{$RED}{$failedFiles}{$RESET}" : "{$GREEN}0{$RESET}") . "\n\n";

if ($failedFiles === 0) {
    echo "{$GREEN}{$BOLD}🎉 ALL MIGRATIONS VALIDATED CLEAN AND READY FOR EXECUTION!{$RESET}\n\n";
    exit(0);
} else {
    echo "{$RED}{$BOLD}❌ MIGRATION VALIDATION FAILED FOR {$failedFiles} FILE(S).{$RESET}\n\n";
    exit(1);
}
