[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Run the published Setup only on GitHub-hosted Windows Actions.'
}

. (Join-Path $PSScriptRoot '../build/uninstall-data.ps1')

$lab = Join-Path $env:RUNNER_TEMP 'setup13-shell'
$evidence = Join-Path $lab 'evidence'
$source = Join-Path $lab 'source'
$installed = Join-Path $lab 'installed'
$setup = Join-Path $lab 'POS-Desktop-Setup-0.0.13.exe'
$uninstallerName = 'Uninstall POS Desktop.exe'
$uninstallerSha256 = 'c796be036cc225bc36fd665b7ee0c0215de61fb3f9082204710619b04edeae4b'
$uninstallerSize = 443419
$updaterCache = Join-Path $env:LOCALAPPDATA 'pos-desktop-updater'

function Write-Receipt {
    param([string] $Name, $Data)
    ConvertTo-Json -InputObject $Data -Depth 12 |
        Set-Content -LiteralPath (Join-Path $evidence $Name) -Encoding utf8NoBOM
}

function Get-Sha256 {
    param([string] $Path)
    (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Get-TreeFootprint {
    param([string] $Root)
    Assert-PosTreeSafe $Root
    $rootItem = Get-Item -LiteralPath $Root -Force
    if (-not $rootItem.PSIsContainer) { throw "Not a directory: $Root" }

    $files = [Collections.Generic.SortedDictionary[string, object]]::new([StringComparer]::Ordinal)
    $directories = [Collections.Generic.SortedSet[string]]::new([StringComparer]::Ordinal)
    $unique = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    $pending = [Collections.Generic.Stack[string]]::new()
    $pending.Push($rootItem.FullName)
    while ($pending.Count -gt 0) {
        foreach ($entry in Get-ChildItem -LiteralPath $pending.Pop() -Force) {
            if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw "Reparse point rejected in footprint: $($entry.FullName)"
            }
            $relative = [IO.Path]::GetRelativePath($rootItem.FullName, $entry.FullName).Replace('\', '/')
            if (-not $unique.Add($relative) -or $unique.Count -gt 40000) {
                throw "Duplicate path or footprint bound exceeded: $relative"
            }
            if ($entry.PSIsContainer) {
                [void]$directories.Add($relative)
                $pending.Push($entry.FullName)
            } else {
                $files.Add($relative, [ordered]@{ sha256 = Get-Sha256 $entry.FullName; size = $entry.Length })
            }
        }
    }

    [ordered]@{ files = $files; directories = [string[]]@($directories) }
}

function Get-PosRegistrations {
    $registryRoots = @(
        @{ scope = 'current-user'; path = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall' },
        @{ scope = 'current-user'; path = 'HKCU:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall' },
        @{ scope = 'machine'; path = 'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall' },
        @{ scope = 'machine'; path = 'HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall' }
    )
    foreach ($root in $registryRoots) {
        if (-not (Test-Path -LiteralPath $root.path)) { continue }
        foreach ($key in Get-ChildItem -LiteralPath $root.path) {
            $name = $key.GetValue('DisplayName')
            $location = $key.GetValue('InstallLocation')
            $uninstallCommand = $key.GetValue('UninstallString')
            if ([string]$name -notlike '*POS Desktop*' -and [string]$location -ine $installed -and
                [string]$uninstallCommand -notlike "*$installed*") {
                continue
            }
            [ordered]@{
                scope = $root.scope
                subkey = $key.PSChildName
                display_name = $name
                display_version = $key.GetValue('DisplayVersion')
                install_location = $location
                uninstall_command = $uninstallCommand
            }
        }
    }
}

function Get-InstalledElectronVersion {
    param([string] $Executable)
    $start = [Diagnostics.ProcessStartInfo]::new($Executable)
    $start.WorkingDirectory = $installed
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $start.Environment['ELECTRON_RUN_AS_NODE'] = '1'
    $start.ArgumentList.Add('-p')
    $start.ArgumentList.Add('process.versions.electron')
    $electron = [Diagnostics.Process]::Start($start)
    try {
        $stdout = $electron.StandardOutput.ReadToEndAsync()
        $stderr = $electron.StandardError.ReadToEndAsync()
        if (-not $electron.WaitForExit(15000)) {
            $electron.Kill($true)
            [void]$electron.WaitForExit(10000)
            throw 'Installed Electron version probe timed out.'
        }
        $version = $stdout.GetAwaiter().GetResult().Trim()
        if ($electron.ExitCode -ne 0 -or $version -cne '43.2.0') {
            throw "Installed Electron version probe failed: exit $($electron.ExitCode), version '$version': $($stderr.GetAwaiter().GetResult())"
        }
        $version
    } finally {
        $electron.Dispose()
    }
}

$expected = Get-Content -LiteralPath (Join-Path $evidence 'setup-source.json') -Raw | ConvertFrom-Json
if ((Get-Sha256 $setup) -cne $expected.setup_sha256 -or (Get-Item -LiteralPath $setup).Length -ne $expected.setup_size) {
    throw 'Installer bytes changed between pinned acquisition and installation.'
}
Assert-PosTreeSafe $installed
if ((Test-Path -LiteralPath $installed) -or @(Get-PosRegistrations).Count -ne 0) {
    throw 'Expected a clean runner with no prior POS Desktop install or uninstall registration.'
}

# Seed only exact product-owned data roots on the disposable runner.
$witnesses = @()
$knownDataRoots = @(Get-PosCleanupTargets -Roaming $env:APPDATA -Local $env:LOCALAPPDATA -Shared $env:PROGRAMDATA)
foreach ($root in $knownDataRoots) {
    Assert-PosTreeSafe $root
    if (Test-Path -LiteralPath $root) { throw "Unexpected pre-existing product data root: $root" }
}
foreach ($root in $knownDataRoots) {
    $fixtureDir = Join-Path $root 'setup13-ci-protected'
    New-Item -ItemType Directory -Path $fixtureDir | Out-Null
    foreach ($leaf in @('.env', 'business-state.sqlite', 'device-private.key')) {
        [IO.File]::WriteAllText((Join-Path $fixtureDir $leaf), "synthetic setup13 witness $([guid]::NewGuid())")
    }
    $witnesses += [ordered]@{ root = $root; before = Get-TreeFootprint $root }
}

$archiveFootprint = Get-TreeFootprint $source
Write-Receipt 'extracted-footprint.json' $archiveFootprint

Write-Host "Running immutable Setup 0.0.13 per user into $installed"
$process = Start-Process -FilePath $setup -ArgumentList @('/S', '/currentuser', "/D=$installed") -WindowStyle Hidden -PassThru
try {
    if (-not $process.WaitForExit(180000)) {
        $process.Kill($true)
        [void]$process.WaitForExit(10000)
        throw 'Setup 0.0.13 did not exit in 180 seconds.'
    }
    if ($process.ExitCode -ne 0) { throw "Setup 0.0.13 failed with exit code $($process.ExitCode)." }
} finally {
    $process.Dispose()
}

$installedFootprint = Get-TreeFootprint $installed
Write-Receipt 'installed-footprint.json' $installedFootprint
$missing = [Collections.Generic.List[string]]::new()
$changed = [Collections.Generic.List[object]]::new()
$extra = [Collections.Generic.List[object]]::new()
foreach ($relative in $archiveFootprint.files.Keys) {
    if (-not $installedFootprint.files.ContainsKey($relative)) {
        $missing.Add($relative)
        continue
    }
    $from = $archiveFootprint.files[$relative]
    $to = $installedFootprint.files[$relative]
    if ($from.sha256 -cne $to.sha256 -or $from.size -ne $to.size) {
        $changed.Add([ordered]@{ path = $relative; extracted = $from; installed = $to })
    }
}
foreach ($relative in $installedFootprint.files.Keys) {
    if (-not $archiveFootprint.files.ContainsKey($relative)) {
        $record = $installedFootprint.files[$relative]
        $extra.Add([ordered]@{ path = $relative; sha256 = $record.sha256; size = $record.size })
    }
}
$addedDirectories = @($installedFootprint.directories | Where-Object { $_ -cnotin $archiveFootprint.directories })
$removedDirectories = @($archiveFootprint.directories | Where-Object { $_ -cnotin $installedFootprint.directories })
$protectedResults = @(foreach ($witness in $witnesses) {
    $after = Get-TreeFootprint $witness.root
    $changedWitnesses = @($witness.before.files.Keys | Where-Object {
        -not $after.files.ContainsKey($_) -or
        $after.files[$_].sha256 -cne $witness.before.files[$_].sha256 -or
        $after.files[$_].size -ne $witness.before.files[$_].size
    })
    $addedWitnessFiles = @(foreach ($relative in $after.files.Keys) {
        if (-not $witness.before.files.ContainsKey($relative)) {
            $record = $after.files[$relative]
            [ordered]@{ path = $relative; sha256 = $record.sha256; size = $record.size }
        }
    })
    [ordered]@{
        root = $witness.root
        fixture_file_count = $witness.before.files.Count
        changed_or_removed_fixture_files = $changedWitnesses
        added_files = $addedWitnessFiles
        directories_unchanged = ($after.directories -join '\n') -ceq ($witness.before.directories -join '\n')
    }
})
$registrations = @(Get-PosRegistrations)
$app = Get-Content -LiteralPath (Join-Path $installed 'resources/app.asar.unpacked/version.json') -Raw | ConvertFrom-Json
$exe = Join-Path $installed 'POS Desktop.exe'
$electronVersion = Get-InstalledElectronVersion $exe
$summary = [ordered]@{
    scope = 'Fresh per-user installation from the hash-verified published Setup, Windows GitHub-hosted Actions'
    setup_sha256 = $expected.setup_sha256
    setup_exit_code = 0
    extracted_file_count = $archiveFootprint.files.Count
    installed_file_count = $installedFootprint.files.Count
    missing_files = $missing.ToArray()
    changed_files = $changed.ToArray()
    added_files = $extra.ToArray()
    added_directories = $addedDirectories
    removed_directories = $removedDirectories
    app_version = $app.version
    electron_version = $electronVersion
    executable_product_version = (Get-Item -LiteralPath $exe).VersionInfo.ProductVersion
    registry = $registrations
    protected_witnesses = $protectedResults
}
Write-Receipt 'installed-layout.json' $summary
Write-Host "Compared $($archiveFootprint.files.Count) extracted files with $($installedFootprint.files.Count) installed files."
$extra | ForEach-Object { Write-Host "NSIS extra: $($_.path), $($_.size) bytes, SHA-256 $($_.sha256)" }
Write-Host "Installed app $($app.version), Electron $electronVersion, PE ProductVersion $($summary.executable_product_version)."
Write-Host "Registrations: $(ConvertTo-Json -InputObject $registrations -Compress)"
foreach ($witness in $protectedResults) {
    foreach ($file in $witness.added_files) {
        Write-Host "External lifecycle file: $($witness.root)/$($file.path), $($file.size) bytes, SHA-256 $($file.sha256)"
    }
}

if ($missing.Count -ne 0 -or $changed.Count -ne 0 -or $addedDirectories.Count -ne 0 -or $removedDirectories.Count -ne 0) {
    throw 'Setup installed a different set of managed bytes or directories. See installed-layout.json.'
}
if ($extra.Count -ne 1 -or $extra[0].path -cne $uninstallerName -or
    $extra[0].size -ne $uninstallerSize -or $extra[0].sha256 -cne $uninstallerSha256) {
    throw 'Expected only the pinned installer-owned uninstaller. See installed-layout.json.'
}
$expectedUninstallCommand = '"{0}" /currentuser' -f (Join-Path $installed $uninstallerName)
if ($registrations.Count -ne 1 -or $registrations[0].scope -cne 'current-user' -or
    $registrations[0].subkey -cne '90a2ff53-faef-56f7-9850-17442841706a' -or
    $registrations[0].display_name -cne 'POS Desktop 0.0.13' -or
    $registrations[0].display_version -cne '0.0.13' -or
    $registrations[0].uninstall_command -ine $expectedUninstallCommand) {
    throw 'The new POS Desktop registration must be per-user and point to the installed uninstaller.'
}
if ($app.version -cne '0.0.13' -or $summary.executable_product_version -cne '0.0.13.0') {
    throw 'Expected application version 0.0.13 and PE ProductVersion 0.0.13.0.'
}
if ($protectedResults.Count -ne 8 -or @($protectedResults | Where-Object {
    -not $_.directories_unchanged -or $_.fixture_file_count -ne 3 -or $_.changed_or_removed_fixture_files.Count -ne 0
}).Count -ne 0) {
    throw 'Protected witness data was modified or removed during installation.'
}
$cacheResult = @($protectedResults | Where-Object { $_.root -ieq $updaterCache })
if ($cacheResult.Count -ne 1 -or $cacheResult[0].added_files.Count -ne 1 -or
    $cacheResult[0].added_files[0].path -cne 'installer.exe' -or
    $cacheResult[0].added_files[0].sha256 -cne $expected.setup_sha256 -or
    $cacheResult[0].added_files[0].size -ne $expected.setup_size -or
    @($protectedResults | Where-Object { $_.root -ine $updaterCache -and $_.added_files.Count -ne 0 }).Count -ne 0) {
    throw 'Expected the exact pinned Setup copy in the installer-owned updater cache and no other protected-root additions.'
}
Write-Host "Setup 0.0.13 physical parity, Electron $electronVersion, and all 24 protected witness files verified."
