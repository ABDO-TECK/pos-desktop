[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Build these fixtures only on GitHub-hosted Windows Actions.'
}

$binary = $env:POS_SHELL_ACCEPTANCE_PROFILE -ceq 'binary'
$lab = Join-Path $env:RUNNER_TEMP $(if ($binary) { 'pos-binary' } else { 'shell-inapp' })
$official = Join-Path $env:RUNNER_TEMP $(if ($binary) { 'setup14-shell' } else { 'setup13-shell' })
$sourceVersion = if ($binary) { '0.0.14' } else { '0.0.13' }
$targetVersion = if ($binary) { '0.0.15' } else { '0.0.14' }
New-Item -ItemType Directory -Path (Join-Path $lab 'evidence') | Out-Null

# The public Setup provides pinned runtime files for the unpublished fixtures.
$officialAsar = Join-Path $official 'source/resources/app.asar'
$files = node -e 'const asar = require("@electron/asar"); console.log(JSON.stringify(asar.listPackage(process.argv[1])));' $officialAsar | ConvertFrom-Json
$files = @($files | ForEach-Object { $_.Replace('\', '/').TrimStart('/') })
$publicShellClient = 'electron/services/shell-update.js' -cin $files
if ($publicShellClient -ne $binary) {
    throw "Unexpected in-app Format 2 client inventory in published Setup $sourceVersion."
}
[ordered]@{
    scope = "Static archive inventory of verified published Setup $sourceVersion"
    app_asar_sha256 = (Get-FileHash -LiteralPath $officialAsar).Hash.ToLowerInvariant()
    in_app_format2_client_present = $publicShellClient
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
& 7z.exe e (Join-Path $official "POS-Desktop-Setup-$sourceVersion.exe") '$PLUGINSDIR\vc_redist.x64.exe' "-o$prerequisites" -y
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
$fixtureProfile = "require('electron').app.setPath('userData', require('node:path').join(process.env.LOCALAPPDATA, 'pos-ci-chromium'));"
Set-Content -LiteralPath $mainPath -Value ($fixtureProfile + [Environment]::NewLine + $main) -Encoding utf8NoBOM

# Pass a real loopback Chromium flag to owned relaunches in this CI build.
# The first launch already receives its own port from the automation driver.
$brokerSource = 'backend/Native/ShellUpdateProcessBroker.cs'
$broker = Get-Content -LiteralPath $brokerSource -Raw
$ownedStart = 'OwnedChildProcess.Start(executable, new string[0], root, environment)'
if ($broker.IndexOf($ownedStart) -lt 0 -or $broker.IndexOf($ownedStart) -ne $broker.LastIndexOf($ownedStart)) {
    throw 'Expected the unique native relaunch invocation in the packaged broker.'
}
$ciStart = 'OwnedChildProcess.Start(executable, new[] { environment["APPDATA"].EndsWith(@"\rollback-profile\roaming", StringComparison.OrdinalIgnoreCase) ? "--remote-debugging-port=19232" : "--remote-debugging-port=19234", "--remote-debugging-address=127.0.0.1" }, root, environment)'
Set-Content -LiteralPath $brokerSource -Value ($broker.Replace($ownedStart, $ciStart)) -Encoding utf8NoBOM
Add-Content -LiteralPath 'electron/preload.js' -Value "contextBridge.exposeInMainWorld('posCiElectronEngine', process.versions.electron);" -Encoding utf8NoBOM
$indexPath = 'frontend/index.html'
$index = Get-Content -LiteralPath $indexPath -Raw
$anchor = '<meta charset="UTF-8" />'
if ($index.IndexOf($anchor) -lt 0 -or $index.IndexOf($anchor) -ne $index.LastIndexOf($anchor)) {
    throw 'Expected a single Vite entry document marker.'
}
$healthPath = 'backend/Services/HealthService.php'
$healthSource = Get-Content -LiteralPath $healthPath -Raw
$healthAnchor = '$this->getVersionIdentifier(),'
if ($binary -and ([regex]::Matches($healthSource, [regex]::Escape($healthAnchor))).Count -ne 1) {
    throw 'Expected a single packaged PHP liveness marker.'
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
    if ($binary) {
        $health = $healthSource.Replace($healthAnchor, "$healthAnchor $([Environment]::NewLine)            'pos_ci_shell_build' => '$Role',")
        Set-Content -LiteralPath $healthPath -Value $health -Encoding utf8NoBOM -NoNewline
    }
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
const broker = asar.extractFile(archive, path.normalize('electron/assets/shell-update-broker.bin'));
assert.equal(broker.readUInt16LE(0), 0x5a4d, 'The packaged native broker must be a PE executable.');
const peHeader = broker.readUInt32LE(0x3c);
assert.equal(broker.readUInt32LE(peHeader), 0x00004550, 'Invalid native broker PE header.');
assert.equal(broker.readUInt16LE(peHeader + 4), 0x8664, 'The packaged native broker must be x64.');
console.log(sha(broker));
'@
    $brokerHash = node -e $asarCheck (Join-Path $product 'resources/app.asar')
    if ($brokerHash -cnotmatch '^[a-f0-9]{64}$') { throw "Could not identify the packaged native broker for $Role." }
    [ordered]@{
        scope = 'Unpublished CI product with ephemeral trust and loopback CDP'
        commit = $env:GITHUB_SHA
        role = $Role
        version = $Version
        electron_runtime = $Engine.version
        electron_archive_asset_id = $Engine.asset_id
        electron_archive_sha256 = $Engine.sha256
        asar_embedded_native_components_verified = $true
        native_broker_sha256 = $brokerHash
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
        '--source-native-version', $sourceVersion, '--target-native-version', $targetVersion,
        '--source-data-contract', $SourceContract,
        '--target-data-contract', (Join-Path $lab 'target-contract.json'),
        '--private-key', $key, '--output-dir', $Release)
    if ($binary) {
        # Run the release builder with the same libzip/zlib as the source that reconstructs the ZIP.
        $phpRoot = Join-Path $Source 'resources/portable/php'
        $php = Join-Path $phpRoot 'php.exe'
        $extensions = Join-Path $phpRoot 'ext'
        & $php -n -d "extension_dir=$extensions" -d 'extension=openssl' -d 'extension=zip' @arguments
    } else {
        & php @arguments
    }
}

function Write-BinaryFixtureReceipt {
    param([string] $Role)
    $release = Join-Path $lab "$Role/release"
    $descriptor = Get-Content -LiteralPath (Join-Path $release 'shell-manifest.json') -Raw | ConvertFrom-Json
    $bin = Join-Path $release 'shell-binary.bin'
    $archive = [IO.Compression.ZipFile]::OpenRead($bin)
    try {
        $entries = @($archive.Entries | Where-Object { $_.FullName -cmatch '^p/\d+$' } | ForEach-Object {
            $relative = $descriptor.files[[int]$_.FullName.Substring(2)].path
            [ordered]@{
                path = $relative
                source_sha256 = $descriptor.source_inventory.files.PSObject.Properties[$relative].Value.sha256
                target_sha256 = $descriptor.target_inventory.files.PSObject.Properties[$relative].Value.sha256
                compressed_patch_bytes = $_.CompressedLength
            }
        })
        $corePaths = @('POS Desktop.exe', 'resources/app.asar', 'resources/app.asar.unpacked/backend/backend.phar')
        if (-not ($entries | Where-Object { $_.path -cin $corePaths })) {
            throw 'Expected a genuine binary patch for at least one installed Electron or PHP program file.'
        }
        [ordered]@{
            payload_sha256 = (Get-FileHash -LiteralPath $bin).Hash.ToLowerInvariant()
            payload_bytes = (Get-Item -LiteralPath $bin).Length
            patch_entries = $entries
        } | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $lab "evidence/patches-$Role.json") -Encoding utf8NoBOM
    } finally {
        $archive.Dispose()
    }
}

try {
    Build-Fixture source $sourceVersion $sourceEngine -Installer
    Build-Fixture target $targetVersion $targetEngine

    # Start the real MariaDB/PHP pair and fail before the desktop reaches sign-in.
    # This trace witnesses execution. The supervisor independently owns recovery.
    $startup = [regex]::Matches($main, '(?m)^    (?:const started = )?await startCoreRuntime\(\{ maxAttempts: [^\r\n]+ \}\);')
    if ($startup.Count -ne 1) { throw 'Expected the unique packaged startup anchor.' }
    $failure = @'
    const powershell = path.join(process.env.SystemRoot, 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe');
    const createdAt = require('node:child_process').execFileSync(powershell, [
      '-NoProfile', '-NonInteractive', '-Command',
      "(Get-Process -Id " + process.pid + ").StartTime.ToUniversalTime().ToString('o')",
    ], { encoding: 'utf8', windowsHide: true, timeout: 12000 }).trim();
    fs.writeFileSync(path.join(process.env.APPDATA, 'candidate-failure.json'), JSON.stringify({
      pid: process.pid, started_at: createdAt, phpPort, mysqlPort, electronEngine: process.versions.electron, phase: 'core-ready-before-exit',
    }));
    app.exit(78);
    return;
'@
    $badMain = $main.Insert($startup[0].Index + $startup[0].Length, [Environment]::NewLine + $failure)
    Set-Content -LiteralPath $mainPath -Value ($fixtureProfile + [Environment]::NewLine + $badMain) -Encoding utf8NoBOM
    Build-Fixture failing $targetVersion $targetEngine -ReuseFrontendAndPhar

    ./scripts/verify-setup13-shell-data.ps1 -Profile in-app
    foreach ($role in @('target', 'failing')) {
        Sign-Fixture (Join-Path $lab 'source/installed-payload') (Join-Path $lab "$role/win-unpacked") (Join-Path $lab 'source-contract.json') (Join-Path $lab "$role/release")
        if ($binary) { Write-BinaryFixtureReceipt $role }
    }
    if ($binary) {
        $formatFixture = Join-Path $lab 'unsupported'
        New-Item -ItemType Directory -Path $formatFixture | Out-Null
        $formatPointer = Join-Path $formatFixture 'shell-binary.json'
        $formatSignature = Join-Path $formatFixture 'shell-binary.signature'
        $pointer = Get-Content -LiteralPath (Join-Path $lab 'target/release/shell-binary.json') -Raw | ConvertFrom-Json -AsHashtable
        $pointer.format = 999
        $pointer | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $formatPointer -Encoding utf8NoBOM
        $sign = @'
const { sign } = require('node:crypto');
const { readFileSync, writeFileSync } = require('node:fs');
const [key, pointer, signature] = process.argv.slice(1);
writeFileSync(signature, sign('sha256', readFileSync(pointer), readFileSync(key)).toString('base64'));
'@
        node -e $sign $key $formatPointer $formatSignature
    }
} finally {
    Remove-Item -LiteralPath $key -ErrorAction SilentlyContinue
}
