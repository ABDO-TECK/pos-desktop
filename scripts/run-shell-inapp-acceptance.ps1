[CmdletBinding()]
param(
    [Parameter(Mandatory, Position = 0)] [ValidateSet('rollback', 'commit', 'corrupt', 'format', 'missing', 'older')] [string] $Scenario
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Run the installed UI proof only on GitHub-hosted Windows Actions.'
}

$binary = $env:POS_SHELL_ACCEPTANCE_PROFILE -ceq 'binary'
if (-not $binary -and $Scenario -cnotin @('rollback', 'commit')) {
    throw 'This scenario requires the binary acceptance profile.'
}
$version = if ($binary) { '0.0.14' } else { '0.0.13' }
$lab = Join-Path $env:RUNNER_TEMP $(if ($binary) { 'pos-binary' } else { 'shell-inapp' })
$installed = Join-Path $lab "installed-$Scenario"
$setup = Join-Path $lab "source/POS-Desktop-Setup-$version.exe"
if (Test-Path -LiteralPath $installed) { throw 'Expected a clean installation path for this scenario.' }
$expected = Get-Content -LiteralPath (Join-Path $lab 'evidence/package-source.json') -Raw | ConvertFrom-Json
if ((Get-FileHash -LiteralPath $setup).Hash.ToLowerInvariant() -cne $expected.setup_sha256) {
    throw 'Installer differs from the one used to produce the signed source inventory.'
}

$process = Start-Process -FilePath $setup -ArgumentList @('/S', '/currentuser', "/D=$installed") -PassThru -WindowStyle Hidden
try {
    if (-not $process.WaitForExit(180000)) {
        $process.Kill($true)
        throw 'CI fixture Setup timed out.'
    }
    if ($process.ExitCode -ne 0) { throw "CI fixture Setup failed with exit code $($process.ExitCode)." }
} finally {
    $process.Dispose()
}

$uninstallRegistry = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\90a2ff53-faef-56f7-9850-17442841706a'
$registration = Get-ItemProperty -LiteralPath $uninstallRegistry
$expectedCommand = '"{0}" /currentuser' -f (Join-Path $installed 'Uninstall POS Desktop.exe')
if ($registration.DisplayVersion -cne $version -or $registration.UninstallString -ine $expectedCommand) {
    throw 'Expected Setup to register the new real per-user installation.'
}
[ordered]@{ setup_sha256 = $expected.setup_sha256; version = $registration.DisplayVersion; scope = 'current-user'; scenario = $Scenario } |
    ConvertTo-Json | Set-Content -LiteralPath (Join-Path $lab "evidence/install-$Scenario.json") -Encoding utf8NoBOM

try {
    node scripts/verify-shell-inapp-acceptance.mjs $Scenario
} finally {
    # The UI harness shuts the desktop down after a successful observation.
    # If it could not attach to a relaunched process, stop only images from
    # this disposable installation after the evidence has been recorded.
    $fixtureBoundary = [IO.Path]::GetFullPath($installed).TrimEnd('\') + '\'
    foreach ($remaining in Get-CimInstance Win32_Process) {
        if (-not $remaining.ExecutablePath -or -not $remaining.ExecutablePath.StartsWith($fixtureBoundary, [StringComparison]::OrdinalIgnoreCase)) {
            continue
        }
        $current = Get-Process -Id $remaining.ProcessId -ErrorAction SilentlyContinue
        if ($current -and $current.Path -ieq $remaining.ExecutablePath) {
            Stop-Process -InputObject $current -ErrorAction SilentlyContinue
        }
    }
}
