[CmdletBinding()]
param([string] $SevenZip = '7z', [string] $ArchivePath)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$printing = (Get-Content (Join-Path $PSScriptRoot 'desktop-runtime.json') -Raw | ConvertFrom-Json).printing
$buildTools = Join-Path $repoRoot 'build-tools'
New-Item -ItemType Directory -Path $buildTools -Force | Out-Null
if (-not $ArchivePath) {
    $ArchivePath = Join-Path $buildTools $printing.archiveName
    if (-not (Test-Path -LiteralPath $ArchivePath)) {
        Invoke-WebRequest -Uri $printing.url -OutFile $ArchivePath
    }
}
if ((Get-FileHash -LiteralPath $ArchivePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $printing.sha256) {
    throw 'Printing runtime archive SHA-256 does not match the pinned vendor download.'
}
$work = Join-Path $buildTools ('printing-work-' + [Guid]::NewGuid().ToString('N'))
$workFull = [System.IO.Path]::GetFullPath($work)
$allowedParent = [System.IO.Path]::GetFullPath($buildTools).TrimEnd('\') + '\'
if (-not $workFull.StartsWith($allowedParent, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Printing extraction directory is outside build-tools.'
}
try {
    & $SevenZip x -tNsis $ArchivePath "-o$work" -y | Out-Null
    # The vendor's Authenticode data produces the standard trailing-data warning.
    if ($LASTEXITCODE -gt 1) { throw "QZ runtime extraction failed: $LASTEXITCODE" }
    $payload = Join-Path $work '$PLUGINSDIR\payload'
    $javaSource = Join-Path $payload 'runtime'
    if (-not (Test-Path -LiteralPath (Join-Path $javaSource 'bin\java.exe'))) { throw 'Bundled Java runtime is missing.' }
    if (-not (Test-Path -LiteralPath (Join-Path $payload 'qz-tray.jar'))) { throw 'QZ Tray JAR is missing.' }
    $javaTarget = Join-Path $repoRoot 'portable\java'
    $qzTarget = Join-Path $repoRoot 'portable\qz-tray'
    New-Item -ItemType Directory -Path $javaTarget,$qzTarget -Force | Out-Null
    Get-ChildItem -LiteralPath $javaSource -Force | Copy-Item -Destination $javaTarget -Recurse -Force
    Copy-Item -LiteralPath (Join-Path $payload 'qz-tray.jar') -Destination $qzTarget -Force
    Copy-Item -LiteralPath (Join-Path $payload 'LICENSE.txt') -Destination $qzTarget -Force
    if (Test-Path -LiteralPath (Join-Path $payload 'libs')) {
        Copy-Item -LiteralPath (Join-Path $payload 'libs') -Destination $qzTarget -Recurse -Force
    }
    & (Join-Path $javaTarget 'bin\java.exe') -version
    if ($LASTEXITCODE -ne 0) { throw 'Bundled Java cannot execute.' }
    $record = @{ version=$printing.version; archiveSha256=$printing.sha256; jarSha256=(Get-FileHash (Join-Path $qzTarget 'qz-tray.jar') -Algorithm SHA256).Hash.ToLowerInvariant() }
    $record | ConvertTo-Json | Set-Content (Join-Path $repoRoot 'portable\printing-manifest.json') -Encoding utf8
    Write-Host "Pinned QZ Tray $($printing.version) and bundled Java prepared."
} finally {
    if (Test-Path -LiteralPath $workFull) { Remove-Item -LiteralPath $workFull -Recurse -Force }
}
