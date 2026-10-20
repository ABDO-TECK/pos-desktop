[CmdletBinding()]
param([string]$InstallRoot = "$env:LOCALAPPDATA\Programs\POS Desktop", [string]$AssetsDirectory = '')
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
function Assert-NoReparse([string]$Path) {
    $item = Get-Item -LiteralPath $Path -Force
    while ($null -ne $item) {
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Reparse path rejected: $($item.FullName)" }
        if ($item -is [IO.FileInfo]) { $item = $item.Directory } else { $item = $item.Parent }
    }
}
function Assert-Closed {
    # Fail closed if process inventory is unavailable; never stop a process.
    foreach ($process in Get-CimInstance Win32_Process -ErrorAction Stop) {
        if ($process.ExecutablePath -and $process.ExecutablePath.StartsWith($script:InstallPrefix,[StringComparison]::OrdinalIgnoreCase)) {
            throw 'Close POS Desktop and its services before running repair.'
        }
        if ($process.Name -eq 'POS Desktop.exe' -and -not $process.ExecutablePath) { throw 'Cannot verify whether POS Desktop is closed.' }
    }
}
try {
    $InstallRoot = (Resolve-Path -LiteralPath $InstallRoot).Path.TrimEnd('\')
    Assert-NoReparse $InstallRoot
    $script:InstallPrefix = $InstallRoot + '\'
    if (-not (Test-Path -LiteralPath "$InstallRoot\POS Desktop.exe") -or -not (Test-Path -LiteralPath "$InstallRoot\resources\app.asar")) { throw 'Select the actual POS Desktop installation folder.' }
    $root = "$InstallRoot\resources\app.asar.unpacked"
    $php = "$InstallRoot\resources\portable\php\php.exe"
    foreach ($entry in @($root,$php,"$root\backend\backend.phar","$root\version.json")) { Assert-NoReparse $entry }
    # Reject all junctions/symlinks before baseline checking or file replacement.
    foreach ($entry in Get-ChildItem -LiteralPath $root -Recurse -Force) {
        if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Reparse entry rejected inside deployed application.' }
    }
    Assert-Closed
    $engineZip = Join-Path $PSScriptRoot 'repair-engine.zip'
    $engineSig = Join-Path $PSScriptRoot 'repair-engine.sig'
    $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider
    $rsa.FromXmlString('@PUBLIC_KEY_XML@')
    $valid = $rsa.VerifyData([IO.File]::ReadAllBytes($engineZip),'SHA256',[Convert]::FromBase64String([IO.File]::ReadAllText($engineSig).Trim()))
    $rsa.Dispose()
    if (-not $valid) { throw 'RSA repair-engine signature rejected. Download the original repair ZIP from GitHub.' }
    $work = Join-Path ([IO.Path]::GetTempPath()) ('POS-Update-Repair-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $work | Out-Null
    Assert-NoReparse $work
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::ExtractToDirectory($engineZip,"$work\tool")
    & $php "$work\tool\repair.php" prepare $root $work $AssetsDirectory
    if ($LASTEXITCODE) { throw "Preparation failed. No application files replaced. Details: $work" }
    Assert-Closed
    & $php "$work\tool\repair.php" apply $root $work
    if ($LASTEXITCODE) { throw "Repair failed; keep recovery files for inspection: $work" }
    Write-Host "Updated to 0.0.10-beta.2. Reopen POS Desktop. Recovery snapshot: $work"
} catch { Write-Error $_; exit 1 }
