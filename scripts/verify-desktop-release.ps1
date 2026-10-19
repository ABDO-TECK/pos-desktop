[CmdletBinding()]
param(
    [string] $ReleaseDirectory = 'dist-electron',
    [string] $Tag,
    [switch] $RequireAuthenticode,
    [string] $ExpectedPublisher
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-LatestValue {
    param([string] $Content, [string] $Name)
    $match = [regex]::Match($Content, "(?m)^\s*$([regex]::Escape($Name)):\s*'?([^'`r`n]+)'?\s*$")
    if (-not $match.Success) { throw "latest.yml is missing '$Name'." }
    return $match.Groups[1].Value.Trim()
}

function Assert-Authenticode {
    param([string] $Path, [string] $Publisher)
    $signature = Get-AuthenticodeSignature -FilePath $Path
    if ($signature.Status -ne 'Valid') { throw "Authenticode verification failed for ${Path}: $($signature.Status)" }
    if (-not $signature.SignerCertificate) { throw "Authenticode signer certificate is missing for $Path." }
    if ($Publisher -and $signature.SignerCertificate.Subject -notlike "*$Publisher*") {
        throw "Authenticode publisher does not match the configured release identity for $Path."
    }
    if (-not $signature.TimeStamperCertificate) { throw "Authenticode timestamp is missing for $Path." }
    $algorithm = [string] $signature.SignerCertificate.SignatureAlgorithm.FriendlyName
    if ($algorithm -notmatch '(?i)sha256') { throw "Authenticode signer is not using a SHA-256 certificate signature algorithm for $Path." }
    Write-Host "Authenticode valid: $(Split-Path $Path -Leaf) ($algorithm, timestamped)"
}

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$releaseRoot = (Resolve-Path $ReleaseDirectory).Path
$package = Get-Content (Join-Path $repoRoot 'package.json') -Raw | ConvertFrom-Json
$version = (Get-Content (Join-Path $repoRoot 'version.json') -Raw | ConvertFrom-Json).version
if (-not $version -or $package.version -ne $version) { throw 'package.json and version.json must contain the same release version.' }
if ($Tag) {
    $tagVersion = $Tag -replace '^v', '' -replace '-.*$', ''
    if ($tagVersion -ne $version) { throw "Tag '$Tag' does not match version '$version'." }
}

$installerName = "POS-Desktop-Setup-$version.exe"
$installerPath = Join-Path $releaseRoot $installerName
$latestPath = Join-Path $releaseRoot 'latest.yml'
if (-not (Test-Path -LiteralPath $installerPath)) { throw "Installer is missing: $installerPath" }
if (-not (Test-Path -LiteralPath $latestPath)) { throw "latest.yml is missing: $latestPath" }
if (-not (Test-Path -LiteralPath (Join-Path $repoRoot 'backend/backend.phar'))) { throw 'backend/backend.phar is missing.' }

$latest = Get-Content $latestPath -Raw
$latestVersion = Get-LatestValue $latest 'version'
$latestPathValue = Get-LatestValue $latest 'path'
$latestSize = [int64] (Get-LatestValue $latest 'size')
$latestHash = Get-LatestValue $latest 'sha512'
if ($latestVersion -ne $version -or $latestPathValue -ne $installerName) { throw 'latest.yml version or installer path does not match the release contract.' }
if ((Get-Item -LiteralPath $installerPath).Length -ne $latestSize) { throw 'latest.yml installer size does not match final installer bytes.' }
$stream = [IO.File]::OpenRead($installerPath)
$sha512 = [Security.Cryptography.SHA512]::Create()
try { $actualHash = [Convert]::ToBase64String($sha512.ComputeHash($stream)) } finally { $sha512.Dispose(); $stream.Dispose() }
if ($actualHash -ne $latestHash) { throw 'latest.yml SHA-512 does not match final installer bytes.' }

$appExecutable = Join-Path $releaseRoot 'win-unpacked/POS Desktop.exe'
if ($RequireAuthenticode) {
    if (-not $ExpectedPublisher) { throw 'ExpectedPublisher is required when Authenticode is required.' }
    Assert-Authenticode $installerPath $ExpectedPublisher
    if (-not (Test-Path -LiteralPath $appExecutable)) { throw "Packaged application executable is missing: $appExecutable" }
    Assert-Authenticode $appExecutable $ExpectedPublisher
}

Write-Host "Release metadata valid for v${version}: $installerName"
