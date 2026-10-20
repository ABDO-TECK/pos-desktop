[CmdletBinding()]
param([string]$Assets = 'release-assets')
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) { throw 'Real installer acceptance runs only on an ephemeral GitHub runner.' }
$lab = Join-Path $env:RUNNER_TEMP ('signed-repair-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $lab | Out-Null
$Assets = (Resolve-Path -LiteralPath $Assets).Path
$seven = (Resolve-Path 'node_modules/electron-winstaller/vendor/7z.exe').Path
gh release download v0.0.1 --repo ABDO-TECK/pos-desktop --pattern POS-Desktop-Setup-0.0.1.exe --dir $lab
if ($LASTEXITCODE) { throw 'Cannot download baseline Setup.' }
$setup = Join-Path $lab 'POS-Desktop-Setup-0.0.1.exe'
if ((Get-FileHash -LiteralPath $setup -Algorithm SHA256).Hash.ToLowerInvariant() -ne '2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a') { throw 'Published Setup baseline changed unexpectedly.' }
& $seven e $setup '$PLUGINSDIR\app-64.7z' "-o$lab/payload" -y | Out-Null
if ($LASTEXITCODE) { throw 'Cannot extract NSIS payload.' }
& $seven x "$lab/payload/app-64.7z" "-o$lab/baseline" -y | Out-Null
if ($LASTEXITCODE) { throw 'Cannot extract baseline files.' }
$tool = Join-Path $lab 'tool'
Expand-Archive -LiteralPath "$Assets/POS-Update-Repair-0.0.10-beta.2.zip" -DestinationPath $tool
$badTool = Join-Path $lab 'bad-tool'
Copy-Item -LiteralPath $tool -Destination $badTool -Recurse
[IO.File]::AppendAllText((Join-Path $badTool 'repair-engine.zip'),'tamper')
$proof = @()
foreach ($baseline in @('0.0.1','0.0.10-beta.1')) {
    $install = Join-Path $lab "installed-$baseline"
    New-Item -ItemType Directory -Path "$install/resources/portable" -Force | Out-Null
    Copy-Item -LiteralPath "$lab/baseline/POS Desktop.exe" -Destination $install
    Copy-Item -LiteralPath "$lab/baseline/resources/app.asar" -Destination "$install/resources"
    Copy-Item -LiteralPath "$lab/baseline/resources/app.asar.unpacked" -Destination "$install/resources" -Recurse
    Copy-Item -LiteralPath "$lab/baseline/resources/portable/php" -Destination "$install/resources/portable" -Recurse
    $root = "$install/resources/app.asar.unpacked"
    if ($baseline -eq '0.0.10-beta.1') {
        $old = Join-Path $lab 'beta1'
        New-Item -ItemType Directory -Path $old | Out-Null
        gh release download v0.0.10-beta.1 --repo ABDO-TECK/pos-desktop-updates --pattern 'delta-*.zip' --pattern 'manifest-0.0.1.json' --pattern 'manifest-0.0.1.sig' --dir $old
        if ($LASTEXITCODE) { throw 'Cannot download beta.1 baseline.' }
        $verified = Join-Path $old 'verified'
        New-Item -ItemType Directory -Path $verified | Out-Null
        Copy-Item -LiteralPath "$old/manifest-0.0.1.json" -Destination "$verified/manifest.json"
        Copy-Item -LiteralPath "$old/manifest-0.0.1.sig" -Destination "$verified/manifest.sig"
        Copy-Item -LiteralPath "$old/delta-0.0.1-to-0.0.10-beta.1.zip" -Destination $verified
        Copy-Item -LiteralPath "$old/delta-0.0.1-to-0.0.10-beta.1.zip" -Destination "$verified/delta.zip"
        [IO.File]::WriteAllText("$verified/release-notes.md",'Acceptance baseline')
        # Authenticate every executable byte BEFORE extracting or using its autoloader.
        php scripts/verify-release-package.php "--release-dir=$verified" --target-version=0.0.10-beta.1 --minimum-version=0.0.1
        if ($LASTEXITCODE) { throw 'Published beta.1 baseline failed pinned RSA/payload verification.' }
        $oldManifest = Get-Content "$old/manifest-0.0.1.json" -Raw | ConvertFrom-Json
        if ($oldManifest.from_version -ne '0.0.1' -or $oldManifest.channel -ne 'beta') { throw 'Incorrect beta.1 baseline association.' }
        foreach ($relative in (@($oldManifest.files.path) + @($oldManifest.deleted_files))) {
            if ($relative -notmatch '^(?:version\.json|backend/backend\.phar|frontend/dist/[^:]+)$' -or $relative -match '(^|[\\/])\.{1,2}([\\/]|$)' -or $relative.Contains('\')) { throw 'Unsafe beta.1 target.' }
            $candidate = [IO.Path]::GetFullPath((Join-Path $root $relative))
            if (-not $candidate.StartsWith([IO.Path]::GetFullPath($root) + '\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Beta.1 path escaped deployment root.' }
        }
        & $seven x "$old/delta-0.0.1-to-0.0.10-beta.1.zip" "-o$old/extracted" -y | Out-Null
        if ($LASTEXITCODE) { throw 'Cannot extract beta.1.' }
        foreach ($file in $oldManifest.files) {
            $from = Join-Path "$old/extracted" $file.path
            if (-not (Test-Path -LiteralPath $from)) { $from = Join-Path "$old/extracted/files" $file.path }
            $target = Join-Path $root $file.path
            New-Item -ItemType Directory -Path (Split-Path $target -Parent) -Force | Out-Null
            Copy-Item -LiteralPath $from -Destination $target -Force
        }
        foreach ($file in $oldManifest.deleted_files) {
            $target = [IO.Path]::GetFullPath((Join-Path $root $file))
            if (-not $target.StartsWith([IO.Path]::GetFullPath($root) + '\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe old baseline deletion.' }
            if (Test-Path -LiteralPath $target) { Remove-Item -LiteralPath $target }
        }
    }
    New-Item -ItemType Directory -Path "$root/backend/storage" -Force | Out-Null
    [IO.File]::WriteAllText("$root/backend/storage/business.db",'Business database sentinel must stay identical')
    [IO.File]::WriteAllText("$root/frontend/dist/digital-certificate.txt",'Per-device printing certificate')
    $manifest = Get-Content "$Assets/manifest-$baseline.json" -Raw | ConvertFrom-Json
    $affected = @($manifest.files.path) + @($manifest.deleted_files)
    $untouched = @{}
    foreach ($file in Get-ChildItem -LiteralPath $root -Recurse -File) {
        $relative = $file.FullName.Substring($root.Length + 1).Replace('\','/')
        if ($relative -notin $affected) {
            $file.LastWriteTimeUtc = [datetime]'2020-01-01'
            $untouched[$file.FullName] = @{hash=(Get-FileHash -LiteralPath $file.FullName).Hash;time=$file.LastWriteTimeUtc.Ticks}
        }
    }
    if ($baseline -eq '0.0.1') {
        & powershell -NoProfile -File "$badTool/Repair-POS.ps1" -InstallRoot $install -AssetsDirectory $Assets
        if ($LASTEXITCODE -eq 0) { throw 'Tampered signed repair engine accepted.' }
    }
    # A running service under the installation must stop repair before writes.
    $php = "$install/resources/portable/php/php.exe"
    $probe = Start-Process -FilePath $php -ArgumentList @('-r','sleep(90);') -WindowStyle Hidden -PassThru
    try {
        & powershell -NoProfile -File "$tool/Repair-POS.ps1" -InstallRoot $install -AssetsDirectory $Assets
        if ($LASTEXITCODE -eq 0) { throw 'Repair accepted running services.' }
    } finally { if (-not $probe.HasExited) { Stop-Process -Id $probe.Id -ErrorAction Stop; $probe.WaitForExit() } }
    & powershell -NoProfile -File "$tool/Repair-POS.ps1" -InstallRoot $install -AssetsDirectory $Assets
    if ($LASTEXITCODE) { throw "Signed repair failed on actual baseline $baseline" }
    foreach ($file in $manifest.files) {
        if ((Get-FileHash -LiteralPath (Join-Path $root $file.path)).Hash.ToLowerInvariant() -ne $file.sha256) { throw 'Applied bytes differ from signed payload.' }
    }
    foreach ($file in $manifest.deleted_files) { if (Test-Path -LiteralPath (Join-Path $root $file)) { throw 'Obsolete artifact survived repair.' } }
    foreach ($file in $untouched.Keys) {
        if ((Get-FileHash -LiteralPath $file).Hash -ne $untouched[$file].hash -or (Get-Item -LiteralPath $file).LastWriteTimeUtc.Ticks -ne $untouched[$file].time) { throw "Untouched file changed: $file" }
    }
    $proof += @{baseline=$baseline;target='0.0.10-beta.2';signedRepair=$true;runningServiceRejected=$true;unchangedFiles=$untouched.Count;dataPreserved=$true}
}
$proof | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath "$Assets/repair-acceptance.json" -Encoding utf8
Write-Host 'PASS signed repair on actual Setup 0.0.1 and published beta.1 artifacts; hashes, times, data and running-service guard verified.'
