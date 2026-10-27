[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseDir,
    [switch]$RequireChecksums
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Guard: GitHub Actions environment, specific repository, and non-empty RUNNER_TEMP
if ($env:GITHUB_ACTIONS -ne 'true') {
    throw "scripts/verify-stable12-release.ps1 is restricted to GitHub Actions (GITHUB_ACTIONS != 'true')."
}

if ($env:GITHUB_REPOSITORY -ne 'ABDO-TECK/pos-desktop-updates') {
    throw "scripts/verify-stable12-release.ps1 is restricted to repository 'ABDO-TECK/pos-desktop-updates' (got '$($env:GITHUB_REPOSITORY)')."
}

if ([string]::IsNullOrWhiteSpace($env:RUNNER_TEMP)) {
    throw 'RUNNER_TEMP environment variable is missing or empty.'
}

if (-not (Test-Path -LiteralPath $env:RUNNER_TEMP -PathType Container)) {
    throw "RUNNER_TEMP directory does not exist: '$($env:RUNNER_TEMP)'."
}

# Resolve existing sourceDir
if ([string]::IsNullOrWhiteSpace($ReleaseDir)) {
    throw 'ReleaseDir parameter must be specified and non-empty.'
}
if (-not (Test-Path -LiteralPath $ReleaseDir -PathType Container)) {
    throw "Source release directory does not exist or is not a directory: '$ReleaseDir'."
}
$sourceDir = (Resolve-Path -LiteralPath $ReleaseDir).Path

# Scoped release parameters
$targetVersion = '0.0.12'

# Exact 13 required payload assets
$expectedPayloadAssets = @(
    'manifest-0.0.11.json',
    'manifest-0.0.11.sig',
    'delta-0.0.11-to-0.0.12.zip',
    'manifest-0.0.12-beta.1.json',
    'manifest-0.0.12-beta.1.sig',
    'delta-0.0.12-beta.1-to-0.0.12.zip',
    'manifest.json',
    'manifest.sig',
    'delta.zip',
    'deployment-inventory.json',
    'release-notes.md',
    'source-provenance.json',
    'stable12-delta-acceptance.json'
)

# Case-collision and duplicate detection
$seenNames = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)

# Verify no unexpected directories, reparse points, unlisted files, or case collisions
$diskItems = Get-ChildItem -LiteralPath $sourceDir -Force
foreach ($item in $diskItems) {
    if ($item.PSIsContainer) {
        throw "Subdirectories are forbidden in release directory: '$($item.Name)'."
    }
    if ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
        throw "Source asset cannot be a reparse point / symlink: '$($item.Name)'."
    }
    if (-not ($item -is [System.IO.FileInfo])) {
        throw "Source asset is not a regular file: '$($item.Name)'."
    }
    if (-not $seenNames.Add($item.Name)) {
        throw "Case collision or duplicate entry detected in release directory: '$($item.Name)'."
    }
    if ($item.Name -ne 'SHA256SUMS.txt' -and $expectedPayloadAssets -notcontains $item.Name) {
        throw "Unexpected or unlisted release asset present: '$($item.Name)'."
    }
}

# Verify all mandatory payload assets exist
foreach ($assetName in $expectedPayloadAssets) {
    $assetPath = Join-Path $sourceDir $assetName
    if (-not (Test-Path -LiteralPath $assetPath -PathType Leaf)) {
        throw "Required release payload asset missing: '$assetName' in '$sourceDir'."
    }
}

# Strict SHA256SUMS.txt verification: mandatory in all release directories
$sumsPath = Join-Path $sourceDir 'SHA256SUMS.txt'
$hasSums = Test-Path -LiteralPath $sumsPath -PathType Leaf

if (-not $hasSums) {
    throw "Mandatory SHA256SUMS.txt is missing in release directory '$sourceDir'."
}

$sumsItem = Get-Item -LiteralPath $sumsPath -Force
if ($sumsItem.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
    throw "SHA256SUMS.txt must not be a reparse point or symlink."
}

# Exactly 14 files must exist in release directory
if ($diskItems.Count -ne 14) {
    throw "Release directory must contain exactly 14 files (13 payload + SHA256SUMS.txt); found $($diskItems.Count)."
}

$rawLines = Get-Content -LiteralPath $sumsPath
$seenSumsFiles = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)

foreach ($line in $rawLines) {
    $trimmed = $line.Trim()
    if ([string]::IsNullOrWhiteSpace($trimmed)) {
        throw "Malformed SHA256SUMS.txt: empty line detected."
    }
    if ($trimmed -notmatch '^([0-9a-f]{64})\s\s([A-Za-z0-9._-]+)$') {
        throw "Malformed SHA256SUMS.txt line format: '$line'"
    }
    $expectedHash = $Matches[1].ToLowerInvariant()
    $leafName = $Matches[2]

    if ($leafName -match '[\\/]' -or $leafName -eq '.' -or $leafName -eq '..') {
        throw "Unsafe leaf asset name in SHA256SUMS.txt: '$leafName'"
    }
    if (-not $seenSumsFiles.Add($leafName)) {
        throw "Duplicate entry detected in SHA256SUMS.txt: '$leafName'"
    }
    if ($expectedPayloadAssets -notcontains $leafName) {
        throw "SHA256SUMS.txt contains unexpected file not in payload asset specification: '$leafName'"
    }

    $targetFile = Join-Path $sourceDir $leafName
    $actualHash = (Get-FileHash -LiteralPath $targetFile -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne $expectedHash) {
        throw "Checksum mismatch for '$leafName' in '$sourceDir'! Expected $expectedHash, got $actualHash"
    }
}

# Verify complete checksum coverage over all 13 payload files
foreach ($exp in $expectedPayloadAssets) {
    if (-not $seenSumsFiles.Contains($exp)) {
        throw "Mandatory payload asset '$exp' is not covered by SHA256SUMS.txt!"
    }
}
if ($seenSumsFiles.Count -ne 13) {
    throw "SHA256SUMS.txt must cover exactly 13 payload files, covered $($seenSumsFiles.Count)."
}
Write-Host "PASS: Full SHA256SUMS.txt coverage verified across all 13 payload assets."

# Verify primary aliases byte identity against baseline 0.0.11
$canonicalManifest11 = Join-Path $sourceDir 'manifest-0.0.11.json'
$genericManifest = Join-Path $sourceDir 'manifest.json'
$canonical11MHash = (Get-FileHash -LiteralPath $canonicalManifest11 -Algorithm SHA256).Hash
$genericMHash = (Get-FileHash -LiteralPath $genericManifest -Algorithm SHA256).Hash
if ($canonical11MHash -ne $genericMHash) {
    throw "Primary alias mismatch: manifest.json hash ($genericMHash) != manifest-0.0.11.json hash ($canonical11MHash)."
}

$canonicalSig11 = Join-Path $sourceDir 'manifest-0.0.11.sig'
$genericSig = Join-Path $sourceDir 'manifest.sig'
$canonical11SHash = (Get-FileHash -LiteralPath $canonicalSig11 -Algorithm SHA256).Hash
$genericSHash = (Get-FileHash -LiteralPath $genericSig -Algorithm SHA256).Hash
if ($canonical11SHash -ne $genericSHash) {
    throw "Primary alias mismatch: manifest.sig hash ($genericSHash) != manifest-0.0.11.sig hash ($canonical11SHash)."
}

$canonicalDelta11 = Join-Path $sourceDir 'delta-0.0.11-to-0.0.12.zip'
$genericDelta = Join-Path $sourceDir 'delta.zip'
$canonical11DHash = (Get-FileHash -LiteralPath $canonicalDelta11 -Algorithm SHA256).Hash
$genericDHash = (Get-FileHash -LiteralPath $genericDelta -Algorithm SHA256).Hash
if ($canonical11DHash -ne $genericDHash) {
    throw "Primary alias mismatch: delta.zip hash ($genericDHash) != delta-0.0.11-to-0.0.12.zip hash ($canonical11DHash)."
}

# Strict verifier script location
$verifierScript = Join-Path $PSScriptRoot 'verify-release-package.php'
if (-not (Test-Path -LiteralPath $verifierScript -PathType Leaf)) {
    throw "Strict verifier script missing at '$verifierScript'."
}

$phpCmd = if (-not [string]::IsNullOrWhiteSpace($env:PHP_BINARY) -and (Test-Path -LiteralPath $env:PHP_BINARY)) {
    $env:PHP_BINARY
} else {
    'php'
}

# Baseline definitions for ordinary view verification
$baselines = @(
    @{
        Version = '0.0.11'
        CanonicalManifest = 'manifest-0.0.11.json'
        CanonicalSig = 'manifest-0.0.11.sig'
        CanonicalDelta = 'delta-0.0.11-to-0.0.12.zip'
    },
    @{
        Version = '0.0.12-beta.1'
        CanonicalManifest = 'manifest-0.0.12-beta.1.json'
        CanonicalSig = 'manifest-0.0.12-beta.1.sig'
        CanonicalDelta = 'delta-0.0.12-beta.1-to-0.0.12.zip'
    }
)

foreach ($b in $baselines) {
    $baselineVer = $b.Version
    $viewGuid = [System.Guid]::NewGuid().ToString('D')
    $viewDir = Join-Path $env:RUNNER_TEMP "pos-stable12-view-$baselineVer-$viewGuid"

    if (Test-Path -LiteralPath $viewDir) {
        throw "Ordinary view directory collision (already exists): '$viewDir'."
    }

    [void](New-Item -ItemType Directory -Path $viewDir)

    # 1. Copy canonical manifest -> manifest.json
    Copy-Item -LiteralPath (Join-Path $sourceDir $b.CanonicalManifest) -Destination (Join-Path $viewDir 'manifest.json')

    # 2. Copy canonical sig -> manifest.sig
    Copy-Item -LiteralPath (Join-Path $sourceDir $b.CanonicalSig) -Destination (Join-Path $viewDir 'manifest.sig')

    # 3. Copy baseline chosen ZIP with versioned name
    Copy-Item -LiteralPath (Join-Path $sourceDir $b.CanonicalDelta) -Destination (Join-Path $viewDir $b.CanonicalDelta)

    # 4. Copy baseline chosen ZIP with its SAME bytes alias delta.zip
    # MUST NOT copy primary delta.zip into beta-baseline view!
    Copy-Item -LiteralPath (Join-Path $sourceDir $b.CanonicalDelta) -Destination (Join-Path $viewDir 'delta.zip')

    # 5. Copy release notes
    Copy-Item -LiteralPath (Join-Path $sourceDir 'release-notes.md') -Destination (Join-Path $viewDir 'release-notes.md')

    # 6. Copy deployment inventory
    Copy-Item -LiteralPath (Join-Path $sourceDir 'deployment-inventory.json') -Destination (Join-Path $viewDir 'deployment-inventory.json')

    # Verify view contains exactly 6 files
    $viewEntries = Get-ChildItem -LiteralPath $viewDir -File
    if ($viewEntries.Count -ne 6) {
        throw "Ordinary view directory for baseline '$baselineVer' must contain exactly 6 files; found $($viewEntries.Count)."
    }

    # Verify byte identity between versioned ZIP and delta.zip in the view
    $viewVerHash = (Get-FileHash -LiteralPath (Join-Path $viewDir $b.CanonicalDelta) -Algorithm SHA256).Hash
    $viewAliasHash = (Get-FileHash -LiteralPath (Join-Path $viewDir 'delta.zip') -Algorithm SHA256).Hash
    if ($viewVerHash -ne $viewAliasHash) {
        throw "View delta.zip for baseline '$baselineVer' does not match its versioned delta archive."
    }

    Write-Host "Verifying ordinary release package view for baseline '$baselineVer' via scripts/verify-release-package.php..."
    & $phpCmd $verifierScript "--release-dir=$viewDir" "--target-version=$targetVersion" "--minimum-version=$baselineVer"
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Verification failed for baseline '$baselineVer' with exit code $LASTEXITCODE."
        exit $LASTEXITCODE
    }
}

Write-Host "Both baselines (0.0.11 and 0.0.12-beta.1) verified successfully in isolated ordinary views."
