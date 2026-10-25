[CmdletBinding()]
param(
    [ValidateSet('prepare', 'sign', 'acceptance')]
    [string]$Phase = 'prepare'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) {
    throw 'Ephemeral Windows GitHub Actions runner required.'
}

$lab = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP 'setup11-channel-transition-lab'))
$evidence = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP 'setup11-channel-transition-evidence'))
$assets = [IO.Path]::GetFullPath((Join-Path $lab 'assets'))

function Assert-SafeDirectory {
    param([string]$Directory)
    $full = [IO.Path]::GetFullPath($Directory)
    if ($full.TrimEnd('\') -eq [IO.Path]::GetPathRoot($full).TrimEnd('\')) {
        throw "Refusing root directory: $full"
    }
    $runnerTemp = [IO.Path]::GetFullPath($env:RUNNER_TEMP)
    if (-not $full.StartsWith($runnerTemp.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -and $full -ne $runnerTemp) {
        throw "Directory outside RUNNER_TEMP: $full"
    }
    $ancestor = $full
    while ($ancestor) {
        if (Test-Path -LiteralPath $ancestor) {
            if ((Get-Item -LiteralPath $ancestor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw "Reparse point rejected: $ancestor"
            }
        }
        $ancestor = [IO.Path]::GetDirectoryName($ancestor)
    }
    return $full
}

function Find-Tool {
    param([string]$Name, [string]$RelativeFallback)
    if ($RelativeFallback -and (Test-Path -LiteralPath $RelativeFallback)) {
        return (Resolve-Path -LiteralPath $RelativeFallback).Path
    }
    $cmd = Get-Command $Name -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }
    if ($RelativeFallback) {
        throw "Tool $Name not found in PATH or at fallback $RelativeFallback"
    }
    throw "Tool $Name not found in PATH"
}

$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
. (Join-Path $repo 'build/uninstall-data.ps1')

$seven = Find-Tool '7z.exe' (Join-Path $repo 'node_modules/electron-winstaller/vendor/7z.exe')
$php = Find-Tool 'php.exe' $env:PHP_BINARY

if ($Phase -eq 'prepare') {
    Write-Host '=== Setup 0.0.11 Channel Transition: Prepare Phase ==='

    if ($env:UPDATE_PRIVATE_KEY) {
        throw 'Security violation: UPDATE_PRIVATE_KEY must not exist during prepare phase.'
    }

    # Verify no leftover POS state across all 8 canonical POS data targets
    $targets = @(Get-PosCleanupTargets -Roaming $env:APPDATA -Local $env:LOCALAPPDATA -Shared $env:PROGRAMDATA)
    foreach ($existing in $targets) {
        if (Test-Path -LiteralPath $existing) {
            throw "Existing POS state blocks isolated acceptance: $existing"
        }
    }

    # Verify no existing installed POS registry identity
    foreach ($regPath in @(
        'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\com.abdoteck.pos',
        'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\com.abdoteck.pos',
        'HKCU:\Software\com.abdoteck.pos',
        'HKLM:\Software\com.abdoteck.pos'
    )) {
        if (Test-Path -LiteralPath $regPath) {
            throw "Existing POS registry identity blocks fresh prepare phase: $regPath"
        }
    }

    # Guard fresh ProgramData/qz state before first installer/app start
    # If pre-existing shared QZ / root cert could be independent, fail closed fresh-lab requirement
    $sharedQzDir = Join-Path $env:PROGRAMDATA 'qz'
    if (Test-Path -LiteralPath $sharedQzDir) {
        throw "Existing shared QZ state blocks fresh prepare phase: $sharedQzDir"
    }
    foreach ($programFiles in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
        if ($programFiles -and (Test-Path -LiteralPath (Join-Path $programFiles 'QZ Tray/qz-tray.exe'))) {
            throw "Existing independent QZ Tray installation blocks fresh prepare phase: $programFiles"
        }
    }

    # Fresh lab & evidence requirement: throw if either exists instead of deleting
    if (Test-Path -LiteralPath $lab) {
        throw "Existing lab directory blocks fresh prepare phase: $lab"
    }
    if (Test-Path -LiteralPath $evidence) {
        throw "Existing evidence directory blocks fresh prepare phase: $evidence"
    }

    Assert-SafeDirectory $lab | Out-Null
    Assert-SafeDirectory $assets | Out-Null

    New-Item -ItemType Directory -Path $lab -Force | Out-Null
    New-Item -ItemType Directory -Path $assets -Force | Out-Null

    $setup11Dir = Join-Path $lab 'setup11-download'
    New-Item -ItemType Directory -Path $setup11Dir -Force | Out-Null
    Write-Host 'Downloading published Setup 0.0.11...'
    gh release download v0.0.11 --repo ABDO-TECK/pos-desktop --pattern 'POS-Desktop-Setup-0.0.11.exe' --pattern 'deployment-inventory.json' --dir $setup11Dir
    if ($LASTEXITCODE -ne 0) { throw 'Setup 0.0.11 download failed.' }

    $setup11Exe = Join-Path $setup11Dir 'POS-Desktop-Setup-0.0.11.exe'
    $setup11Hash = (Get-FileHash -LiteralPath $setup11Exe -Algorithm SHA256).Hash.ToLowerInvariant()
    $expected11Hash = '4abfbd98898bf314d1531834166ec8f682710b90386920e7d71924e7fc92357d'
    if ($setup11Hash -ne $expected11Hash) {
        throw "Setup 0.0.11 hash mismatch! Expected $expected11Hash, got $setup11Hash"
    }
    $setup11Size = (Get-Item -LiteralPath $setup11Exe).Length
    if ($setup11Size -ne 322549986) {
        throw "Setup 0.0.11 file size mismatch! Expected 322549986, got $setup11Size"
    }
    Copy-Item -LiteralPath (Join-Path $setup11Dir 'deployment-inventory.json') -Destination (Join-Path $lab 'setup11-inventory.json')

    $setup001Dir = Join-Path $lab 'setup001-download'
    New-Item -ItemType Directory -Path $setup001Dir -Force | Out-Null
    Write-Host 'Downloading baseline Setup 0.0.1 and published inventory...'
    gh release download v0.0.1 --repo ABDO-TECK/pos-desktop --pattern 'POS-Desktop-Setup-0.0.1.exe' --pattern 'deployment-inventory.json' --dir $setup001Dir
    if ($LASTEXITCODE -ne 0) { throw 'Baseline Setup 0.0.1 download failed.' }

    $setup001Exe = Join-Path $setup001Dir 'POS-Desktop-Setup-0.0.1.exe'
    $setup001Hash = (Get-FileHash -LiteralPath $setup001Exe -Algorithm SHA256).Hash.ToLowerInvariant()
    $expected001Hash = '2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a'
    if ($setup001Hash -ne $expected001Hash) {
        throw "Setup 0.0.1 hash mismatch! Expected $expected001Hash, got $setup001Hash"
    }

    $setup001Inv = Join-Path $setup001Dir 'deployment-inventory.json'
    if (-not (Test-Path -LiteralPath $setup001Inv)) {
        throw 'Setup 0.0.1 deployment-inventory.json missing after download.'
    }
    $setup001InvHash = (Get-FileHash -LiteralPath $setup001Inv -Algorithm SHA256).Hash.ToLowerInvariant()
    $expected001InvHash = 'dc8f213aa3cbd2659529eb6cd8b0d298c9138e74d4b9142e42a52c774a27f488'
    if ($setup001InvHash -ne $expected001InvHash) {
        throw "Setup 0.0.1 inventory hash mismatch! Expected $expected001InvHash, got $setup001InvHash"
    }

    $beta10Dir = Join-Path $lab 'beta10-download'
    New-Item -ItemType Directory -Path $beta10Dir -Force | Out-Null
    Write-Host 'Downloading published Beta 10 artifacts...'
    gh release download v0.0.10-beta.10 --repo ABDO-TECK/pos-desktop-updates --pattern '*' --dir $beta10Dir
    if ($LASTEXITCODE -ne 0) { throw 'Beta 10 download failed.' }

    # Silently install published Setup 0.0.11 into fresh canonical lab/app
    $appDir = [IO.Path]::GetFullPath((Join-Path $lab 'app'))
    if (Test-Path -LiteralPath $appDir) {
        throw "Existing application directory blocks fresh Setup 0.0.11 installation: $appDir"
    }
    Assert-SafeDirectory $appDir | Out-Null

    Write-Host "Installing published Setup 0.0.11 silently to $appDir..."
    $process = Start-Process -FilePath $setup11Exe -ArgumentList @('/S', '/currentuser', "/D=$appDir") -WindowStyle Hidden -PassThru
    if (-not $process.WaitForExit(180000)) {
        throw 'Silent Setup 0.0.11 installation timed out.'
    }
    if ($process.ExitCode -ne 0) {
        throw "Silent Setup 0.0.11 installation failed with exit code: $($process.ExitCode)"
    }

    # Verify native installed application structure
    $installedExe = Join-Path $appDir 'POS Desktop.exe'
    if (-not (Test-Path -LiteralPath $installedExe)) {
        throw "Installed Setup 0.0.11 executable missing: $installedExe"
    }
    $installedAsar = Join-Path $appDir 'resources/app.asar'
    if (-not (Test-Path -LiteralPath $installedAsar)) {
        throw "Installed Setup 0.0.11 app.asar missing: $installedAsar"
    }
    $installedMysql = Join-Path $appDir 'resources/portable/mysql'
    if (-not (Test-Path -LiteralPath $installedMysql)) {
        throw "Installed Setup 0.0.11 portable MySQL missing: $installedMysql"
    }
    $installedPhp = Join-Path $appDir 'resources/portable/php'
    if (-not (Test-Path -LiteralPath $installedPhp)) {
        throw "Installed Setup 0.0.11 portable PHP missing: $installedPhp"
    }
    Assert-PosTreeSafe $appDir

    # Extract Setup 0.0.1 baseline for Beta 10 reconstruction
    Write-Host 'Extracting Setup 0.0.1 baseline payload...'
    $setup001Extract = Join-Path $lab 'setup001-extract'
    & $seven e $setup001Exe '$PLUGINSDIR\app-64.7z' "-o$setup001Extract" -y | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Failed to extract app-64.7z from Setup 0.0.1.' }
    $setup001App = Join-Path $lab 'setup001-app'
    & $seven x (Join-Path $setup001Extract 'app-64.7z') "-o$setup001App" -y | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Failed to unpack Setup 0.0.1 application files.' }

    # Reconstruct Beta 10 app files
    Write-Host 'Reconstructing Beta 10 target application files with manifest and stream verification...'
    $beta10App = Join-Path $lab 'beta10-app'
    & $php scripts/build-setup11-transition-acceptance.php `
        --action=reconstruct-beta10 `
        --setup001-root="$setup001App/resources/app.asar.unpacked" `
        --setup001-inventory="$setup001Inv" `
        --beta10-download="$beta10Dir" `
        --output="$beta10App"
    if ($LASTEXITCODE -ne 0) { throw 'Beta 10 reconstruction failed.' }
    Copy-Item -LiteralPath (Join-Path $beta10App 'deployment-inventory.json') -Destination (Join-Path $lab 'beta10-inventory.json')

    # Prepare Candidate 12 app files and inventory
    Write-Host 'Preparing Candidate 12 app files and deployment inventory...'
    $candidateApp = Join-Path $lab 'candidate-app'
    & $php scripts/build-setup11-transition-acceptance.php `
        --action=prepare-candidate `
        --source-root="$PWD" `
        "--setup11-inventory=$(Join-Path $lab 'setup11-inventory.json')" `
        --output="$candidateApp"
    if ($LASTEXITCODE -ne 0) { throw 'Candidate 12 preparation failed.' }
    Copy-Item -LiteralPath (Join-Path $candidateApp 'deployment-inventory.json') -Destination (Join-Path $lab 'candidate-inventory.json')

    # Execute UI contract phase
    Write-Host 'Executing UI contract phase to capture data compatibility contract...'
    $contractEvidence = Join-Path $lab 'contract-evidence'
    Assert-SafeDirectory $contractEvidence | Out-Null
    New-Item -ItemType Directory -Path $contractEvidence -Force | Out-Null

    node scripts/verify-setup11-channel-transition.mjs contract "$appDir" "$contractEvidence" "$assets"
    if ($LASTEXITCODE -ne 0) { throw 'Setup 0.0.11 contract phase failed.' }

    if (-not (Test-Path -LiteralPath (Join-Path $lab 'data-contract.json'))) {
        throw 'Contract phase completed without writing data-contract.json'
    }
    if (-not (Test-Path -LiteralPath (Join-Path $lab 'fixture-auth.json'))) {
        throw 'Contract phase completed without writing fixture-auth.json'
    }
    if (-not (Test-Path -LiteralPath (Join-Path $lab 'owned-lab.json'))) {
        throw 'Contract phase completed without writing owned-lab.json'
    }

    Write-Host 'Prepare phase completed successfully.'
}
elseif ($Phase -eq 'sign') {
    Write-Host '=== Setup 0.0.11 Channel Transition: Sign Phase ==='

    if (-not $env:UPDATE_PRIVATE_KEY -or [string]::IsNullOrWhiteSpace($env:UPDATE_PRIVATE_KEY)) {
        throw 'UPDATE_PRIVATE_KEY secret is required during sign phase.'
    }

    try {
        Assert-SafeDirectory $lab | Out-Null
        Assert-SafeDirectory $assets | Out-Null

        $ownedLabJson = Join-Path $lab 'owned-lab.json'
        if (-not (Test-Path -LiteralPath $ownedLabJson)) {
            throw "Owned lab marker missing ($ownedLabJson); refusing to sign."
        }

        & $php scripts/build-setup11-transition-acceptance.php `
            --action=sign `
            --lab-dir="$lab" `
            --assets-dir="$assets"
        if ($LASTEXITCODE -ne 0) { throw 'Signing phase build failed.' }

        # Verify signed outputs exist
        foreach ($required in @(
            'manifest.json',
            'manifest.sig',
            'delta-0.0.11-to-0.0.12.zip',
            'channel-transitions.json',
            'channel-transitions.sig',
            'transition-0.0.12-to-beta-0.0.10-beta.10-delta.zip',
            'transition-0.0.12-to-beta-0.0.10-beta.10-full.zip',
            'transition-0.0.10-beta.10-to-stable-0.0.12-delta.zip',
            'transition-0.0.10-beta.10-to-stable-0.0.12-full.zip'
        )) {
            $p = Join-Path $assets $required
            if (-not (Test-Path -LiteralPath $p)) {
                throw "Expected signed output missing: $required"
            }
        }
    }
    finally {
        # Secret hygiene: purge private key in finally block so exceptions or failures never leak it
        Remove-Item Env:UPDATE_PRIVATE_KEY -ErrorAction SilentlyContinue
        [Environment]::SetEnvironmentVariable('UPDATE_PRIVATE_KEY', $null, 'Process')
        Write-Host 'Private key purged from environment.'
    }

    Write-Host 'Sign phase completed and private key purged.'
}
elseif ($Phase -eq 'acceptance') {
    Write-Host '=== Setup 0.0.11 Channel Transition: Acceptance Phase ==='

    if ($env:UPDATE_PRIVATE_KEY) {
        throw 'Security violation: UPDATE_PRIVATE_KEY must not exist during acceptance phase.'
    }

    Assert-SafeDirectory $lab | Out-Null
    Assert-SafeDirectory $assets | Out-Null

    # Reject pre-existing evidence directory for fresh acceptance
    if (Test-Path -LiteralPath $evidence) {
        throw "Pre-existing evidence directory blocks fresh acceptance: $evidence"
    }
    Assert-SafeDirectory $evidence | Out-Null
    New-Item -ItemType Directory -Path $evidence -Force | Out-Null

    $ownedLabJson = Join-Path $lab 'owned-lab.json'
    if (-not (Test-Path -LiteralPath $ownedLabJson)) {
        throw "Owned lab marker missing ($ownedLabJson); refusing acceptance execution."
    }
    $owned = Get-Content -LiteralPath $ownedLabJson -Raw | ConvertFrom-Json
    $appDir = Join-Path $lab 'app'
    if ($owned.application -ne $appDir) {
        throw "Owned lab marker application mismatch: expected $appDir, got $($owned.application)"
    }

    node scripts/verify-setup11-channel-transition.mjs acceptance "$appDir" "$evidence" "$assets"
    if ($LASTEXITCODE -ne 0) { throw 'Setup 0.0.11 acceptance execution failed.' }

    $acceptanceJson = Join-Path $evidence 'acceptance.json'
    if (-not (Test-Path -LiteralPath $acceptanceJson)) {
        throw "Acceptance phase finished without generating $acceptanceJson"
    }

    # Verify owned lab marker corroboration before finishing
    $expectedStorage = [IO.Path]::GetFullPath((Join-Path $env:PROGRAMDATA 'POS System'))
    if ($owned.storage -ne $expectedStorage) {
        throw "Owned lab storage path mismatch: expected $expectedStorage, got $($owned.storage)"
    }

    Write-Host 'Setup 0.0.11 acceptance verification succeeded.'
    # Post-acceptance runtime environment and data disposal are owned by transient ephemeral Windows runner teardown.
}
