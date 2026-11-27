[CmdletBinding()]
param(
    [Parameter(Mandatory)] [ValidateSet('staged', 'live')] [string] $Mode,
    [Parameter(Mandatory)] [string] $ReleaseAssets
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Run the installed beta proof only on GitHub-hosted Windows Actions.'
}
if ($env:UPDATE_PRIVATE_KEY -or $env:SOURCE_PRIVATE_SIGNING_KEY) {
    throw 'The installed beta proof does not need access to signing keys.'
}

$baseline = Join-Path $env:RUNNER_TEMP 'setup14-shell'
$setup = Join-Path $baseline 'POS-Desktop-Setup-0.0.14.exe'
if (-not (Test-Path -LiteralPath $setup)) {
    & "$PSScriptRoot/verify-setup13-shell-baseline.ps1" -BinaryAcceptance
}
$setupHash = (Get-FileHash -LiteralPath $setup).Hash.ToLowerInvariant()
if ($setupHash -cne 'd496b8224159cc29224b761334b57e64a699d380568a1fa408bf3958fb8fdc50') {
    throw 'The .14 installer differs from the published Windows Setup.'
}

$assets = (Resolve-Path -LiteralPath $ReleaseAssets).Path
$lab = Join-Path $env:RUNNER_TEMP "beta15-channel-$Mode"
$installed = Join-Path $lab 'installed'
New-Item -ItemType Directory -Path $lab | Out-Null

$process = Start-Process -FilePath $setup -ArgumentList @('/S', '/currentuser', "/D=$installed") -PassThru -WindowStyle Hidden
try {
    if (-not $process.WaitForExit(180000)) {
        $process.Kill($true)
        throw 'The official per-user Setup timed out.'
    }
    if ($process.ExitCode -ne 0) { throw "Setup exited with code $($process.ExitCode)." }
} finally {
    $process.Dispose()
}

$registration = Get-ItemProperty -LiteralPath 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\90a2ff53-faef-56f7-9850-17442841706a'
$expectedCommand = '"{0}" /currentuser' -f (Join-Path $installed 'Uninstall POS Desktop.exe')
if ($registration.DisplayVersion -cne '0.0.14' -or $registration.UninstallString -ine $expectedCommand) {
    throw 'The official Setup did not register the expected isolated per-user installation.'
}

try {
    node "$PSScriptRoot/verify-beta15-channel-ui.mjs" $Mode $lab $baseline $assets
} finally {
    $boundary = [IO.Path]::GetFullPath($installed).TrimEnd('\') + '\'
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
