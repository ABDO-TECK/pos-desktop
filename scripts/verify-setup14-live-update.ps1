[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Run the public Setup acceptance only on GitHub-hosted Windows Actions.'
}

$lab = Join-Path $env:RUNNER_TEMP 'p14-live'
$evidence = Join-Path $lab 'evidence'
$install = Join-Path $lab 'app'
$registration = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\90a2ff53-faef-56f7-9850-17442841706a'
if ((Test-Path -LiteralPath $lab) -or (Test-Path -LiteralPath $registration) -or
    (Test-Path -LiteralPath (Join-Path $env:PROGRAMDATA 'POS System')) -or
    (Test-Path -LiteralPath (Join-Path $env:APPDATA 'POS System'))) {
    throw 'Expected a clean Windows runner without product state.'
}
New-Item -ItemType Directory -Path $evidence | Out-Null
$env:POS_LIVE_PROOF_ROOT = $lab

$repo = 'ABDO-TECK/pos-desktop'
$tag = 'v0.0.14'
$approvedCommit = 'cee698f8b5d2cb8577a485f9a07330184986e9a5'
$approvedSetupSha256 = 'd496b8224159cc29224b761334b57e64a699d380568a1fa408bf3958fb8fdc50'
$approvedSetupSize = 322747384
$repoApi = "https://api.github.com/repos/$repo"
$headers = @{ Accept = 'application/vnd.github+json'; 'X-GitHub-Api-Version' = '2022-11-28' }
$release = Invoke-RestMethod -Uri "$repoApi/releases/tags/$tag" -Headers $headers -TimeoutSec 30
if ($release.tag_name -cne $tag -or $release.draft -or $release.prerelease -or
    -not $release.published_at -or $release.html_url -cne "https://github.com/$repo/releases/tag/$tag") {
    throw 'Expected the published stable Setup 0.0.14 release.'
}
$tagObject = (Invoke-RestMethod -Uri "$repoApi/git/ref/tags/$tag" -Headers $headers -TimeoutSec 30).object
if ($tagObject.type -ceq 'tag') {
    $tagObject = (Invoke-RestMethod -Uri "$repoApi/git/tags/$($tagObject.sha)" -Headers $headers -TimeoutSec 30).object
}
if ($tagObject.type -cne 'commit' -or $tagObject.sha -cnotmatch '^[a-f0-9]{40}$') {
    throw 'The public Setup tag does not resolve to a Git commit.'
}
if ($tagObject.sha -cne $approvedCommit) {
    throw 'The public Setup tag does not match the approved commit.'
}

function Save-ReleaseAsset {
    param([string] $Name, [string] $ExpectedSha256, [long] $ExpectedSize)
    $assets = @($release.assets | Where-Object { $_.name -ceq $Name })
    $url = "https://github.com/$repo/releases/download/$tag/$Name"
    if ($assets.Count -ne 1 -or $assets[0].browser_download_url -cne $url -or
        $assets[0].digest -cnotmatch '^sha256:[a-f0-9]{64}$' -or $assets[0].size -le 0) {
        throw "The public release is missing valid asset metadata for $Name."
    }
    $asset = $assets[0]
    if ($ExpectedSha256 -and ($asset.digest -cne "sha256:$ExpectedSha256" -or $asset.size -ne $ExpectedSize)) {
        throw "Published asset $Name differs from the approved build."
    }
    $file = Join-Path $lab $Name
    Invoke-WebRequest -Uri $url -OutFile $file -TimeoutSec 600
    $digest = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($asset.digest -cne "sha256:$digest" -or $asset.size -ne (Get-Item -LiteralPath $file).Length) {
        throw "Downloaded bytes do not match GitHub metadata for $Name."
    }
    [ordered]@{ name = $Name; url = $url; id = $asset.id; sha256 = $digest; size = $asset.size; file = $file }
}

$checksums = Save-ReleaseAsset 'SHA256SUMS.txt'
$setup = Save-ReleaseAsset 'POS-Desktop-Setup-0.0.14.exe' $approvedSetupSha256 $approvedSetupSize
$setupChecksum = '^' + $setup.sha256 + '\s+\*?' + [regex]::Escape($setup.name) + '$'
if (@(Get-Content -LiteralPath $checksums.file | Where-Object { $_.Trim() -cmatch $setupChecksum }).Count -ne 1) {
    throw 'The public SHA256SUMS file does not bind this Setup executable.'
}
[ordered]@{
    release = $release.html_url
    release_id = $release.id
    tag_commit = $tagObject.sha
    setup_asset_id = $setup.id
    setup_sha256 = $setup.sha256
    setup_size = $setup.size
    checksum_asset_sha256 = $checksums.sha256
    authenticode_status = (Get-AuthenticodeSignature -LiteralPath $setup.file).Status.ToString()
} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $evidence 'public-setup.json') -Encoding utf8NoBOM

$process = Start-Process -FilePath $setup.file -ArgumentList @('/S', '/currentuser', "/D=$install") -WindowStyle Hidden -PassThru
try {
    if (-not $process.WaitForExit(180000)) {
        $process.Kill($true)
        throw 'Public Setup installation timed out.'
    }
    if ($process.ExitCode -ne 0) { throw "Public Setup exited with code $($process.ExitCode)." }
} finally {
    $process.Dispose()
}
$installed = Get-ItemProperty -LiteralPath $registration
$expectedCommand = '"{0}" /currentuser' -f (Join-Path $install 'Uninstall POS Desktop.exe')
if ($installed.DisplayVersion -cne '0.0.14' -or $installed.UninstallString -ine $expectedCommand) {
    throw 'Expected Windows registration for the newly installed per-user Setup 0.0.14.'
}
[ordered]@{ setup_sha256 = $setup.sha256; version = $installed.DisplayVersion; scope = 'current-user' } |
    ConvertTo-Json | Set-Content -LiteralPath (Join-Path $evidence 'install.json') -Encoding utf8NoBOM

try {
    node scripts/verify-setup14-live-update.mjs
} finally {
    # A failure before CDP connected may leave a packaged process running.
    $boundary = [IO.Path]::GetFullPath($install).TrimEnd('\') + '\'
    foreach ($remaining in Get-CimInstance Win32_Process) {
        if (-not $remaining.ExecutablePath -or -not $remaining.ExecutablePath.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) {
            continue
        }
        $current = Get-Process -Id $remaining.ProcessId -ErrorAction SilentlyContinue
        if ($current -and $current.Path -ieq $remaining.ExecutablePath) {
            Stop-Process -InputObject $current -ErrorAction SilentlyContinue
        }
    }
}
