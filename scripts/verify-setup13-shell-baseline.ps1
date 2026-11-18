[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:GITHUB_EVENT_NAME -cne 'push' -or
    $env:GITHUB_REF -cne 'refs/heads/codex/setup13-shell-delta-acceptance') {
    throw 'This probe runs only on the dedicated Windows Actions branch.'
}

$runner = Get-Item -LiteralPath $env:RUNNER_TEMP
if (-not $runner.PSIsContainer -or ($runner.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    throw 'Expected a regular Actions runner temp directory.'
}
$lab = Join-Path $runner.FullName 'setup13-shell'
New-Item -ItemType Directory -Path $lab | Out-Null
$evidence = Join-Path $lab 'evidence'
New-Item -ItemType Directory -Path $evidence | Out-Null
$seven = (Get-Command 7z.exe -ErrorAction Stop).Source
$headers = @{
    'Accept' = 'application/vnd.github+json'
    'X-GitHub-Api-Version' = '2022-11-28'
}

$repo = 'ABDO-TECK/pos-desktop'
$tag = 'v0.0.13'
$sourceCommit = 'ee5bf691c4acf6ffcd7730db1d7d0ed24f274787'
$tagRef = Invoke-RestMethod -Uri "https://api.github.com/repos/$repo/git/ref/tags/$tag" -Headers $headers -TimeoutSec 40
if ($tagRef.object.type -cne 'commit' -or $tagRef.object.sha -cne $sourceCommit) {
    throw 'Setup tag does not reference the expected source commit.'
}
$release = Invoke-RestMethod -Uri "https://api.github.com/repos/$repo/releases/tags/$tag" -Headers $headers -TimeoutSec 40
if ($release.draft -or $release.prerelease -or $release.tag_name -cne $tag) {
    throw 'Expected the published stable Setup 13 release.'
}

function Save-PinnedAsset {
    param(
        [Parameter(Mandatory)] [long] $Id,
        [Parameter(Mandatory)] [string] $Name,
        [Parameter(Mandatory)] [long] $Size,
        [Parameter(Mandatory)] [string] $Sha256
    )
    $assets = @($release.assets | Where-Object { $_.name -ceq $Name })
    $url = "https://github.com/$repo/releases/download/$tag/$Name"
    if ($assets.Count -ne 1 -or $assets[0].id -ne $Id -or $assets[0].size -ne $Size -or
        $assets[0].digest -cne "sha256:$Sha256" -or $assets[0].browser_download_url -cne $url) {
        throw "Release metadata does not match the pinned asset $Name."
    }

    $path = Join-Path $lab $Name
    Invoke-WebRequest -Uri $url -OutFile $path -TimeoutSec 600 -MaximumRetryCount 2 -RetryIntervalSec 3
    if ((Get-Item -LiteralPath $path).Length -ne $Size -or
        (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $Sha256) {
        throw "Downloaded bytes do not match the pinned asset $Name."
    }
    return $path
}

$setupName = 'POS-Desktop-Setup-0.0.13.exe'
$setupSha256 = '442f110ee7f188f2cb9d1a9b0b1859074e7b953bfb2230cb86449a64ee09c62e'
$setupSize = 322568909
$setupId = 689979269
$inventoryName = 'deployment-inventory.json'
$inventorySha256 = '4f3b41f28a6bf4ede11487880c46d6bd9048bc1bc1d4c511426cb9e7e08e1fb4'
$checksums = Save-PinnedAsset -Id 689979692 -Name 'SHA256SUMS.txt' -Size 368 -Sha256 '5781353dc7bdf9f2e1df42904da08dc4804bc41f24bd8dde5caddce1535fa12c'
if (@(Get-Content -LiteralPath $checksums | Where-Object { $_ -ceq "$setupSha256  $setupName" }).Count -ne 1 -or
    @(Get-Content -LiteralPath $checksums | Where-Object { $_ -ceq "$inventorySha256  $inventoryName" }).Count -ne 1) {
    throw 'Published SHA256SUMS.txt does not bind the installer and deployment inventory.'
}

$inventory = Save-PinnedAsset -Id 689979744 -Name $inventoryName -Size 11202 -Sha256 $inventorySha256
$installer = Save-PinnedAsset -Id $setupId -Name $setupName -Size $setupSize -Sha256 $setupSha256
$extract = Join-Path $lab 'archive'
$source = Join-Path $lab 'source'
& $seven e $installer '$PLUGINSDIR\app-64.7z' "-o$extract" -y
if ($LASTEXITCODE -ne 0) { throw 'Could not extract the exact x64 application archive from Setup.' }
$archive = Get-Item -LiteralPath (Join-Path $extract 'app-64.7z')
& $seven x $archive.FullName "-o$source" -y
if ($LASTEXITCODE -ne 0) { throw 'Could not unpack the verified Setup application archive.' }

[ordered]@{
    scope = 'Verified NSIS x64 application archive, extracted without running Setup'
    release = $release.html_url
    tag_commit = $sourceCommit
    setup_asset_id = $setupId
    setup_sha256 = $setupSha256
    setup_size = $setupSize
    published_inventory_sha256 = $inventorySha256
    payload_sha256 = (Get-FileHash -LiteralPath $archive.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    payload_size = $archive.Length
} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $evidence 'setup-source.json') -Encoding utf8NoBOM
