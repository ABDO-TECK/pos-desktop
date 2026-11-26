[CmdletBinding()]
param(
    [Parameter(Mandatory)][System.Collections.IDictionary] $Configuration,
    [Parameter(Mandatory)][ValidatePattern('^v\d+\.\d+\.\d+(?:-beta\.\d+)?\z')][string] $Tag,
    [Parameter(Mandatory)][ValidateSet('stable', 'beta')][string] $Channel,
    [Parameter(Mandatory)][string[]] $BaselineTags,
    [Parameter(Mandatory)][string] $ReleaseAssets,
    [switch] $StageOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Stage native shell release assets on GitHub-hosted Windows Actions.'
}
$sourceRepository = 'ABDO-TECK/pos-desktop'
$expectedRepository = if ($StageOnly) { $sourceRepository } else { 'ABDO-TECK/pos-desktop-updates' }
if ($env:GITHUB_REPOSITORY -cne $expectedRepository) {
    throw "This staging mode runs only in $expectedRepository."
}
$privateKey = $env:UPDATE_PRIVATE_KEY
if (($StageOnly -and $privateKey) -or (-not $StageOnly -and -not $privateKey)) {
    throw 'Use the release signing secret for publication builds and an ephemeral key for the stage-only probe.'
}

$keys = @('source_setup_version', 'source_setup_sha256', 'target_setup_version', 'target_setup_sha256')
if ($Configuration.Count -ne $keys.Count) { throw 'Expected exactly four native shell release fields.' }
foreach ($key in $keys) {
    if (!(($Configuration.Keys -ccontains $key) -and ($Configuration[$key] -is [string]))) {
        throw "Missing or invalid native shell release field $key."
    }
    $pattern = if ($key.EndsWith('_sha256')) { '^[a-f0-9]{64}\z' } else { '^\d+\.\d+\.\d+(?:-beta\.\d+)?\z' }
    if ($Configuration[$key] -cnotmatch $pattern) { throw "Invalid native shell release field $key." }
}
$sourceVersion = $Configuration.source_setup_version
$targetVersion = $Configuration.target_setup_version
$versionChannel = if ($targetVersion.Contains('-beta.')) { 'beta' } else { 'stable' }
if ($Tag -cne "v$targetVersion" -or $Channel -cne $versionChannel -or $BaselineTags -cnotcontains "v$sourceVersion" -or
    $sourceVersion.Contains('-beta.') -ne $targetVersion.Contains('-beta.') -or
    [semver]$sourceVersion -ge [semver]$targetVersion) {
    throw 'Native shell options must match the target release and a lower baseline on the same channel.'
}
if (-not $StageOnly -and [semver]$sourceVersion -lt [semver]'0.0.14') {
    throw 'The installed native shell must be Setup 0.0.14 or newer.'
}

function Get-OrdinaryDirectory {
    param([string] $Path)
    $entry = Get-Item -LiteralPath $Path -Force
    if (-not $entry.PSIsContainer -or ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "Expected a regular directory at $Path."
    }
    return $entry.FullName
}

function Save-VerifiedAsset {
    param($Release, [string] $Name, [string] $PinnedSha256)

    $url = "https://github.com/$sourceRepository/releases/download/$($Release.tag_name)/$Name"
    $assets = @($Release.assets | Where-Object { $_.name -ceq $Name })
    if ($assets.Count -ne 1 -or $assets[0].browser_download_url -cne $url -or
        $assets[0].state -cne 'uploaded' -or $assets[0].digest -cnotmatch '^sha256:[a-f0-9]{64}\z' -or $assets[0].size -le 0) {
        throw "Unexpected release metadata for $Name."
    }
    $sha256 = $assets[0].digest.Substring(7)
    if ($PinnedSha256 -and $sha256 -cne $PinnedSha256) { throw "Published digest for $Name does not match the reviewed asset." }
    $path = Join-Path $lab $Name
    Invoke-WebRequest -Uri $url -OutFile $path -TimeoutSec 600 -MaximumRetryCount 2 -RetryIntervalSec 3
    if ((Get-Item -LiteralPath $path).Length -ne $assets[0].size -or
        (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $sha256) {
        throw "Downloaded $Name does not match the published bytes."
    }
    return $path
}

function Expand-VerifiedSetup {
    param([string] $Version, [string] $Sha256, [string] $Role, [string] $ProductPath)

    $releaseTag = "v$Version"
    $name = "POS-Desktop-Setup-$Version.exe"
    $headers = @{ Accept = 'application/vnd.github+json'; 'X-GitHub-Api-Version' = '2022-11-28' }
    $release = Invoke-RestMethod -Uri "https://api.github.com/repos/$sourceRepository/releases/tags/$releaseTag" -TimeoutSec 40 -Headers $headers
    if ($release.draft -or $release.prerelease -ne ($Channel -ceq 'beta') -or $release.tag_name -cne $releaseTag) {
        throw "Expected the published Setup release $releaseTag."
    }
    $installer = Save-VerifiedAsset -Release $release -Name $name -PinnedSha256 $Sha256

    $archiveDir = Join-Path $lab "$Role-archive"
    & $sevenZip e $installer '$PLUGINSDIR\app-64.7z' "-o$archiveDir" -y | Out-Null
    if ($LASTEXITCODE) { throw "Could not extract the Setup $releaseTag application archive." }
    $archivePath = Join-Path $archiveDir 'app-64.7z'
    & $sevenZip x $archivePath "-o$ProductPath" -y | Out-Null
    if ($LASTEXITCODE) { throw "Could not expand the verified Setup $releaseTag application." }
    $ProductPath = Get-OrdinaryDirectory -Path $ProductPath
    if (Get-ChildItem -LiteralPath $ProductPath -Force -Recurse -Attributes ReparsePoint | Select-Object -First 1) {
        throw "Setup $releaseTag contains a reparse point."
    }
    $app = Get-Item -LiteralPath (Join-Path $ProductPath 'POS Desktop.exe')
    $metadata = Get-Content -LiteralPath (Join-Path $ProductPath 'resources/app.asar.unpacked/version.json') -Raw | ConvertFrom-Json
    if ($app.VersionInfo.ProductVersion -cnotmatch ('^{0}(?:\.0)?\z' -f [regex]::Escape($Version)) -or
        $metadata.version -cne $Version -or $metadata.installer_version -cne $Version -or
        $metadata.update_channel -cne $Channel) {
        throw "Extracted product identity does not match Setup $releaseTag."
    }
    Write-Host "$Role Setup $releaseTag SHA-256 verified."
    if ($Role -ceq 'target') { return (Save-VerifiedAsset -Release $release -Name 'deployment-inventory.json') }
}

$destination = Get-OrdinaryDirectory -Path $ReleaseAssets
$runnerTemp = Get-OrdinaryDirectory -Path $env:RUNNER_TEMP
$sevenZip = (Get-Command 7z.exe -ErrorAction Stop).Source
$labName = 'shell-release-' + [guid]::NewGuid().ToString('N')
$lab = Join-Path $runnerTemp $labName
New-Item -ItemType Directory -Path $lab | Out-Null
New-Item -ItemType Directory -Path (Join-Path $lab 'evidence') | Out-Null
$sourceRoot = Join-Path $lab 'source/installed-payload'
$targetRoot = Join-Path $lab 'target/win-unpacked'
$output = Join-Path $lab 'packages'
$keyPath = Join-Path $lab 'signing.pem'

# Database and package tools only need the key file, which is created after measurement.
Remove-Item Env:UPDATE_PRIVATE_KEY -ErrorAction SilentlyContinue
try {
    Expand-VerifiedSetup -Version $sourceVersion -Sha256 $Configuration.source_setup_sha256 -Role source -ProductPath $sourceRoot
    $targetInventory = Expand-VerifiedSetup -Version $targetVersion -Sha256 $Configuration.target_setup_sha256 -Role target -ProductPath $targetRoot
    $phpDir = Join-Path $sourceRoot 'resources/portable/php'
    $php = Join-Path $phpDir 'php.exe'
    $targetPayload = Join-Path $targetRoot 'resources/app.asar.unpacked'
    & $php -n -r 'require $argv[1]; $inventory = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR); exit($inventory["format"] === 1 && $inventory["version"] === $argv[4] && preg_match("/^[a-f0-9]{64}$/D", $inventory["shell_sha256"]) && $inventory["files"] == deploymentFiles($argv[3]) ? 0 : 1);' -- "$PSScriptRoot/deployment-inventory.php" $targetInventory $targetPayload $targetVersion
    if ($LASTEXITCODE) { throw 'Published target deployment inventory does not match the verified Setup payload.' }
    & (Join-Path $PSHOME 'pwsh.exe') -NoProfile -File "$PSScriptRoot/verify-setup13-shell-data.ps1" -Profile release -ReleaseLab $labName
    if ($LASTEXITCODE) { throw 'Could not measure both packaged data contracts.' }

    if ($StageOnly) {
        node -e 'const crypto = require("node:crypto"); const fs = require("node:fs"); const {privateKey} = crypto.generateKeyPairSync("rsa", { modulusLength: 2048 }); fs.writeFileSync(process.argv[1], privateKey.export({ type: "pkcs8", format: "pem" }), { flag: "wx" });' $keyPath
        if ($LASTEXITCODE) { throw 'Could not generate the ephemeral verification key.' }
    } else {
        [IO.File]::WriteAllText($keyPath, $privateKey)
        $sourceTrust = Join-Path $sourceRoot 'resources/app.asar.unpacked/backend/certs/update_public_key.pem'
        $targetTrust = Join-Path $targetRoot 'resources/app.asar.unpacked/backend/certs/update_public_key.pem'
        & $php -n -d "extension_dir=$phpDir/ext" -d extension=openssl -r 'function publicPem($key) { return $key ? openssl_pkey_get_details($key)["key"] : null; } $signer = publicPem(openssl_pkey_get_private(file_get_contents($argv[1]))); foreach (array_slice($argv, 2) as $file) { if (!$signer || $signer !== publicPem(openssl_pkey_get_public(file_get_contents($file)))) { exit(1); } }' -- $keyPath $sourceTrust $targetTrust
        if ($LASTEXITCODE) { throw 'The release signing key does not match the trust bundled with both Setups.' }
    }
    $builderArgs = @("$PSScriptRoot/build-shell-delta.php",
        '--source-root', $sourceRoot, '--target-root', $targetRoot,
        '--source-native-version', $sourceVersion, '--target-native-version', $targetVersion,
        '--source-data-contract', (Join-Path $lab 'source-contract.json'),
        '--target-data-contract', (Join-Path $lab 'target-contract.json'),
        '--private-key', $keyPath, '--output-dir', $output)
    & $php -n -d "extension_dir=$phpDir/ext" -d extension=openssl -d extension=zip @builderArgs
    if ($LASTEXITCODE) { throw 'Signed native shell release generation failed.' }
} finally {
    if (Test-Path -LiteralPath $keyPath) { Remove-Item -LiteralPath $keyPath -Force }
    if ($privateKey) { $env:UPDATE_PRIVATE_KEY = $privateKey }
}

$canonical = @('shell-manifest.json', 'shell-manifest.sig', 'shell-delta.zip')
$optional = @('shell-binary.json', 'shell-binary.signature', 'shell-binary.bin')
$binaryAssets = @($optional | Where-Object { Test-Path -LiteralPath (Join-Path $output $_) })
if ($binaryAssets.Count -ne 0 -and $binaryAssets.Count -ne $optional.Count) {
    throw 'The generated binary transport asset set is incomplete.'
}
$assetsToStage = $canonical + $binaryAssets
foreach ($assetName in $assetsToStage) {
    $file = Get-Item -LiteralPath (Join-Path $output $assetName) -Force
    if ($file.Name -cne $assetName -or $file.PSIsContainer -or $file.Length -le 0 -or
        ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
        (Test-Path -LiteralPath (Join-Path $destination $assetName))) {
        throw "Refusing to stage invalid or duplicate asset $assetName."
    }
}
Copy-Item -LiteralPath $targetInventory -Destination (Join-Path $destination 'deployment-inventory.json')
foreach ($assetName in $assetsToStage) {
    Copy-Item -LiteralPath (Join-Path $output $assetName) -Destination (Join-Path $destination $assetName)
    Write-Host "Staged $assetName"
}
