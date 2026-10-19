[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if ($env:GITHUB_ACTIONS -ne 'true') { throw 'This first-run smoke is restricted to an ephemeral GitHub Actions runner.' }
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$packageRoot = Join-Path $repoRoot 'dist-electron\win-unpacked'
$configDir = Join-Path $env:APPDATA 'POS System'
if (Test-Path -LiteralPath (Join-Path $configDir 'db_credentials.json')) { throw 'Runner already contains a POS installation; refusing first-run smoke.' }
$stdout = Join-Path $env:RUNNER_TEMP 'pos-startup.stdout.log'
$stderr = Join-Path $env:RUNNER_TEMP 'pos-startup.stderr.log'
$appProcess = Start-Process -FilePath (Join-Path $packageRoot 'POS Desktop.exe') -WorkingDirectory $packageRoot -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
$ports = $null
try {
    $ready = $false
    $deadline = (Get-Date).AddMinutes(4)
    while ((Get-Date) -lt $deadline) {
        $appProcess.Refresh()
        if ($appProcess.HasExited) { throw "Packaged app exited during startup: $($appProcess.ExitCode)" }
        $portsFile = Join-Path $configDir 'runtime_ports.json'
        if (Test-Path -LiteralPath $portsFile) {
            try {
                $ports = Get-Content -LiteralPath $portsFile -Raw | ConvertFrom-Json
                $health = Invoke-RestMethod -Uri "$($ports.apiBaseUrl)/api/health" -TimeoutSec 3
                if ($health.status -eq 'ok') { $ready = $true; break }
            } catch { }
        }
        Start-Sleep -Seconds 2
    }
    if (-not $ready) { throw 'Packaged API failed its first-run readiness check.' }
    $creds = Get-Content (Join-Path $configDir 'db_credentials.json') -Raw | ConvertFrom-Json
    $env:ENV_PATH = Join-Path $configDir '.env'
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = [string]$ports.mysqlPort
    $env:DB_NAME = 'pos_db'
    $env:DB_USER = $creds.user
    $env:DB_PASS = $creds.password
    $env:DB_MIGRATION_USER = $creds.migrationUser
    $env:DB_MIGRATION_PASS = $creds.migrationPassword
    $env:APP_STORAGE_DIR = Join-Path $env:PROGRAMDATA 'POS System'
    $php = Join-Path $packageRoot 'resources\portable\php\php.exe'
    $phar = (Join-Path $packageRoot 'resources\app.asar.unpacked\backend\backend.phar').Replace('\','/')
    & $php -r "require 'phar://$phar/cli/verify-database.php';"
    if ($LASTEXITCODE -ne 0) { throw 'Packaged PHAR database verification failed.' }
    $qzReady = $false
    for ($attempt=0; $attempt -lt 30; $attempt++) {
        $tcp = [Net.Sockets.TcpClient]::new()
        try { $tcp.Connect('127.0.0.1',8182); $qzReady = $true; break } catch { Start-Sleep -Seconds 1 } finally { $tcp.Dispose() }
    }
    if (-not $qzReady) { throw 'Bundled QZ printing service did not start.' }
    Write-Host 'Packaged first-run smoke passed: app, API, database migrations and QZ service.'
} finally {
    if ($ports) {
        & (Join-Path $packageRoot 'resources\portable\mysql\bin\mysqladmin.exe') --host=127.0.0.1 "--port=$($ports.mysqlPort)" --user=root shutdown 2>$null
    }
    $appProcess.Refresh()
    if (-not $appProcess.HasExited) {
        $null = $appProcess.CloseMainWindow()
        if (-not $appProcess.WaitForExit(10000)) { Stop-Process -Id $appProcess.Id -Force }
    }
}
