[CmdletBinding()]
param([Parameter(Mandatory)][string] $Tag, [ValidateSet('stable','beta')][string] $Channel)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if (-not $env:GITHUB_ACTIONS -or $env:GITHUB_REPOSITORY -ne 'ABDO-TECK/pos-desktop-updates') { throw 'This publisher runs only in the project GitHub Actions repository.' }
if (-not $env:UPDATE_PRIVATE_KEY) { throw 'UPDATE_PRIVATE_KEY repository secret is required.' }

# Confine private key to signing children; never expose to test child processes or evidence
$signingKey = $env:UPDATE_PRIVATE_KEY
Remove-Item env:UPDATE_PRIVATE_KEY
$php = if ($env:PHP_BINARY) { $env:PHP_BINARY } else { 'php' }

function Sync-ReleaseCandidateTag {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string] $Tag,
        [Parameter(Mandatory)][string] $Channel,
        [Parameter(Mandatory)][psobject] $Version
    )

    $headCommitOutput = git rev-parse HEAD
    $headCommit = if ($headCommitOutput) { "$headCommitOutput".Trim() } else { '' }

    $tagList = git tag -l $Tag
    $existingTag = if ($tagList) { "$tagList".Trim() } else { '' }

    if ($existingTag) {
        $tagCommitOutput = git rev-parse -q --verify "refs/tags/$Tag^{commit}"
        $tagCommit = if ($tagCommitOutput) { "$tagCommitOutput".Trim() } else { '' }
        if ($tagCommit -ne $headCommit) {
            throw "Tag '$Tag' already exists and points to $tagCommit, which does not match HEAD ($headCommit). Refusing to force or retag."
        }
        Write-Host "Tag '$Tag' already exists and matches HEAD ($headCommit)."
        return
    }

    # Tag does not exist locally. Check if authorized to create ephemeral lightweight local candidate tag for CI validation:
    if ($env:GITHUB_ACTIONS -ne 'true' -or $env:GITHUB_REPOSITORY -ne 'ABDO-TECK/pos-desktop-updates') {
        return
    }

    if ($env:GITHUB_EVENT_NAME -ne 'workflow_dispatch') {
        return
    }

    if (-not $env:GITHUB_EVENT_PATH -or -not (Test-Path -LiteralPath $env:GITHUB_EVENT_PATH -PathType Leaf)) {
        return
    }

    $eventPayload = $null
    try {
        $rawEvent = Get-Content -LiteralPath $env:GITHUB_EVENT_PATH -Raw -ErrorAction Stop
        $eventPayload = $rawEvent | ConvertFrom-Json -ErrorAction Stop
    } catch {
        return
    }

    $inputsProperty = $eventPayload.PSObject.Properties['inputs']
    if ($null -eq $inputsProperty -or $null -eq $inputsProperty.Value) {
        return
    }

    # Must explicitly require publish === false or 'false' (not PowerShell truthiness)
    $publishProperty = $inputsProperty.Value.PSObject.Properties['publish']
    if ($null -eq $publishProperty) {
        return
    }
    $publish = $publishProperty.Value
    $isPublishExplicitFalse = ($publish -is [bool] -and -not $publish) -or ($publish -is [string] -and $publish.Trim() -eq 'false')
    if (-not $isPublishExplicitFalse) {
        return
    }

    # Workflow dispatch inputs.tag must match requested Tag parameter
    $tagProperty = $inputsProperty.Value.PSObject.Properties['tag']
    if ($null -eq $tagProperty -or $tagProperty.Value -ne $Tag) {
        return
    }

    # Must be stable channel
    if ($Channel -ne 'stable' -or $Version.channel -ne 'stable') {
        return
    }

    # Must match valid stable regex (vX.Y.Z)
    if ($Tag -notmatch '^v\d+\.\d+\.\d+$') {
        return
    }

    # Must match version.json version
    if ($Tag -ne "v$($Version.version)") {
        return
    }

    Write-Host "Creating ephemeral lightweight local candidate tag '$Tag' at HEAD ($headCommit) for CI validation (publish=false)..."
    git tag $Tag HEAD
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to create ephemeral lightweight candidate tag '$Tag' at HEAD."
    }
}

if ($Channel -eq 'stable') {
    Write-Host 'Running stable pre-packaging gates: release readiness helper tests and contracts...'
    node scripts/verify-stable-delta-readiness.mjs
    if ($LASTEXITCODE) { throw 'Stable delta readiness verification failed.' }
    Write-Host 'Running stable pre-packaging gates: PHP backend regressions and trigger healing...'
    & $php scripts/verify-admin-update-center.php
    if ($LASTEXITCODE) { throw 'Admin Update Center backend verification failed.' }
    & $php scripts/verify-trigger-healing.php
    if ($LASTEXITCODE) { throw 'Trigger healing verification failed.' }
    & $php scripts/verify-catalog-trigger-recovery.php
    if ($LASTEXITCODE) { throw 'Catalog trigger recovery verification failed.' }
    Write-Host 'Running stable pre-packaging gates: Admin Update Center real DOM frontend verification...'
    node scripts/verify-admin-update-center.mjs
    if ($LASTEXITCODE) { throw 'Admin Update Center real DOM frontend verification failed.' }
}

$version = Get-Content version.json -Raw | ConvertFrom-Json
Sync-ReleaseCandidateTag -Tag $Tag -Channel $Channel -Version $version
New-Item -ItemType Directory -Path release-assets -Force | Out-Null
foreach ($baselineTag in $version.delta_baseline_tags) {
    if ($baselineTag -notmatch '^v\d+\.\d+\.\d+(?:-beta\.\d+)?$') { throw 'Invalid baseline tag.' }
    $baselineVersion = $baselineTag.Substring(1)
    $work = Join-Path $env:RUNNER_TEMP ('delta-' + $baselineVersion)
    New-Item -ItemType Directory -Path $work -Force | Out-Null
    $baselineInventoryUrl = "https://raw.githubusercontent.com/ABDO-TECK/pos-desktop-updates/main/baselines/$baselineTag/deployment-inventory.json"
    try {
        $storedInventory = Invoke-WebRequest -Uri $baselineInventoryUrl -UseBasicParsing -ErrorAction Stop
        [IO.File]::WriteAllBytes((Join-Path $work 'deployment-inventory.json'), $storedInventory.RawContentStream.ToArray())
        $LASTEXITCODE = 0
    } catch {
        $responseProperty = $_.Exception.PSObject.Properties['Response']
        if ($null -eq $responseProperty -or $null -eq $responseProperty.Value -or [int] $responseProperty.Value.StatusCode -ne 404) { throw }
        gh release download $baselineTag --repo $env:GITHUB_REPOSITORY --pattern deployment-inventory.json --dir $work
    }
    if ($LASTEXITCODE) { throw 'Baseline release has no deployment inventory; install/publish a new Setup baseline.' }
    $inventory = Join-Path $work 'deployment-inventory.json'
    $output = Join-Path $work 'package'
    node scripts/validate-release-source.mjs --mode delta --tag $Tag --release-channel $Channel --baseline-ref $baselineTag --baseline-version $baselineVersion
    if ($LASTEXITCODE) { throw 'Delta source validation failed.' }
    try {
        $env:UPDATE_PRIVATE_KEY = $signingKey
        & $php scripts/build-release-package.php "--tag=$Tag" "--from-ref=$baselineTag" "--from-version=$baselineVersion" "--baseline-inventory=$inventory" "--output-dir=$output"
    } finally {
        Remove-Item env:UPDATE_PRIVATE_KEY -ErrorAction SilentlyContinue
    }
    if ($LASTEXITCODE) { throw 'Delta generation failed.' }
    & $php scripts/verify-release-package.php "--release-dir=$output" "--target-version=$($version.version)" "--minimum-version=$baselineVersion"
    if ($LASTEXITCODE) { throw 'Signed package verification failed.' }
    node scripts/validate-release-source.mjs --mode delta --tag $Tag --release-channel $Channel --baseline-ref $baselineTag --baseline-version $baselineVersion --manifest "$output/manifest.json"
    if ($LASTEXITCODE) { throw 'Signed release metadata validation failed.' }
    Copy-Item -LiteralPath "$output/manifest.json" -Destination "release-assets/manifest-$baselineVersion.json"
    Copy-Item -LiteralPath "$output/manifest.sig" -Destination "release-assets/manifest-$baselineVersion.sig"
    Copy-Item -LiteralPath "$output/delta-$baselineVersion-to-$($version.version).zip" -Destination release-assets
    Copy-Item -LiteralPath "$output/deployment-inventory.json" -Destination release-assets
    Copy-Item -LiteralPath "$output/release-notes.md" -Destination release-assets
}

$signingKey = $null

if ($Channel -eq 'stable') {
    Write-Host 'Running packaged stable delta upgrade UI acceptance gate...'
    & (Join-Path $PSScriptRoot 'prepare-stable-delta-ui.ps1') -Assets 'release-assets'
    if ($LASTEXITCODE) { throw 'Packaged stable delta UI acceptance failed.' }
}
