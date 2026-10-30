<?php
declare(strict_types=1);

/**
 * GHA-only verification suite for physical product inventory path policy,
 * lexical confinement, junction and reparse rejection, ancestor chain validation,
 * protected user-state sentinel preservation, and SemVer 2.0 vectors.
 */

// GHA-only test guard: must throw outside GHA before creating any fixtures or executing commands
if (getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException("GHA-only verification suite: GITHUB_ACTIONS environment variable is not 'true'. Dynamic execution permitted only in GHA.");
}

require_once __DIR__ . '/../backend/Services/ProductInventoryService.php';

use App\Services\ProductInventoryService;

class TestAssertionFailure extends RuntimeException {}

$testsRun = 0;
$testsPassed = 0;

function assertCondition(bool $condition, string $message): void
{
    global $testsRun, $testsPassed;
    $testsRun++;
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        throw new TestAssertionFailure("[FAIL] {$message}");
    }
    $testsPassed++;
    echo "[PASS] {$message}\n";
}

function assertThrows(callable $fn, string $expectedMessageSubstr, string $message): void
{
    global $testsRun, $testsPassed;
    $testsRun++;
    try {
        $fn();
    } catch (TestAssertionFailure $e) {
        // Rethrow assertion failure so assertThrows does not catch its own test assertion failure
        throw $e;
    } catch (\Throwable $e) {
        if ($expectedMessageSubstr !== '' && !str_contains($e->getMessage(), $expectedMessageSubstr)) {
            fwrite(STDERR, "[FAIL] Expected substring '{$expectedMessageSubstr}' in exception '{$e->getMessage()}': {$message}\n");
            throw new TestAssertionFailure("[FAIL] Expected substring '{$expectedMessageSubstr}' in exception '{$e->getMessage()}': {$message}");
        }
        $testsPassed++;
        echo "[PASS] {$message}\n";
        return;
    }
    fwrite(STDERR, "[FAIL] Expected exception but none thrown: {$message}\n");
    throw new TestAssertionFailure("[FAIL] Expected exception but none thrown: {$message}");
}

$createdJunctions = [];

function createTrackedJunction(string $link, string $target): void
{
    global $createdJunctions;
    $output = [];
    $exitCode = 0;
    exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($target), $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Failed to create junction from '{$link}' to '{$target}'");
    }
    $createdJunctions[] = $link;
}

function removeTrackedJunction(string $link): void
{
    global $createdJunctions;
    if (file_exists($link) || is_link($link)) {
        $output = [];
        $exitCode = 0;
        exec('cmd /c rmdir ' . escapeshellarg($link), $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException("Failed to remove junction: '{$link}'");
        }
    }
    $idx = array_search($link, $createdJunctions, true);
    if ($idx !== false) {
        unset($createdJunctions[$idx]);
    }
}

function safeLexicalSubdirCleanup(string $dir): void
{
    $items = scandir($dir);
    if ($items === false) {
        throw new RuntimeException("Cannot list directory for cleanup: '{$dir}'");
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $lstat = @lstat($path);
        if ($lstat !== false && (is_link($path) || (($lstat['mode'] & 0120000) === 0120000))) {
            if (is_dir($path)) {
                $output = [];
                $exitCode = 0;
                exec('cmd /c rmdir ' . escapeshellarg($path), $output, $exitCode);
                if ($exitCode !== 0) {
                    throw new RuntimeException("Failed to remove link during cleanup: '{$path}'");
                }
            } else {
                if (!unlink($path)) {
                    throw new RuntimeException("Failed to unlink: '{$path}'");
                }
            }
        } elseif (is_dir($path)) {
            safeLexicalSubdirCleanup($path);
            if (!rmdir($path)) {
                throw new RuntimeException("Failed to rmdir: '{$path}'");
            }
        } else {
            if (!unlink($path)) {
                throw new RuntimeException("Failed to unlink: '{$path}'");
            }
        }
    }
}

function safeLexicalCleanup(string $dir, string $expectedMarker): void
{
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }
    $markerFile = $dir . DIRECTORY_SEPARATOR . '.pos_owned_marker';
    if (!file_exists($markerFile) || trim((string)@file_get_contents($markerFile)) !== $expectedMarker) {
        throw new RuntimeException("Refusing to clean up directory without valid ownership marker: '{$dir}'");
    }

    $items = scandir($dir);
    if ($items === false) {
        throw new RuntimeException("Cannot list directory for cleanup: '{$dir}'");
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $lstat = @lstat($path);
        if ($lstat !== false && (is_link($path) || (($lstat['mode'] & 0120000) === 0120000))) {
            if (is_dir($path)) {
                $output = [];
                $exitCode = 0;
                exec('cmd /c rmdir ' . escapeshellarg($path), $output, $exitCode);
                if ($exitCode !== 0) {
                    throw new RuntimeException("Failed to remove link during cleanup: '{$path}'");
                }
            } else {
                if (!unlink($path)) {
                    throw new RuntimeException("Failed to unlink: '{$path}'");
                }
            }
        } elseif (is_dir($path)) {
            safeLexicalSubdirCleanup($path);
            if (!rmdir($path)) {
                throw new RuntimeException("Failed to rmdir: '{$path}'");
            }
        } else {
            if (!unlink($path)) {
                throw new RuntimeException("Failed to unlink: '{$path}'");
            }
        }
    }
    if (!rmdir($dir)) {
        throw new RuntimeException("Failed to rmdir root: '{$dir}'");
    }
}

// -------------------------------------------------------------------------
// 1. SemVer 2.0 Vectors
// -------------------------------------------------------------------------
echo "--- Testing SemVer Vectors ---\n";
$validSemvers = [
    '0.0.4',
    '1.0.0',
    '1.2.3-alpha',
    '1.2.3-alpha.1',
    '1.2.3-0.3.7',
    '1.2.3-x.7.z.92',
    '1.2.3-alpha+001',
    '1.2.3+20130313144700',
    '1.2.3-beta+exp.sha.5114f85',
    '2.0.0-rc.1+build.123',
    '1.0.0-alpha-a.b-c-somethinglong+build.1-aef.1-its-okay',
];
foreach ($validSemvers as $v) {
    assertCondition(ProductInventoryService::isValidSemver($v), "Valid SemVer accepted: {$v}");
}

$invalidSemvers = [
    '1.0.0-01',          // leading zero in numeric prerelease identifier
    '1.0.0-alpha.01',    // leading zero in numeric prerelease segment
    '01.0.0',            // leading zero in major
    '1.01.0',            // leading zero in minor
    '1.0.01',            // leading zero in patch
    '1.0.0-',            // empty prerelease
    '1.0.0-alpha..1',    // empty segment in prerelease
    '1.0',               // missing patch
    '1',                 // missing minor and patch
    'v1.0.0',            // leading v prefix
    '1.0.0+',            // empty build metadata
    '1.0.0+build..1',    // empty segment in build metadata
    '1.0.0-alpha_beta',  // underscore not allowed in prerelease
];
foreach ($invalidSemvers as $inv) {
    assertCondition(!ProductInventoryService::isValidSemver($inv), "Invalid SemVer rejected: {$inv}");
}

// -------------------------------------------------------------------------
// 2. Typed / Case / Ancestor Path Sets
// -------------------------------------------------------------------------
echo "--- Testing validatePathSet ---\n";

// Non-string path in array
$nonStringResult = ProductInventoryService::validatePathSet(['POS Desktop.exe', 12345]);
assertCondition($nonStringResult['ok'] === false && $nonStringResult['reason_code'] === 'path_invalid', 'Non-string path rejected as path_invalid');

// Case collision check globally
$caseCollisionResult = ProductInventoryService::validatePathSet(['resources/app.asar', 'RESOURCES/app.asar']);
assertCondition($caseCollisionResult['ok'] === false && $caseCollisionResult['reason_code'] === 'case_collision', 'Case collision detected as case_collision');

// Ancestor collision check BEFORE allowlist: resources/app.asar vs resources/app.asar/subfile.js
// Must report ancestor_collision, NOT path_unauthorized!
$ancestorCollisionResult = ProductInventoryService::validatePathSet(['resources/app.asar', 'resources/app.asar/subfile.js']);
assertCondition($ancestorCollisionResult['ok'] === false && $ancestorCollisionResult['reason_code'] === 'ancestor_collision', 'Ancestor collision reports ancestor_collision before allowlist check');

// Valid path set
$validSetResult = ProductInventoryService::validatePathSet([
    'POS Desktop.exe',
    'resources/app.asar',
    'resources/app.asar.unpacked/version.json',
]);
assertCondition($validSetResult['ok'] === true, 'Valid path set accepted');

// -------------------------------------------------------------------------
// 2b. Bounded Allowlist & Negative Security Policy Vectors
// -------------------------------------------------------------------------
echo "--- Testing Bounded Allowlist & Negative Security Policy Vectors ---\n";

// Positive 1: Exact 9 Vendor Bootstrap SQL files
$vendorBootstrapSqls = [
    'resources/portable/mysql/share/fill_help_tables.sql',
    'resources/portable/mysql/share/install_spider.sql',
    'resources/portable/mysql/share/maria_add_gis_sp.sql',
    'resources/portable/mysql/share/maria_add_gis_sp_bootstrap.sql',
    'resources/portable/mysql/share/mysql_performance_tables.sql',
    'resources/portable/mysql/share/mysql_system_tables.sql',
    'resources/portable/mysql/share/mysql_system_tables_data.sql',
    'resources/portable/mysql/share/mysql_test_data_timezone.sql',
    'resources/portable/mysql/share/mysql_test_db.sql',
];

assertCondition(count($vendorBootstrapSqls) === 9, 'Exact 9 vendor bootstrap SQL scripts enumerated');
foreach ($vendorBootstrapSqls as $sql) {
    assertCondition(!ProductInventoryService::isProtectedPath($sql), "Vendor bootstrap SQL is NOT protected: {$sql}");
    assertCondition(ProductInventoryService::isVendorBootstrapSqlPath($sql), "Identified as vendor bootstrap SQL: {$sql}");
    assertCondition(ProductInventoryService::isAllowedSqlPath($sql), "Identified as allowed SQL: {$sql}");
    $res = ProductInventoryService::validateProductPath($sql);
    assertCondition($res['ok'] === true, "Vendor bootstrap SQL authorized: {$sql}");
}

// SQL Canonicality & Protection Tests: Missing prefix, nested, syntax invalid
$missingPrefixSql = 'portable/mysql/share/mysql_system_tables.sql';
assertCondition(ProductInventoryService::isProtectedPath($missingPrefixSql), 'SQL missing resources/ prefix is strictly protected');
assertCondition(!ProductInventoryService::isVendorBootstrapSqlPath($missingPrefixSql), 'SQL missing resources/ prefix is not vendor bootstrap');
assertCondition(!ProductInventoryService::isAllowedSqlPath($missingPrefixSql), 'SQL missing resources/ prefix is not allowed SQL');
$missingPrefixRes = ProductInventoryService::validateProductPath($missingPrefixSql);
assertCondition($missingPrefixRes['ok'] === false, 'SQL missing resources/ prefix rejected in validateProductPath');

$missingPrefixSchema = 'app.asar.unpacked/database/pos_schema.sql';
assertCondition(ProductInventoryService::isProtectedPath($missingPrefixSchema), 'pos_schema.sql missing resources/ prefix is strictly protected');
assertCondition(!ProductInventoryService::isAllowedSqlPath($missingPrefixSchema), 'pos_schema.sql missing resources/ prefix is not allowed SQL');
$missingPrefixSchemaRes = ProductInventoryService::validateProductPath($missingPrefixSchema);
assertCondition($missingPrefixSchemaRes['ok'] === false, 'pos_schema.sql missing resources/ prefix rejected in validateProductPath');

$nestedSql = 'resources/portable/mysql/share/nested/dir/mysql_system_tables.sql';
assertCondition(ProductInventoryService::isProtectedPath($nestedSql), 'Nested SQL in share is strictly protected');
assertCondition(!ProductInventoryService::isVendorBootstrapSqlPath($nestedSql), 'Nested SQL is not vendor bootstrap SQL');
$nestedSqlRes = ProductInventoryService::validateProductPath($nestedSql);
assertCondition($nestedSqlRes['ok'] === false && $nestedSqlRes['reason_code'] === 'path_protected', 'Nested SQL rejected as path_protected');

$syntaxDotDotSql = ProductInventoryService::validateProductPath('resources/portable/mysql/share/../mysql_system_tables.sql');
assertCondition($syntaxDotDotSql['ok'] === false && $syntaxDotDotSql['reason_code'] === 'path_invalid', 'Traversal SQL rejected as path_invalid');

$syntaxStreamSql = ProductInventoryService::validateProductPath('resources/portable/mysql/share/mysql_system_tables.sql:stream');
assertCondition($syntaxStreamSql['ok'] === false && $syntaxStreamSql['reason_code'] === 'path_invalid', 'ADS stream SQL rejected as path_invalid');

// Positive 2: Root and Portable Manifests
$manifestAndRootFiles = [
    'resources/uninstall-data.ps1',
    'resources/portable/runtime-manifest.json',
    'resources/portable/printing-manifest.json',
];
foreach ($manifestAndRootFiles as $p) {
    assertCondition(!ProductInventoryService::isProtectedPath($p), "Manifest/root file is NOT protected: {$p}");
    $res = ProductInventoryService::validateProductPath($p);
    assertCondition($res['ok'] === true, "Manifest/root file authorized: {$p}");
}

// Positive 3: Portable PHP representatives
$phpPositives = [
    'resources/portable/php/php.exe',
    'resources/portable/php/php-cgi.exe',
    'resources/portable/php/php-win.exe',
    'resources/portable/php/phpdbg.exe',
    'resources/portable/php/deplister.exe',
    'resources/portable/php/php.ini',
    'resources/portable/php/php8.dll',
    'resources/portable/php/libsqlite3.dll',
    'resources/portable/php/php8embed.lib',
    'resources/portable/php/README.md',
    'resources/portable/php/license.txt',
    'resources/portable/php/phar.phar.bat',
    'resources/portable/php/pharcommand.phar',
    'resources/portable/php/dev/php8.lib',
    'resources/portable/php/extras/ssl/legacy.dll',
    'resources/portable/php/extras/ssl/openssl.cnf',
    'resources/portable/php/lib/enchant/libenchant2_hunspell.dll',
    'resources/portable/php/ext/php_curl.dll',
    'resources/portable/php/ext/php_openssl.dll',
];
foreach ($phpPositives as $p) {
    assertCondition(!ProductInventoryService::isProtectedPath($p), "PHP representative is NOT protected: {$p}");
    $res = ProductInventoryService::validateProductPath($p);
    assertCondition($res['ok'] === true, "PHP representative authorized: {$p}");
}

// Positive 4: Portable Java representatives (including exact 8 public vendor files from measured log)
$javaPositives = [
    'resources/portable/java/bin/java.exe',
    'resources/portable/java/bin/javaw.exe',
    'resources/portable/java/bin/jcmd.exe',
    'resources/portable/java/bin/jabswitch.exe',
    'resources/portable/java/bin/java.dll',
    'resources/portable/java/bin/server/jvm.dll',
    'resources/portable/java/release',
    'resources/portable/java/conf/security/java.policy',
    'resources/portable/java/conf/security/java.security',
    'resources/portable/java/conf/security/policy/README.txt',
    'resources/portable/java/conf/security/policy/unlimited/default_US_export.policy',
    'resources/portable/java/lib/jrt-fs.jar',
    'resources/portable/java/lib/jvm.cfg',
    'resources/portable/java/lib/tzdb.dat',
    'resources/portable/java/lib/modules',
    'resources/portable/java/lib/security/default.policy',
    'resources/portable/java/lib/jfr/default.jfc',
    'resources/portable/java/lib/fontconfig.bfc',
    'resources/portable/java/lib/jawt.lib',
    'resources/portable/java/lib/jvm.lib',
    'resources/portable/java/lib/security/blocked.certs',
    'resources/portable/java/lib/security/cacerts',
    'resources/portable/java/lib/client/Xusage.txt',
    'resources/portable/java/lib/server/Xusage.txt',
];
foreach ($javaPositives as $p) {
    assertCondition(!ProductInventoryService::isProtectedPath($p), "Java representative is NOT protected: {$p}");
    $res = ProductInventoryService::validateProductPath($p);
    assertCondition($res['ok'] === true, "Java representative authorized: {$p}");
}

// Positive 5: Portable QZ-Tray representatives (only DLLs in libs/)
$qzPositives = [
    'resources/portable/qz-tray/qz-tray.jar',
    'resources/portable/qz-tray/LICENSE.txt',
    'resources/portable/qz-tray/libs/api-ms-win-core-console-l1-1-0.dll',
];
foreach ($qzPositives as $p) {
    assertCondition(!ProductInventoryService::isProtectedPath($p), "QZ representative is NOT protected: {$p}");
    $res = ProductInventoryService::validateProductPath($p);
    assertCondition($res['ok'] === true, "QZ representative authorized: {$p}");
}

// Positive 6: Portable MySQL representatives (including exact 5 measured perl scripts)
$mysqlPositives = [
    'resources/portable/mysql/my.ini',
    'resources/portable/mysql/README.md',
    'resources/portable/mysql/bin/mysqld.exe',
    'resources/portable/mysql/bin/mysql.exe',
    'resources/portable/mysql/bin/mysqladmin.exe',
    'resources/portable/mysql/bin/mariabackup.exe',
    'resources/portable/mysql/bin/aria_chk.exe',
    'resources/portable/mysql/bin/replace.exe',
    'resources/portable/mysql/bin/sst_dump.exe',
    'resources/portable/mysql/bin/concrt140.dll',
    'resources/portable/mysql/bin/mysql_config.pl',
    'resources/portable/mysql/bin/mysql_convert_table_format.pl',
    'resources/portable/mysql/bin/mysqld_multi.pl',
    'resources/portable/mysql/bin/mysqldumpslow.pl',
    'resources/portable/mysql/bin/mysqlhotcopy.pl',
    'resources/portable/mysql/bin/myrocks_hotbackup',
    'resources/portable/mysql/bin/mysqld.pdb',
    'resources/portable/mysql/include/mysql.h',
    'resources/portable/mysql/include/mysql/server/private/item.h',
    'resources/portable/mysql/lib/libmariadb.dll',
    'resources/portable/mysql/lib/plugin/ha_rocksdb.dll',
    'resources/portable/mysql/lib/plugin/ha_connect.lib',
    'resources/portable/mysql/share/JavaWrappers.jar',
    'resources/portable/mysql/share/charsets/Index.xml',
    'resources/portable/mysql/share/english/errmsg.sys',
];
foreach ($mysqlPositives as $p) {
    assertCondition(!ProductInventoryService::isProtectedPath($p), "MySQL representative is NOT protected: {$p}");
    $res = ProductInventoryService::validateProductPath($p);
    assertCondition($res['ok'] === true, "MySQL representative authorized: {$p}");
}

// Combined Positive Path Set Check
$allPositives = array_merge(
    ['POS Desktop.exe', 'resources/app.asar', 'resources/app.asar.unpacked/version.json'],
    $vendorBootstrapSqls,
    $manifestAndRootFiles,
    $phpPositives,
    $javaPositives,
    $qzPositives,
    $mysqlPositives
);
$posSetCheck = ProductInventoryService::validatePathSet($allPositives);
assertCondition($posSetCheck['ok'] === true, 'All positive representative paths validate cleanly together in validatePathSet');

// Negative 1: Root wrong uninstall path (must NOT allow uninstall-data.ps1 at root)
$badRootUninstall = ProductInventoryService::validateProductPath('uninstall-data.ps1');
assertCondition($badRootUninstall['ok'] === false && $badRootUninstall['reason_code'] === 'path_unauthorized', 'Root uninstall-data.ps1 rejected as path_unauthorized');

// Negative 2: Unknown executables per vendor folder
$unknownExes = [
    'resources/portable/php/unknown.exe',
    'resources/portable/php/evil.exe',
    'resources/portable/java/bin/unknown.exe',
    'resources/portable/java/bin/javac.exe',
    'resources/portable/qz-tray/qz-tray.exe',
    'resources/portable/qz-tray/evil.exe',
    'resources/portable/qz-tray/libs/evil.exe',
    'resources/portable/mysql/bin/unknown.exe',
    'resources/portable/mysql/bin/evil.exe',
];
foreach ($unknownExes as $exe) {
    $res = ProductInventoryService::validateProductPath($exe);
    assertCondition($res['ok'] === false && $res['reason_code'] === 'path_unauthorized', "Unknown executable rejected as path_unauthorized: {$exe}");
}

// Negative 3: Misplaced executables in share / lib / include / ext
$misplacedExes = [
    'resources/portable/mysql/share/evil.exe',
    'resources/portable/mysql/lib/evil.exe',
    'resources/portable/mysql/include/evil.exe',
    'resources/portable/java/lib/evil.exe',
    'resources/portable/php/ext/evil.exe',
];
foreach ($misplacedExes as $exe) {
    $res = ProductInventoryService::validateProductPath($exe);
    assertCondition($res['ok'] === false && $res['reason_code'] === 'path_unauthorized', "Misplaced executable rejected as path_unauthorized: {$exe}");
}

// Negative 4: Arbitrary PHP phar and bat scripts
$arbitraryPhpScripts = [
    'resources/portable/php/evil.phar',
    'resources/portable/php/composer.phar',
    'resources/portable/php/run.bat',
    'resources/portable/php/ext/evil.bat',
];
foreach ($arbitraryPhpScripts as $script) {
    $res = ProductInventoryService::validateProductPath($script);
    assertCondition($res['ok'] === false && $res['reason_code'] === 'path_unauthorized', "Arbitrary PHP script rejected as path_unauthorized: {$script}");
}

// Negative 5: Arbitrary Java jar, conf/properties, and policy data
$arbitraryJavaFiles = [
    'resources/portable/java/lib/evil.jar',
    'resources/portable/java/bin/evil.jar',
    'resources/portable/java/conf/evil.conf',
    'resources/portable/java/conf/net.properties',
    'resources/portable/java/conf/security/evil.policy',
];
foreach ($arbitraryJavaFiles as $f) {
    $res = ProductInventoryService::validateProductPath($f);
    assertCondition($res['ok'] === false && $res['reason_code'] === 'path_unauthorized', "Arbitrary Java file rejected as path_unauthorized: {$f}");
}

// Negative 6: QZ certificate, private key, env, log protections, and QZ libs JAR rejection
$qzProtectedFiles = [
    'resources/portable/qz-tray/override.crt' => 'path_unauthorized',
    'resources/portable/qz-tray/device-cert.pem' => 'path_protected',
    'resources/portable/qz-tray/digital-certificate.txt' => 'path_protected',
    'resources/portable/qz-tray/private.key' => 'path_protected',
    'resources/portable/qz-tray/.env' => 'path_protected',
    'resources/portable/qz-tray/tray.log' => 'path_protected',
];
foreach ($qzProtectedFiles as $f => $expectedReason) {
    $res = ProductInventoryService::validateProductPath($f);
    assertCondition($res['ok'] === false && $res['reason_code'] === $expectedReason, "QZ sensitive file rejected with {$expectedReason}: {$f}");
}
$badQzJars = [
    'resources/portable/qz-tray/tray-override.jar',
    'resources/portable/qz-tray/libs/free-tts.jar',
    'resources/portable/qz-tray/libs/evil.jar',
];
foreach ($badQzJars as $bj) {
    $res = ProductInventoryService::validateProductPath($bj);
    assertCondition($res['ok'] === false && $res['reason_code'] === 'path_unauthorized', "Unauthorized QZ jar rejected as path_unauthorized: {$bj}");
}

// Negative 7: Customer SQL in vendor share and root
$customerSqls = [
    'resources/portable/mysql/share/customer_data.sql',
    'resources/portable/mysql/share/my_backup.sql',
    'pos.sql',
    'resources/portable/mysql/data/ibdata1.sql',
];
foreach ($customerSqls as $sql) {
    assertCondition(ProductInventoryService::isProtectedPath($sql), "Customer SQL is strictly protected: {$sql}");
    assertCondition(!ProductInventoryService::isVendorBootstrapSqlPath($sql), "Customer SQL is NOT vendor bootstrap SQL: {$sql}");
    $res = ProductInventoryService::validateProductPath($sql);
    assertCondition($res['ok'] === false && $res['reason_code'] === 'path_protected', "Customer SQL rejected as path_protected: {$sql}");
}

// Negative 8: Uppercase SQL variants
$uppercaseSqls = [
    'resources/portable/mysql/share/FILL_HELP_TABLES.SQL',
    'resources/portable/mysql/share/fill_help_tables.SQL',
    'resources/portable/mysql/share/INSTALL_SPIDER.SQL',
    'resources/portable/mysql/share/MYSQL_SYSTEM_TABLES.SQL',
];
foreach ($uppercaseSqls as $sql) {
    assertCondition(ProductInventoryService::isProtectedPath($sql), "Uppercase SQL variant is strictly protected: {$sql}");
    $res = ProductInventoryService::validateProductPath($sql);
    assertCondition($res['ok'] === false && $res['reason_code'] === 'path_protected', "Uppercase SQL variant rejected as path_protected: {$sql}");
}

// Negative 9: Invented MySQL perl scripts
$inventedMysqlPerl = [
    'resources/portable/mysql/bin/mytop.pl',
    'resources/portable/mysql/bin/wsrep_sst_common.pl',
];
foreach ($inventedMysqlPerl as $pl) {
    $res = ProductInventoryService::validateProductPath($pl);
    assertCondition($res['ok'] === false && $res['reason_code'] === 'path_unauthorized', "Invented MySQL perl script rejected as path_unauthorized: {$pl}");
}

// Negative 10: Path Set Case and Ancestor Collisions with new paths
$caseCollisionSet = ProductInventoryService::validatePathSet([
    'resources/portable/mysql/share/fill_help_tables.sql',
    'resources/portable/mysql/share/FILL_HELP_TABLES.SQL',
]);
assertCondition($caseCollisionSet['ok'] === false && $caseCollisionSet['reason_code'] === 'case_collision', 'Vendor SQL case collision detected as case_collision');

$ancestorCollisionSet = ProductInventoryService::validatePathSet([
    'resources/portable/php/ext',
    'resources/portable/php/ext/php_curl.dll',
]);
assertCondition($ancestorCollisionSet['ok'] === false && $ancestorCollisionSet['reason_code'] === 'ancestor_collision', 'PHP ext ancestor collision detected as ancestor_collision');

// -------------------------------------------------------------------------
// 3. Lexical Confinement & Syntax Vectors
// -------------------------------------------------------------------------
echo "--- Testing Lexical Confinement & Syntax ---\n";

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('\\\\server\\share\\file.txt');
}, 'UNC or device namespace', 'UNC path rejected');

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('\\\\?\\C:\\app\\file.txt');
}, 'UNC or device namespace', 'Device path \\\\?\\ rejected');

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('C:app\\file.txt');
}, 'must be an absolute Windows drive path', 'Drive-relative path C:app rejected');

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('/app/file.txt');
}, 'must be an absolute Windows drive path', 'Unix absolute path rejected');

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('C:\\app\\file.txt:stream');
}, 'NTFS alternate data streams', 'NTFS ADS colon rejected');

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('C:\\app\\..\\outside');
}, 'traversal segment', 'Traversal in absolute path rejected');

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('C:\\app \\file.txt');
}, 'trailing dot/space', 'Trailing space in segment rejected');

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('C:\\app.\\file.txt');
}, 'trailing dot/space', 'Trailing dot in segment rejected');

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('C:\\app\\nul');
}, 'reserved Windows device name', 'DOS device NUL rejected');

assertThrows(function () {
    ProductInventoryService::resolveAbsolutePath('C:\\app\\PROGRA~1');
}, '8.3 alias rejected', '8.3 alias pattern rejected');

// -------------------------------------------------------------------------
// 4. Junctions, Confinement, Ancestor Chains, and Fixtures on Windows
// -------------------------------------------------------------------------
echo "--- Testing Junctions, Confinement, and Ancestor Chains ---\n";

// Prefer RUNNER_TEMP to avoid 8.3 short name aliases in default TEMP on Windows runners
$runnerTemp = getenv('RUNNER_TEMP');
$baseTempDir = ($runnerTemp !== false && $runnerTemp !== '' && is_dir($runnerTemp)) ? $runnerTemp : sys_get_temp_dir();
$resolvedBase = realpath($baseTempDir);
if ($resolvedBase !== false) {
    $baseTempDir = $resolvedBase;
}

$tempBase = $baseTempDir . DIRECTORY_SEPARATOR . 'pos_owned_test_' . bin2hex(random_bytes(8));
$victimDir = $baseTempDir . DIRECTORY_SEPARATOR . 'pos_victim_root_' . bin2hex(random_bytes(8));

try {
    mkdir($tempBase, 0777, true);
    mkdir($victimDir, 0777, true);
    file_put_contents($tempBase . DIRECTORY_SEPARATOR . '.pos_owned_marker', 'OWNED_TEST_DIR');
    file_put_contents($victimDir . DIRECTORY_SEPARATOR . '.pos_owned_marker', 'OWNED_VICTIM_DIR');

    $victimSecretFile = $victimDir . DIRECTORY_SEPARATOR . 'secret.txt';
    $victimContent = 'CONFIDENTIAL_VICTIM_DATA_' . bin2hex(random_bytes(8));
    file_put_contents($victimSecretFile, $victimContent);

    $productRoot = $tempBase . DIRECTORY_SEPARATOR . 'product_install';
    mkdir($productRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'app.asar.unpacked', 0777, true);

    file_put_contents($productRoot . DIRECTORY_SEPARATOR . 'POS Desktop.exe', 'FAKE_EXE_BINARY_DATA');
    file_put_contents($productRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'app.asar', 'FAKE_ASAR_ARCHIVE_DATA');
    file_put_contents(
        $productRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'app.asar.unpacked' . DIRECTORY_SEPARATOR . 'version.json',
        json_encode(['version' => '1.0.0'], JSON_THROW_ON_ERROR)
    );

    $isWindows = PHP_OS_FAMILY === 'Windows';
    assertCondition($isWindows, 'Executing on Windows environment for junction tests');

    // 4a. Direct Junction test inside product root
    $directJunction = $productRoot . DIRECTORY_SEPARATOR . 'junction_to_victim';
    createTrackedJunction($directJunction, $victimDir);
    assertCondition(is_dir($directJunction), 'Created direct directory junction without elevation');

    assertThrows(function () use ($productRoot) {
        ProductInventoryService::resolveConfinedPath($productRoot, 'junction_to_victim/secret.txt', true);
    }, '', 'Direct junction to outside victim rejected by resolveConfinedPath');

    // Check outside victim remains intact
    assertCondition(file_get_contents($victimSecretFile) === $victimContent, 'Outside victim file remains intact');

    // 4b. Intermediate Junction test (ancestor junction inside directory tree)
    $nestedParent = $productRoot . DIRECTORY_SEPARATOR . 'nested_parent';
    mkdir($nestedParent, 0777, true);
    $interJunction = $nestedParent . DIRECTORY_SEPARATOR . 'mid_junction';
    createTrackedJunction($interJunction, $victimDir);

    assertThrows(function () use ($productRoot) {
        ProductInventoryService::resolveConfinedPath($productRoot, 'nested_parent/mid_junction/secret.txt', true);
    }, '', 'Intermediate junction rejected by resolveConfinedPath');

    assertCondition(file_get_contents($victimSecretFile) === $victimContent, 'Outside victim file remains intact after intermediate check');

    // 4c. Root itself as Junction
    $rootAsJunction = $tempBase . DIRECTORY_SEPARATOR . 'root_as_junction';
    createTrackedJunction($rootAsJunction, $productRoot);

    assertThrows(function () use ($rootAsJunction) {
        ProductInventoryService::resolveConfinedPath($rootAsJunction, 'POS Desktop.exe', true);
    }, '', 'Root directory as junction rejected by resolveConfinedPath');

    assertThrows(function () use ($rootAsJunction) {
        ProductInventoryService::assertNoReparse($rootAsJunction);
    }, '', 'Root directory as junction rejected by assertNoReparse');

    // 4d. Internal Junction (junction pointing within root boundary)
    // Policy explicitly rejects internal junctions as well
    $internalTarget = $productRoot . DIRECTORY_SEPARATOR . 'internal_target';
    mkdir($internalTarget, 0777, true);
    file_put_contents($internalTarget . DIRECTORY_SEPARATOR . 'dummy.txt', 'internal');
    $internalJunction = $productRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'internal_link';
    createTrackedJunction($internalJunction, $internalTarget);

    assertThrows(function () use ($productRoot) {
        ProductInventoryService::resolveConfinedPath($productRoot, 'resources/internal_link/dummy.txt', true);
    }, '', 'Internal junction rejected by resolveConfinedPath');

    // 4e. Missing path below existing file vs missing path in readable directory
    // Missing segment below existing file MUST FAIL (non-terminal segment is not a directory)
    assertThrows(function () use ($productRoot) {
        ProductInventoryService::resolveConfinedPath($productRoot, 'resources/app.asar/missing_child.txt', false);
    }, 'not a directory', 'Missing path below existing file rejected with requireExists=false');

    assertThrows(function () use ($productRoot) {
        ProductInventoryService::resolveAbsolutePath($productRoot . '\\resources\\app.asar\\missing_child.txt', false);
    }, 'not a directory', 'Missing path below existing file rejected in resolveAbsolutePath with requireExists=false');

    // Missing path in truly readable directory is allowed with requireExists=false
    $missingAllowed = ProductInventoryService::resolveConfinedPath($productRoot, 'resources/app.asar.unpacked/missing_terminal.json', false);
    assertCondition(is_string($missingAllowed) && str_ends_with(str_replace('/', '\\', $missingAllowed), 'resources\\app.asar.unpacked\\missing_terminal.json'), 'Missing terminal segment with requireExists=false resolves canonical path when parent is readable');

    // Missing terminal segment with requireExists = true must throw
    assertThrows(function () use ($productRoot) {
        ProductInventoryService::resolveConfinedPath($productRoot, 'resources/app.asar.unpacked/missing_terminal.json', true);
    }, 'does not exist', 'Missing target with requireExists=true throws');

    // Escaped missing target (even with requireExists = false) must throw
    assertThrows(function () use ($productRoot) {
        ProductInventoryService::resolveConfinedPath($productRoot, 'junction_to_victim/nonexistent.txt', false);
    }, '', 'Escaped missing target via junction rejected even with requireExists=false');

    // 4f. Test DACL denial in GHA if icacls is functional
    $daclDir = $tempBase . DIRECTORY_SEPARATOR . 'dacl_test_dir';
    mkdir($daclDir, 0777, true);
    $daclExercised = false;
    $daclOut = [];
    $daclExit = 0;
    exec('icacls ' . escapeshellarg($daclDir) . ' /deny Everyone:(OI)(CI)(R)', $daclOut, $daclExit);
    if ($daclExit === 0) {
        try {
            assertThrows(function () use ($daclDir) {
                ProductInventoryService::resolveAbsolutePath($daclDir . '\\missing.txt', false);
            }, 'Cannot inspect parent directory', 'DACL unreadable directory throws and prevents treating path as missing');
            $daclExercised = true;
        } finally {
            exec('icacls ' . escapeshellarg($daclDir) . ' /remove:d Everyone');
        }
    }
    if (!$daclExercised) {
        echo "[NOTE] Explicit DACL denial test not exercised in this environment.\n";
    }

    // -------------------------------------------------------------------------
    // 5. Protected Sentinels, Junction Rejection in buildInventory, and Preservation
    // -------------------------------------------------------------------------
    echo "--- Testing Protected Sentinels & BuildInventory ---\n";

    // Clean up test junctions created in section 4 before testing buildInventory
    removeTrackedJunction($directJunction);
    removeTrackedJunction($interJunction);
    removeTrackedJunction($rootAsJunction);
    removeTrackedJunction($internalJunction);
    @unlink($internalTarget . DIRECTORY_SEPARATOR . 'dummy.txt');
    @rmdir($internalTarget);
    @rmdir($nestedParent);

    // 5a. Test buildInventory rejection of intermediate junction in unprotected tree
    $unprotectedJunction = $productRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'unprotected_junction';
    createTrackedJunction($unprotectedJunction, $victimDir);

    $service = new ProductInventoryService();
    assertThrows(function () use ($service, $productRoot) {
        $service->buildInventory($productRoot, '1.0.0');
    }, '', 'buildInventory rejects intermediate junction in unprotected tree');

    // Victim intact after rejected buildInventory
    assertCondition(file_get_contents($victimSecretFile) === $victimContent, 'Victim file remains intact after buildInventory junction rejection');
    removeTrackedJunction($unprotectedJunction);

    // 5b. Test protected storage/cache JUNCTION to outside sentinel
    // Inventory must skip branch without walking, error, read, or mutation
    $protectedJunction = $productRoot . DIRECTORY_SEPARATOR . 'storage';
    createTrackedJunction($protectedJunction, $victimDir);

    // Create other protected sentinels inside productRoot:
    // .env
    $envFile = $productRoot . DIRECTORY_SEPARATOR . '.env';
    $envContent = 'APP_KEY=base64:secure_env_sentinel_12345';
    file_put_contents($envFile, $envContent);

    // logs/ directory
    $logsDir = $productRoot . DIRECTORY_SEPARATOR . 'logs';
    mkdir($logsDir, 0777, true);
    $logFile = $logsDir . DIRECTORY_SEPARATOR . 'error.log';
    $logContent = 'ERROR_LOG_BYTES';
    file_put_contents($logFile, $logContent);

    // mysql/data directory
    $mysqlDataDir = $productRoot . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'data';
    mkdir($mysqlDataDir, 0777, true);
    file_put_contents($mysqlDataDir . DIRECTORY_SEPARATOR . 'ibdata1', 'MYSQL_INNODB_BYTES');

    // Private key
    $certsDir = $productRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'app.asar.unpacked' . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'certs';
    mkdir($certsDir, 0777, true);
    $privKeyFile = $certsDir . DIRECTORY_SEPARATOR . 'update_private_key.pem';
    $privKeyContent = '-----BEGIN RSA PRIVATE KEY-----SENTINEL';
    file_put_contents($privKeyFile, $privKeyContent);

    // Digital certificate
    $certFile = $productRoot . DIRECTORY_SEPARATOR . 'digital-certificate.txt';
    $certContent = 'DEVICE_CERTIFICATE_CONTENT';
    file_put_contents($certFile, $certContent);

    // Run buildInventory: must SUCCEED, skipping protected storage junction without error
    $inventory = $service->buildInventory($productRoot, '1.0.0');

    // Validate inventory format and exact 7 keys
    assertCondition($inventory['format'] === 1, 'Inventory format is 1');
    assertCondition($inventory['version'] === '1.0.0', 'Inventory app version is 1.0.0');
    assertCondition($inventory['native_version'] === '1.0.0', 'Inventory native version is 1.0.0');

    // Validate only valid product files are included in files
    $files = $inventory['files'];
    assertCondition(isset($files['POS Desktop.exe']), 'POS Desktop.exe present in inventory');
    assertCondition(isset($files['resources/app.asar']), 'resources/app.asar present in inventory');
    assertCondition(isset($files['resources/app.asar.unpacked/version.json']), 'version.json present in inventory');

    // Crucial: protected sentinels and protected junction must NOT be in files
    assertCondition(!isset($files['storage']), 'storage branch not included in inventory');
    assertCondition(!isset($files['storage/secret.txt']), 'storage/secret.txt not included in inventory');
    assertCondition(!isset($files['.env']), '.env not included in inventory');
    assertCondition(!isset($files['logs/error.log']), 'logs/error.log not included in inventory');
    assertCondition(!isset($files['mysql/data/ibdata1']), 'mysql/data/ibdata1 not included in inventory');
    assertCondition(!isset($files['digital-certificate.txt']), 'digital-certificate.txt not included in inventory');

    // Verify victim content intact (not walked, read, or mutated)
    assertCondition(file_get_contents($victimSecretFile) === $victimContent, 'Outside victim file intact after protected junction skip in buildInventory');

    // Verify sentinels are intact and unchanged on disk
    assertCondition(file_get_contents($envFile) === $envContent, '.env content intact and unchanged');
    assertCondition(file_get_contents($logFile) === $logContent, 'logs/error.log intact and unchanged');
    assertCondition(file_get_contents($privKeyFile) === $privKeyContent, 'private key intact and unchanged');
    assertCondition(file_get_contents($certFile) === $certContent, 'digital-certificate.txt intact and unchanged');

    // Clean up protected junction
    removeTrackedJunction($protectedJunction);

    // -------------------------------------------------------------------------
    // 6. ValidateInventory & Exact 7 Keys
    // -------------------------------------------------------------------------
    echo "--- Testing ValidateInventory & Exact 7 Keys ---\n";
    $val = $service->validateInventory($inventory, '1.0.0', '1.0.0');
    assertCondition($val['ok'] === true, 'Built inventory passes validateInventory');

    // Extra top-level key rejected
    $badTopKeyInv = $inventory;
    $badTopKeyInv['arbitrary_command'] = 'whoami';
    $valBadKey = $service->validateInventory($badTopKeyInv);
    assertCondition($valBadKey['ok'] === false && $valBadKey['reason_code'] === 'inventory_keys_invalid', 'Inventory extra top-level key rejected as inventory_keys_invalid');

    // Tamper native shell file in files map: must report native_shell_file_mismatch
    $badMirrorInv = $inventory;
    $badMirrorInv['files']['POS Desktop.exe']['size'] = 999999;
    $valBadMirror = $service->validateInventory($badMirrorInv);
    assertCondition($valBadMirror['ok'] === false && $valBadMirror['reason_code'] === 'native_shell_file_mismatch', 'Mirrored shell file size mismatch triggers native_shell_file_mismatch');

    // Extra key in non-shell file entry: must report file_invalid
    $badEntryInv = $inventory;
    $badEntryInv['files']['resources/app.asar.unpacked/version.json']['extra_flag'] = true;
    $valBadEntry = $service->validateInventory($badEntryInv);
    assertCondition($valBadEntry['ok'] === false && $valBadEntry['reason_code'] === 'file_invalid', 'Non-shell file entry extra key rejected as file_invalid');

    // Non-string path key in files rejected
    $badPathKeyInv = $inventory;
    $badPathKeyInv['files'][123] = ['sha256' => str_repeat('a', 64), 'size' => 10];
    $valBadPath = $service->validateInventory($badPathKeyInv);
    assertCondition($valBadPath['ok'] === false, 'Non-string path key rejected');

} finally {
    // Lexical cleanup: remove remaining tracked junctions first, then clean roots
    echo "--- Lexical Cleanup ---\n";
    foreach (array_reverse($createdJunctions) as $j) {
        removeTrackedJunction($j);
    }
    safeLexicalCleanup($tempBase, 'OWNED_TEST_DIR');
    assertCondition(!file_exists($tempBase), 'Temp base directory cleaned up');

    // Verify victim content intact AFTER tempBase cleanup BEFORE victimDir cleanup
    assertCondition(file_get_contents($victimSecretFile) === $victimContent, 'Victim file verified intact after tempBase cleanup');

    safeLexicalCleanup($victimDir, 'OWNED_VICTIM_DIR');
    assertCondition(!file_exists($victimDir), 'Victim directory cleaned up');
}

echo "All {$testsPassed}/{$testsRun} verification tests passed successfully.\n";
