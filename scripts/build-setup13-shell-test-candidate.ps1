[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Build the test candidate only on GitHub-hosted Windows Actions.'
}
$lab = Join-Path $env:RUNNER_TEMP 'setup13-shell'
$source = Join-Path $lab 'source'
$runtimeVersion = '43.8.11'
$archiveName = "electron-v$runtimeVersion-win32-x64.zip"
$archiveSha256 = '25de7bab4a1b940776b2cc06cb9d2698fec903554188be5977394a0373b75904'
$archiveSize = 151377887
$archiveId = 760039177
$release = "https://github.com/electron/electron/releases/download/v$runtimeVersion"
$archive = Join-Path $lab $archiveName
Invoke-WebRequest -Uri "$release/$archiveName" -OutFile $archive -TimeoutSec 600 -MaximumRetryCount 2 -RetryIntervalSec 3
if ((Get-Item -LiteralPath $archive).Length -ne $archiveSize -or
    (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -cne $archiveSha256) {
    throw 'Electron runtime archive does not match the published Windows x64 checksum.'
}
$dist = Join-Path $lab 'electron'
Expand-Archive -LiteralPath $archive -DestinationPath $dist

# Keep the packaged MariaDB, PHP, Java and printing runtimes byte-for-byte.
# The Setup archive was verified before extraction and the cold source contains no customer data.
if (Test-Path -LiteralPath 'portable') { throw 'Unexpected portable directory before candidate staging.' }
Copy-Item -LiteralPath (Join-Path $source 'resources/portable') -Destination 'portable' -Recurse

# The frontend sources at the branch base match the Setup tag. Build a controlled
# HTML metadata change through Vite so the probe exercises a physical frontend replacement.
$index = Get-Content -LiteralPath 'frontend/index.html' -Raw
$anchor = '<meta charset="UTF-8" />'
if ($index.IndexOf($anchor) -lt 0 -or $index.IndexOf($anchor) -ne $index.LastIndexOf($anchor)) {
    throw 'Could not locate the unique Vite entry document marker.'
}
$index = $index.Replace($anchor, $anchor + ' <meta name="pos-shell-ci-candidate" content="0.0.14" />')
Set-Content -LiteralPath 'frontend/index.html' -Value $index -Encoding utf8NoBOM -NoNewline

$package = Get-Content -LiteralPath 'package.json' -Raw | ConvertFrom-Json -AsHashtable
$version = Get-Content -LiteralPath 'version.json' -Raw | ConvertFrom-Json -AsHashtable
if ($package.version -cne '0.0.13' -or $version.version -cne '0.0.13' -or $version.update_channel -cne 'stable') {
    throw 'Unexpected base package metadata for the isolated 0.0.14 candidate.'
}
$package.version = '0.0.14'
$version.version = '0.0.14'
$version.application_version = '0.0.14'
$version.installer_version = '0.0.14'
$package | ConvertTo-Json -Depth 50 | Set-Content -LiteralPath 'package.json' -Encoding utf8NoBOM
$version | ConvertTo-Json -Depth 50 | Set-Content -LiteralPath 'version.json' -Encoding utf8NoBOM

npm run frontend:build
if ($LASTEXITCODE) { throw 'Candidate frontend build failed.' }
npm run build:phar
if ($LASTEXITCODE) { throw 'Candidate backend PHAR build failed.' }
php scripts/verify-phar-runtime.php
if ($LASTEXITCODE) { throw 'Candidate backend PHAR runtime verification failed.' }

$out = Join-Path $lab 'package'
$builderArgs = @('--win', '--x64', '--dir', '--publish', 'never',
    "-c.electronVersion=$runtimeVersion", "-c.electronDist=$dist", "-c.directories.output=$out")
& ./node_modules/.bin/electron-builder.cmd @builderArgs
if ($LASTEXITCODE) { throw 'Could not build the unpacked Electron test candidate.' }
$target = Join-Path $out 'win-unpacked'

# These two resources come from the NSIS packaging step, so an electron-builder
# directory build does not define their disposition. Keep the Setup 13 bytes.
foreach ($relative in @('resources/app-update.yml', 'resources/elevate.exe')) {
    $destination = Join-Path $target $relative
    Copy-Item -LiteralPath (Join-Path $source $relative) -Destination $destination -Force
}
if ((Get-Item -LiteralPath (Join-Path $target 'POS Desktop.exe')).VersionInfo.ProductVersion -cne '0.0.14') {
    throw 'Packaged Windows executable version is inconsistent with the test version.'
}

[ordered]@{
    checkout_commit = $env:GITHUB_SHA
    scope = 'Unpacked Electron test candidate built from the checkout on this runner'
    application_version = $version.version
    frontend_build_marker = 'pos-shell-ci-candidate'
    electron_runtime = "v$runtimeVersion"
    runtime_archive_asset_id = $archiveId
    runtime_archive_url = "$release/$archiveName"
    runtime_archive_sha256 = $archiveSha256
    runtime_archive_size = $archiveSize
} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $lab 'evidence/candidate.json') -Encoding utf8NoBOM
