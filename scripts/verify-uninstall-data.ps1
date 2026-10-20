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
    $savedProgramData = $env:PROGRAMDATA
    $savedProgramFiles = $env:ProgramFiles
    $savedProgramFilesX86 = ${env:ProgramFiles(x86)}
    try {
        $env:PROGRAMDATA = $roots.Shared
        $env:ProgramFiles = Join-Path $lab 'No installed QZ'
        ${env:ProgramFiles(x86)} = $env:ProgramFiles
        $qz = Join-Path $roots.Shared 'qz'
        New-Item -ItemType Directory -Path (Join-Path $qz 'ssl') -Force | Out-Null
        # Fresh random public certificate; it is never installed in a trust store.
        $fixtureScript = 'const fs=require("fs"),forge=require("node-forge");const keys=forge.pki.rsa.generateKeyPair(1024),cert=forge.pki.createCertificate();cert.publicKey=keys.publicKey;cert.serialNumber=require("crypto").randomBytes(8).toString("hex");cert.validity.notBefore=new Date();cert.validity.notAfter=new Date(Date.now()+86400000);cert.setSubject([{name:"commonName",value:"POS uninstall fixture"}]);cert.setIssuer(cert.subject.attributes);cert.sign(keys.privateKey);fs.writeFileSync(process.argv[2],forge.pki.certificateToPem(cert));'
        $fixtureScript | node - (Join-Path $qz 'ssl/root-ca.crt')
        if ($LASTEXITCODE) { throw 'Certificate fixture generation failed' }
        Set-Content -LiteralPath (Join-Path $qz 'ssl/owned.key') -Value 'owned key fixture'
        Set-Content -LiteralPath (Join-Path $qz 'unrelated.txt') -Value 'another application'
        Set-Content -LiteralPath (Join-Path $qz 'ssl/changed.key') -Value 'modified by another application'
        $cert = [Security.Cryptography.X509Certificates.X509Certificate2]::new((Join-Path $qz 'ssl/root-ca.crt'))
        $sha = [Security.Cryptography.SHA256]::Create()
        $fingerprint = ([BitConverter]::ToString($sha.ComputeHash($cert.RawData))).Replace('-', '').ToLowerInvariant()
        $originalCertificate = [Convert]::ToBase64String($cert.RawData)
        $sha.Dispose(); $cert.Dispose()
        $receiptRoot = Join-Path $roots.Roaming 'POS Desktop'
        New-Item -ItemType Directory -Path $receiptRoot -Force | Out-Null
        $ownedFiles = @('ssl/root-ca.crt','ssl/owned.key') | ForEach-Object { @{path=$_;sha256=(Get-FileHash -LiteralPath (Join-Path $qz $_) -Algorithm SHA256).Hash.ToLowerInvariant()} }
        $ownedFiles += @{path='ssl/changed.key';sha256=('0' * 64)}
        @{rootSha256=$fingerprint;rootCertificateBase64=$originalCertificate;files=$ownedFiles} | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $receiptRoot 'qz-shared-install.json')
        Set-Content -LiteralPath (Join-Path $qz 'ssl/root-ca.crt') -Value 'rotated by another application'
        Remove-PosOwnedPrintingTrust -Targets @($receiptRoot)
        if (-not (Test-Path -LiteralPath (Join-Path $qz 'ssl/root-ca.crt'))) { throw 'FAIL: QZ cleanup deleted a rotated shared root' }
        if (-not (Test-Path -LiteralPath (Join-Path $qz 'unrelated.txt'))) { throw 'FAIL: QZ cleanup deleted an unrecorded shared file' }
        if (-not (Test-Path -LiteralPath (Join-Path $qz 'ssl/changed.key'))) { throw 'FAIL: QZ cleanup deleted a changed shared file' }
        if (Test-Path -LiteralPath (Join-Path $qz 'ssl/owned.key')) { throw 'FAIL: QZ cleanup retained an unchanged owned file' }
    } finally {
        $env:PROGRAMDATA = $savedProgramData
        $env:ProgramFiles = $savedProgramFiles
        ${env:ProgramFiles(x86)} = $savedProgramFilesX86
    }
    Write-Host 'PASS: upgrades preserve data; manual removal clears managed data; junctions fail closed; unrelated files survive'
} finally {
    $fullLab = [IO.Path]::GetFullPath($lab)
    if (-not $fullLab.StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) { throw 'Invalid test cleanup root' }
    if (Test-Path -LiteralPath $fullLab) { Remove-Item -LiteralPath $fullLab -Recurse -Force }
}
