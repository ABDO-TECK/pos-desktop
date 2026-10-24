[CmdletBinding()]
param([string]$Assets = 'release-assets')
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# Strict ephemeral runner guards
if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) {
    throw 'Real stable delta acceptance runs only on an ephemeral GitHub Actions Windows runner.'
}

# Strict no-preexisting POS data guard
foreach ($existing in @((Join-Path $env:PROGRAMDATA 'POS System'), (Join-Path $env:APPDATA 'POS System'), (Join-Path $env:APPDATA 'pos-desktop'))) {
    if (Test-Path -LiteralPath $existing) {
        throw "Existing POS state ($existing) blocks isolated stable delta acceptance."
    }
}

$lab = Join-Path $env:RUNNER_TEMP 'stable-delta-ui-lab'
if (Test-Path -LiteralPath $lab) {
    throw 'Stable delta lab directory must be fresh and isolated.'
}
New-Item -ItemType Directory -Path $lab | Out-Null

$evidence = Join-Path $env:RUNNER_TEMP 'stable-delta-ui-evidence'
New-Item -ItemType Directory -Path $evidence -Force | Out-Null

$AssetsPath = (Resolve-Path -LiteralPath $Assets).Path

# Locate 7z extractor
$seven = Join-Path $PWD 'node_modules/electron-winstaller/vendor/7z.exe'
if (-not (Test-Path -LiteralPath $seven)) {
    $sevenCmd = Get-Command 7z.exe -ErrorAction SilentlyContinue
    if ($sevenCmd) { $seven = $sevenCmd.Source }
}
if (-not (Test-Path -LiteralPath $seven)) {
    throw '7z extraction utility not found.'
}

# 1. Download original published Setup 0.0.1
Write-Host 'Downloading original published Setup v0.0.1 baseline...'
gh release download v0.0.1 --repo ABDO-TECK/pos-desktop --pattern POS-Desktop-Setup-0.0.1.exe --pattern deployment-inventory.json --dir $lab
if ($LASTEXITCODE) { throw 'Cannot download original Setup baseline.' }

$setup = Join-Path $lab 'POS-Desktop-Setup-0.0.1.exe'
$actualSetupHash = (Get-FileHash -LiteralPath $setup -Algorithm SHA256).Hash.ToLowerInvariant()
$expectedSetupHash = '2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a'
if ($actualSetupHash -ne $expectedSetupHash) {
    throw "Published Setup baseline SHA256 mismatch: expected $expectedSetupHash, got $actualSetupHash"
}

# Extract Setup payload to obtain baseline application
Write-Host 'Extracting baseline Setup application...'
& $seven e $setup '$PLUGINSDIR\app-64.7z' "-o$lab" -y | Out-Null
if ($LASTEXITCODE) { throw 'Cannot extract original Setup payload.' }

$appSetup = Join-Path $lab 'app-setup'
& $seven x (Join-Path $lab 'app-64.7z') "-o$appSetup" -y | Out-Null
if ($LASTEXITCODE) { throw 'Cannot extract original baseline application.' }

# 2. Download published beta10 baseline assets (delta from 0.0.1 to beta10)
Write-Host 'Downloading published v0.0.10-beta.10 baseline assets...'
$beta10Assets = Join-Path $lab 'beta10-assets'
New-Item -ItemType Directory -Path $beta10Assets | Out-Null
gh release download v0.0.10-beta.10 --repo ABDO-TECK/pos-desktop-updates --pattern 'delta-0.0.1-to-0.0.10-beta.10.zip' --pattern 'manifest-0.0.1.*' --pattern 'deployment-inventory.json' --dir $beta10Assets
if ($LASTEXITCODE) { throw 'Cannot download published v0.0.10-beta.10 baseline assets.' }

$appBeta10 = Join-Path $lab 'app-beta10'
Copy-Item -LiteralPath $appSetup -Destination $appBeta10 -Recurse

# Scrub sensitive tokens from environment before executing acceptance
Remove-Item env:UPDATE_PRIVATE_KEY -ErrorAction SilentlyContinue
Remove-Item env:GH_TOKEN -ErrorAction SilentlyContinue
Remove-Item env:GITHUB_TOKEN -ErrorAction SilentlyContinue

# Execute packaged stable upgrade acceptance
Write-Host 'Executing packaged stable delta upgrade acceptance (Setup 0.0.1 and beta10 -> stable 0.0.10)...'
node scripts/verify-stable-delta-ui.mjs "$lab" "$evidence" "$AssetsPath"
if ($LASTEXITCODE) { throw 'Packaged stable delta UI acceptance script failed.' }

# Copy safe public acceptance evidence into release assets
$evidenceFile = Join-Path $evidence 'acceptance.json'
if (-not (Test-Path -LiteralPath $evidenceFile)) {
    throw 'Acceptance evidence file was not generated.'
}
Copy-Item -LiteralPath $evidenceFile -Destination (Join-Path $AssetsPath 'stable-delta-acceptance.json')
Write-Host 'PASS: Packaged stable delta UI acceptance passed and public evidence saved.'
