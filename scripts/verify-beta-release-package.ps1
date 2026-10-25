[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseDir
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Guard: GitHub Actions environment, specific repository, and non-empty RUNNER_TEMP
if ($env:GITHUB_ACTIONS -ne 'true') {
    throw "scripts/verify-beta-release-package.ps1 is restricted to GitHub Actions (GITHUB_ACTIONS != 'true')."
}

if ($env:GITHUB_REPOSITORY -ne 'ABDO-TECK/pos-desktop-updates') {
    throw "scripts/verify-beta-release-package.ps1 is restricted to repository 'ABDO-TECK/pos-desktop-updates' (got '$($env:GITHUB_REPOSITORY)')."
}

if ([string]::IsNullOrWhiteSpace($env:RUNNER_TEMP)) {
    throw 'RUNNER_TEMP environment variable is missing or empty.'
}

if (-not (Test-Path -LiteralPath $env:RUNNER_TEMP -PathType Container)) {
    throw "RUNNER_TEMP directory does not exist: '$($env:RUNNER_TEMP)'."
}

# Scoped release parameters
$baselineVersion = '0.0.11'
$targetVersion = '0.0.12-beta.1'

# Resolve existing sourceDir
if ([string]::IsNullOrWhiteSpace($ReleaseDir)) {
    throw 'ReleaseDir parameter must be specified and non-empty.'
}
if (-not (Test-Path -LiteralPath $ReleaseDir -PathType Container)) {
    throw "Source release directory does not exist or is not a directory: '$ReleaseDir'."
}
$sourceDir = (Resolve-Path -LiteralPath $ReleaseDir).Path

# Require eight ordinary source assets and verify normal files (not reparse points)
$versionedDeltaName = "delta-$baselineVersion-to-$targetVersion.zip"
$canonicalManifestName = "manifest-$baselineVersion.json"
$canonicalSigName = "manifest-$baselineVersion.sig"

$requiredEightAssets = @(
    'manifest.json',
    'manifest.sig',
    $versionedDeltaName,
    'delta.zip',
    'release-notes.md',
    'deployment-inventory.json',
    $canonicalManifestName,
    $canonicalSigName
)

foreach ($assetName in $requiredEightAssets) {
    $assetPath = Join-Path $sourceDir $assetName
    if (-not (Test-Path -LiteralPath $assetPath -PathType Leaf)) {
        throw "Required ordinary source asset missing: '$assetName' in '$sourceDir'."
    }
    $item = Get-Item -LiteralPath $assetPath -Force
    if (-not ($item -is [System.IO.FileInfo])) {
        throw "Source asset is not a regular file: '$assetName'."
    }
    if ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
        throw "Source asset cannot be a reparse point: '$assetName'."
    }
}

# Assert canonical manifest and signature are identical to generic aliases
$canonicalManifestPath = Join-Path $sourceDir $canonicalManifestName
$genericManifestPath = Join-Path $sourceDir 'manifest.json'
$canonicalManifestHash = (Get-FileHash -LiteralPath $canonicalManifestPath -Algorithm SHA256).Hash
$genericManifestHash = (Get-FileHash -LiteralPath $genericManifestPath -Algorithm SHA256).Hash
if ($canonicalManifestHash -ne $genericManifestHash) {
    throw "Canonical manifest ($canonicalManifestName) SHA256 ($canonicalManifestHash) does not match generic manifest.json ($genericManifestHash)."
}

$canonicalSigPath = Join-Path $sourceDir $canonicalSigName
$genericSigPath = Join-Path $sourceDir 'manifest.sig'
$canonicalSigHash = (Get-FileHash -LiteralPath $canonicalSigPath -Algorithm SHA256).Hash
$genericSigHash = (Get-FileHash -LiteralPath $genericSigPath -Algorithm SHA256).Hash
if ($canonicalSigHash -ne $genericSigHash) {
    throw "Canonical signature ($canonicalSigName) SHA256 ($canonicalSigHash) does not match generic manifest.sig ($genericSigHash)."
}

# Create NEW unique ordinary-view directory under RUNNER_TEMP (GUID/fresh, refuse existing; NO delete/overwrite)
$viewGuid = [System.Guid]::NewGuid().ToString('D')
$viewDir = Join-Path $env:RUNNER_TEMP "pos-beta-ordinary-view-$viewGuid"

if (Test-Path -LiteralPath $viewDir) {
    throw "Ordinary view directory collision (already exists): '$viewDir'."
}

[void](New-Item -ItemType Directory -Path $viewDir)

# Copy ONLY 6 required ordinary files to view unchanged (public key stays pinned repository default)
$sixOrdinaryFiles = @(
    'manifest.json',
    'manifest.sig',
    $versionedDeltaName,
    'delta.zip',
    'release-notes.md',
    'deployment-inventory.json'
)

foreach ($fileName in $sixOrdinaryFiles) {
    $srcPath = Join-Path $sourceDir $fileName
    $dstPath = Join-Path $viewDir $fileName
    Copy-Item -LiteralPath $srcPath -Destination $dstPath
}

$viewEntries = Get-ChildItem -LiteralPath $viewDir -File
if ($viewEntries.Count -ne 6) {
    throw "Ordinary view directory must contain exactly 6 files; found $($viewEntries.Count)."
}

# Call EXISTING strict verify-release-package.php on view with target/minimum; propagate error
$verifierScript = Join-Path $PSScriptRoot 'verify-release-package.php'
if (-not (Test-Path -LiteralPath $verifierScript -PathType Leaf)) {
    throw "Strict verifier script missing at '$verifierScript'."
}

$phpCmd = if (-not [string]::IsNullOrWhiteSpace($env:PHP_BINARY) -and (Test-Path -LiteralPath $env:PHP_BINARY)) {
    $env:PHP_BINARY
} else {
    'php'
}

Write-Host "Verifying ordinary release package view via scripts/verify-release-package.php..."
& $phpCmd $verifierScript "--release-dir=$viewDir" "--target-version=$targetVersion" "--minimum-version=$baselineVersion"
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
