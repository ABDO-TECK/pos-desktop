[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$helper = Join-Path $PSScriptRoot '../build/uninstall-data.ps1'
if (-not (Test-Path -LiteralPath $helper)) { throw 'FAIL: uninstall has no managed-data cleanup implementation' }
. $helper
$lab = Join-Path ([IO.Path]::GetTempPath()) ('pos-uninstall-test-' + [guid]::NewGuid())
New-Item -ItemType Directory -Path $lab | Out-Null
try {
    $roots = @{ Roaming=(Join-Path $lab 'Roaming'); Local=(Join-Path $lab 'Local'); Shared=(Join-Path $lab 'Shared') }
    $targets = @(Get-PosCleanupTargets -Roaming $roots.Roaming -Local $roots.Local -Shared $roots.Shared)
    foreach ($target in $targets) {
        New-Item -ItemType Directory -Path $target -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $target 'fixture.txt') -Value 'existing data'
    }
    $unrelated = Join-Path $roots.Shared 'Other application'
    New-Item -ItemType Directory -Path $unrelated -Force | Out-Null
    Set-Content -LiteralPath (Join-Path $unrelated 'keep.txt') -Value 'keep'
    Invoke-PosDataRemoval -Targets $targets -Updated
    foreach ($target in $targets) { if (-not (Test-Path -LiteralPath (Join-Path $target 'fixture.txt'))) { throw 'FAIL: upgrade deleted existing data' } }
    $outside = Join-Path $lab 'outside'
    New-Item -ItemType Directory -Path $outside | Out-Null
    Set-Content -LiteralPath (Join-Path $outside 'keep.txt') -Value 'keep'
    $link = Join-Path $targets[0] 'linked-data'
    New-Item -ItemType Junction -Path $link -Target $outside | Out-Null
    $rejected = $false
    try { Invoke-PosDataRemoval -Targets $targets } catch { $rejected = $true }
    if (-not $rejected) { throw 'FAIL: cleanup followed a junction outside the managed directory' }
    if (-not (Test-Path -LiteralPath (Join-Path $outside 'keep.txt'))) { throw 'FAIL: external junction target changed' }
    # Remove only the junction itself, never recurse into its target.
    [IO.Directory]::Delete($link)
    Invoke-PosDataRemoval -Targets $targets
    foreach ($target in $targets) { if (Test-Path -LiteralPath $target) { throw "FAIL: leftover managed data: $target" } }
    if (-not (Test-Path -LiteralPath (Join-Path $unrelated 'keep.txt'))) { throw 'FAIL: unrelated data changed' }
    Write-Host 'PASS: upgrades preserve data; manual removal clears managed data; junctions fail closed; unrelated files survive'
} finally {
    $fullLab = [IO.Path]::GetFullPath($lab)
    if (-not $fullLab.StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) { throw 'Invalid test cleanup root' }
    if (Test-Path -LiteralPath $fullLab) { Remove-Item -LiteralPath $fullLab -Recurse -Force }
}
