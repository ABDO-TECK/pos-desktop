param([ValidateSet('contract','acceptance')][string]$Phase='contract')
$ErrorActionPreference='Stop'
if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) { throw 'Ephemeral Windows runner required.' }
$lab=Join-Path $env:RUNNER_TEMP 'channel-transition-ui-lab'
if ($Phase -eq 'contract') {
    if (Test-Path -LiteralPath $lab) { throw 'Fresh transition lab required.' }
    foreach ($existing in @((Join-Path $env:PROGRAMDATA 'POS System'),(Join-Path $env:APPDATA 'POS System'),(Join-Path $env:APPDATA 'pos-desktop'))) {
        if (Test-Path -LiteralPath $existing) { throw 'Existing POS state blocks isolated acceptance.' }
    }
    New-Item -ItemType Directory -Path $lab | Out-Null
    gh release download v0.0.1 --repo ABDO-TECK/pos-desktop --pattern POS-Desktop-Setup-0.0.1.exe --pattern deployment-inventory.json --dir $lab
    if ($LASTEXITCODE) { throw 'Original Setup download failed.' }
    $setup=Join-Path $lab 'POS-Desktop-Setup-0.0.1.exe'
    if ((Get-FileHash -LiteralPath $setup).Hash.ToLowerInvariant() -ne '2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a') { throw 'Setup changed.' }
    if ((Get-FileHash -LiteralPath (Join-Path $lab 'deployment-inventory.json')).Hash.ToLowerInvariant() -ne 'dc8f213aa3cbd2659529eb6cd8b0d298c9138e74d4b9142e42a52c774a27f488') { throw 'Setup inventory changed.' }
    $seven=Join-Path $PWD 'node_modules/electron-winstaller/vendor/7z.exe'
    & $seven e $setup '$PLUGINSDIR\app-64.7z' "-o$lab" -y | Out-Null
    if ($LASTEXITCODE) { throw 'Cannot extract original Setup.' }
    & $seven x "$lab/app-64.7z" "-o$lab/app" -y | Out-Null
    if ($LASTEXITCODE) { throw 'Cannot extract original app.' }
    node scripts/verify-channel-transition-ui.mjs contract "$lab/app" "$env:RUNNER_TEMP/channel-transition-ui-evidence" release-assets
} else {
    node scripts/verify-channel-transition-ui.mjs acceptance "$lab/app" "$env:RUNNER_TEMP/channel-transition-ui-evidence" release-assets
}
if ($LASTEXITCODE) { throw 'Channel transition UI phase failed.' }
