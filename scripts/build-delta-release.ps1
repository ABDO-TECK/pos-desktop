[CmdletBinding()]
param([Parameter(Mandatory)][string] $Tag, [ValidateSet('stable','beta')][string] $Channel)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if (-not $env:GITHUB_ACTIONS -or $env:GITHUB_REPOSITORY -ne 'ABDO-TECK/pos-desktop-updates') { throw 'This publisher runs only in the project GitHub Actions repository.' }
if (-not $env:UPDATE_PRIVATE_KEY) { throw 'UPDATE_PRIVATE_KEY repository secret is required.' }

function Copy-SafeAsset {
    param(
        [Parameter(Mandatory)][string]$SourcePath,
        [Parameter(Mandatory)][string]$DestinationPath
    )
    if (-not (Test-Path -LiteralPath $SourcePath)) {
        throw "Source asset does not exist: $SourcePath"
    }
    if (Test-Path -LiteralPath $DestinationPath) {
        $sourceHash = (Get-FileHash -LiteralPath $SourcePath -Algorithm SHA256).Hash.ToLowerInvariant()
        $destHash = (Get-FileHash -LiteralPath $DestinationPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($sourceHash -ne $destHash) {
            throw "Refusing to overwrite '$DestinationPath': existing destination has different hash ($destHash) than source ($sourceHash)."
        }
        # Identical bytes -> no-op
        return
    }
    Copy-Item -LiteralPath $SourcePath -Destination $DestinationPath
}

$version = Get-Content version.json -Raw | ConvertFrom-Json
New-Item -ItemType Directory -Path release-assets -Force | Out-Null
foreach ($baselineTag in $version.delta_baseline_tags) {
    if ($baselineTag -notmatch '^v\d+\.\d+\.\d+(?:-beta\.\d+)?$') { throw 'Invalid baseline tag.' }
    $baselineVersion = $baselineTag.Substring(1)
    $work = Join-Path $env:RUNNER_TEMP ('delta-' + $baselineVersion)
    New-Item -ItemType Directory -Path $work -Force | Out-Null
    $inventory = Join-Path $work 'deployment-inventory.json'
    $inventoryResolved = $false

    # 1. Try stored baseline inventory in pos-desktop-updates main branch
    $baselineInventoryUrl = "https://raw.githubusercontent.com/ABDO-TECK/pos-desktop-updates/main/baselines/$baselineTag/deployment-inventory.json"
    try {
        $storedInventory = Invoke-WebRequest -Uri $baselineInventoryUrl -UseBasicParsing -ErrorAction Stop
        [IO.File]::WriteAllBytes($inventory, $storedInventory.RawContentStream.ToArray())
        $inventoryResolved = $true
        $LASTEXITCODE = 0
    } catch {
        $responseProperty = $_.Exception.PSObject.Properties['Response']
        if ($null -eq $responseProperty -or $null -eq $responseProperty.Value -or [int] $responseProperty.Value.StatusCode -ne 404) {
            # Network failure, 403, 5xx, timeout, etc.: never hide as absence
            throw
        }
    }

    # 2. If not stored, query updates repository Release via gh api for bounded 404 vs error vs presence
    if (-not $inventoryResolved) {
        $isGenuine404 = $false
        $apiErr = ''
        $releaseJson = $null
        try {
            $rawApi = & gh api "repos/$env:GITHUB_REPOSITORY/releases/tags/$baselineTag" 2>&1
            if ($LASTEXITCODE -eq 0) {
                $releaseJson = ($rawApi | Out-String) | ConvertFrom-Json
            } else {
                $apiErr = ($rawApi | Out-String).Trim()
                # Check for rate limit, bad credentials, permissions, network connection
                if ($apiErr -match '(?i)rate limit|bad credentials|401|403|forbidden|could not resolve|connection refused|timeout') {
                    throw "GitHub API error querying release '$baselineTag' in $env:GITHUB_REPOSITORY: $apiErr"
                }
                # Check for structured 404
                if ($apiErr -match '"status":\s*"404"' -or $apiErr -match 'HTTP 404' -or $apiErr -match '"message":\s*"Not Found"') {
                    $isGenuine404 = $true
                } else {
                    throw "Non-404 error inspecting release '$baselineTag' in updates repo: $apiErr"
                }
            }
        } catch {
            if ($isGenuine404) {
                # continue to fallback
            } else {
                throw
            }
        }

        if ($releaseJson) {
            # Release exists in updates repo; inspect structured assets metadata
            $invAsset = $null
            if ($releaseJson.PSObject.Properties['assets']) {
                $invAsset = $releaseJson.assets | Where-Object { $_.name -eq 'deployment-inventory.json' }
            }
            if (-not $invAsset) {
                throw "Release '$baselineTag' exists in $env:GITHUB_REPOSITORY but has no deployment-inventory.json asset."
            }
            # Download asset directly
            $rawDownload = & gh release download $baselineTag --repo $env:GITHUB_REPOSITORY --pattern deployment-inventory.json --dir $work 2>&1
            if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $inventory)) {
                throw "Failed downloading deployment-inventory.json from existing updates release '$baselineTag': $($rawDownload | Out-String)"
            }
            $inventoryResolved = $true
        } elseif ($isGenuine404) {
            # 3. Only on genuine 404 fall back to approved source release for known approved v0.0.11
            if ($baselineTag -eq 'v0.0.11') {
                Write-Host "Updates release v0.0.11 returned genuine 404; falling back to approved source release v0.0.11 in pos-desktop..."
                $expectedCommit = 'aa14fce3dc5c0ec15b3a38c8ae83010430a50cf7'
                $sourceCommit = (git rev-parse --verify 'v0.0.11^{commit}' 2>&1).Trim()
                if ($LASTEXITCODE -ne 0 -or $sourceCommit -ne $expectedCommit) {
                    throw "Source tag v0.0.11 does not resolve to pinned commit $expectedCommit (got: '$sourceCommit')."
                }

                $sourceRepo = 'ABDO-TECK/pos-desktop'
                $rawSourceOutput = & gh release download v0.0.11 --repo $sourceRepo --pattern deployment-inventory.json --dir $work 2>&1
                if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $inventory)) {
                    throw "Failed downloading deployment-inventory.json from source release v0.0.11: $($rawSourceOutput | Out-String)"
                }
                $inventoryResolved = $true
            } else {
                throw "Baseline release '$baselineTag' not found in updates repository (404), and no approved source fallback exists."
            }
        }
    }

    if (-not $inventoryResolved -or -not (Test-Path -LiteralPath $inventory)) {
        throw 'Baseline release has no deployment inventory; install/publish a new Setup baseline.'
    }

    # Pin approved v11 inventory version/hash AFTER ALL resolution paths
    if ($baselineTag -eq 'v0.0.11') {
        $actualSha256 = (Get-FileHash -LiteralPath $inventory -Algorithm SHA256).Hash.ToLowerInvariant()
        $expectedSha256 = '88475c625b47d0e60bba21ceb52923b8ab5e0b6eb1f57cfd4f495e7e1924ced2'
        if ($actualSha256 -ne $expectedSha256) {
            throw "Baseline v0.0.11 deployment-inventory.json SHA256 mismatch! Expected $expectedSha256, got $actualSha256"
        }

        $inventoryJson = Get-Content -LiteralPath $inventory -Raw | ConvertFrom-Json
        $invVersion = if ($inventoryJson.PSObject.Properties['version']) { $inventoryJson.version } else { $null }
        if ($invVersion -ne '0.0.11') {
            throw "Baseline v0.0.11 deployment-inventory.json version mismatch! Expected 0.0.11, got '$invVersion'"
        }
    }

    $output = Join-Path $work 'package'
    node scripts/validate-release-source.mjs --mode delta --tag $Tag --release-channel $Channel --baseline-ref $baselineTag --baseline-version $baselineVersion
    if ($LASTEXITCODE) { throw 'Delta source validation failed.' }
    php scripts/build-release-package.php "--tag=$Tag" "--from-ref=$baselineTag" "--from-version=$baselineVersion" "--baseline-inventory=$inventory" "--output-dir=$output"
    if ($LASTEXITCODE) { throw 'Delta generation failed.' }
    php scripts/verify-release-package.php "--release-dir=$output" "--target-version=$($version.version)" "--minimum-version=$baselineVersion"
    if ($LASTEXITCODE) { throw 'Signed package verification failed.' }
    node scripts/validate-release-source.mjs --mode delta --tag $Tag --release-channel $Channel --baseline-ref $baselineTag --baseline-version $baselineVersion --manifest "$output/manifest.json"
    if ($LASTEXITCODE) { throw 'Signed release metadata validation failed.' }

    $canonicalManifest = "release-assets/manifest-$baselineVersion.json"
    $canonicalSig = "release-assets/manifest-$baselineVersion.sig"
    $canonicalZip = "release-assets/delta-$baselineVersion-to-$($version.version).zip"

    Copy-SafeAsset -SourcePath "$output/manifest.json" -DestinationPath $canonicalManifest
    Copy-SafeAsset -SourcePath "$output/manifest.sig" -DestinationPath $canonicalSig
    Copy-SafeAsset -SourcePath "$output/delta-$baselineVersion-to-$($version.version).zip" -DestinationPath $canonicalZip
    Copy-SafeAsset -SourcePath "$output/deployment-inventory.json" -DestinationPath "release-assets/deployment-inventory.json"
    Copy-SafeAsset -SourcePath "$output/release-notes.md" -DestinationPath "release-assets/release-notes.md"

    # Emit generic aliases manifest.json/.sig/delta.zip (identical bytes, no clobber inconsistent files) since only 1 baseline
    if ($version.delta_baseline_tags.Count -eq 1) {
        $aliasManifest = 'release-assets/manifest.json'
        $aliasSig = 'release-assets/manifest.sig'
        $aliasZip = 'release-assets/delta.zip'

        Copy-SafeAsset -SourcePath "$output/manifest.json" -DestinationPath $aliasManifest
        Copy-SafeAsset -SourcePath "$output/manifest.sig" -DestinationPath $aliasSig
        Copy-SafeAsset -SourcePath "$output/delta-$baselineVersion-to-$($version.version).zip" -DestinationPath $aliasZip

        # Verify byte identity between canonical and alias files
        if ((Get-FileHash -LiteralPath $canonicalManifest -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $aliasManifest -Algorithm SHA256).Hash) {
            throw "Byte mismatch between $canonicalManifest and $aliasManifest"
        }
        if ((Get-FileHash -LiteralPath $canonicalSig -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $aliasSig -Algorithm SHA256).Hash) {
            throw "Byte mismatch between $canonicalSig and $aliasSig"
        }
        if ((Get-FileHash -LiteralPath $canonicalZip -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $aliasZip -Algorithm SHA256).Hash) {
            throw "Byte mismatch between $canonicalZip and $aliasZip"
        }
    }
}
