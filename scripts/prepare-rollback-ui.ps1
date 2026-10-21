$ErrorActionPreference='Stop'
if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) { throw 'Ephemeral runner required.' }
$lab=Join-Path $env:RUNNER_TEMP 'rollback-ui-lab'
if (Test-Path -LiteralPath $lab) { throw 'Use a fresh rollback acceptance directory.' }
New-Item -ItemType Directory -Path $lab | Out-Null
gh release download v0.0.1 --repo ABDO-TECK/pos-desktop --pattern POS-Desktop-Setup-0.0.1.exe --dir $lab
if ($LASTEXITCODE) { throw 'Cannot download original Setup.' }
$setup=Join-Path $lab 'POS-Desktop-Setup-0.0.1.exe'
if ((Get-FileHash -LiteralPath $setup).Hash.ToLowerInvariant() -ne '2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a') { throw 'Setup baseline changed.' }
$seven=Join-Path $PWD 'node_modules/electron-winstaller/vendor/7z.exe'
& $seven e $setup '$PLUGINSDIR\app-64.7z' "-o$lab" -y | Out-Null
if ($LASTEXITCODE) { throw 'Cannot extract installer payload.' }
& $seven x "$lab/app-64.7z" "-o$lab/app" -y | Out-Null
if ($LASTEXITCODE) { throw 'Cannot extract baseline application.' }
$prior=Join-Path $lab 'prior-assets'
New-Item -ItemType Directory -Path $prior | Out-Null
gh release download v0.0.10-beta.3 --repo ABDO-TECK/pos-desktop-updates --pattern 'delta-0.0.1-to-0.0.10-beta.3.zip' --pattern 'manifest-0.0.1.*' --dir $prior
if ($LASTEXITCODE) { throw 'Cannot download signed intermediate beta baseline.' }
$env:POS_ROLLBACK_PRIOR_ASSETS=$prior
node scripts/verify-rollback-ui.mjs "$lab/app" "$env:RUNNER_TEMP/rollback-ui-evidence" release-assets
if ($LASTEXITCODE) { throw 'Real rollback UI acceptance failed.' }
Copy-Item -LiteralPath "$env:RUNNER_TEMP/rollback-ui-evidence/acceptance.json" -Destination 'release-assets/rollback-acceptance.json'
