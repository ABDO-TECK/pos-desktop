[CmdletBinding()]
param(
    [ValidateSet('setup13-shell', 'in-app', 'historical', 'release')] [string] $Profile = 'setup13-shell',
    [ValidatePattern('^shell-release-[a-f0-9]{32}$')] [string] $ReleaseLab
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if (-not $IsWindows -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_ENVIRONMENT -cne 'github-hosted') {
    throw 'Measure packaged database contracts only on GitHub-hosted Windows Actions.'
}

$InAppFixture = $Profile -cne 'setup13-shell'
if (($Profile -ceq 'release') -ne [bool]$ReleaseLab) {
    throw 'The release measurement requires an isolated release lab name.'
}
$labName = if ($ReleaseLab) { $ReleaseLab }
    elseif (-not $InAppFixture) { 'setup13-shell' }
    elseif ($Profile -ceq 'in-app' -and $env:POS_SHELL_ACCEPTANCE_PROFILE -ceq 'binary') { 'pos-binary' }
    else { 'shell-inapp' }
$lab = Join-Path $env:RUNNER_TEMP $labName

function Measure-PackagedDatabase {
    param([string] $Root, [string] $Role, [string] $DataDir)

    $mysql = Join-Path $Root 'resources/portable/mysql'
    $payload = Join-Path $Root 'resources/app.asar.unpacked'
    $phar = Join-Path $payload 'backend/backend.phar'
    $schema = Join-Path $payload 'database/pos_schema.sql'
    $pidFile = Join-Path $lab "$Role.pid"
    $stderr = Join-Path $lab "$Role-mysql.stderr.log"

    if (Test-Path -LiteralPath $DataDir) { throw "Expected fresh MariaDB data directory for $Role." }
    & (Join-Path $mysql 'bin/mysql_install_db.exe') "--datadir=$DataDir"
    if ($LASTEXITCODE) { throw "$Role database initialization failed." }

    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try { $port = $listener.LocalEndpoint.Port }
    finally { $listener.Stop() }

    $serverArgs = @('--no-defaults', '--standalone', '--console', '--bind-address=127.0.0.1',
        '--skip-name-resolve', "--port=$port", ('--basedir="{0}"' -f $mysql), ('--datadir="{0}"' -f $DataDir),
        ('--pid-file="{0}"' -f $pidFile))
    $server = Start-Process -FilePath (Join-Path $mysql 'bin/mysqld.exe') -WindowStyle Hidden -PassThru -ArgumentList $serverArgs -RedirectStandardError $stderr
    $client = Join-Path $mysql 'bin/mysql.exe'
    $clientArgs = @('--no-defaults', '--protocol=tcp', '--host=127.0.0.1', "--port=$port", '--user=root',
        '--connect-timeout=2', '--default-character-set=utf8mb4')
    try {
        $deadline = [DateTime]::UtcNow.AddSeconds(45)
        do {
            if ($server.HasExited) {
                Get-Content -LiteralPath $stderr -Tail 25
                throw "$Role MariaDB process exited prematurely."
            }
            Start-Sleep -Milliseconds 250
            $owned = @(Get-NetTCPConnection -LocalAddress '127.0.0.1' -LocalPort $port -State Listen -OwningProcess $server.Id -ErrorAction SilentlyContinue)
        } while (($owned.Count -eq 0 -or -not (Test-Path -LiteralPath $pidFile)) -and [DateTime]::UtcNow -lt $deadline)
        if ($owned.Count -ne 1 -or -not (Test-Path -LiteralPath $pidFile) -or
            [int](Get-Content -LiteralPath $pidFile -Raw) -ne $server.Id) {
            throw "Unable to prove ownership of the $Role MariaDB listener."
        }
        $actualDataDir = & $client @clientArgs --batch --skip-column-names --execute 'SELECT @@datadir'
        if ($LASTEXITCODE -or [IO.Path]::GetFullPath($actualDataDir).TrimEnd('\') -ine [IO.Path]::GetFullPath($DataDir)) {
            throw "$Role MariaDB is serving a different data directory."
        }

        # Feed the packaged SQL to the matching CLI as raw bytes.
        $process = Start-Process -FilePath $client -ArgumentList $clientArgs -RedirectStandardInput $schema -WindowStyle Hidden -Wait -PassThru
        if ($process.ExitCode) { throw "$Role packaged schema import failed." }

        $storage = Join-Path $lab "$Role-storage"
        New-Item -ItemType Directory -Path $storage | Out-Null
        $env:APP_STORAGE_DIR = $storage
        $env:ENV_PATH = Join-Path $storage '.env'
        $env:APP_DEPLOY_ROOT = $payload
        $env:APP_ENV = 'production'
        $env:APP_DEBUG = 'false'
        $env:DEPLOYMENT_MODE = 'desktop'
        $env:DB_HOST = '127.0.0.1'
        $env:DB_PORT = "$port"
        $env:DB_NAME = 'pos_db'
        $env:DB_USER = 'root'
        $env:DB_PASS = ''
        $env:DB_MIGRATION_USER = 'root'
        $env:DB_MIGRATION_PASS = ''

        php $phar migrate --force
        if ($LASTEXITCODE) { throw "$Role packaged PHAR migrations failed." }
        $profileArgs = @('scripts/verify-setup13-shell-transaction.php', 'profile', $Role)
        if ($Profile -ceq 'release') { $profileArgs += @('release', $ReleaseLab) }
        elseif ($InAppFixture) { $profileArgs += 'in-app' }
        & php @profileArgs
        if ($LASTEXITCODE) { throw "$Role live MariaDB profile capture failed." }

        & (Join-Path $mysql 'bin/mysqladmin.exe') @clientArgs shutdown
        if ($LASTEXITCODE -or -not $server.WaitForExit(30000) -or $server.ExitCode) {
            throw "$Role MariaDB graceful shutdown failed."
        }
    }
    finally {
        if (-not $server.HasExited) {
            $server.Kill()
            if (-not $server.WaitForExit(10000)) { throw "$Role MariaDB did not exit after termination." }
        }
    }
}

if ($Profile -in @('in-app', 'release')) {
    Measure-PackagedDatabase -Root (Join-Path $lab 'source/installed-payload') -Role source -DataDir (Join-Path $lab 'source-data')
    Measure-PackagedDatabase -Root (Join-Path $lab 'target/win-unpacked') -Role target -DataDir (Join-Path $lab 'target-data')
} elseif ($Profile -ceq 'historical') {
    $published = Join-Path $env:RUNNER_TEMP 'setup13-shell/source'
    Measure-PackagedDatabase -Root $published -Role published-source -DataDir (Join-Path $lab 'published-source-data')
} else {
    $installed = Join-Path $lab 'installed'
    $target = Join-Path $lab 'target'
    Measure-PackagedDatabase -Root $installed -Role source -DataDir (Join-Path $installed 'resources/portable/mysql/data')
    Measure-PackagedDatabase -Root $target -Role target -DataDir (Join-Path $lab 'target-data')
}
