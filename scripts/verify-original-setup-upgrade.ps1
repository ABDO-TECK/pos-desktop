<#
.SYNOPSIS
    Verify true end-to-end upgrade preservation from a baseline POS-Desktop-Setup EXE
    (0.0.1 or 0.0.11) to the newly built Setup 0.0.12 on an ephemeral GitHub Actions runner.
.DESCRIPTION
    1. Enforces GITHUB_ACTIONS and RUNNER_TEMP guards; verifies no pre-existing POS data.
    2. Validates runner temp install/evidence paths are safe and free of reparse points.
       EvidenceName must be a leaf directory name bound to RUNNER_TEMP with no path traversal.
    3. Downloads baseline installer (0.0.1 or 0.0.11) and asserts pinned SHA256:
       - 0.0.1:  2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a
       - 0.0.11: 4abfbd98898bf314d1531834166ec8f682710b90386920e7d71924e7fc92357d
    4. Silently installs baseline to an owned runner temp directory.
    5. Launches baseline UI to initialize admin credentials and printing trust, and while alive
       executes scripts/fixtures/channel-transition-capability.php 'prepare-compatibility' and 'seed'
       to prepare migration 059, insert business rows (customer, settings), and record baseline ledger/schema.
    6. Snapshots quiesced baseline state:
       - Retained business rows and settings (canonical JSON)
       - Admin user identity digest (SHA256 of id, name, email, password, role without secret contents)
       - Normalized schema_sha256 and ledger_sha256 via UpdateDataCompatibilityService
       - Raw migration ledger and schema hash
       - Admin credentials and .env hashes (generic mismatch without printing credential hashes)
       - Real QZ ownership receipt (discovered across POS targets), Enterprise root certificate,
         and owned printing files
       - Required runtime userData/qz-certs/private-key.pem and digital-certificate.pem pair,
         plus LAN HTTPS server key/cert pair when enabled (disallowing zero-private-key passes)
       - Custom business sentinel
    7. Upgrades in-place using new Setup 0.0.12.
    8. Launches upgraded UI (asserts version 0.0.12, existing admin identity retained, no credentials modal),
       and while alive executes scripts/fixtures/channel-transition-capability.php 'inspect-compatibility'
       and 'inspect' to read live SQL business rows, settings, admin digest, ledger, and schema.
    9. Asserts complete preservation:
       - Normalized schema_sha256 and ledger_sha256 strictly preserved
       - Raw database schema_sha256 and raw migration ledger strictly preserved
       - Admin user identity digest strictly preserved
       - Customer rows and settings strictly retained after canonical JSON normalization
       - db_credentials.json hash and .env hash unchanged (reported as 'not_exercised' if absent)
       - Device SSL/key file hashes strictly unchanged (using absolute canonical path keys)
       - QZ ownership receipt, Enterprise trust certificate, and owned printing files unchanged
       - Business sentinel file unchanged
    10. Writes upgrade-acceptance.json evidence ONLY AFTER all assertions pass.
    11. Stops owned lab processes and uninstalls cleanly; fails if uninstaller missing, verifies managed data wiped.
#>

[CmdletBinding()]
param(
    [ValidateSet('0.0.1', '0.0.11')]
    [string]$BaselineVersion = '0.0.1',
    [string]$EvidenceName = 'pos-upgrade-evidence'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Guard: Never run on developer workstation
if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) {
    throw 'Upgrade verification is strictly restricted to an ephemeral GitHub Actions runner'
}

# Validate EvidenceName is a safe leaf name without separators or traversal
if ([string]::IsNullOrWhiteSpace($EvidenceName)) {
    throw 'EvidenceName parameter cannot be empty'
}
if ($EvidenceName -match '[/\\:]' -or $EvidenceName -match '\.\.') {
    throw "EvidenceName must be a leaf directory name without path separators or traversal: '$EvidenceName'"
}

$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
. (Join-Path $repo 'build/uninstall-data.ps1')

$install = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP ("POS Upgrade Acceptance-$BaselineVersion")))
$evidence = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP $EvidenceName))
$runnerTempRoot = [IO.Path]::GetFullPath($env:RUNNER_TEMP).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar

# Ensure install and evidence paths are strictly within RUNNER_TEMP boundary
foreach ($checkPath in @($install, $evidence)) {
    if (-not ($checkPath + [IO.Path]::DirectorySeparatorChar).StartsWith($runnerTempRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Target path escapes RUNNER_TEMP boundary: $checkPath"
    }
}
if ($evidence.Equals($install, [StringComparison]::OrdinalIgnoreCase)) {
    throw "Evidence path cannot collide with install path: $evidence"
}
if ($evidence.TrimEnd('\', '/').Equals($runnerTempRoot.TrimEnd('\', '/'), [StringComparison]::OrdinalIgnoreCase)) {
    throw "Evidence path must be a leaf directory within RUNNER_TEMP, cannot be RUNNER_TEMP root itself"
}

New-Item -ItemType Directory -Path $evidence -Force | Out-Null
Assert-PosTreeSafe $evidence

$targets = @(Get-PosCleanupTargets -Roaming $env:APPDATA -Local $env:LOCALAPPDATA -Shared $env:PROGRAMDATA)
foreach ($target in $targets) {
    if (Test-Path -LiteralPath $target) {
        throw "Runner contains pre-existing POS data before upgrade test: $target"
    }
}

$versionMeta = Get-Content -LiteralPath (Join-Path $repo 'version.json') -Raw | ConvertFrom-Json
$installerVersion = if ($versionMeta.installer_version) { $versionMeta.installer_version } else { (Get-Content -LiteralPath (Join-Path $repo 'package.json') -Raw | ConvertFrom-Json).version }
$newInstaller = Join-Path $repo "dist-electron/POS-Desktop-Setup-$installerVersion.exe"
if (-not (Test-Path -LiteralPath $newInstaller)) {
    throw "New installer executable missing: $newInstaller"
}

$originalInstaller = Join-Path $env:RUNNER_TEMP "POS-Desktop-Setup-$BaselineVersion.exe"
if ($BaselineVersion -eq '0.0.1') {
    $expectedSha256 = '2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a'
    $downloadUrl = 'https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.1/POS-Desktop-Setup-0.0.1.exe'
} elseif ($BaselineVersion -eq '0.0.11') {
    $expectedSha256 = '4abfbd98898bf314d1531834166ec8f682710b90386920e7d71924e7fc92357d'
    $downloadUrl = 'https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.11/POS-Desktop-Setup-0.0.11.exe'
} else {
    throw "Unsupported baseline version: $BaselineVersion"
}

Write-Host "Downloading baseline $BaselineVersion installer from $downloadUrl..."
Invoke-WebRequest -Uri $downloadUrl -OutFile $originalInstaller
if (-not (Test-Path -LiteralPath $originalInstaller)) {
    throw "Failed to download baseline $BaselineVersion installer to $originalInstaller"
}

$actualSha256 = (Get-FileHash -LiteralPath $originalInstaller -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actualSha256 -ne $expectedSha256) {
    throw "Baseline $BaselineVersion installer SHA256 mismatch! Expected: $expectedSha256, got: $actualSha256"
}
Write-Host "Baseline $BaselineVersion installer SHA256 verified: $actualSha256"

function Install-OriginalPos {
    Write-Host "Installing baseline $BaselineVersion silently to $install..."
    $process = Start-Process -FilePath $originalInstaller -ArgumentList @('/S', '/currentuser', "/D=$install") -WindowStyle Hidden -PassThru
    if (-not $process.WaitForExit(180000)) { throw "Silent Setup of baseline $BaselineVersion timed out" }
    if ($process.ExitCode -ne 0) { throw "Setup of baseline $BaselineVersion failed with exit code: $($process.ExitCode)" }
    if (-not (Test-Path -LiteralPath (Join-Path $install 'POS Desktop.exe'))) { throw "Installed $BaselineVersion executable missing" }
    Assert-PosTreeSafe $install
}

function Upgrade-Pos {
    Write-Host "Upgrading to $installerVersion over $install..."
    $process = Start-Process -FilePath $newInstaller -ArgumentList @('/S', '/currentuser', "/D=$install") -WindowStyle Hidden -PassThru
    if (-not $process.WaitForExit(180000)) { throw 'Silent Setup upgrade timed out' }
    if ($process.ExitCode -ne 0) { throw "Setup upgrade failed with exit code: $($process.ExitCode)" }
    if (-not (Test-Path -LiteralPath (Join-Path $install 'POS Desktop.exe'))) { throw 'Upgraded executable missing' }
    Assert-PosTreeSafe $install
}

function Test-InstalledUiWithFixture {
    param(
        [string]$Name,
        [string]$Expectation,
        [int]$Port,
        [string]$ExpectedVersion,
        [string]$FixtureAction,
        [string]$FixtureOutput
    )
    $stdout = Join-Path $evidence "$Name.stdout.log"
    $stderr = Join-Path $evidence "$Name.stderr.log"
    $receipt = Join-Path $evidence "$Name.json"
    $process = Start-Process -FilePath (Join-Path $install 'POS Desktop.exe') -WorkingDirectory $install -ArgumentList "--remote-debugging-port=$Port" -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    & node (Join-Path $repo 'scripts/verify-first-run-ui.mjs') $Port $Expectation $receipt $ExpectedVersion $FixtureAction $FixtureOutput
    if ($LASTEXITCODE) { throw "Installed UI acceptance failed: $Name" }
    if (-not $process.WaitForExit(30000)) { throw "Application did not shut down after UI acceptance ($Name)" }
    $deadline = (Get-Date).AddSeconds(30)
    do {
        $children = @(Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -and $_.ExecutablePath.StartsWith($install + '\', [StringComparison]::OrdinalIgnoreCase) })
        if (-not $children.Count) { break }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)
    if ($children.Count) { throw "Owned runtime processes survived normal application shutdown ($Name)" }
}

function Stop-OwnedLabProcesses {
    param([string]$InstallPath)
    if (-not $InstallPath) { return }
    try {
        $owned = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
            $_.ExecutablePath -and $_.ExecutablePath.StartsWith($InstallPath + '\', [StringComparison]::OrdinalIgnoreCase)
        })
        foreach ($p in $owned) {
            try {
                Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
            } catch {}
        }
    } catch {}
}

function Uninstall-Pos {
    $uninstaller = Join-Path $install 'Uninstall POS Desktop.exe'
    if (-not (Test-Path -LiteralPath $uninstaller)) {
        throw "Uninstaller executable missing at $uninstaller; cleanup cannot safely proceed"
    }
    $uninstallerCopy = Join-Path $evidence ('uninstall-' + [guid]::NewGuid().ToString('N') + '.exe')
    Copy-Item -LiteralPath $uninstaller -Destination $uninstallerCopy
    $process = Start-Process -FilePath $uninstallerCopy -ArgumentList @('/S', '/currentuser', "_?=$install") -WindowStyle Hidden -PassThru
    if (-not $process.WaitForExit(180000)) { throw 'Silent uninstall timed out' }
    $uninstallCode = $process.ExitCode
    Remove-Item -LiteralPath $uninstallerCopy -Force
    $deadline = (Get-Date).AddSeconds(45)
    do {
        $left = @($targets | Where-Object { Test-Path -LiteralPath $_ })
        if (-not $left.Count -and -not (Test-Path -LiteralPath (Join-Path $install 'POS Desktop.exe'))) { break }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)
    if ($left.Count -or $uninstallCode -ne 0) {
        throw "Uninstall did not remove managed data after upgrade acceptance (ExitCode: $uninstallCode)"
    }
}

try {
    # 1. Install baseline
    Install-OriginalPos

    # 2. Launch baseline UI and while runtime is alive, execute fixture 'prepare-compatibility,seed'
    $baselineCompatFile = Join-Path $evidence 'baseline-compat.json'
    $baselineDbFile = Join-Path $evidence 'baseline-db.json'
    Test-InstalledUiWithFixture -Name "baseline-$BaselineVersion-run" -Expectation 'fresh' -Port 19231 -ExpectedVersion $BaselineVersion -FixtureAction 'prepare-compatibility,seed' -FixtureOutput "$baselineCompatFile,$baselineDbFile"

    # 3. Snapshot quiesced baseline state
    if (-not (Test-Path -LiteralPath $baselineCompatFile)) {
        throw "Baseline compatibility fixture execution failed to produce $baselineCompatFile"
    }
    $baselineCompat = Get-Content -LiteralPath $baselineCompatFile -Raw | ConvertFrom-Json
    if (-not $baselineCompat.schema_sha256 -or -not $baselineCompat.ledger_sha256) {
        throw "Baseline compatibility fixture missing schema_sha256 or ledger_sha256"
    }

    if (-not (Test-Path -LiteralPath $baselineDbFile)) {
        throw "Baseline fixture execution failed to produce $baselineDbFile"
    }
    $baselineDb = Get-Content -LiteralPath $baselineDbFile -Raw | ConvertFrom-Json
    if (-not $baselineDb.business_rows -or $baselineDb.business_rows.Count -lt 1) {
        throw "Baseline database did not contain seeded customer business rows"
    }
    if (-not $baselineDb.settings -or $baselineDb.settings.Count -lt 1) {
        throw "Baseline database did not contain seeded settings"
    }
    if (-not $baselineDb.admin_digest) {
        throw "Baseline database did not produce an admin user digest"
    }
    Write-Host "Baseline SQL state snapshotted: $($baselineDb.business_rows.Count) customer row(s), $($baselineDb.settings.Count) setting(s), $($baselineDb.ledger.Count) migration(s)."

    # Snapshot credentials and .env hashes
    $credsPath = Join-Path $env:APPDATA 'POS System/db_credentials.json'
    if (-not (Test-Path -LiteralPath $credsPath)) {
        throw "Baseline did not create credentials at $credsPath"
    }
    $baselineCredsHash = (Get-FileHash -LiteralPath $credsPath -Algorithm SHA256).Hash

    $envPath = Join-Path $env:APPDATA 'POS System/.env'
    $baselineEnvHash = if (Test-Path -LiteralPath $envPath) { (Get-FileHash -LiteralPath $envPath -Algorithm SHA256).Hash } else { $null }

    # Snapshot printing ownership receipt (discovered across POS targets)
    $receipts = @($targets | ForEach-Object { Join-Path $_ 'qz-shared-install.json' } | Where-Object { Test-Path -LiteralPath $_ })
    if ($receipts.Count -ne 1) {
        throw "Baseline did not record ownership of QZ printing trust in POS data targets (found $($receipts.Count))"
    }
    $receiptPath = $receipts[0]
    $receiptHash = (Get-FileHash -LiteralPath $receiptPath -Algorithm SHA256).Hash
    $ownership = Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json

    $qzRoot = Join-Path $env:PROGRAMDATA 'qz'
    $rootCert = [Security.Cryptography.X509Certificates.X509Certificate2]::new([Convert]::FromBase64String($ownership.rootCertificateBase64))
    $printingThumbprint = $rootCert.Thumbprint
    $rootCert.Dispose()

    # Verify printing root certificate is installed in Enterprise store
    $enterpriseKeyPath = "Registry::HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\EnterpriseCertificates\Root\Certificates\$printingThumbprint"
    if (-not (Test-Path -LiteralPath $enterpriseKeyPath)) {
        throw "Printing root certificate was not installed in Enterprise trust store: $enterpriseKeyPath"
    }

    # Snapshot owned printing files
    $baselinePrintingFiles = @{}
    foreach ($file in $ownership.files) {
        $absPath = Join-Path $qzRoot $file.path
        if (-not (Test-Path -LiteralPath $absPath)) {
            throw "Owned printing file missing from disk: $absPath"
        }
        $baselinePrintingFiles[$file.path] = (Get-FileHash -LiteralPath $absPath -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    Write-Host "Baseline printing trust snapshotted: thumbprint $printingThumbprint, $($baselinePrintingFiles.Count) owned file(s)."

    # Snapshot device SSL certificates and private keys
    $baselineDeviceKeys = @{}

    # A. Require actual runtime userData/qz-certs/private-key.pem and digital-certificate.pem pair
    $qzCertDirs = @($targets | ForEach-Object { Join-Path $_ 'qz-certs' } | Where-Object { Test-Path -LiteralPath $_ })
    $foundQzPair = $false
    foreach ($dir in $qzCertDirs) {
        $privateKey = Join-Path $dir 'private-key.pem'
        $digitalCert = Join-Path $dir 'digital-certificate.pem'
        if ((Test-Path -LiteralPath $privateKey) -and (Test-Path -LiteralPath $digitalCert)) {
            $baselineDeviceKeys[[IO.Path]::GetFullPath($privateKey)] = (Get-FileHash -LiteralPath $privateKey -Algorithm SHA256).Hash
            $baselineDeviceKeys[[IO.Path]::GetFullPath($digitalCert)] = (Get-FileHash -LiteralPath $digitalCert -Algorithm SHA256).Hash
            $foundQzPair = $true
        }
    }
    if (-not $foundQzPair) {
        throw "Required original runtime QZ certificate pair (userData/qz-certs/private-key.pem and digital-certificate.pem) missing after printing initialization"
    }

    # B. Discover actual HTTPS ssl/server.key and server.crt pair if LAN HTTPS feature was enabled
    $sslDirs = @($targets | ForEach-Object { Join-Path $_ 'ssl' } | Where-Object { Test-Path -LiteralPath $_ })
    $httpsEnabled = $false
    foreach ($dir in $sslDirs) {
        $serverKey = Join-Path $dir 'server.key'
        $serverCert = Join-Path $dir 'server.crt'
        if ((Test-Path -LiteralPath $serverKey) -and (Test-Path -LiteralPath $serverCert)) {
            $baselineDeviceKeys[[IO.Path]::GetFullPath($serverKey)] = (Get-FileHash -LiteralPath $serverKey -Algorithm SHA256).Hash
            $baselineDeviceKeys[[IO.Path]::GetFullPath($serverCert)] = (Get-FileHash -LiteralPath $serverCert -Algorithm SHA256).Hash
            $httpsEnabled = $true
        }
    }
    if ($httpsEnabled) {
        Write-Host "Discovered active LAN HTTPS server key/cert pair; included in preservation baseline."
    } else {
        Write-Host "LAN HTTPS proxy not enabled in baseline (no server.key/server.crt in userData/ssl); optional path documented."
    }

    # C. Discover any additional device key/cert files across all targets (ban zero-private-key pass)
    foreach ($target in $targets) {
        if (Test-Path -LiteralPath $target) {
            Get-ChildItem -LiteralPath $target -Include '*.key', '*.crt', '*.pem' -Recurse -File -ErrorAction SilentlyContinue | ForEach-Object {
                $absFile = [IO.Path]::GetFullPath($_.FullName)
                if (-not $baselineDeviceKeys.ContainsKey($absFile)) {
                    $baselineDeviceKeys[$absFile] = (Get-FileHash -LiteralPath $absFile -Algorithm SHA256).Hash
                }
            }
        }
    }
    if ($baselineDeviceKeys.Count -lt 2) {
        throw "No device private keys or certificates discovered; expected at least 2 QZ cert files"
    }
    Write-Host "Baseline device SSL/key material snapshotted: $($baselineDeviceKeys.Count) key/cert file(s)."

    # Create business fixture sentinel
    $sharedDataDir = Join-Path $env:PROGRAMDATA 'POS System'
    if (-not (Test-Path -LiteralPath $sharedDataDir)) {
        New-Item -ItemType Directory -Path $sharedDataDir -Force | Out-Null
    }
    $sentinelFile = Join-Path $sharedDataDir 'business-fixture-sentinel.txt'
    $sentinelGuid = [guid]::NewGuid().ToString('N')
    Set-Content -LiteralPath $sentinelFile -Value $sentinelGuid

    # 4. In-place upgrade with Setup 0.0.12
    Upgrade-Pos

    # 5. Launch upgraded UI and while runtime is alive, execute fixture 'inspect-compatibility,inspect'
    $upgradedCompatFile = Join-Path $evidence 'upgraded-compat.json'
    $upgradedDbFile = Join-Path $evidence 'upgraded-db.json'
    Test-InstalledUiWithFixture -Name "upgraded-$installerVersion-run" -Expectation 'existing' -Port 19232 -ExpectedVersion $installerVersion -FixtureAction 'inspect-compatibility,inspect' -FixtureOutput "$upgradedCompatFile,$upgradedDbFile"

    # 6. Verify upgraded UI asserted retained admin
    $upgradedUiReceipt = Get-Content -LiteralPath (Join-Path $evidence "upgraded-$installerVersion-run.json") -Raw | ConvertFrom-Json
    if ($upgradedUiReceipt.version -ne $installerVersion) {
        throw "Upgraded UI version mismatch: expected $installerVersion"
    }
    if ($upgradedUiReceipt.newAdmin -ne $false) {
        throw "Upgraded UI did not retain existing administrator identity (reported newAdmin: true)"
    }
    if ($upgradedUiReceipt.modal -ne $false) {
        throw "Upgraded UI unexpectedly displayed first-run credentials modal for retained admin"
    }

    # 7. Strict SQL Preservation Assertions
    if (-not (Test-Path -LiteralPath $upgradedCompatFile)) {
        throw "Upgraded compatibility fixture execution failed to produce $upgradedCompatFile"
    }
    $upgradedCompat = Get-Content -LiteralPath $upgradedCompatFile -Raw | ConvertFrom-Json

    if (-not (Test-Path -LiteralPath $upgradedDbFile)) {
        throw "Upgraded fixture execution failed to produce $upgradedDbFile"
    }
    $upgradedDb = Get-Content -LiteralPath $upgradedDbFile -Raw | ConvertFrom-Json

    # A. Normalized Schema & Ledger Verification (UpdateDataCompatibilityService contract)
    if (-not $upgradedCompat.schema_sha256 -or -not $baselineCompat.schema_sha256) {
        throw "Normalized schema SHA256 missing from compatibility contract"
    }
    if ($upgradedCompat.schema_sha256 -ne $baselineCompat.schema_sha256) {
        throw "Normalized schema SHA256 mismatch after upgrade"
    }
    if (-not $upgradedCompat.ledger_sha256 -or -not $baselineCompat.ledger_sha256) {
        throw "Normalized ledger SHA256 missing from compatibility contract"
    }
    if ($upgradedCompat.ledger_sha256 -ne $baselineCompat.ledger_sha256) {
        throw "Normalized ledger SHA256 mismatch after upgrade"
    }
    Write-Host "PASS: Normalized schema SHA256 and ledger SHA256 strictly preserved via compatibility contract."

    # B. Raw Schema & Ledger Equality Verification
    if (-not $upgradedDb.schema_sha256 -or -not $baselineDb.schema_sha256) {
        throw "Raw database schema SHA256 missing from inspection"
    }
    if ($upgradedDb.schema_sha256 -ne $baselineDb.schema_sha256) {
        throw "Raw database schema SHA256 mismatch after upgrade"
    }
    $baselineLedgerJson = $baselineDb.ledger | ConvertTo-Json -Compress
    $upgradedLedgerJson = $upgradedDb.ledger | ConvertTo-Json -Compress
    if ($upgradedLedgerJson -ne $baselineLedgerJson) {
        throw "Raw migration ledger mismatch after upgrade"
    }
    Write-Host "PASS: Raw schema SHA256 and raw migration ledger strictly preserved ($($baselineDb.ledger.Count) migrations)."

    # C. Admin User Identity Digest Verification (id, name, email, password, role)
    if (-not $upgradedDb.admin_digest -or -not $baselineDb.admin_digest) {
        throw "Admin digest missing from database inspection"
    }
    if ($upgradedDb.admin_digest -ne $baselineDb.admin_digest) {
        throw "Administrator identity or password hash changed during upgrade"
    }
    Write-Host "PASS: Administrator identity and password hash digest strictly preserved."

    # D. Exact Customer Rows Verification (id, name, phone, initial_balance, loyalty_points)
    if (-not $upgradedDb.business_rows -or $upgradedDb.business_rows.Count -lt 1) {
        throw "Upgraded database lost seeded customer business rows"
    }
    $baselineCustomerJson = $baselineDb.business_rows | ConvertTo-Json -Compress
    $upgradedCustomerJson = $upgradedDb.business_rows | ConvertTo-Json -Compress
    if ($upgradedCustomerJson -ne $baselineCustomerJson) {
        throw "Customer business rows modified during upgrade (id, name, phone, balance, loyalty mismatch)"
    }

    # E. Exact Settings Verification (key, value)
    if (-not $upgradedDb.settings -or $upgradedDb.settings.Count -lt 1) {
        throw "Upgraded database lost seeded settings"
    }
    $baselineSettingsJson = $baselineDb.settings | ConvertTo-Json -Compress
    $upgradedSettingsJson = $upgradedDb.settings | ConvertTo-Json -Compress
    if ($upgradedSettingsJson -ne $baselineSettingsJson) {
        throw "Settings modified or missing after upgrade"
    }
    Write-Host "PASS: Customer business rows and settings strictly preserved after canonical normalization."

    # 8. Credentials & .env Preservation Assertions (generic error messages without secret hashes)
    $upgradedCredsHash = (Get-FileHash -LiteralPath $credsPath -Algorithm SHA256).Hash
    if ($upgradedCredsHash -ne $baselineCredsHash) {
        throw "db_credentials.json hash mismatch after upgrade"
    }
    if ($baselineEnvHash) {
        $upgradedEnvHash = (Get-FileHash -LiteralPath $envPath -Algorithm SHA256).Hash
        if ($upgradedEnvHash -ne $baselineEnvHash) {
            throw ".env hash mismatch after upgrade"
        }
    }
    Write-Host "PASS: db_credentials.json and .env hashes strictly preserved."

    # 9. Device SSL & Key Material Preservation Assertions (hashes only, no contents printed)
    foreach ($absFile in $baselineDeviceKeys.Keys) {
        if (-not (Test-Path -LiteralPath $absFile)) {
            throw "Device key or certificate missing after upgrade"
        }
        $currentHash = (Get-FileHash -LiteralPath $absFile -Algorithm SHA256).Hash
        if ($currentHash -ne $baselineDeviceKeys[$absFile]) {
            throw "Device key or certificate hash mismatch after upgrade"
        }
    }
    Write-Host "PASS: Device SSL certificates and keys strictly preserved ($($baselineDeviceKeys.Count) items verified)."

    # 10. Printing Trust & Ownership Receipt Assertions
    if (-not (Test-Path -LiteralPath $receiptPath)) {
        throw "QZ ownership receipt missing after upgrade: $receiptPath"
    }
    $upgradedReceiptHash = (Get-FileHash -LiteralPath $receiptPath -Algorithm SHA256).Hash
    if ($upgradedReceiptHash -ne $receiptHash) {
        throw "QZ ownership receipt altered during upgrade"
    }
    if (-not (Test-Path -LiteralPath $enterpriseKeyPath)) {
        throw "Enterprise printing root certificate trust key missing after upgrade: $enterpriseKeyPath"
    }
    foreach ($filePath in $baselinePrintingFiles.Keys) {
        $absPath = Join-Path $qzRoot $filePath
        if (-not (Test-Path -LiteralPath $absPath)) {
            throw "Owned printing file missing after upgrade: $absPath"
        }
        $currentFileHash = (Get-FileHash -LiteralPath $absPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($currentFileHash -ne $baselinePrintingFiles[$filePath]) {
            throw "Owned printing file was modified during upgrade: $filePath"
        }
    }
    Write-Host "PASS: Printing trust receipt, Enterprise store certificate, and owned files strictly preserved."

    # 11. Business Sentinel Assertion
    if (-not (Test-Path -LiteralPath $sentinelFile)) {
        throw "Business sentinel file was deleted during upgrade: $sentinelFile"
    }
    $preservedSentinel = (Get-Content -LiteralPath $sentinelFile -Raw).Trim()
    if ($preservedSentinel -ne $sentinelGuid) {
        throw "Business sentinel value corrupted during upgrade: expected $sentinelGuid, got $preservedSentinel"
    }

    # 12. Upgraded Shell Version Assertion
    $unpackedVer = (Get-Content -LiteralPath (Join-Path $install 'resources/app.asar.unpacked/version.json') -Raw | ConvertFrom-Json).version
    if ($unpackedVer -ne $installerVersion) {
        throw "Upgraded app.asar.unpacked version mismatch: expected $installerVersion, got $unpackedVer"
    }

    # 13. Acceptance Evidence Recorded ONLY AFTER ALL Assertions Pass
    @{
        originalBaseline = $BaselineVersion
        targetVersion = $installerVersion
        installerSha256 = $actualSha256
        businessRowsPreserved = $true
        settingsPreserved = $true
        schemaPreserved = $true
        normalizedSchemaPreserved = $true
        ledgerPreserved = $true
        normalizedLedgerPreserved = $true
        adminIdentityPreserved = $true
        credentialsPreserved = $true
        envPreserved = if ($baselineEnvHash) { $true } else { 'not_exercised' }
        deviceKeysPreserved = $true
        printingTrustPreserved = $true
        sentinelPreserved = $true
        upgradedUiVerified = $true
        baselineMigrationsCount = $baselineDb.ledger.Count
        upgradedMigrationsCount = $upgradedDb.ledger.Count
    } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $evidence 'upgrade-acceptance.json')

    Write-Host "PASS: True end-to-end upgrade from $BaselineVersion to $installerVersion verified. All business SQL rows, settings, ledger, credentials, device keys, and printing trust preserved."
} finally {
    # 14. Clean up owned fixture safely via full uninstallation
    Stop-OwnedLabProcesses -InstallPath $install
    $hasApp = (Test-Path -LiteralPath $install) -and (Test-Path -LiteralPath (Join-Path $install 'POS Desktop.exe'))
    $uninstallerPath = Join-Path $install 'Uninstall POS Desktop.exe'
    if (Test-Path -LiteralPath $uninstallerPath) {
        Write-Host "Cleaning up test fixture via uninstallation..."
        Uninstall-Pos
    } elseif ($hasApp) {
        throw "Uninstaller executable missing at $uninstallerPath; cleanup cannot safely proceed"
    }
}

Write-Host "PASS: verify-original-setup-upgrade completed cleanly."
