[CmdletBinding()]
param(
    [Parameter(Mandatory, Position = 0)] [ValidateSet('rollback', 'commit')] [string] $Scenario
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Run the installed UI proof only on GitHub-hosted Windows Actions.'
}

$lab = Join-Path $env:RUNNER_TEMP 'shell-inapp'
$installed = Join-Path $lab 'installed'
$setup = Join-Path $lab 'source/POS-Desktop-Setup-0.0.13.exe'
if ($Scenario -ceq 'rollback') {
    if (Test-Path -LiteralPath $installed) { throw 'Expected a clean initial install.' }
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
    if ($registration.DisplayVersion -cne '0.0.13' -or $registration.UninstallString -ine $expectedCommand) {
        throw 'Expected Setup to register a real per-user install.'
    }
    [ordered]@{ setup_sha256 = $expected.setup_sha256; version = $registration.DisplayVersion; scope = 'current-user' } |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $lab 'evidence/install.json') -Encoding utf8NoBOM
} elseif (-not (Test-Path -LiteralPath (Join-Path $lab 'evidence/rollback.json'))) {
    throw 'The automatic rollback proof must finish before starting the healthy update.'
}

node scripts/verify-shell-inapp-acceptance.mjs $Scenario
