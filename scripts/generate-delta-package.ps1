[CmdletBinding()]
param(
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string] $ToVersion,

    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string] $FromVersion,

    [string] $FromTag,
    [string] $ToTag,

    [string[]] $Files = @(),

    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string] $MinimumVersion,

    [string] $OutputDir,

    [ValidateSet('stable', 'beta', 'alpha')]
    [string] $Channel = 'stable',

    [string[]] $Changelog = @(),

    [string] $PrivateKeyPath,

    [switch] $Zip,
    [switch] $DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$VersionJsonPath = Join-Path $RepoRoot 'version.json'

function Get-PhpBinary {
    if (Get-Command 'php' -ErrorAction SilentlyContinue) {
        return 'php'
    }
    $candidates = @('C:\xampp\php\php.exe', 'C:\php\php.exe')
    foreach ($cand in $candidates) {
        if (Test-Path $cand) { return $cand }
    }
    return 'php'
}

function Read-Utf8TextFile {
    param([string] $Path)
    $encoding = [System.Text.UTF8Encoding]::new($false, $true)
    [System.IO.File]::ReadAllText($Path, $encoding)
}

function Get-FileSha256 {
    param([string] $FilePath)
    $stream = [System.IO.File]::OpenRead($FilePath)
    try {
        $sha = [System.Security.Cryptography.SHA256]::Create()
        $bytes = $sha.ComputeHash($stream)
        return (-join ($bytes | ForEach-Object { $_.ToString('x2') })).ToLower()
    } finally {
        $stream.Dispose()
    }
}

function Write-Step {
    param([string] $Message)
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

# Determine ToVersion if not provided
if ([string]::IsNullOrWhiteSpace($ToVersion)) {
    if (Test-Path $VersionJsonPath -PathType Leaf) {
        $versionContent = Read-Utf8TextFile $VersionJsonPath | ConvertFrom-Json
        $ToVersion = [string] $versionContent.version
    } else {
        throw 'ToVersion is required or must be present in version.json.'
    }
}

# Determine MinimumVersion
if ([string]::IsNullOrWhiteSpace($MinimumVersion)) {
    $MinimumVersion = if (-not [string]::IsNullOrWhiteSpace($FromVersion)) { $FromVersion } else { '1.0.0' }
}

# Determine OutputDir
if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = Join-Path $RepoRoot "release/$ToVersion"
}

# Determine PrivateKeyPath
if ([string]::IsNullOrWhiteSpace($PrivateKeyPath)) {
    $defaultPriv = Join-Path $RepoRoot 'release/private_key.pem'
    if (Test-Path $defaultPriv -PathType Leaf) {
        $PrivateKeyPath = $defaultPriv
    }
}

Write-Step "Preparing Delta Package for v$ToVersion (Minimum supported: v$MinimumVersion)"

# Determine changed files
$changedFilesList = New-Object System.Collections.Generic.List[string]
$deletedFilesList = New-Object System.Collections.Generic.List[string]

$excludedPrefixes = @(
    '.git/',
    '.github/',
    '.env',
    'dist-electron/',
    'node_modules/',
    'backend/vendor/',
    'backend/storage/',
    'backend/logs/',
    'backend/.phpunit.result.cache',
    'storage/',
    'release/'
)

function Test-IsExcluded {
    param([string] $Path)
    $norm = ($Path -replace '\\', '/').TrimStart('/')
    foreach ($prefix in $excludedPrefixes) {
        if ($norm -eq $prefix -or $norm.StartsWith($prefix)) {
            return $true
        }
    }
    return $false
}

$flattenedFiles = New-Object System.Collections.Generic.List[string]
foreach ($entry in $Files) {
    if ([string]::IsNullOrWhiteSpace($entry)) { continue }
    foreach ($part in ($entry -split ',')) {
        $trimmed = $part.Trim()
        if (-not [string]::IsNullOrWhiteSpace($trimmed)) {
            $flattenedFiles.Add($trimmed)
        }
    }
}

if ($flattenedFiles.Count -gt 0) {
    Write-Host "Using explicitly specified files ($($flattenedFiles.Count) files)..."
    foreach ($f in $flattenedFiles) {
        $norm = ($f -replace '\\', '/').TrimStart('/')
        if (-not (Test-IsExcluded $norm)) {
            $changedFilesList.Add($norm)
        }
    }
} else {
    $fromRef = if (-not [string]::IsNullOrWhiteSpace($FromTag)) {
        $FromTag
    } elseif (-not [string]::IsNullOrWhiteSpace($FromVersion)) {
        "v$FromVersion"
    } else {
        $latestTag = (& git -C $RepoRoot describe --tags --abbrev=0 2>$null)
        if ($LASTEXITCODE -eq 0 -and -not [string]::IsNullOrWhiteSpace($latestTag)) {
            $latestTag.Trim()
        } else {
            'HEAD~1'
        }
    }

    $toRef = if (-not [string]::IsNullOrWhiteSpace($ToTag)) {
        $ToTag
    } else {
        'HEAD'
    }

    Write-Host "Comparing git changes between $fromRef and $toRef..."
    $diffOutput = & git -C $RepoRoot diff --name-status $fromRef $toRef
    if ($LASTEXITCODE -ne 0) {
        throw "git diff failed between $fromRef and $toRef"
    }

    foreach ($line in $diffOutput) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -lt 2) { continue }
        $status = $parts[0].Trim()
        $path = ($parts[1].Trim() -replace '\\', '/').TrimStart('/')

        if (Test-IsExcluded $path) { continue }

        if ($status -eq 'D') {
            $deletedFilesList.Add($path)
        } else {
            $changedFilesList.Add($path)
        }
    }
}

Write-Host "Detected $($changedFilesList.Count) modified/added file(s) and $($deletedFilesList.Count) deleted file(s)."

if ($changedFilesList.Count -eq 0 -and $deletedFilesList.Count -eq 0) {
    Write-Warning 'No changed files found for delta update.'
    return
}

# Build manifest files list
$manifestFiles = @()
$filesDir = Join-Path $OutputDir 'files'

if (-not $DryRun) {
    if (Test-Path $OutputDir) {
        Remove-Item -Path $OutputDir -Recurse -Force
    }
    New-Item -Path $filesDir -ItemType Directory -Force | Out-Null
}

$totalSize = 0

foreach ($relPath in $changedFilesList) {
    $fullPath = Join-Path $RepoRoot $relPath
    if (-not (Test-Path $fullPath -PathType Leaf)) {
        Write-Warning "File does not exist on disk, skipping: $relPath"
        continue
    }

    $fileInfo = Get-Item $fullPath
    $fileSize = $fileInfo.Length
    $totalSize += $fileSize
    $sha256 = Get-FileSha256 $fullPath

    $manifestFiles += [PSCustomObject]@{
        path   = $relPath
        action = 'replace'
        sha256 = $sha256
        size   = $fileSize
    }

    Write-Host " + $relPath ($fileSize bytes, SHA256: $($sha256.Substring(0, 12))...)"

    if (-not $DryRun) {
        $destPath = Join-Path $filesDir $relPath
        $destDir = Split-Path $destPath -Parent
        if (-not (Test-Path $destDir)) {
            New-Item -Path $destDir -ItemType Directory -Force | Out-Null
        }
        Copy-Item -Path $fullPath -Destination $destPath -Force
    }
}

# Determine changelog
if ($Changelog.Count -eq 0 -and (Test-Path $VersionJsonPath -PathType Leaf)) {
    $verJson = Read-Utf8TextFile $VersionJsonPath | ConvertFrom-Json
    if ($verJson.changelog) {
        $Changelog = @($verJson.changelog)
    }
}

# Create manifest object
$manifest = [ordered]@{
    manifest_version = '1.0'
    version          = $ToVersion
    minimum_version  = $MinimumVersion
    released_at      = (Get-Date).ToString('yyyy-MM-dd')
    channel          = $Channel
    type             = 'delta'
    changelog        = $Changelog
    files            = $manifestFiles
    deleted_files    = @($deletedFilesList)
}

$manifestJson = $manifest | ConvertTo-Json -Depth 10

if ($DryRun) {
    Write-Step 'DRY RUN - Generated Manifest:'
    Write-Host $manifestJson
    return
}

$manifestPath = Join-Path $OutputDir 'manifest.json'
[System.IO.File]::WriteAllText($manifestPath, $manifestJson + [Environment]::NewLine, [System.Text.UTF8Encoding]::new($false))
Write-Host "Wrote manifest to: $manifestPath" -ForegroundColor Green

# Generate RSA Digital Signature
$signaturePath = Join-Path $OutputDir 'manifest.sig'
if (-not [string]::IsNullOrWhiteSpace($PrivateKeyPath) -and (Test-Path $PrivateKeyPath -PathType Leaf)) {
    Write-Host "Signing manifest with private key: $PrivateKeyPath" -ForegroundColor Yellow
    $phpBin = Get-PhpBinary
    $escapedManifestPath = $manifestPath -replace '\\', '/'
    $escapedPrivPath = $PrivateKeyPath -replace '\\', '/'
    $escapedSigPath = $signaturePath -replace '\\', '/'
    $vendorAutoload = (Join-Path $RepoRoot 'backend/vendor/autoload.php') -replace '\\', '/'

    $signScript = "require '$vendorAutoload'; `$s = new App\Services\ManifestSignatureService(); `$sig = `$s->signData(file_get_contents('$escapedManifestPath'), '$escapedPrivPath'); file_put_contents('$escapedSigPath', `$sig); echo 'SIGNED';"
    $signOutput = & $phpBin -r $signScript 2>&1
    if ($LASTEXITCODE -eq 0 -and (Test-Path $signaturePath)) {
        Write-Host "Generated RSA digital signature: $signaturePath" -ForegroundColor Green
    } else {
        Write-Warning "Could not sign manifest via PHP OpenSSL: $signOutput"
    }
} else {
    Write-Warning "Private key not provided or not found. Manifest signature (manifest.sig) was skipped."
}

# Generate Delta ZIP Archive
$fromLabel = if (-not [string]::IsNullOrWhiteSpace($FromVersion)) { $FromVersion } else { $MinimumVersion }
$zipName = "delta-$fromLabel-to-$ToVersion.zip"
$zipPath = Join-Path $OutputDir $zipName

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
$existingFiles = @(Get-ChildItem -Path $filesDir -Recurse -File)

if ($existingFiles.Count -gt 0) {
    Compress-Archive -Path "$filesDir/*" -DestinationPath $zipPath -Force
    Write-Host "Created Delta ZIP archive: $zipPath" -ForegroundColor Green

    # Also create generic delta.zip copy
    $genericZip = Join-Path $OutputDir 'delta.zip'
    Copy-Item -Path $zipPath -Destination $genericZip -Force
} else {
    Write-Warning "No files were staged into $filesDir to compress into delta ZIP."
}


Write-Step "Delta Release Package Ready for GitHub Releases at: $OutputDir"
Write-Host "Assets ready to upload to GitHub Release v$($ToVersion):" -ForegroundColor Cyan
Write-Host " 1. $manifestPath"
if (Test-Path $signaturePath) {
    Write-Host " 2. $signaturePath"
}
Write-Host " 3. $zipPath"
Write-Host "Total files: $($manifestFiles.Count) ($([math]::Round($totalSize / 1KB, 2)) KB)"
