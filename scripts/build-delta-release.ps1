[CmdletBinding()]
param([Parameter(Mandatory)][string] $Tag, [ValidateSet('stable','beta','rc')][string] $Channel)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if (-not $env:GITHUB_ACTIONS -or $env:GITHUB_REPOSITORY -ne 'ABDO-TECK/pos-desktop-updates') { throw 'This publisher runs only in the project GitHub Actions repository.' }
if (-not $env:UPDATE_PRIVATE_KEY) { throw 'UPDATE_PRIVATE_KEY repository secret is required.' }
$version = Get-Content version.json -Raw | ConvertFrom-Json
New-Item -ItemType Directory -Path release-assets -Force | Out-Null
foreach ($baselineTag in $version.delta_baseline_tags) {
    if ($baselineTag -notmatch '^v\d+\.\d+\.\d+(?:-(?:beta|rc)\.\d+)?$') { throw 'Invalid baseline tag.' }
    $baselineVersion = $baselineTag.Substring(1)
    $work = Join-Path $env:RUNNER_TEMP ('delta-' + $baselineVersion)
    New-Item -ItemType Directory -Path $work -Force | Out-Null
    $inventoryReleaseTag = "baseline-$baselineTag"
    gh release view $inventoryReleaseTag --repo $env:GITHUB_REPOSITORY --json tagName > $null 2>&1
    if ($LASTEXITCODE) { $inventoryReleaseTag = $baselineTag }
    gh release download $inventoryReleaseTag --repo $env:GITHUB_REPOSITORY --pattern deployment-inventory.json --dir $work
    if ($LASTEXITCODE) { throw 'Baseline release has no deployment inventory; install/publish a new Setup baseline.' }
    $inventory = Join-Path $work 'deployment-inventory.json'
    $output = Join-Path $work 'package'
    node scripts/validate-release-source.mjs --mode delta --tag $Tag --release-channel $Channel --baseline-ref $baselineTag --baseline-version $baselineVersion
    if ($LASTEXITCODE) { throw 'Delta source validation failed.' }
    php scripts/build-release-package.php "--tag=$Tag" "--from-ref=$baselineTag" "--from-version=$baselineVersion" "--baseline-inventory=$inventory" "--output-dir=$output"
    if ($LASTEXITCODE) { throw 'Delta generation failed.' }
    php scripts/verify-release-package.php "--release-dir=$output" "--target-version=$($version.version)" "--minimum-version=$baselineVersion"
    if ($LASTEXITCODE) { throw 'Signed package verification failed.' }
    node scripts/validate-release-source.mjs --mode delta --tag $Tag --release-channel $Channel --baseline-ref $baselineTag --baseline-version $baselineVersion --manifest "$output/manifest.json"
    if ($LASTEXITCODE) { throw 'Signed release metadata validation failed.' }
    Copy-Item -LiteralPath "$output/manifest.json" -Destination "release-assets/manifest-$baselineVersion.json"
    Copy-Item -LiteralPath "$output/manifest.sig" -Destination "release-assets/manifest-$baselineVersion.sig"
    Copy-Item -LiteralPath "$output/delta-$baselineVersion-to-$($version.version).zip" -Destination release-assets
    Copy-Item -LiteralPath "$output/deployment-inventory.json" -Destination release-assets
    Copy-Item -LiteralPath "$output/release-notes.md" -Destination release-assets
}
