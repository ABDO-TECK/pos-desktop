[CmdletBinding()]
param(
    [ValidateSet('prepare', 'prepare-target', 'acceptance')]
    [string]$Phase = 'prepare',
    [string]$Assets = 'release-assets'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Strict CI guards before any actions
if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) {
    throw 'Ephemeral Windows GitHub Actions runner required.'
}

if ($env:GITHUB_REPOSITORY -ne 'ABDO-TECK/pos-desktop-updates') {
    throw "Invalid repository: '$($env:GITHUB_REPOSITORY)'. Must be exactly 'ABDO-TECK/pos-desktop-updates'."
}

if (-not [IO.Path]::IsPathRooted($env:RUNNER_TEMP)) {
    throw "RUNNER_TEMP must be a rooted Windows absolute path: $($env:RUNNER_TEMP)"
}

$lab = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP 'setup11-beta-release-lab'))
$evidence = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP 'setup11-beta-release-evidence'))

$assetsDir = if ([IO.Path]::IsPathRooted($Assets)) {
    [IO.Path]::GetFullPath($Assets)
} else {
    [IO.Path]::GetFullPath((Join-Path $PWD $Assets))
}

function Assert-SafeDirectory {
    param([string]$Directory)
    if ([string]::IsNullOrWhiteSpace($Directory)) {
        throw 'Directory path cannot be empty.'
    }
    $full = [IO.Path]::GetFullPath($Directory)
    if ($full.TrimEnd('\') -eq [IO.Path]::GetPathRoot($full).TrimEnd('\')) {
        throw "Refusing drive root directory: $full"
    }
    $runnerTemp = [IO.Path]::GetFullPath($env:RUNNER_TEMP)
    $workspace = [IO.Path]::GetFullPath($PWD)

    $isUnderTemp = $full.StartsWith($runnerTemp.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -or ($full -eq $runnerTemp)
    $isUnderWorkspace = $full.StartsWith($workspace.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -or ($full -eq $workspace)

    if (-not $isUnderTemp -and -not $isUnderWorkspace) {
        throw "Directory outside RUNNER_TEMP or Workspace: $full"
    }

    # Inspect ancestor chain for reparse points before following/accessing
    $ancestor = $full
    while ($ancestor) {
        if (Test-Path -LiteralPath $ancestor) {
            $item = Get-Item -LiteralPath $ancestor -Force
            if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw "Reparse point or junction rejected in path: $ancestor"
            }
        }
        $parent = [IO.Path]::GetDirectoryName($ancestor)
        if ($parent -eq $ancestor) { break }
        $ancestor = $parent
    }
    return $full
}

function Stop-OwnedLabProcesses {
    param([string]$AppDirectory)
    if ([string]::IsNullOrWhiteSpace($AppDirectory)) { return }
    $appPath = [IO.Path]::GetFullPath($AppDirectory).TrimEnd('\') + '\'
    Get-Process -ErrorAction SilentlyContinue | Where-Object {
        try {
            $p = $_.Path
            $p -and $p.StartsWith($appPath, [StringComparison]::OrdinalIgnoreCase)
        } catch {
            $false
        }
    } | ForEach-Object {
        try {
            $_.Kill()
            $_.WaitForExit(5000)
        } catch {}
    }
}

function Assert-NoPrivateKeys {
    foreach ($varName in @('UPDATE_PRIVATE_KEY', 'SOURCE_PRIVATE_SIGNING_KEY', 'PRIVATE_KEY')) {
        if ([Environment]::GetEnvironmentVariable($varName)) {
            throw "Security violation: $varName must not exist during $Phase phase."
        }
    }
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

$php = Find-Tool 'php.exe' $env:PHP_BINARY

Assert-NoPrivateKeys

if ($Phase -eq 'prepare') {
    Write-Host '=== Setup 0.0.11 Beta Release: Prepare Phase ==='

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
    Assert-SafeDirectory $assetsDir | Out-Null

    New-Item -ItemType Directory -Path $lab -Force | Out-Null
    if (-not (Test-Path -LiteralPath $assetsDir)) {
        New-Item -ItemType Directory -Path $assetsDir -Force | Out-Null
    }

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

    $setup11Inv = Join-Path $setup11Dir 'deployment-inventory.json'
    if (-not (Test-Path -LiteralPath $setup11Inv)) {
        throw 'Setup 0.0.11 deployment-inventory.json missing after download.'
    }
    $setup11InvHash = (Get-FileHash -LiteralPath $setup11Inv -Algorithm SHA256).Hash.ToLowerInvariant()
    $expected11InvHash = '88475c625b47d0e60bba21ceb52923b8ab5e0b6eb1f57cfd4f495e7e1924ced2'
    if ($setup11InvHash -ne $expected11InvHash) {
        throw "Setup 0.0.11 inventory hash mismatch! Expected $expected11InvHash, got $setup11InvHash"
    }
    Copy-Item -LiteralPath $setup11Inv -Destination (Join-Path $lab 'setup11-inventory.json')

    # Silently install published Setup 0.0.11 into canonical lab/app
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

    # Execute UI contract phase
    Write-Host 'Executing UI contract phase to capture baseline data contract and auth fixture...'
    $contractEvidence = Join-Path $lab 'contract-evidence'
    Assert-SafeDirectory $contractEvidence | Out-Null
    New-Item -ItemType Directory -Path $contractEvidence -Force | Out-Null

    node scripts/verify-setup11-channel-transition.mjs contract "$appDir" "$contractEvidence" "$assetsDir"
    if ($LASTEXITCODE -ne 0) { throw 'Setup 0.0.11 contract phase failed.' }

    $dataContractJson = Join-Path $lab 'data-contract.json'
    $fixtureAuthJson = Join-Path $lab 'fixture-auth.json'
    $ownedLabJson = Join-Path $lab 'owned-lab.json'

    if (-not (Test-Path -LiteralPath $dataContractJson)) {
        throw 'Contract phase completed without writing data-contract.json'
    }
    if (-not (Test-Path -LiteralPath $fixtureAuthJson)) {
        throw 'Contract phase completed without writing fixture-auth.json'
    }
    if (-not (Test-Path -LiteralPath $ownedLabJson)) {
        throw 'Contract phase completed without writing owned-lab.json'
    }

    # Validate owned lab marker strictly
    $owned = Get-Content -LiteralPath $ownedLabJson -Raw | ConvertFrom-Json
    if ($owned.application -ne $appDir) {
        throw "Owned lab application mismatch: expected $appDir, got $($owned.application)"
    }
    $expectedStorage = [IO.Path]::GetFullPath((Join-Path $env:PROGRAMDATA 'POS System'))
    if ($owned.storage -ne $expectedStorage) {
        throw "Owned lab storage mismatch: expected $expectedStorage, got $($owned.storage)"
    }

    # Stop ONLY owned lab processes under $appDir (never kill unrelated runner or system processes)
    Stop-OwnedLabProcesses $appDir

    Write-Host 'Prepare phase completed successfully.'
}
elseif ($Phase -eq 'prepare-target') {
    Write-Host '=== Setup 0.0.11 Beta Release: Prepare-Target Phase ==='

    Assert-SafeDirectory $lab | Out-Null
    Assert-SafeDirectory $assetsDir | Out-Null

    $ownedLabJson = Join-Path $lab 'owned-lab.json'
    if (-not (Test-Path -LiteralPath $ownedLabJson)) {
        throw "Owned lab marker missing ($ownedLabJson); refusing prepare-target execution."
    }
    $owned = Get-Content -LiteralPath $ownedLabJson -Raw | ConvertFrom-Json
    $appDir = Join-Path $lab 'app'
    if ($owned.application -ne $appDir) {
        throw "Owned lab application mismatch: expected $appDir, got $($owned.application)"
    }
    $expectedStorage = [IO.Path]::GetFullPath((Join-Path $env:PROGRAMDATA 'POS System'))
    if ($owned.storage -ne $expectedStorage) {
        throw "Owned lab storage mismatch: expected $expectedStorage, got $($owned.storage)"
    }

    $setup11InvJson = Join-Path $lab 'setup11-inventory.json'
    if (-not (Test-Path -LiteralPath $setup11InvJson)) {
        throw "Setup 11 baseline inventory missing ($setup11InvJson); refusing prepare-target execution."
    }

    Write-Host "Verifying release package in $assetsDir before target reconstruction..."
    pwsh scripts/verify-beta-release-package.ps1 -ReleaseDir "$assetsDir"
    if ($LASTEXITCODE -ne 0) {
        throw 'Release package pre-verification failed.'
    }

    Write-Host 'Reconstructing Candidate 12 target application files from baseline inventory and signed delta payload...'
    & $php scripts/prepare-beta-release-target.php "--lab-dir=$lab" "--assets-dir=$assetsDir"
    if ($LASTEXITCODE -ne 0) {
        throw 'Candidate 12 target reconstruction failed.'
    }

    $candidateApp = Join-Path $lab 'candidate-app'
    if (-not (Test-Path -LiteralPath $candidateApp)) {
        throw "Candidate application directory missing after reconstruction: $candidateApp"
    }
    if (-not (Test-Path -LiteralPath (Join-Path $candidateApp 'version.json'))) {
        throw "Candidate version.json missing after reconstruction: $candidateApp/version.json"
    }
    if (-not (Test-Path -LiteralPath (Join-Path $candidateApp 'backend/backend.phar'))) {
        throw "Candidate backend.phar missing after reconstruction: $candidateApp/backend/backend.phar"
    }
    if (-not (Test-Path -LiteralPath (Join-Path $lab 'candidate-inventory.json'))) {
        throw "Candidate inventory marker missing after reconstruction: $lab/candidate-inventory.json"
    }

    Write-Host 'Prepare-target phase completed successfully.'
}
elseif ($Phase -eq 'acceptance') {
    Write-Host '=== Setup 0.0.11 Beta Release: Acceptance Phase ==='

    Assert-SafeDirectory $lab | Out-Null
    Assert-SafeDirectory $assetsDir | Out-Null

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
    $expectedStorage = [IO.Path]::GetFullPath((Join-Path $env:PROGRAMDATA 'POS System'))
    if ($owned.storage -ne $expectedStorage) {
        throw "Owned lab storage path mismatch BEFORE execution: expected $expectedStorage, got $($owned.storage)"
    }

    Write-Host "Executing signed beta repair and bidirectional channel acceptance..."
    node scripts/verify-setup11-beta-repair.mjs acceptance "$appDir" "$evidence" "$assetsDir"
    if ($LASTEXITCODE -ne 0) {
        throw 'Setup 0.0.11 beta repair acceptance execution failed.'
    }

    $acceptanceArtifact = Join-Path $assetsDir 'setup11-beta-repair-acceptance.json'
    if (-not (Test-Path -LiteralPath $acceptanceArtifact)) {
        throw "Acceptance phase finished without generating release asset: $acceptanceArtifact"
    }
    $evidenceArtifact = Join-Path $evidence 'setup11-beta-repair-acceptance.json'
    if (-not (Test-Path -LiteralPath $evidenceArtifact)) {
        throw "Acceptance phase finished without generating evidence artifact: $evidenceArtifact"
    }

    $acc = Get-Content -LiteralPath $acceptanceArtifact -Raw | ConvertFrom-Json
    if ($acc.ok -isnot [bool] -or $acc.ok -ne $true) {
        throw "Acceptance artifact reports ok is not boolean true"
    }

    Write-Host 'Setup 0.0.11 beta release acceptance verification succeeded.'
}
