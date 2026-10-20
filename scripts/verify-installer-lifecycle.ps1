[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) { throw 'Installer execution is restricted to an ephemeral GitHub Actions runner' }
$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$installer = Join-Path $repo 'dist-electron/POS-Desktop-Setup-0.0.1.exe'
$install = Join-Path $env:RUNNER_TEMP 'POS Installer Acceptance'
$evidence = Join-Path $env:RUNNER_TEMP 'pos-installer-evidence'
New-Item -ItemType Directory -Path $evidence -Force | Out-Null
. (Join-Path $repo 'build/uninstall-data.ps1')
$targets = @(Get-PosCleanupTargets -Roaming $env:APPDATA -Local $env:LOCALAPPDATA -Shared $env:PROGRAMDATA)
foreach ($target in $targets) { if (Test-Path -LiteralPath $target) { throw "Runner contains pre-existing POS data: $target" } }
function Install-Pos {
    $process = Start-Process -FilePath $installer -ArgumentList @('/S','/currentuser',"/D=$install") -WindowStyle Hidden -PassThru -Wait
    if ($process.ExitCode -ne 0) { throw "Setup failed: $($process.ExitCode)" }
    if (-not (Test-Path -LiteralPath (Join-Path $install 'POS Desktop.exe'))) { throw 'Installed executable missing' }
}
function Test-InstalledUi {
    param([string]$Name, [string]$Expectation, [int]$Port)
    $process = Start-Process -FilePath (Join-Path $install 'POS Desktop.exe') -WorkingDirectory $install -ArgumentList "--remote-debugging-port=$Port" -WindowStyle Hidden -RedirectStandardOutput (Join-Path $evidence "$Name.stdout.log") -RedirectStandardError (Join-Path $evidence "$Name.stderr.log") -PassThru
    & node (Join-Path $repo 'scripts/verify-first-run-ui.mjs') $Port $Expectation (Join-Path $evidence "$Name.json")
    if ($LASTEXITCODE) { throw "Installed UI acceptance failed: $Name" }
    if (-not $process.WaitForExit(30000)) { throw 'Application did not shut down after UI acceptance' }
    $deadline = (Get-Date).AddSeconds(30)
    do {
        $children = @(Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -and $_.ExecutablePath.StartsWith($install + '\', [StringComparison]::OrdinalIgnoreCase) })
        if (-not $children.Count) { break }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)
    if ($children.Count) { throw 'Owned runtime processes survived normal application shutdown' }
}
function Uninstall-Pos {
    $uninstaller = Join-Path $install 'Uninstall POS Desktop.exe'
    $process = Start-Process -FilePath $uninstaller -ArgumentList @('/S') -WindowStyle Hidden -PassThru -Wait
    if ($process.ExitCode -ne 0) { throw "Uninstall failed: $($process.ExitCode)" }
    $deadline = (Get-Date).AddSeconds(45)
    do {
        $left = @($targets | Where-Object { Test-Path -LiteralPath $_ })
        if (-not $left.Count -and -not (Test-Path -LiteralPath (Join-Path $install 'POS Desktop.exe'))) { break }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)
    if ($left.Count) { throw "Uninstall retained managed data: $($left -join ', ')" }
    if (Test-Path -LiteralPath (Join-Path $install 'POS Desktop.exe')) { throw 'Uninstall retained installed application' }
}
Install-Pos
& node (Join-Path $repo 'scripts/verify-executable-icons.mjs') (Join-Path $install 'POS Desktop.exe') $installer
if ($LASTEXITCODE) { throw 'Executable branding verification failed' }
$shortcut = Join-Path ([Environment]::GetFolderPath('Desktop')) 'POS Desktop.lnk'
if (-not (Test-Path -LiteralPath $shortcut)) { throw 'Desktop shortcut missing' }
$shell = New-Object -ComObject WScript.Shell
$link = $shell.CreateShortcut($shortcut)
if ($link.TargetPath -ne (Join-Path $install 'POS Desktop.exe')) { throw 'Shortcut targets a different application' }
if ($link.IconLocation -and $link.IconLocation -notlike "$install\POS Desktop.exe,*") { throw 'Shortcut uses a different icon resource' }
Test-InstalledUi 'first-install' 'fresh' 19221
$sentinel = Join-Path $env:PROGRAMDATA 'POS System/acceptance-preserve.txt'
Set-Content -LiteralPath $sentinel -Value 'upgrade must retain this file'
$credentials = Join-Path $env:APPDATA 'POS System/db_credentials.json'
$credentialHash = (Get-FileHash -LiteralPath $credentials).Hash
Install-Pos
if ((Get-FileHash -LiteralPath $credentials).Hash -ne $credentialHash -or -not (Test-Path -LiteralPath $sentinel)) { throw 'Upgrade altered persistent configuration/data' }
Test-InstalledUi 'upgrade' 'existing' 19222
Uninstall-Pos
if (Test-Path -LiteralPath $shortcut) { throw 'Uninstall retained the desktop shortcut' }
Install-Pos
Test-InstalledUi 'reinstall' 'fresh' 19223
$first = Get-Content (Join-Path $evidence 'first-install.json') -Raw | ConvertFrom-Json
$second = Get-Content (Join-Path $evidence 'reinstall.json') -Raw | ConvertFrom-Json
if ($first.passwordDigest -eq $second.passwordDigest) { throw 'Reinstall reused the previous temporary password' }
Uninstall-Pos
@{ version='0.0.1'; installedUi=$true; logoEmbedded=$true; shortcutVerified=$true; upgradePreservedData=$true; uninstallRemovedManagedData=$true; reinstallCreatedNewTemporaryPassword=$true } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $evidence 'acceptance.json')
Write-Host 'PASS: actual Setup install, upgrade preservation, complete uninstall, fresh reinstall and temporary password UI'
