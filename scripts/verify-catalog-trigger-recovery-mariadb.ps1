# scripts/verify-catalog-trigger-recovery-mariadb.ps1
#
# Isolated MariaDB Orchestrator for Catalog Trigger Recovery:
# 1. Bootstraps an ephemeral datadir in a unique temp directory using portable mysql_install_db.
# 2. Allocates a dynamic loopback port and starts mysqld with explicit loopback/skip-name-resolve/pid-file.
# 3. Verifies server @@datadir matches lab temp directory and PID belongs to this tracked process.
# 4. Prohibits connection to 3306/3307 or reading customer credentials/config.
# 5. Configures ephemeral database, DML-only runtime account (pos_app), and privileged migration account (pos_migration).
# 6. Executes reproduction of Error 1449 and Error 1142, followed by privileged reconciliation and runtime verification.
# 7. Guaranteed teardown in finally block: stops owned PID, verifies exit, and safely deletes temp datadir.

$ErrorActionPreference = "Stop"

Write-Host "====================================================================" -ForegroundColor Cyan
Write-Host "ISOLATED MARIADB CATALOG TRIGGER RECOVERY ORCHESTRATOR" -ForegroundColor Cyan
Write-Host "====================================================================" -ForegroundColor Cyan

# Verify portable binaries exist
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Split-Path -Parent $scriptDir
$mysqlInstallDb = Join-Path $repoRoot "portable\mysql\bin\mysql_install_db.exe"
$mysqld = Join-Path $repoRoot "portable\mysql\bin\mysqld.exe"
$php = Join-Path $repoRoot "portable\php\php.exe"

if (-not (Test-Path $mysqlInstallDb)) {
    Write-Error "FAIL: Required binary not found: $mysqlInstallDb"
    exit 1
}
if (-not (Test-Path $mysqld)) {
    Write-Error "FAIL: Required binary not found: $mysqld"
    exit 1
}
if (-not (Test-Path $php)) {
    Write-Error "FAIL: Required binary not found: $php"
    exit 1
}

$labBaseName = "pos_mariadb_lab " + [Guid]::NewGuid().ToString("N")
$tempLabDir = Join-Path $env:TEMP $labBaseName
$expectedCreatedLabPath = [System.IO.Path]::GetFullPath($tempLabDir)
New-Item -ItemType Directory -Path $tempLabDir | Out-Null
$logFile = Join-Path $tempLabDir "mysqld_lab.log"
$pidFile = Join-Path $tempLabDir "mysqld_lab.pid"
$serverProc = $null

try {
    Write-Host "[1/6] Bootstrapping ephemeral MariaDB datadir at: $tempLabDir" -ForegroundColor Yellow
    # Native direct invocation of mysql_install_db.exe.
    # Note: On Windows, mysql_install_db does not accept '--no-defaults' directly (exits with code 2),
    # but internally invokes mysqld --bootstrap. We isolate the datadir by pointing to the ephemeral path.
    & $mysqlInstallDb "--datadir=$tempLabDir"
    if ($LASTEXITCODE -ne 0) {
        Write-Error "FAIL: MariaDB datadir initialization failed with exit code $LASTEXITCODE."
        exit 1
    }

    # Allocate ephemeral loopback port and assert non-3306/3307
    $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    $listener.Start()
    $labPort = ($listener.LocalEndpoint).Port
    $listener.Stop()

    if ($labPort -eq 3306 -or $labPort -eq 3307 -or $labPort -le 1024) {
        Write-Error "FAIL: Ephemeral port allocation yielded reserved/privileged port: $labPort"
        exit 1
    }

    Write-Host "[2/6] Starting ephemeral mysqld process on 127.0.0.1:$labPort..." -ForegroundColor Yellow
    # mysqld startup passes --no-defaults as the very first argument, with correctly quoted paths for datadir, pid-file, log-error
    $serverProc = Start-Process -FilePath $mysqld `
        -ArgumentList "--no-defaults", "--skip-name-resolve", "--standalone", "--console", `
                      "--port=$labPort", "--bind-address=127.0.0.1", "--skip-networking=0", `
                      "--datadir=`"$tempLabDir`"", "--pid-file=`"$pidFile`"", "--log-error=`"$logFile`"" `
        -PassThru

    Write-Host "Tracked MariaDB process PID: $($serverProc.Id)" -ForegroundColor DarkGray

    # Wait for server readiness (TCP connect + PID file existence + process alive)
    $serverReady = $false
    for ($i = 0; $i -lt 40; $i++) {
        Start-Sleep -Milliseconds 500
        if ($serverProc.HasExited) {
            if (Test-Path $logFile) {
                Write-Host "Server log tail on premature exit:" -ForegroundColor Red
                Get-Content $logFile -Tail 30
            }
            Write-Error "FAIL: MariaDB process $($serverProc.Id) exited prematurely with exit code $($serverProc.ExitCode)!"
            exit 1
        }
        if (Test-Path $pidFile) {
            try {
                $tcp = [System.Net.Sockets.TcpClient]::new()
                $asyncResult = $tcp.BeginConnect("127.0.0.1", $labPort, $null, $null)
                if ($asyncResult.AsyncWaitHandle.WaitOne(500)) {
                    $tcp.EndConnect($asyncResult)
                    $tcp.Close()
                    $serverReady = $true
                    break
                }
                $tcp.Close()
            } catch {}
        }
    }

    if (-not $serverReady) {
        Write-Error "FAIL: Ephemeral MariaDB did not become ready on port $labPort within 20 seconds."
        if (Test-Path $logFile) {
            Write-Host "Server log tail:" -ForegroundColor Red
            Get-Content $logFile -Tail 30
        }
        exit 1
    }

    Write-Host "[3/6] Verifying server identity and process ownership (@@datadir, @@pid_file, and PID)..." -ForegroundColor Yellow

    # Verify PID file matches Start-Process tracked PID
    if (-not (Test-Path $pidFile)) {
        Write-Error "SAFETY FAILURE: PID file was not created by mysqld process!"
        exit 1
    }
    $filePidContent = (Get-Content $pidFile -Raw).Trim()
    $filePid = [int]$filePidContent
    if ($filePid -ne $serverProc.Id) {
        Write-Error "SAFETY FAILURE: PID file content ($filePid) does not match tracked process PID ($($serverProc.Id))!"
        exit 1
    }

    # Verify process ownership
    $ownedProc = Get-Process -Id $serverProc.Id -ErrorAction Stop
    if ($ownedProc.ProcessName -inotlike "*mysqld*") {
        Write-Error "SAFETY FAILURE: Tracked process ($($serverProc.Id)) is not mysqld ($($ownedProc.ProcessName))!"
        exit 1
    }

    $marker = [Guid]::NewGuid().ToString("N")
    $manifestFile = Join-Path $tempLabDir "fixture_manifest.json"
    $manifest = @{
        datadir = $tempLabDir
        port = $labPort
        pid = $serverProc.Id
        pidFile = $pidFile
        db = "test_pos_recovery"
        migUser = "pos_migration"
        migPass = "mig_lab_secret_9988"
        appUser = "pos_app"
        appPass = "app_lab_secret_7766"
        rootUser = "root"
        rootPass = ""
        marker = $marker
    }
    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($manifestFile, ($manifest | ConvertTo-Json), $utf8NoBom)

    Write-Host "Process ownership confirmed: PID $($serverProc.Id) matches mysqld and pid-file." -ForegroundColor Green

    Write-Host "[4/6] Provisioning ephemeral database, DML-only pos_app, and pos_migration accounts..." -ForegroundColor Yellow

    # PHP setup script: root PDO connection MUST verify @@datadir, @@port, @@pid_file + PID before CREATE database/users
    $setupFile = Join-Path $tempLabDir "setup_db.php"
    $setupCode = @'
<?php
try {
    $manifestPath = $argv[1];
    $rawContent = file_get_contents($manifestPath);
    $cleanJson = preg_replace('/^\xEF\xBB\xBF/', '', $rawContent);
    $manifest = json_decode($cleanJson, true, 512, JSON_THROW_ON_ERROR);

    $port = (int)$manifest['port'];
    $expectedDatadir = realpath($manifest['datadir']);
    $expectedPid = (int)$manifest['pid'];

    $pdo = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Identity check on root connection BEFORE ANY DDL / WRITES
    $identity = $pdo->query('SELECT @@datadir AS datadir, @@port AS port, @@pid_file AS pid_file')->fetch(PDO::FETCH_ASSOC);
    $realConnectedDatadir = realpath(rtrim($identity['datadir'], '/\\'));

    if ($realConnectedDatadir === false || strtolower($realConnectedDatadir) !== strtolower($expectedDatadir)) {
        throw new RuntimeException("SAFETY ABORT: Root connection @@datadir ({$realConnectedDatadir}) does not match expected lab ({$expectedDatadir})!");
    }
    if ((int)$identity['port'] !== $port) {
        throw new RuntimeException("SAFETY ABORT: Root connection @@port ({$identity['port']}) does not match expected port ({$port})!");
    }
    if (!file_exists($identity['pid_file']) || (int)trim(file_get_contents($identity['pid_file'])) !== $expectedPid) {
        throw new RuntimeException("SAFETY ABORT: @@pid_file content does not match owned process PID ({$expectedPid})!");
    }

    $pdo->exec("CREATE DATABASE IF NOT EXISTS test_pos_recovery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

    $pdo->exec("CREATE USER IF NOT EXISTS 'pos_migration'@'127.0.0.1' IDENTIFIED BY 'mig_lab_secret_9988';");
    $pdo->exec("GRANT ALL ON test_pos_recovery.* TO 'pos_migration'@'127.0.0.1';");

    $pdo->exec("CREATE USER IF NOT EXISTS 'pos_app'@'127.0.0.1' IDENTIFIED BY 'app_lab_secret_7766';");
    $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE ON test_pos_recovery.* TO 'pos_app'@'127.0.0.1';");

    $pdo->exec("FLUSH PRIVILEGES;");

    // Seed schema as migration user
    $migPdo = new PDO("mysql:host=127.0.0.1;port={$port};dbname=test_pos_recovery;charset=utf8mb4", 'pos_migration', 'mig_lab_secret_9988', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Identity check on migration connection BEFORE ANY DDL / WRITES
    $migIdentity = $migPdo->query('SELECT @@datadir AS datadir, @@port AS port, CURRENT_USER() AS cur_user')->fetch(PDO::FETCH_ASSOC);
    $migDatadir = realpath(rtrim($migIdentity['datadir'], '/\\'));
    if ($migDatadir === false || strtolower($migDatadir) !== strtolower($expectedDatadir)) {
        throw new RuntimeException("SAFETY ABORT: Migration connection @@datadir mismatch!");
    }

    $migPdo->exec("
        CREATE TABLE IF NOT EXISTS schema_versions (
            version VARCHAR(255) PRIMARY KEY,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");
    $migPdo->exec("INSERT IGNORE INTO schema_versions (version) VALUES ('043_add_product_catalog_changes.sql');");

    $migPdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            barcode VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) DEFAULT 0.00,
            cost DECIMAL(10,2) DEFAULT 0.00,
            branch_id INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $migPdo->exec("
        CREATE TABLE IF NOT EXISTS product_catalog_changes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id INT NOT NULL,
            product_id INT NOT NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_catalog_changes_branch_sequence (branch_id, id),
            KEY idx_catalog_changes_product (product_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $migPdo->exec("
        INSERT INTO products (name, barcode, price, cost, branch_id) VALUES
        ('Sentinel Alpha', 'SENTINEL_001', 19.99, 10.00, 1),
        ('Sentinel Beta', 'SENTINEL_002', 29.99, 15.00, 1);
    ");

    echo "SETUP_OK";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
'@
    Set-Content -Path $setupFile -Value $setupCode -Encoding UTF8

    $setupResult = & $php $setupFile $manifestFile
    if ($setupResult -ne 'SETUP_OK') {
        Write-Error "FAIL: Database provisioning failed: $setupResult"
        exit 1
    }
    Write-Host "Database and users configured successfully with confirmed identity guards." -ForegroundColor Green

    Write-Host "[5/6] Executing catalog trigger recovery & definer alignment verification suite..." -ForegroundColor Yellow

    $verifyArgs = @(
        "scripts/verify-catalog-trigger-recovery.php",
        "--mariadb",
        "--host=127.0.0.1",
        "--port=$labPort",
        "--db=test_pos_recovery",
        "--expected-datadir=$tempLabDir",
        "--expected-pid=$($serverProc.Id)",
        "--fixture-marker=$marker",
        "--mig-user=pos_migration",
        "--mig-pass=mig_lab_secret_9988",
        "--app-user=pos_app",
        "--app-pass=app_lab_secret_7766",
        "--root-user=root",
        "--root-pass="
    )

    & $php @verifyArgs
    if ($LASTEXITCODE -ne 0) {
        Write-Error "FAIL: Live MariaDB integration tests failed with exit code $LASTEXITCODE."
        exit $LASTEXITCODE
    }

    Write-Host "[6/6] Live MariaDB integration tests passed all reproduction, repair, and DML checks!" -ForegroundColor Green

} finally {
    Write-Host "Cleaning up ephemeral MariaDB lab environment..." -ForegroundColor DarkGray
    $processStopped = $true
    if ($serverProc -ne $null) {
        try {
            if (-not $serverProc.HasExited) {
                Stop-Process -Id $serverProc.Id -Force -ErrorAction SilentlyContinue
                $serverProc.WaitForExit(5000) | Out-Null
            }
        } catch {}
        if (-not $serverProc.HasExited) {
            $processStopped = $false
            Write-Error "FAIL: Tracked MariaDB process $($serverProc.Id) failed to stop! Lab directory retained at: $tempLabDir"
        }
    }

    if ($processStopped -and (Test-Path $tempLabDir)) {
        $resolvedTarget = (Resolve-Path $tempLabDir).Path
        $tempRoot = [System.IO.Path]::GetFullPath($env:TEMP).TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
        $tempRootWithSlash = $tempRoot + [System.IO.Path]::DirectorySeparatorChar

        # Safety checks before Remove-Item:
        # 1. Must be strictly a subfolder of TEMP (exact separator boundary)
        $isChildOfTemp = $resolvedTarget.StartsWith($tempRootWithSlash, [System.StringComparison]::OrdinalIgnoreCase)
        # 2. Must match the exact unique creation path resolved at startup
        $isExpectedPath = ($resolvedTarget -eq $expectedCreatedLabPath)
        # 3. Must match the lab basename pattern
        $leafName = Split-Path -Leaf $resolvedTarget
        $isLabDir = $leafName -imatch '^pos_mariadb_lab[_ ][a-f0-9]{32}$'
        # 4. Check for reparse points (root or nested entries)
        $item = Get-Item $resolvedTarget -Force
        $isReparseRoot = $item.Attributes.HasFlag([System.IO.FileAttributes]::ReparsePoint)
        $hasReparseChildren = $false
        try {
            $reparseChildren = Get-ChildItem -Path $resolvedTarget -Recurse -Force | Where-Object { $_.Attributes.HasFlag([System.IO.FileAttributes]::ReparsePoint) }
            if ($reparseChildren -ne $null -and @($reparseChildren).Count -gt 0) {
                $hasReparseChildren = $true
            }
        } catch {
            $hasReparseChildren = $true
        }

        if (-not $isChildOfTemp -or -not $isExpectedPath -or -not $isLabDir) {
            Write-Error "SAFETY ABORT: Refusing to delete non-temp, unexpected, or unverified path: $resolvedTarget. Lab retained."
            exit 1
        } elseif ($isReparseRoot -or $hasReparseChildren) {
            Write-Error "SAFETY ABORT: Refusing to delete directory containing reparse points: $resolvedTarget. Lab retained."
            exit 1
        } else {
            Remove-Item -Recurse -Force $resolvedTarget -ErrorAction SilentlyContinue
            if (Test-Path $resolvedTarget) {
                Write-Error "FAIL: Failed to remove ephemeral lab directory: $resolvedTarget. Lab retained."
                exit 1
            } else {
                Write-Host "Ephemeral resources successfully released." -ForegroundColor DarkGray
            }
        }
    } elseif (-not $processStopped) {
        exit 1
    }
}

Write-Host "====================================================================" -ForegroundColor Cyan
Write-Host "MARIADB ORCHESTRATOR COMPLETED SUCCESSFULLY" -ForegroundColor Green
Write-Host "====================================================================" -ForegroundColor Cyan
exit 0
