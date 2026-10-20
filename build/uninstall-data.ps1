[CmdletBinding()]
param([string]$InstallRoot, [switch]$Updated)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-PosCleanupTargets {
    param([string]$Roaming, [string]$Local, [string]$Shared)
    foreach ($root in @($Roaming, $Local, $Shared)) {
        if ([string]::IsNullOrWhiteSpace($root) -or -not [IO.Path]::IsPathRooted($root)) { throw 'Missing absolute Windows data root' }
    }
    @(
        (Join-Path $Shared 'POS System'),
        (Join-Path $Roaming 'POS System'),
        (Join-Path $Roaming 'POS Desktop'),
        (Join-Path $Roaming 'pos-desktop'),
        (Join-Path $Local 'POS System'),
        (Join-Path $Local 'POS Desktop'),
        (Join-Path $Local 'pos-desktop'),
        (Join-Path $Local 'pos-desktop-updater')
    ) | ForEach-Object { [IO.Path]::GetFullPath($_) } | Sort-Object -Unique
}

function Assert-PosTreeSafe {
    param([string]$Directory)
    $full = [IO.Path]::GetFullPath($Directory)
    if ($full.TrimEnd('\') -eq [IO.Path]::GetPathRoot($full).TrimEnd('\')) { throw 'Refusing a drive root' }
    $ancestor = $full
    while ($ancestor) {
        if (Test-Path -LiteralPath $ancestor) {
            if ((Get-Item -LiteralPath $ancestor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Reparse point rejected: $ancestor" }
        }
        $ancestor = [IO.Path]::GetDirectoryName($ancestor)
    }
    if (Test-Path -LiteralPath $full) {
        foreach ($entry in Get-ChildItem -LiteralPath $full -Force) {
            if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Reparse point rejected: $($entry.FullName)" }
            if ($entry.PSIsContainer) { Assert-PosTreeSafe $entry.FullName }
        }
    }
}

function Invoke-PosDataRemoval {
    param([string[]]$Targets, [switch]$Updated)
    if ($Updated) { return }
    # Preflight the entire deletion set before changing anything.
    foreach ($target in $Targets) { Assert-PosTreeSafe $target }
    foreach ($target in $Targets) {
        if (Test-Path -LiteralPath $target) { Remove-Item -LiteralPath ([IO.Path]::GetFullPath($target)) -Recurse -Force }
        if (Test-Path -LiteralPath $target) { throw "Managed data remains: $target" }
    }
}

function Remove-PosOwnedPrintingTrust {
    param([string[]]$Targets)
    # QZ can also be installed independently. Never remove a third-party QZ installation.
    foreach ($programFiles in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
        if ($programFiles -and (Test-Path -LiteralPath (Join-Path $programFiles 'QZ Tray/qz-tray.exe'))) { return }
    }
    $qzRoot = Join-Path $env:PROGRAMDATA 'qz'
    $rootCert = Join-Path $qzRoot 'ssl/root-ca.crt'
    foreach ($target in $Targets) {
        $receipt = Join-Path $target 'qz-shared-install.json'
        if (-not (Test-Path -LiteralPath $receipt)) { continue }
        Assert-PosTreeSafe $qzRoot
        $owner = Get-Content -LiteralPath $receipt -Raw | ConvertFrom-Json
        if ($owner.PSObject.Properties.Name -notcontains 'files') { throw 'QZ ownership receipt has no file inventory' }
        foreach ($file in $owner.files) {
            if ([IO.Path]::IsPathRooted($file.path) -or $file.path -match '(^|[\\/])\.\.([\\/]|$)|:' -or $file.sha256 -notmatch '^[a-f0-9]{64}$') { throw 'Unsafe QZ ownership receipt' }
            $absolute = [IO.Path]::GetFullPath((Join-Path $qzRoot $file.path))
            if (-not $absolute.StartsWith($qzRoot.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'QZ file is outside its shared directory' }
        }
        # Use the original public certificate from the ownership receipt. A
        # later consumer may rotate/delete the shared file; that must not
        # block removal of POS or delete the replacement certificate.
        $cert = [Security.Cryptography.X509Certificates.X509Certificate2]::new([Convert]::FromBase64String($owner.rootCertificateBase64))
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $hash = ([BitConverter]::ToString($sha.ComputeHash($cert.RawData))).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
        if ($owner.rootSha256 -ne $hash) { throw 'QZ ownership certificate does not match its receipt' }
        # QZ installed this exact thumbprint in the Enterprise physical root
        # store. Remove it there first, avoiding inherited/read-only entries
        # in the logical CurrentUser Root collection.
        $enterpriseKey = [Microsoft.Win32.Registry]::LocalMachine.OpenSubKey('SOFTWARE\Microsoft\EnterpriseCertificates\Root\Certificates\' + $cert.Thumbprint)
        if ($enterpriseKey) {
            $enterpriseKey.Dispose()
            & (Join-Path $env:WINDIR 'System32/certutil.exe') -enterprise -delstore Root $cert.Thumbprint | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'Failed to remove the recorded POS Enterprise printing trust' }
        }
        # Do not depend on the PowerShell Cert: provider. Windows PowerShell
        # launched from a PS7/NSIS environment can fail its module autoload
        # with duplicate extended type data, leaving that drive unavailable.
        foreach ($location in @('LocalMachine','CurrentUser')) {
            $trustStore = [Security.Cryptography.X509Certificates.X509Store]::new('Root', [Security.Cryptography.X509Certificates.StoreLocation]$location)
            try {
                $trustStore.Open([Security.Cryptography.X509Certificates.OpenFlags]::ReadOnly)
                $trustedCertificates = $trustStore.Certificates.Find([Security.Cryptography.X509Certificates.X509FindType]::FindByThumbprint, $cert.Thumbprint, $false)
                $trustStore.Close()
                if ($trustedCertificates.Count) {
                    $trustStore.Open([Security.Cryptography.X509Certificates.OpenFlags]::ReadWrite)
                    foreach ($trusted in $trustStore.Certificates.Find([Security.Cryptography.X509Certificates.X509FindType]::FindByThumbprint, $cert.Thumbprint, $false)) {
                        $trustStore.Remove($trusted)
                    }
                }
            } finally { $trustStore.Close(); $trustStore.Dispose() }
        }
        $cert.Dispose()
        foreach ($file in $owner.files) {
            $absolute = Join-Path $qzRoot $file.path
            if (Test-Path -LiteralPath $absolute) {
                if ((Get-FileHash -LiteralPath $absolute -Algorithm SHA256).Hash.ToLowerInvariant() -eq $file.sha256) {
                    Remove-Item -LiteralPath $absolute -Force
                } else { Write-Warning "Retained changed shared QZ file: $($file.path)" }
            }
        }
        # Remove only empty directories, so later additions from other apps survive.
        if (Test-Path -LiteralPath $qzRoot) {
            Get-ChildItem -LiteralPath $qzRoot -Directory -Recurse -Force | Sort-Object { $_.FullName.Length } -Descending | ForEach-Object {
                if (-not @(Get-ChildItem -LiteralPath $_.FullName -Force).Count) { [IO.Directory]::Delete($_.FullName) }
            }
            if (-not @(Get-ChildItem -LiteralPath $qzRoot -Force).Count) { [IO.Directory]::Delete($qzRoot) }
        }
        return
    }
}

function Invoke-PosUninstall {
    param([string]$InstallRoot, [switch]$Updated)
    if ($Updated) { Write-Host 'Upgrade: existing POS data retained'; return }
    $install = [IO.Path]::GetFullPath($InstallRoot)
    Assert-PosTreeSafe $install
    if (-not (Test-Path -LiteralPath (Join-Path $install 'resources/app.asar')) -or
        [IO.Path]::GetFullPath($PSScriptRoot) -ne (Join-Path $install 'resources')) { throw 'Cleanup must run from the installed POS resources directory' }
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        # Preserve the initiating user's paths if UAC uses a different administrator account.
        $quote = { param($value) "'" + $value.Replace("'", "''") + "'" }
        $command = "`$env:APPDATA=$(& $quote $env:APPDATA); `$env:LOCALAPPDATA=$(& $quote $env:LOCALAPPDATA); `$env:PROGRAMDATA=$(& $quote $env:PROGRAMDATA); & $(& $quote $PSCommandPath) -InstallRoot $(& $quote $install); if(-not `$?) { exit 1 }"
        $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($command))
        $child = Start-Process -FilePath "$PSHOME/powershell.exe" -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-EncodedCommand',$encoded) -Verb RunAs -WindowStyle Hidden -PassThru -Wait
        if ($child.ExitCode -ne 0) { throw 'Elevated POS data removal failed or was cancelled' }
        return
    }
    $targets = @(Get-PosCleanupTargets -Roaming $env:APPDATA -Local $env:LOCALAPPDATA -Shared $env:PROGRAMDATA)
    Write-Host 'Validating POS-owned data paths'
    foreach ($target in $targets) { Assert-PosTreeSafe $target }
    $prefix = $install.TrimEnd('\') + '\'
    Write-Host 'Stopping remaining owned runtime processes'
    foreach ($process in Get-CimInstance Win32_Process) {
        if ($process.ExecutablePath -and $process.ExecutablePath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase) -and
            [IO.Path]::GetFileName($process.ExecutablePath) -in @('POS Desktop.exe','php.exe','mysqld.exe','mariadbd.exe','java.exe','javaw.exe')) {
            Stop-Process -Id $process.ProcessId -Force -ErrorAction Stop
        }
    }
    Write-Host 'Removing POS firewall rules'
    foreach ($name in @('POS System - SSL Port 8443','POS System - Embedded PHP','POS System - Main Executable','POS System - Web Port 8080')) {
        Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue | Remove-NetFirewallRule -ErrorAction Stop
    }
    Write-Host 'Removing recorded POS printing trust and files'
    Remove-PosOwnedPrintingTrust -Targets $targets
    Write-Host 'Removing POS database, configuration and caches'
    Invoke-PosDataRemoval -Targets $targets
    Write-Host 'POS data, configuration, device keys and caches removed'
}

if ($MyInvocation.InvocationName -ne '.') {
    $diagnostic = Join-Path ([IO.Path]::GetTempPath()) ('pos-desktop-uninstall-' + [guid]::NewGuid() + '.log')
    Start-Transcript -LiteralPath $diagnostic | Out-Null
    try {
        Invoke-PosUninstall -InstallRoot $InstallRoot -Updated:$Updated
        Stop-Transcript | Out-Null
        Remove-Item -LiteralPath $diagnostic -Force
        exit 0
    } catch {
        Write-Host $_.Exception.Message
        Write-Host "Uninstall diagnostic: $diagnostic"
        Stop-Transcript | Out-Null
        exit 1
    }
}
