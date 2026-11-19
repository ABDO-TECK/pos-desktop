[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Build these fixtures only on GitHub-hosted Windows Actions.'
}

$lab = Join-Path $env:RUNNER_TEMP 'shell-inapp'
$official = Join-Path $env:RUNNER_TEMP 'setup13-shell'
New-Item -ItemType Directory -Path (Join-Path $lab 'evidence') | Out-Null

# The public Setup is the benchmark baseline. Record the actual shipped JS
# inventory gap that prevents it from being the source of the new UI proof.
$officialAsar = Join-Path $official 'source/resources/app.asar'
$files = node -e 'const asar = require("@electron/asar"); console.log(JSON.stringify(asar.listPackage(process.argv[1])));' $officialAsar | ConvertFrom-Json
$files = @($files | ForEach-Object { $_.Replace('\', '/').TrimStart('/') })
if ('electron/services/shell-update.js' -cin $files) {
    throw 'Unexpected in-app Format 2 client in published Setup 0.0.13.'
}
[ordered]@{
    scope = 'Static archive inventory of the verified published Setup 0.0.13'
    app_asar_sha256 = (Get-FileHash -LiteralPath $officialAsar).Hash.ToLowerInvariant()
    in_app_format2_client_present = $false
} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $lab 'evidence/published-client-scope.json') -Encoding utf8NoBOM

function Prepare-Electron {
    param([string] $Version, [long] $Size, [string] $Sha256, [long] $AssetId)
    $archiveName = "electron-v$Version-win32-x64.zip"
    $archive = Join-Path $lab $archiveName
    Invoke-WebRequest -Uri "https://github.com/electron/electron/releases/download/v$Version/$archiveName" -OutFile $archive -TimeoutSec 600 -MaximumRetryCount 2 -RetryIntervalSec 3
    if ((Get-Item -LiteralPath $archive).Length -ne $Size -or (Get-FileHash -LiteralPath $archive).Hash.ToLowerInvariant() -cne $Sha256) {
        throw "Electron $Version archive does not match the pinned x64 runtime."
    }
    $distribution = Join-Path $lab "electron-$Version"
    Expand-Archive -LiteralPath $archive -DestinationPath $distribution
    Remove-Item -LiteralPath (Join-Path $distribution 'resources/default_app.asar'), (Join-Path $distribution 'version')
    return [pscustomobject]@{ version = $Version; sha256 = $Sha256; asset_id = $AssetId; distribution = $distribution }
}
$sourceEngine = Prepare-Electron '43.2.0' 144326439 'eba5f5088af40ecb364fe258809c79a5234c6ece5a75c64722772eba01b02786' 484988681
$targetEngine = Prepare-Electron '43.8.11' 151377887 '25de7bab4a1b940776b2cc06cb9d2698fec903554188be5977394a0373b75904' 760039177

if (Test-Path -LiteralPath 'portable') { throw 'Expected a fresh checkout with no portable runtime.' }
Copy-Item -LiteralPath (Join-Path $official 'source/resources/portable') -Destination 'portable' -Recurse
$redistributable = Get-Content -LiteralPath 'scripts/desktop-runtime.json' -Raw | ConvertFrom-Json
$prerequisites = Join-Path $PWD 'build/prerequisites'
& 7z.exe e (Join-Path $official 'POS-Desktop-Setup-0.0.13.exe') '$PLUGINSDIR\vc_redist.x64.exe' "-o$prerequisites" -y
if ((Get-FileHash -LiteralPath (Join-Path $prerequisites $redistributable.vcredist.archiveName)).Hash.ToLowerInvariant() -cne $redistributable.vcredist.sha256.ToLowerInvariant()) {
    throw 'Extracted Visual C++ prerequisite did not match the pinned hash.'
}

# The runner pins an ephemeral manifest public key and TLS CA at build time.
# The source and both candidate packages use the same trust inputs.
$openssl = Join-Path $env:ProgramFiles 'Git/usr/bin/openssl.exe'
$key = Join-Path $lab 'manifest-private.pem'
$caKey = Join-Path $lab 'ca-private.pem'
$ca = Join-Path $lab 'ca.pem'
$serverKey = Join-Path $lab 'server-private.pem'
$serverCsr = Join-Path $lab 'server.csr'
$serverCert = Join-Path $lab 'server.pem'
$serverExt = Join-Path $lab 'server.ext'
'extendedKeyUsage=serverAuth', 'subjectAltName=DNS:api.github.com,DNS:github.com' |
    Set-Content -LiteralPath $serverExt -Encoding ascii

$env:MSYS2_ARG_CONV_EXCL = '*'
try {
    & $openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out $key
    & $openssl pkey -in $key -pubout -out 'backend/certs/update_public_key.pem'
    & $openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 2 -subj '/CN=POS Actions Fixture CA' -keyout $caKey -out $ca
    & $openssl req -new -newkey rsa:2048 -nodes -subj '/CN=api.github.com' -keyout $serverKey -out $serverCsr
    & $openssl x509 -req -in $serverCsr -CA $ca -CAkey $caKey -CAcreateserial -days 2 -sha256 -extfile $serverExt -out $serverCert
    Add-Content -LiteralPath 'backend/certs/cacert.pem' -Value ([Environment]::NewLine + (Get-Content -LiteralPath $ca -Raw)) -Encoding ascii
} finally {
    Remove-Item -LiteralPath $caKey -ErrorAction SilentlyContinue
}

# The instrumentation exists only in these generated CI packages. CDP is used
# to drive and observe the same renderer after the supervisor launches it.
$mainPath = 'electron/main.js'
$main = Get-Content -LiteralPath $mainPath -Raw
$debugSwitch = "require('electron').app.commandLine.appendSwitch('remote-debugging-port', '19231'); require('electron').app.setPath('userData', require('node:path').join(process.env.LOCALAPPDATA, 'pos-ci-chromium'));"
Set-Content -LiteralPath $mainPath -Value ($debugSwitch + [Environment]::NewLine + $main) -Encoding utf8NoBOM
Add-Content -LiteralPath 'electron/preload.js' -Value "contextBridge.exposeInMainWorld('posCiElectronEngine', process.versions.electron);" -Encoding utf8NoBOM
$indexPath = 'frontend/index.html'
$index = Get-Content -LiteralPath $indexPath -Raw
$anchor = '<meta charset="UTF-8" />'
if ($index.IndexOf($anchor) -lt 0 -or $index.IndexOf($anchor) -ne $index.LastIndexOf($anchor)) {
    throw 'Expected a single Vite entry document marker.'
}

function Set-FixtureVersion {
    param([string] $Version, [string] $Role)
    foreach ($file in @('package.json', 'version.json')) {
        $metadata = Get-Content -LiteralPath $file -Raw | ConvertFrom-Json -AsHashtable
        $metadata.version = $Version
        if ($file -ceq 'version.json') {
            if ($metadata.update_channel -cne 'stable') { throw 'Expected a stable fixture.' }
            $metadata.application_version = $Version
            $metadata.installer_version = $Version
        }
        $metadata | ConvertTo-Json -Depth 50 | Set-Content -LiteralPath $file -Encoding utf8NoBOM
    }
    $asset = $index.Replace($anchor, "$anchor <meta name='pos-ci-shell-build' content='$Role' />")
    Set-Content -LiteralPath $indexPath -Value $asset -Encoding utf8NoBOM -NoNewline
}

function Build-Fixture {
    param([string] $Role, [string] $Version, $Engine, [switch] $Installer, [switch] $ReuseFrontendAndPhar)
    if (-not $ReuseFrontendAndPhar) {
        Set-FixtureVersion $Version $Role
        npm run frontend:build
        npm run build:phar
        php scripts/verify-phar-runtime.php
    }

    $out = Join-Path $lab $Role
    $builderArgs = @('--win', '--x64', '--publish', 'never',
        "-c.electronVersion=$($Engine.version)", "-c.electronDist=$($Engine.distribution)", "-c.directories.output=$out")
    if (-not $Installer) { $builderArgs += '--dir' }
    & ./node_modules/.bin/electron-builder.cmd @builderArgs

    $product = Join-Path $out 'win-unpacked'
    if ($Installer) {
        # Build against the product bytes embedded in the actual CI installer.
        $exactArchive = Join-Path $out 'setup-archive'
        $setup = Join-Path $out "POS-Desktop-Setup-$Version.exe"
        & 7z.exe e $setup '$PLUGINSDIR\app-64.7z' "-o$exactArchive" -y
        $product = Join-Path $out 'installed-payload'
        & 7z.exe x (Join-Path $exactArchive 'app-64.7z') "-o$product" -y
    }
    $exe = Get-Item -LiteralPath (Join-Path $product 'POS Desktop.exe')
    if ($exe.VersionInfo.ProductVersion -cnotmatch ('^{0}(?:\.0)?$' -f [regex]::Escape($Version))) {
        throw "Unexpected PE product version for $Role."
    }
    $asarCheck = @'
const asar = require('@electron/asar');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const archive = process.argv[1];
const assets = {
  'electron/assets/shell-update-public-key.pem': 'backend/certs/update_public_key.pem',
  'electron/assets/OwnedChildProcess.cs': 'backend/Native/OwnedChildProcess.cs',
};
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
for (const [entry, expected] of Object.entries(assets)) {
  assert.equal(sha(asar.extractFile(archive, path.normalize(entry))), sha(fs.readFileSync(expected)), entry);
}
'@
    node -e $asarCheck (Join-Path $product 'resources/app.asar')
    [ordered]@{
        scope = 'Unpublished CI product with ephemeral trust and loopback CDP'
        commit = $env:GITHUB_SHA
        role = $Role
        version = $Version
        electron_runtime = $Engine.version
        electron_archive_asset_id = $Engine.asset_id
        electron_archive_sha256 = $Engine.sha256
        asar_embedded_native_components_verified = $true
        exe_sha256 = (Get-FileHash -LiteralPath $exe.FullName).Hash.ToLowerInvariant()
        asar_sha256 = (Get-FileHash -LiteralPath (Join-Path $product 'resources/app.asar')).Hash.ToLowerInvariant()
        phar_sha256 = (Get-FileHash -LiteralPath (Join-Path $product 'resources/app.asar.unpacked/backend/backend.phar')).Hash.ToLowerInvariant()
        setup_sha256 = if ($Installer) { (Get-FileHash -LiteralPath $setup).Hash.ToLowerInvariant() } else { $null }
    } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $lab "evidence/package-$Role.json") -Encoding utf8NoBOM
}

function Sign-Fixture {
    param([string] $Source, [string] $Target, [string] $SourceContract, [string] $Release)
    $arguments = @('scripts/build-shell-delta.php',
        '--source-root', $Source, '--target-root', $Target,
        '--source-native-version', '0.0.13', '--target-native-version', '0.0.14',
        '--source-data-contract', $SourceContract,
        '--target-data-contract', (Join-Path $lab 'target-contract.json'),
        '--private-key', $key, '--output-dir', $Release)
    & php @arguments
}

try {
    Build-Fixture source '0.0.13' $sourceEngine -Installer
    Build-Fixture target '0.0.14' $targetEngine

    # Start the real MariaDB/PHP pair and fail before the desktop reaches sign-in.
    # This trace witnesses execution. The supervisor independently owns recovery.
    $startup = [regex]::Matches($main, '(?m)^    (?:const started = )?await startCoreRuntime\(\{ maxAttempts: [^\r\n]+ \}\);')
    if ($startup.Count -ne 1) { throw 'Expected the unique packaged startup anchor.' }
    $failure = "    fs.writeFileSync(path.join(process.env.APPDATA, 'candidate-failure.json'), JSON.stringify({ pid: process.pid, phpPort, mysqlPort, electronEngine: process.versions.electron })); process.exit(78);"
    $badMain = $main.Insert($startup[0].Index + $startup[0].Length, [Environment]::NewLine + $failure)
    Set-Content -LiteralPath $mainPath -Value ($debugSwitch + [Environment]::NewLine + $badMain) -Encoding utf8NoBOM
    Build-Fixture failing '0.0.14' $targetEngine -ReuseFrontendAndPhar

    ./scripts/verify-setup13-shell-data.ps1 -Profile in-app
    foreach ($role in @('target', 'failing')) {
        Sign-Fixture (Join-Path $lab 'source/installed-payload') (Join-Path $lab "$role/win-unpacked") (Join-Path $lab 'source-contract.json') (Join-Path $lab "$role/release")
    }
} finally {
    Remove-Item -LiteralPath $key -ErrorAction SilentlyContinue
}
