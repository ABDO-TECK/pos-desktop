[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Benchmark packaged bytes only on GitHub-hosted Windows Actions.'
}

$lab = Join-Path $env:RUNNER_TEMP 'shell-inapp'
foreach ($scenario in @('rollback', 'commit')) {
    $receipt = Get-Content -LiteralPath (Join-Path $lab "evidence/$scenario.json") -Raw | ConvertFrom-Json
    if ($receipt.passed -ne $true) { throw "Expected successful $scenario acceptance before measurement." }
}
# The historical archive targets the same accepted candidate, but uses the
# published source and an independent measurement-only signing key.
./scripts/verify-setup13-shell-data.ps1 -Profile historical
$openssl = Join-Path $env:ProgramFiles 'Git/usr/bin/openssl.exe'
$historicalKey = Join-Path $lab 'historical-private.pem'
try {
    & $openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out $historicalKey
    $arguments = @('scripts/build-shell-delta.php',
        '--source-root', (Join-Path $env:RUNNER_TEMP 'setup13-shell/source'),
        '--target-root', (Join-Path $lab 'target/win-unpacked'),
        '--source-native-version', '0.0.13', '--target-native-version', '0.0.14',
        '--source-data-contract', (Join-Path $lab 'published-source-contract.json'),
        '--target-data-contract', (Join-Path $lab 'target-contract.json'),
        '--private-key', $historicalKey, '--output-dir', (Join-Path $lab 'historical-release'))
    & php @arguments
} finally {
    Remove-Item -LiteralPath $historicalKey -ErrorAction SilentlyContinue
}

$zstdName = 'zstd-v1.5.7-win64'
$zstdArchive = Join-Path $lab "$zstdName.zip"
$zstdHash = 'acb4e8111511749dc7a3ebedca9b04190e37a17afeb73f55d4425dbf0b90fad9'
Invoke-WebRequest -Uri "https://github.com/meta/zstd/releases/download/v1.5.7/$zstdName.zip" -OutFile $zstdArchive -TimeoutSec 180
if ((Get-Item -LiteralPath $zstdArchive).Length -ne 1747181 -or
    (Get-FileHash -LiteralPath $zstdArchive).Hash.ToLowerInvariant() -cne $zstdHash) {
    throw 'The Zstandard tool archive differs from the pinned Windows x64 release.'
}
$tools = Join-Path $lab 'benchmark-tools'
Expand-Archive -LiteralPath $zstdArchive -DestinationPath $tools
$zstd = Join-Path $tools "$zstdName/zstd.exe"
python scripts/measure-shell-binary-delta.py $lab $zstd $openssl $zstdHash
