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

# The redistributable is embedded alongside the application archive by NSIS.
# Verify it against the project pin before reusing it in the unpublished Setup.
$seven = (Get-Command 7z.exe -ErrorAction Stop).Source
$prerequisites = [IO.Path]::GetFullPath((Join-Path $PWD 'build/prerequisites'))
$runtimeManifest = Get-Content -LiteralPath 'scripts/desktop-runtime.json' -Raw | ConvertFrom-Json
if ($runtimeManifest.vcredist.archiveName -cne 'vc_redist.x64.exe') { throw 'Unexpected redistributable archive name.' }
$sourceSetup = Join-Path $lab 'POS-Desktop-Setup-0.0.13.exe'
& $seven e $sourceSetup '$PLUGINSDIR\vc_redist.x64.exe' "-o$prerequisites" -y
if ($LASTEXITCODE) { throw 'Could not extract the pinned prerequisite from the verified Setup.' }
$redistributable = Join-Path $prerequisites $runtimeManifest.vcredist.archiveName
$redistHash = (Get-FileHash -LiteralPath $redistributable -Algorithm SHA256).Hash.ToLowerInvariant()
if ($redistHash -cne $runtimeManifest.vcredist.sha256.ToLowerInvariant()) {
    throw 'Extracted Visual C++ redistributable does not match the project pin.'
}

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
$builderArgs = @('--win', '--x64', '--publish', 'never',
    "-c.electronVersion=$runtimeVersion", "-c.electronDist=$dist", "-c.directories.output=$out")
& ./node_modules/.bin/electron-builder.cmd @builderArgs
if ($LASTEXITCODE) { throw 'Could not build the unpublished NSIS test candidate.' }
$installer = Get-Item -LiteralPath (Join-Path $out 'POS-Desktop-Setup-0.0.14.exe')
$extract = Join-Path $lab 'target-archive'
$target = Join-Path $lab 'target'
& $seven e $installer.FullName '$PLUGINSDIR\app-64.7z' "-o$extract" -y
if ($LASTEXITCODE) { throw 'Could not extract the exact x64 candidate application archive.' }
$payload = Get-Item -LiteralPath (Join-Path $extract 'app-64.7z')
& $seven x $payload.FullName "-o$target" -y
if ($LASTEXITCODE) { throw 'Could not unpack the candidate application archive.' }
if ((Get-Item -LiteralPath (Join-Path $target 'POS Desktop.exe')).VersionInfo.ProductVersion -cne '0.0.14') {
    throw 'Packaged Windows executable version is inconsistent with the test version.'
}

$asar = Join-Path $target 'resources/app.asar'
$packageVersion = node -e "const asar = require('@electron/asar'); console.log(JSON.parse(asar.extractFile(process.argv[1], 'package.json')).version)" $asar
if ($LASTEXITCODE -or $packageVersion -cne '0.0.14') {
    throw 'Packaged app.asar version is inconsistent with the test version.'
}

[ordered]@{
    checkout_commit = $env:GITHUB_SHA
    redistributable_sha256 = $redistHash
    asar_package_version = $packageVersion
    scope = 'Unpublished NSIS x64 application archive built from the checkout and extracted on this runner'
    candidate_installer_sha256 = (Get-FileHash -LiteralPath $installer.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    candidate_installer_size = $installer.Length
    payload_sha256 = (Get-FileHash -LiteralPath $payload.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    payload_size = $payload.Length
    application_version = $version.version
    frontend_build_marker = 'pos-shell-ci-candidate'
    electron_runtime = "v$runtimeVersion"
    runtime_archive_asset_id = $archiveId
    runtime_archive_url = "$release/$archiveName"
    runtime_archive_sha256 = $archiveSha256
    runtime_archive_size = $archiveSize
} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $lab 'evidence/candidate.json') -Encoding utf8NoBOM
