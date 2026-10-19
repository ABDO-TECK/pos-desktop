[CmdletBinding()]
param(
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string] $Version,

    [ValidateSet(
        'status',
        'sync-version',
        'build-frontend',
        'build-phar',
        'build-electron',
        'build',
        'commit',
        'tag',
        'push',
        'repo',
        'electron',
        'all',
        'verify-artifacts',
        'verify-release',
        'clean',
        'rollback-list',
        'rollback-revert',
        'rollback-range',
        'rollback-abort',
        'rollback-continue',
        'rollback-push'
    )]
    [string] $Mode,

    [ValidateSet('patch', 'minor', 'major', 'custom')]
    [string] $Bump = 'custom',

    [switch] $DryRun,
    [switch] $CleanDist,
    [switch] $KeepPhar,
    [string] $CommitMessage,
    [switch] $Push,
    [switch] $AllChanged,
    [string[]] $Files = @(),
    [switch] $AllowDirty,
    [switch] $AllowDirtyVersionFiles,
    [switch] $SkipTests,
    [switch] $Yes,
    [switch] $UpdateReleasedAt,
    [switch] $NonInteractive,
    [string] $Commit,
    [string] $FromCommit,
    [string] $ToCommit,
    [ValidateRange(1, 100)]
    [int] $Limit = 30,
    [switch] $AllowDirtyRollback
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$PackageJsonPath = Join-Path $RepoRoot 'package.json'
$PackageLockPath = Join-Path $RepoRoot 'package-lock.json'
$VersionJsonPath = Join-Path $RepoRoot 'version.json'
$BuildPharPath = Join-Path $RepoRoot 'build-phar.php'
$ReleaseManagerConfigPath = Join-Path $PSScriptRoot 'release-manager.config.json'
$DistPath = Join-Path $RepoRoot 'dist-electron'
$PharPath = Join-Path $RepoRoot 'backend/backend.phar'
$UnpackedPath = Join-Path $DistPath 'win-unpacked'
$script:ElectronArtifacts = $null
$script:PreviousVersion = $null
$script:LastReleaseCommand = '(none)'

function Write-Step {
    param([string] $Message)
    Write-Host ''
    Write-Host "==> $Message"
}

function Write-DryRun {
    param([string] $Message)
    Write-Host "DRY RUN: $Message"
}

function Stop-Release {
    param([string] $Message)
    throw "[release] $Message"
}

function Set-ReleaseCommandAttempt {
    param([string] $Command)
    $script:LastReleaseCommand = $Command
}

function Write-ActionFailure {
    param(
        [string] $ActionName,
        [string] $CommandAttempted,
        [object] $ErrorRecord
    )

    Write-Host "Action failed: $ActionName"
    Write-Host "Command attempted: $CommandAttempted"
    Write-Host "Error: $($ErrorRecord.Exception.Message)"
}

function Assert-FileExists {
    param(
        [string] $Path,
        [string] $Description
    )
    if (-not (Test-Path $Path -PathType Leaf)) {
        Stop-Release "$Description is missing: $Path"
    }
}

function Confirm-Action {
    param([string] $Message)
    if ($Yes -or $NonInteractive) {
        return $true
    }
    $answer = Read-Host "$Message [y/N]"
    return $answer -match '^(y|yes)$'
}

function Invoke-Checked {
    param(
        [string] $Description,
        [scriptblock] $Command
    )

    Write-Step $Description
    if ($DryRun) {
        Write-DryRun $Description
        return
    }

    Set-ReleaseCommandAttempt $Description
    & $Command
    if ($LASTEXITCODE -ne 0) {
        Stop-Release "$Description failed with exit code $LASTEXITCODE"
    }
}

function Read-Utf8TextFile {
    param([string] $Path)
    $encoding = [System.Text.UTF8Encoding]::new($false, $true)
    [System.IO.File]::ReadAllText($Path, $encoding)
}

function Write-Utf8TextFile {
    param(
        [string] $Path,
        [string] $Value
    )
    $encoding = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($Path, $Value, $encoding)
}

function Read-JsonFile {
    param([string] $Path)
    Read-Utf8TextFile $Path | ConvertFrom-Json
}

function Get-ReleaseManagerConfig {
    if (Test-Path $ReleaseManagerConfigPath -PathType Leaf) {
        return Read-JsonFile $ReleaseManagerConfigPath
    }

    [PSCustomObject]@{
        blockedCommitPatterns = @('dist-electron/**', 'node_modules/**', 'backend/.phpunit.result.cache')
        warningCommitPatterns = @('backend/backend.phar', 'package-lock.json', 'package.json', 'version.json')
        safeCommitPatterns = @('frontend/src/**', 'electron/**', 'backend/services/**', 'backend/controllers/**', 'backend/tests/**', 'scripts/**', 'docs/**', 'backend/certs/**', 'package.json', 'package-lock.json', 'version.json')
        defaultBranch = 'main'
        repoOwner = 'ABDO-TECK'
        repoName = 'pos-desktop'
        productName = 'POS System'
        installerNamePattern = '*{version}*Setup*.exe'
        versionTextFiles = @(
            [PSCustomObject]@{
                path = 'README.md'
                patterns = @(
                    [PSCustomObject]@{
                        name = 'README version badge'
                        regex = 'version-\d+\.\d+\.\d+-blue\.svg'
                        replacement = 'version-{version}-blue.svg'
                    }
                )
            }
        )
    }
}

function Test-ReleasePathPattern {
    param(
        [string] $Path,
        [string] $Pattern
    )

    $normalizedPath = ($Path -replace '\\', '/').TrimStart('/')
    $normalizedPattern = ($Pattern -replace '\\', '/').TrimStart('/')
    $options = [System.Management.Automation.WildcardOptions]::IgnoreCase
    $wildcard = [System.Management.Automation.WildcardPattern]::new($normalizedPattern, $options)
    $wildcard.IsMatch($normalizedPath)
}

function Test-ReleasePathMatchesAnyPattern {
    param(
        [string] $Path,
        [string[]] $Patterns
    )

    foreach ($pattern in @($Patterns)) {
        if (Test-ReleasePathPattern $Path $pattern) {
            return $true
        }
    }
    return $false
}

function Get-CommitFileClassification {
    param([string] $Path)

    $normalized = ($Path -replace '\\', '/').Trim()
    $config = Get-ReleaseManagerConfig

    if (Test-ReleasePathMatchesAnyPattern $normalized @($config.blockedCommitPatterns)) {
        return 'blocked'
    }
    if (Test-ReleasePathMatchesAnyPattern $normalized @($config.warningCommitPatterns)) {
        return 'warning'
    }
    if (Test-ReleasePathMatchesAnyPattern $normalized @($config.safeCommitPatterns)) {
        return 'safe'
    }
    return 'warning'
}

function Write-JsonFile {
    param(
        [string] $Path,
        [object] $Value,
        [int] $Depth = 100
    )
    if ($DryRun) {
        Write-DryRun "Would update $Path"
        return
    }
    $json = $Value | ConvertTo-Json -Depth $Depth
    Write-Utf8TextFile $Path ($json + [Environment]::NewLine)
}

function Update-ConfiguredTextVersionFiles {
    param([string] $NewVersion)

    $config = Get-ReleaseManagerConfig
    if (-not ($config.PSObject.Properties.Name -contains 'versionTextFiles')) {
        return @()
    }

    $updated = New-Object System.Collections.Generic.List[string]
    foreach ($entry in @($config.versionTextFiles)) {
        if (-not ($entry.PSObject.Properties.Name -contains 'path') -or [string]::IsNullOrWhiteSpace($entry.path)) {
            continue
        }

        $relativePath = [string] $entry.path
        $targetPath = Join-Path $RepoRoot $relativePath
        if (-not (Test-Path $targetPath -PathType Leaf)) {
            Write-Warning "Configured version text file is missing: $relativePath"
            continue
        }

        $text = Read-Utf8TextFile $targetPath
        $nextText = $text
        foreach ($pattern in @($entry.patterns)) {
            $regex = [string] $pattern.regex
            $replacement = ([string] $pattern.replacement).Replace('{version}', $NewVersion)
            $nextText = [regex]::Replace($nextText, $regex, $replacement)
        }

        if ($nextText -ne $text) {
            $updated.Add($relativePath)
            if ($DryRun) {
                Write-DryRun "Would update $relativePath to $NewVersion"
            } else {
                Write-Utf8TextFile $targetPath $nextText
            }
        }
    }

    $updated.ToArray()
}

function Update-PackageLockVersion {
    param([string] $NewVersion)

    if (-not (Test-Path $PackageLockPath -PathType Leaf)) {
        return
    }

    if ($DryRun) {
        Write-DryRun "Would update package-lock.json to $NewVersion"
        return
    }

    $code = @'
const fs = require('fs');
const version = process.argv[1];
const path = 'package-lock.json';
const data = JSON.parse(fs.readFileSync(path, 'utf8'));
if (Object.prototype.hasOwnProperty.call(data, 'version')) {
  data.version = version;
}
if (data.packages && data.packages['']) {
  data.packages[''].version = version;
}
fs.writeFileSync(path, JSON.stringify(data, null, 2) + '\n');
'@
    & node -e $code $NewVersion
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'package-lock.json version update failed'
    }
}

function Get-PackageLockVersions {
    if (-not (Test-Path $PackageLockPath -PathType Leaf)) {
        return $null
    }

    $code = @'
const fs = require('fs');
const data = JSON.parse(fs.readFileSync('package-lock.json', 'utf8'));
console.log(JSON.stringify({
  version: data.version || null,
  rootVersion: data.packages && data.packages[''] ? data.packages[''].version : null
}));
'@
    $json = & node -e $code
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'package-lock.json version read failed'
    }
    $json | ConvertFrom-Json
}

function Assert-PackageLockVersion {
    param([string] $ExpectedVersion)

    $versions = Get-PackageLockVersions
    if (-not $versions) {
        return
    }

    if ($versions.version -and [string] $versions.version -ne $ExpectedVersion) {
        Stop-Release 'package-lock.json version update failed'
    }
    if ($versions.rootVersion -and [string] $versions.rootVersion -ne $ExpectedVersion) {
        Stop-Release 'package-lock.json root package version update failed'
    }
}

function Assert-RequiredFiles {
    Assert-FileExists $PackageJsonPath 'package.json'
    Assert-FileExists $VersionJsonPath 'version.json'
    Assert-FileExists $BuildPharPath 'build-phar.php'

    $package = Read-JsonFile $PackageJsonPath
    if (-not $package.scripts) {
        Stop-Release 'package.json has no scripts section'
    }
    if (-not $package.scripts.'frontend:build') {
        Stop-Release 'package.json is missing scripts.frontend:build'
    }
    if (-not $package.scripts.'electron:build') {
        Stop-Release 'package.json is missing scripts.electron:build'
    }
}

function Get-PackageVersion {
    [string] (Read-JsonFile $PackageJsonPath).version
}

function Get-VersionJsonVersion {
    [string] (Read-JsonFile $VersionJsonPath).version
}

function Get-EffectiveVersion {
    if ($Version) {
        return $Version
    }
    return Get-PackageVersion
}

function Get-NextVersion {
    param([string] $CurrentVersion)

    if ($Version) {
        return $Version
    }

    if ($Bump -eq 'custom' -and -not $NonInteractive) {
        $inputVersion = Read-Host 'Enter version X.Y.Z'
        if ($inputVersion -notmatch '^\d+\.\d+\.\d+$') {
            Stop-Release "Invalid version: $inputVersion"
        }
        return $inputVersion
    }

    $parts = $CurrentVersion.Split('.') | ForEach-Object { [int] $_ }
    switch ($Bump) {
        'patch' { $parts[2] += 1 }
        'minor' { $parts[1] += 1; $parts[2] = 0 }
        'major' { $parts[0] += 1; $parts[1] = 0; $parts[2] = 0 }
        default { Stop-Release 'Version is required when Bump is custom in non-interactive mode' }
    }
    "$($parts[0]).$($parts[1]).$($parts[2])"
}

function Get-GitBranch {
    $branch = & git -C $RepoRoot branch --show-current
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($branch)) {
        return '(detached or unknown)'
    }
    $branch.Trim()
}

function Get-GitStatusLines {
    Set-ReleaseCommandAttempt 'git status --porcelain'
    & git -C $RepoRoot status --porcelain
}

function Convert-GitStatusLineToPath {
    param([string] $Line)

    if ([string]::IsNullOrWhiteSpace($Line) -or $Line.Length -lt 4) {
        return $null
    }

    $path = $Line.Substring(3).Trim()
    if ($path -match ' -> ') {
        $path = ($path -split ' -> ', 2)[1]
    }
    $path -replace '\\', '/'
}

function Test-IsDefaultExcludedCommitFile {
    param([string] $Path)

    (Get-CommitFileClassification $Path) -eq 'blocked'
}

function Get-ClassifiedChangedFiles {
    param([string[]] $StatusLines)

    $items = New-Object System.Collections.Generic.List[object]
    foreach ($line in $StatusLines) {
        $path = Convert-GitStatusLineToPath $line
        if ([string]::IsNullOrWhiteSpace($path)) {
            continue
        }

        $items.Add([PSCustomObject]@{
            Path = $path
            Classification = Get-CommitFileClassification $path
            Status = $line.Substring(0, [Math]::Min(2, $line.Length)).Trim()
        })
    }

    $items.ToArray()
}

function Get-SafeChangedFiles {
    param([string[]] $StatusLines)

    $include = New-Object System.Collections.Generic.List[string]
    $exclude = New-Object System.Collections.Generic.List[string]

    foreach ($item in (Get-ClassifiedChangedFiles $StatusLines)) {
        if ($item.Classification -eq 'blocked') {
            $exclude.Add($item.Path)
        } else {
            $include.Add($item.Path)
        }
    }

    [PSCustomObject]@{
        Include = $include.ToArray()
        Exclude = $exclude.ToArray()
    }
}

function Resolve-CommitFileSelection {
    param(
        [string] $Selection,
        [string[]] $SafeFiles
    )

    $trimmed = if ($null -eq $Selection) { '' } else { $Selection.Trim() }
    if ([string]::IsNullOrWhiteSpace($trimmed)) {
        Stop-Release 'File selection is required'
    }

    if ($trimmed -ieq 'all') {
        return @($SafeFiles)
    }

    $selected = New-Object System.Collections.Generic.List[string]
    $seen = @{}
    foreach ($part in ($trimmed -split ',')) {
        $token = $part.Trim()
        if ($token -notmatch '^\d+$') {
            Stop-Release "Invalid file selection: $token"
        }

        $index = [int] $token
        if ($index -lt 1 -or $index -gt $SafeFiles.Count) {
            Stop-Release "Invalid file number: $index"
        }

        $file = $SafeFiles[$index - 1]
        if (-not $seen.ContainsKey($file)) {
            $seen[$file] = $true
            $selected.Add($file)
        }
    }

    $selected.ToArray()
}

function Test-PosRunning {
    $process = Get-Process -ErrorAction SilentlyContinue |
        Where-Object { $_.ProcessName -eq 'POS System' -or $_.Path -like '*POS System.exe' } |
        Select-Object -First 1
    [bool] $process
}

function Warn-IfPosRunning {
    if (Test-PosRunning) {
        Write-Warning 'POS System.exe appears to be running. Close it before build or clean operations.'
    }
}

function Warn-VersionMismatches {
    $packageVersion = Get-PackageVersion
    $versionJsonVersion = Get-VersionJsonVersion
    if ($packageVersion -ne $versionJsonVersion) {
        Write-Warning "package.json ($packageVersion) and version.json ($versionJsonVersion) versions differ."
    }

    $lockVersions = Get-PackageLockVersions
    if ($lockVersions) {
        if ($lockVersions.version -and [string] $lockVersions.version -ne $packageVersion) {
            Write-Warning "package-lock.json ($($lockVersions.version)) differs from package.json ($packageVersion)."
        }
        if ($lockVersions.rootVersion -and [string] $lockVersions.rootVersion -ne $packageVersion) {
            Write-Warning "package-lock root package ($($lockVersions.rootVersion)) differs from package.json ($packageVersion)."
        }
    }
}

function Warn-PhpUnitCache {
    $cachePath = 'backend/.phpunit.result.cache'
    Set-ReleaseCommandAttempt "git ls-files $cachePath"
    $trackedFiles = & git -C $RepoRoot ls-files $cachePath 2>$null
    $isTracked = ($trackedFiles -ne $null -and $trackedFiles.Length -gt 0)
    if (-not $isTracked) {
        return
    }

    Set-ReleaseCommandAttempt "git status --porcelain -- $cachePath"
    $status = & git -C $RepoRoot status --porcelain -- $cachePath
    if ($status) {
        Write-Warning 'backend/.phpunit.result.cache is tracked and modified. Revert or exclude it before committing release changes.'
    }
}

function Assert-GhTokenForPublish {
    if ($DryRun) {
        return
    }
    if ($Mode -in @('electron', 'all') -and [string]::IsNullOrWhiteSpace($env:GH_TOKEN)) {
        Stop-Release 'GH_TOKEN is required for electron/all publish modes'
    }
}

function Assert-ReleaseTagDoesNotExist {
    param([string] $TagVersion)

    $tag = "v$TagVersion"
    & git -C $RepoRoot show-ref --tags --verify --quiet "refs/tags/$tag"
    if ($LASTEXITCODE -eq 0) {
        Stop-Release "Git tag already exists: $tag"
    }
}

function Assert-VersionFilesCommittedForElectronPublish {
    if ($AllowDirtyVersionFiles -or $DryRun) {
        return
    }

    $status = & git -C $RepoRoot status --porcelain -- package.json version.json
    if ($status) {
        Stop-Release 'Refusing to publish Electron release while package.json or version.json has uncommitted changes. Commit/tag with repo mode first, use all mode, or pass -AllowDirtyVersionFiles.'
    }
}

function Show-Status {
    $package = Read-JsonFile $PackageJsonPath
    $versionJson = Read-JsonFile $VersionJsonPath
    $lockVersions = Get-PackageLockVersions
    $latestPath = Join-Path $DistPath 'latest.yml'
    $latestVersion = '(missing)'
    if (Test-Path $latestPath -PathType Leaf) {
        $latestText = Read-Utf8TextFile $latestPath
        if ($latestText -match '(?m)^version:\s*(.+?)\s*$') {
            $latestVersion = $Matches[1]
        } else {
            $latestVersion = '(unreadable)'
        }
    }

    $gitStatus = Get-GitStatusLines
    $installer = Get-InstallerForVersion (Get-PackageVersion) -AllowMissing
    $blockMapExists = $false
    if ($installer) {
        $blockMapExists = Test-Path ($installer.FullName + '.blockmap') -PathType Leaf
    }

    Write-Step 'Release status'
    Write-Host "package.json version: $($package.version)"
    Write-Host "version.json version: $($versionJson.version)"
    if ($lockVersions) {
        Write-Host "package-lock.json version: $($lockVersions.version)"
        Write-Host "package-lock root version: $($lockVersions.rootVersion)"
    } else {
        Write-Host 'package-lock.json version: (missing)'
    }
    Write-Host "appId: $($package.build.appId)"
    Write-Host "productName: $($package.build.productName)"
    Write-Host "git branch: $(Get-GitBranch)"
    Write-Host "git status: $(if ($gitStatus) { 'dirty' } else { 'clean' })"
    Write-Host "GH_TOKEN exists: $(-not [string]::IsNullOrWhiteSpace($env:GH_TOKEN))"
    Write-Host "backend.phar exists: $(Test-Path $PharPath -PathType Leaf)"
    Write-Host "dist-electron exists: $(Test-Path $DistPath -PathType Container)"
    Write-Host "latest.yml exists: $(Test-Path $latestPath -PathType Leaf)"
    Write-Host "latest.yml version: $latestVersion"
    Write-Host "installer exe exists for package version: $([bool] $installer)"
    Write-Host "installer blockmap exists: $blockMapExists"
    Write-Host "POS System.exe running: $(Test-PosRunning)"
    Warn-VersionMismatches
    Warn-PhpUnitCache
}

function Sync-VersionFiles {
    param([string] $TargetVersion)

    $currentVersion = Get-PackageVersion
    if (-not $TargetVersion) {
        $TargetVersion = Get-NextVersion $currentVersion
    }

    Write-Step "Syncing version files to $TargetVersion"
    $script:PreviousVersion = $currentVersion

    $package = Read-JsonFile $PackageJsonPath
    $package.version = $TargetVersion
    Write-JsonFile $PackageJsonPath $package

    Update-PackageLockVersion $TargetVersion

    $versionJson = Read-JsonFile $VersionJsonPath
    $versionJson.version = $TargetVersion
    if ((-not $DryRun) -and ($versionJson.PSObject.Properties.Name -contains 'released_at') -and ($UpdateReleasedAt -or ((-not $NonInteractive) -and (Confirm-Action 'Update released_at to today?')))) {
        $versionJson.released_at = (Get-Date -Format 'yyyy-MM-dd')
    }
    Write-JsonFile $VersionJsonPath $versionJson
    $textVersionFiles = @(Update-ConfiguredTextVersionFiles $TargetVersion)

    if (-not $DryRun) {
        if ((Get-PackageVersion) -ne $TargetVersion) {
            Stop-Release 'package.json version update failed'
        }
        if ((Get-VersionJsonVersion) -ne $TargetVersion) {
            Stop-Release 'version.json version update failed'
        }
        Assert-PackageLockVersion $TargetVersion
    }

    Write-Host "Current version before change: $currentVersion"
    Write-Host "New version: $TargetVersion"
    Write-Host 'Files updated: package.json, version.json'
    if (Test-Path $PackageLockPath -PathType Leaf) {
        Write-Host 'Files updated: package-lock.json'
    }
    if ($textVersionFiles.Count -gt 0) {
        Write-Host "Files updated: $($textVersionFiles -join ', ')"
    }
}

function Invoke-FrontendBuild {
    Invoke-Checked 'Building frontend' {
        Push-Location (Join-Path $RepoRoot 'frontend')
        try {
            npm run build
        } finally {
            Pop-Location
        }
    }
}

function Invoke-LockedDependencyInstall {
    Invoke-Checked 'Installing locked Composer quality dependencies' {
        Push-Location (Join-Path $RepoRoot 'backend')
        try { composer install --prefer-dist --no-interaction --no-progress } finally { Pop-Location }
    }
    Invoke-Checked 'Installing locked root npm dependencies' { Push-Location $RepoRoot; try { npm ci } finally { Pop-Location } }
    Invoke-Checked 'Installing locked frontend npm dependencies' { Push-Location (Join-Path $RepoRoot 'frontend'); try { npm ci } finally { Pop-Location } }
}

function Invoke-ComposerProductionInstall {
    Invoke-Checked 'Installing locked Composer production dependencies' {
        Push-Location (Join-Path $RepoRoot 'backend')
        try { composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress } finally { Pop-Location }
    }
}

function Get-PhpExecutable {
    if (-not [string]::IsNullOrWhiteSpace($env:PHP_BINARY) -and (Test-Path $env:PHP_BINARY -PathType Leaf)) {
        return $env:PHP_BINARY
    }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) {
        return 'php'
    }
    if (Test-Path 'C:\xampp\php\php.exe' -PathType Leaf) {
        return 'C:\xampp\php\php.exe'
    }
    return 'php'
}

function Assert-PharBuildExtensions {
    $php = Get-PhpExecutable
    $required = @('phar', 'openssl', 'mbstring', 'dom', 'zlib')
    $missing = @($required | Where-Object { & $php -r "exit(extension_loaded('$($_)') ? 0 : 1);" 2>$null; $LASTEXITCODE -ne 0 })
    if ($missing.Count -gt 0) { Stop-Release "PHP is missing required PHAR build extensions: $($missing -join ', ')" }
}

function Invoke-PharBuild {
    $php = Get-PhpExecutable
    Assert-PharBuildExtensions
    Invoke-Checked 'Building backend/backend.phar' { & $php -d phar.readonly=0 build-phar.php }
    if (-not $DryRun) {
        Assert-FileExists $PharPath 'backend/backend.phar'
        Test-PharContents
    }
}


function Test-PharContents {
    Write-Step 'Verifying PHAR contents'
    if ($DryRun) {
        Write-DryRun 'Would verify backend/backend.phar contains version.json and certs/cacert.pem'
        return
    }

    $code = @'
$p = new Phar('backend/backend.phar');
if (!isset($p['version.json']) || !isset($p['certs/cacert.pem'])) {
    fwrite(STDERR, "backend/backend.phar is missing version.json or certs/cacert.pem\n");
    exit(1);
}
'@
    $encodedCode = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($code))
    Set-ReleaseCommandAttempt 'php -r eval(base64_decode(...))'
    & php -r "eval(base64_decode('$encodedCode'));"
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'PHAR verification failed'
    }
    Write-Host 'PHAR verification result: version.json and certs/cacert.pem found'

    $runtimeVerifier = Join-Path $RepoRoot 'scripts/verify-phar-runtime.php'
    if (-not (Test-Path -LiteralPath $runtimeVerifier -PathType Leaf)) {
        Stop-Release 'PHAR runtime verifier is missing.'
    }
    & $php $runtimeVerifier "--phar=$PharPath" "--root=$RepoRoot"
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'PHAR runtime verification failed'
    }
}

function Remove-DistElectron {
    if (-not (Test-Path $DistPath)) {
        return
    }

    $resolvedDist = Resolve-Path $DistPath
    if (-not ([string] $resolvedDist).StartsWith([string] $RepoRoot, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-Release "Refusing to remove path outside repository: $resolvedDist"
    }

    Write-Step 'Cleaning dist-electron'
    if ($DryRun) {
        Write-DryRun "Would remove $resolvedDist"
        return
    }
    Remove-Item -LiteralPath $resolvedDist -Recurse -Force
}

function Invoke-ElectronBuild {
    Warn-IfPosRunning
    if ($CleanDist -or $Mode -in @('build', 'repo', 'electron', 'all')) {
        Remove-DistElectron
    }
    Invoke-Checked 'Preparing pinned portable PHP and MariaDB runtimes' { npm run prepare:desktop-runtime }
    Invoke-Checked 'Verifying portable runtime before packaging' { npm run verify:desktop-runtime }
    Invoke-Checked 'Building Electron NSIS installer' { npx electron-builder --win --publish never }
    if (-not $DryRun) {
        Test-ElectronOutput (Get-EffectiveVersion)
    }
}

function Get-InstallerForVersion {
    param(
        [string] $TargetVersion,
        [switch] $AllowMissing
    )

    if (-not (Test-Path $DistPath -PathType Container)) {
        if ($AllowMissing) { return $null }
        Stop-Release 'dist-electron is missing'
    }

    $installer = Get-ChildItem $DistPath -Filter '*.exe' -File |
        Where-Object { $_.Name -like "*$TargetVersion*" -and $_.Name -like '*Setup*' } |
        Select-Object -First 1
    if (-not $installer -and -not $AllowMissing) {
        Stop-Release "Installer exe for $TargetVersion is missing"
    }
    $installer
}

function Test-ElectronOutput {
    param([string] $TargetVersion)

    Write-Step 'Verifying Electron artifacts'
    if ($DryRun) {
        Write-DryRun "Would verify Electron artifacts for $TargetVersion"
        return
    }

    $latestPath = Join-Path $DistPath 'latest.yml'
    Assert-FileExists $latestPath 'latest.yml'

    $latestText = Read-Utf8TextFile $latestPath
    if ($latestText -notmatch "(?m)^version:\s*$([regex]::Escape($TargetVersion))\s*$") {
        Stop-Release "latest.yml version does not match $TargetVersion"
    }

    $installer = Get-InstallerForVersion $TargetVersion
    $blockMap = Get-Item ($installer.FullName + '.blockmap') -ErrorAction SilentlyContinue
    if (-not $blockMap) {
        Stop-Release ".blockmap for $($installer.Name) is missing"
    }

    Test-PharContents

    Assert-FileExists (Join-Path $UnpackedPath 'resources/app.asar') 'Packaged app.asar'
    Assert-FileExists (Join-Path $UnpackedPath 'resources/app.asar.unpacked/backend/backend.phar') 'Packaged backend/backend.phar'
    Assert-FileExists (Join-Path $UnpackedPath 'resources/app.asar.unpacked/backend/certs/cacert.pem') 'Packaged backend/certs/cacert.pem'
    Assert-FileExists (Join-Path $UnpackedPath 'resources/portable/php/php.exe') 'Packaged portable PHP runtime'
    Assert-FileExists (Join-Path $UnpackedPath 'resources/portable/mysql/bin/mysqld.exe') 'Packaged portable MariaDB server'
    Assert-FileExists (Join-Path $UnpackedPath 'resources/portable/mysql/bin/mysql.exe') 'Packaged portable MariaDB client'
    Assert-FileExists (Join-Path $UnpackedPath 'resources/portable/runtime-manifest.json') 'Packaged portable runtime manifest'
    Invoke-Checked 'Verifying packaged portable runtime probes' { node scripts/verify-desktop-runtime.mjs --root (Join-Path $UnpackedPath 'resources/portable') --strict }

    $script:ElectronArtifacts = [PSCustomObject]@{
        LatestYml = $latestPath
        Installer = $installer.FullName
        BlockMap = $blockMap.FullName
    }

    Write-Host "latest.yml: $latestPath"
    Write-Host "installer exe: $($installer.FullName)"
    Write-Host "exe.blockmap: $($blockMap.FullName)"
}

function Write-ElectronPublishArtifacts {
    if (-not $script:ElectronArtifacts) {
        Test-ElectronOutput (Get-EffectiveVersion)
    }

    Write-Step 'GitHub Release artifacts expected'
    if ($DryRun -and -not $script:ElectronArtifacts) {
        $targetVersion = Get-EffectiveVersion
        Write-Host "latest.yml: $(Join-Path $DistPath 'latest.yml')"
        Write-Host "installer exe: dist-electron/*$targetVersion*Setup*.exe"
        Write-Host "exe.blockmap: dist-electron/*$targetVersion*Setup*.exe.blockmap"
        return
    }
    Write-Host "latest.yml: $($script:ElectronArtifacts.LatestYml)"
    Write-Host "installer exe: $($script:ElectronArtifacts.Installer)"
    Write-Host "exe.blockmap: $($script:ElectronArtifacts.BlockMap)"
}

function Invoke-ElectronPublish {
    Assert-GhTokenForPublish
    Test-ElectronOutput (Get-EffectiveVersion)
    Assert-VersionFilesCommittedForElectronPublish
    Write-ElectronPublishArtifacts
    Invoke-Checked 'Publishing Electron GitHub Release with electron-builder' {
        npx electron-builder --win --publish always --prepackaged dist-electron\win-unpacked
    }
}

function Get-CommitCandidateFiles {
    param(
        [string[]] $CandidateFiles = $Files,
        [switch] $IncludeAllChanged = $AllChanged
    )

    $selected = @()
    if ($CandidateFiles.Count -gt 0) {
        $selected = @($CandidateFiles | ForEach-Object {
            if ($null -eq $_) {
                return
            }
            $_ -split ',' | ForEach-Object { $_.Trim() } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
        })
    } elseif ($IncludeAllChanged) {
        $selected = Get-GitStatusLines | ForEach-Object {
            $line = $_
            if ($line.Length -ge 4) { $line.Substring(3) }
        }
    } else {
        $selected = @('package.json', 'package-lock.json', 'version.json', 'backend/backend.phar', 'scripts/release.ps1', 'docs/release.md')
    }

    $include = New-Object System.Collections.Generic.List[string]
    $exclude = New-Object System.Collections.Generic.List[string]
    $warning = New-Object System.Collections.Generic.List[string]
    foreach ($path in $selected) {
        if ([string]::IsNullOrWhiteSpace($path)) { continue }
        $normalized = $path -replace '\\', '/'
        $classification = Get-CommitFileClassification $normalized
        if ($classification -eq 'blocked') {
            $exclude.Add($normalized)
        } else {
            if ($classification -eq 'warning') {
                $warning.Add($normalized)
            }
            $include.Add($normalized)
        }
    }

    [PSCustomObject]@{
        Include = $include.ToArray()
        Exclude = $exclude.ToArray()
        Warning = $warning.ToArray()
    }
}

function Invoke-Commit {
    param([string] $DefaultMessage)

    $candidates = Get-CommitCandidateFiles
    if ($candidates.Exclude.Count -gt 0) {
        Write-Host "Blocked from commit: $($candidates.Exclude -join ', ')"
    } else {
        Write-Host 'Blocked from commit: (none selected)'
    }
    if ($candidates.Warning.Count -gt 0) {
        Write-Host "Warning / review first: $($candidates.Warning -join ', ')"
    }

    if ($candidates.Include.Count -eq 0) {
        Stop-Release 'No commit files selected'
    }

    $message = $CommitMessage
    if ([string]::IsNullOrWhiteSpace($message)) {
        $message = $DefaultMessage
    }
    if ([string]::IsNullOrWhiteSpace($message)) {
        Stop-Release 'CommitMessage is required for commit mode'
    }

    Write-Step 'Commit selected files'
    Write-Host "Files: $($candidates.Include -join ', ')"
    Write-Host "Commit message: $message"

    if ($DryRun) {
        Write-DryRun "Would git add and commit $($candidates.Include.Count) file(s)"
        return
    }

    foreach ($path in $candidates.Include) {
        if (Test-Path (Join-Path $RepoRoot $path)) {
            Set-ReleaseCommandAttempt "git add -- $path"
            & git -C $RepoRoot add -- $path
            if ($LASTEXITCODE -ne 0) {
                Stop-Release "git add failed for $path"
            }
        }
    }

    Set-ReleaseCommandAttempt 'git diff --cached --quiet'
    & git -C $RepoRoot diff --cached --quiet
    if ($LASTEXITCODE -eq 0) {
        Stop-Release 'No staged release changes to commit'
    }

    Set-ReleaseCommandAttempt 'git commit -m <message>'
    & git -C $RepoRoot commit -m $message
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'git commit failed'
    }

    if ($Push) {
        Invoke-Push $false
    }
}

function Invoke-Tag {
    param([string] $TargetVersion)

    Assert-ReleaseTagDoesNotExist $TargetVersion
    $tag = "v$TargetVersion"
    Write-Step "Create tag $tag"
    if ($DryRun) {
        Write-DryRun "Would create git tag $tag"
        return
    }

    Set-ReleaseCommandAttempt "git tag $tag"
    & git -C $RepoRoot tag $tag
    if ($LASTEXITCODE -ne 0) {
        Stop-Release "git tag failed for $tag"
    }

    if ($Push) {
        Invoke-Push $true $TargetVersion
    }
}

function Invoke-Push {
    param(
        [bool] $IncludeTag = $false,
        [string] $TargetVersion = $Version
    )

    Write-Step 'Push current branch'
    if ($DryRun) {
        Write-DryRun 'Would push current branch'
        if ($IncludeTag -and $TargetVersion) {
            Write-DryRun "Would push tag v$TargetVersion"
        }
        return
    }

    Set-ReleaseCommandAttempt 'git push'
    & git -C $RepoRoot push
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'git push failed'
    }

    if ($IncludeTag -and $TargetVersion) {
        Set-ReleaseCommandAttempt "git push origin v$TargetVersion"
        & git -C $RepoRoot push origin "v$TargetVersion"
        if ($LASTEXITCODE -ne 0) {
            Stop-Release "git push origin v$TargetVersion failed"
        }
    }
}

function Confirm-ReleasePlan {
    param(
        [string] $Action,
        [string] $TargetVersion
    )

    if ($Yes -or $DryRun) {
        return
    }

    Write-Step 'Final confirmation'
    Write-Host "Action: $Action"
    Write-Host "Version: $TargetVersion"
    Write-Host "Branch: $(Get-GitBranch)"
    Write-Host 'This may commit, tag, push, or publish depending on the selected mode.'
    if (-not (Confirm-Action 'Continue?')) {
        Stop-Release 'Cancelled by user'
    }
}

function Invoke-BuildPipeline {
    Invoke-LockedDependencyInstall
    Invoke-Checked 'Running quality checks' { npm run quality }
    Invoke-ComposerProductionInstall
    Invoke-FrontendBuild
    Invoke-PharBuild
    Invoke-ElectronBuild
}

function Invoke-RepoRelease {
    param([string] $TargetVersion)

    Confirm-ReleasePlan 'repo release' $TargetVersion
    Invoke-FrontendBuild
    Invoke-PharBuild
    Invoke-Commit "Release v$TargetVersion"
    Invoke-Tag $TargetVersion
    Invoke-Push $true $TargetVersion
}

function Invoke-AllRelease {
    param([string] $TargetVersion)

    Assert-GhTokenForPublish
    Assert-ReleaseTagDoesNotExist $TargetVersion
    Confirm-ReleasePlan 'full release' $TargetVersion
    Invoke-BuildPipeline
    Invoke-Commit "Release v$TargetVersion"
    Invoke-Tag $TargetVersion
    Invoke-Push $true $TargetVersion
    Invoke-ElectronPublish
}

function Invoke-VerifyRelease {
    param([string] $TargetVersion)

    $tag = "v$TargetVersion"
    if ([string]::IsNullOrWhiteSpace($env:GH_TOKEN)) {
        Write-Host "GH_TOKEN is not set. Manually verify: https://github.com/ABDO-TECK/pos-desktop/releases/tag/$tag"
        return
    }

    Write-Step "Verify GitHub Release $tag"
    if ($DryRun) {
        Write-DryRun "Would query GitHub API for release $tag"
        return
    }

    $headers = @{
        Authorization = "Bearer $env:GH_TOKEN"
        'User-Agent' = 'pos-release-manager'
        Accept = 'application/vnd.github+json'
    }
    $release = Invoke-RestMethod -Uri "https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/$tag" -Headers $headers
    $assetNames = @($release.assets | ForEach-Object { $_.name })
    foreach ($required in @('latest.yml', '.exe', '.blockmap')) {
        $found = $assetNames | Where-Object { $_ -like "*$required*" }
        if (-not $found) {
            Stop-Release "GitHub Release $tag is missing asset matching $required"
        }
    }
    Write-Host "GitHub Release $tag contains latest.yml, installer exe, and blockmap."
}

function Invoke-Clean {
    Warn-IfPosRunning
    Remove-DistElectron

    if ((-not $KeepPhar) -and (Test-Path $PharPath -PathType Leaf)) {
        if (Confirm-Action 'Also remove backend/backend.phar?') {
            Write-Step 'Cleaning backend/backend.phar'
            if ($DryRun) {
                Write-DryRun "Would remove $PharPath"
            } else {
                Remove-Item -LiteralPath $PharPath -Force
            }
        }
    }
}

function Assert-CleanWorkingTreeForRollback {
    if ($AllowDirtyRollback) {
        return
    }

    $status = @(Get-GitStatusLines)
    if ($status.Count -gt 0) {
        Stop-Release 'Refusing rollback while working tree has uncommitted changes. Commit/stash changes first, or pass -AllowDirtyRollback if you are resolving an in-progress revert.'
    }
}

function Assert-Commitish {
    param(
        [string] $Value,
        [string] $Name
    )

    if ([string]::IsNullOrWhiteSpace($Value)) {
        Stop-Release "$Name is required"
    }
    if ($Value -notmatch '^[0-9A-Za-z._/\-]+$') {
        Stop-Release "Invalid $Name value: $Value"
    }
}

function Assert-NotMergeCommit {
    param([string] $CommitHash)

    Set-ReleaseCommandAttempt "git rev-list --parents -n 1 $CommitHash"
    $parentsLine = & git -C $RepoRoot rev-list --parents -n 1 $CommitHash
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($parentsLine)) {
        Stop-Release "Cannot inspect commit: $CommitHash"
    }

    $parts = $parentsLine.Trim() -split '\s+'
    if ($parts.Count -gt 2) {
        Stop-Release "Refusing automatic rollback of merge commit $CommitHash. Revert merge commits manually with the correct mainline parent."
    }
}

function Show-RollbackCommits {
    param([int] $Count = $Limit)

    Write-Step "Recent commits ($Count)"
    Set-ReleaseCommandAttempt "git log -n $Count"
    & git -C $RepoRoot log -n $Count --date=short --pretty=format:'%h | %ad | %an | %s'
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'git log failed'
    }
    Write-Host ''
}

function Invoke-RollbackRevert {
    param([string] $CommitHash)

    Assert-Commitish $CommitHash 'Commit'
    Assert-CleanWorkingTreeForRollback
    Assert-NotMergeCommit $CommitHash

    Write-Step "Revert commit $CommitHash"
    if ($DryRun) {
        Write-DryRun "Would run git revert --no-edit $CommitHash"
        return
    }

    Set-ReleaseCommandAttempt "git revert --no-edit $CommitHash"
    & git -C $RepoRoot revert --no-edit $CommitHash
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'git revert failed. Resolve conflicts, then use rollback-continue or rollback-abort.'
    }
}

function Invoke-RollbackRange {
    param(
        [string] $From,
        [string] $To
    )

    Assert-Commitish $From 'FromCommit'
    Assert-Commitish $To 'ToCommit'
    Assert-CleanWorkingTreeForRollback

    $range = "$From..$To"
    Write-Step "Revert commit range $range"
    if ($DryRun) {
        Write-DryRun "Would run git revert --no-edit $range"
        return
    }

    Set-ReleaseCommandAttempt "git revert --no-edit $range"
    & git -C $RepoRoot revert --no-edit $range
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'git revert range failed. Resolve conflicts, then use rollback-continue or rollback-abort.'
    }
}

function Invoke-RollbackAbort {
    Write-Step 'Abort in-progress revert'
    if ($DryRun) {
        Write-DryRun 'Would run git revert --abort'
        return
    }

    Set-ReleaseCommandAttempt 'git revert --abort'
    & git -C $RepoRoot revert --abort
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'git revert --abort failed'
    }
}

function Invoke-RollbackContinue {
    Write-Step 'Continue in-progress revert'
    if ($DryRun) {
        Write-DryRun 'Would run git revert --continue'
        return
    }

    Set-ReleaseCommandAttempt 'git revert --continue'
    & git -C $RepoRoot revert --continue
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'git revert --continue failed'
    }
}

function Get-InteractiveActionName {
    param([string] $Choice)

    $normalized = if ($null -eq $Choice) { '' } else { $Choice.Trim() }
    switch ($normalized) {
        '1' { return 'status' }
        '2' { return 'sync-version' }
        '3' { return 'build-frontend' }
        '4' { return 'build-phar' }
        '5' { return 'build-electron' }
        '6' { return 'build' }
        '7' { return 'commit' }
        '8' { return 'commit-tag' }
        '9' { return 'push' }
        '10' { return 'electron' }
        '11' { return 'all' }
        '12' { return 'verify-artifacts' }
        '13' { return 'verify-release' }
        '14' { return 'clean' }
        '15' { return 'exit' }
        default { Stop-Release "Invalid menu choice: $normalized" }
    }
}

function Invoke-InteractiveCommit {
    $changed = @(Get-ClassifiedChangedFiles (Get-GitStatusLines))
    $selectable = @($changed | Where-Object { $_.Classification -ne 'blocked' })
    $blocked = @($changed | Where-Object { $_.Classification -eq 'blocked' })

    if ($blocked.Count -gt 0) {
        Write-Host "Blocked from commit: $((@($blocked | ForEach-Object { $_.Path })) -join ', ')"
    } else {
        Write-Host 'Blocked from commit: (none)'
    }

    if ($selectable.Count -eq 0) {
        Write-Host 'No safe or warning changed files found to commit.'
        return
    }

    Write-Step 'Changed files'
    for ($i = 0; $i -lt $selectable.Count; $i += 1) {
        $label = $selectable[$i].Classification.ToUpperInvariant()
        Write-Host "[$($i + 1)] [$label] $($selectable[$i].Path)"
    }

    $selection = Read-Host 'Select files by number, comma-separated, or all'
    $selectedFiles = @(Resolve-CommitFileSelection $selection @($selectable | ForEach-Object { $_.Path }))
    if ($selectedFiles.Count -eq 0) {
        Stop-Release 'No commit files selected'
    }

    $message = Read-Host 'Commit message'
    if ([string]::IsNullOrWhiteSpace($message)) {
        Stop-Release 'Commit message cannot be empty'
    }

    Write-Step 'Commit selected files'
    Write-Host "Files: $($selectedFiles -join ', ')"
    Write-Host "Commit message: $message"

    if ($DryRun) {
        Write-DryRun "Would git add and commit $($selectedFiles.Count) file(s)"
        return
    }

    foreach ($path in $selectedFiles) {
        Set-ReleaseCommandAttempt "git add -- $path"
        & git -C $RepoRoot add -- $path
        if ($LASTEXITCODE -ne 0) {
            Stop-Release "git add failed for $path"
        }
    }

    Set-ReleaseCommandAttempt 'git diff --cached --quiet'
    & git -C $RepoRoot diff --cached --quiet
    if ($LASTEXITCODE -eq 0) {
        Stop-Release 'No staged release changes to commit'
    }

    Set-ReleaseCommandAttempt 'git commit -m <message>'
    & git -C $RepoRoot commit -m $message
    if ($LASTEXITCODE -ne 0) {
        Stop-Release 'git commit failed'
    }
}

function Invoke-InteractiveAction {
    param([string] $Choice)

    $action = '(unresolved)'
    Set-ReleaseCommandAttempt 'Resolve interactive choice'

    try {
        $action = Get-InteractiveActionName $Choice
        if ($action -eq 'exit') {
            Write-Host 'Action completed'
            return $false
        }

        switch ($action) {
            'status' {
                Show-Status
            }
            'sync-version' {
                Sync-VersionFiles $Version
            }
            'build-frontend' {
                Invoke-FrontendBuild
            }
            'build-phar' {
                Invoke-PharBuild
            }
            'build-electron' {
                Invoke-ElectronBuild
            }
            'build' {
                Invoke-BuildPipeline
            }
            'commit' {
                Invoke-InteractiveCommit
            }
            'commit-tag' {
                $target = Get-EffectiveVersion
                Invoke-InteractiveCommit
                Invoke-Tag $target
            }
            'push' {
                Invoke-Push $true (Get-EffectiveVersion)
            }
            'electron' {
                Invoke-ElectronPublish
            }
            'all' {
                $target = Get-EffectiveVersion
                Invoke-AllRelease $target
            }
            'verify-artifacts' {
                Test-ElectronOutput (Get-EffectiveVersion)
            }
            'verify-release' {
                Invoke-VerifyRelease (Get-EffectiveVersion)
            }
            'clean' {
                Invoke-Clean
            }
            default {
                Stop-Release "Unsupported interactive action: $action"
            }
        }

        Write-Host 'Action completed'
    } catch {
        Write-ActionFailure $action $script:LastReleaseCommand $_
        Write-Host 'Action failed'
    }

    return $true
}

function Show-InteractiveMenu {
    Show-Status
    while ($true) {
        Write-Host ''
        Write-Host '[1] Status / diagnostics'
        Write-Host '[2] Sync version files'
        Write-Host '[3] Build frontend'
        Write-Host '[4] Build backend PHAR'
        Write-Host '[5] Build Electron installer'
        Write-Host '[6] Full local build'
        Write-Host '[7] Commit selected files'
        Write-Host '[8] Commit + tag'
        Write-Host '[9] Push commit/tag'
        Write-Host '[10] Publish Electron release only'
        Write-Host '[11] Full release: build + commit + tag + push + publish'
        Write-Host '[12] Verify local artifacts'
        Write-Host '[13] Verify GitHub release assets'
        Write-Host '[14] Clean build artifacts'
        Write-Host '[15] Exit'
        $choice = Read-Host 'Select'
        if (-not (Invoke-InteractiveAction $choice)) {
            return
        }
    }
}

Set-Location $RepoRoot
Assert-RequiredFiles

if ($env:RELEASE_MANAGER_TEST_IMPORT -eq '1') {
    return
}

if (-not $Mode) {
    if ($NonInteractive) {
        Stop-Release 'Mode is required when -NonInteractive is used'
    }
    Show-InteractiveMenu
    return
}

Write-Host "Selected mode: $Mode"
if ($DryRun) {
    Write-Host 'DRY RUN enabled: files, git, build, clean, push, and publish commands will not be executed.'
}

Warn-VersionMismatches
Warn-PhpUnitCache

switch ($Mode) {
    'status' {
        Show-Status
    }
    'sync-version' {
        Sync-VersionFiles $Version
    }
    'build-frontend' {
        Invoke-FrontendBuild
    }
    'build-phar' {
        Invoke-PharBuild
    }
    'build-electron' {
        Invoke-ElectronBuild
    }
    'build' {
        if ($Version) { Sync-VersionFiles $Version }
        Invoke-BuildPipeline
        Write-Host 'Git commit/tag/push: no'
        Write-Host 'GitHub Release publish: no'
    }
    'commit' {
        Invoke-Commit $CommitMessage
    }
    'tag' {
        Invoke-Tag (Get-EffectiveVersion)
    }
    'push' {
        Invoke-Push $Push (Get-EffectiveVersion)
    }
    'repo' {
        $target = Get-NextVersion (Get-PackageVersion)
        Assert-ReleaseTagDoesNotExist $target
        Sync-VersionFiles $target
        Invoke-RepoRelease $target
        Write-Host 'GitHub Release publish: no'
    }
    'electron' {
        Assert-GhTokenForPublish
        if ($Version) { Sync-VersionFiles $Version }
        Invoke-BuildPipeline
        Invoke-ElectronPublish
        Write-Host 'Git commit/tag/push: no'
        Write-Host 'GitHub Release publish: yes'
    }
    'all' {
        $target = Get-NextVersion (Get-PackageVersion)
        Assert-ReleaseTagDoesNotExist $target
        if ($Version -or $Bump -ne 'custom') { Sync-VersionFiles $target }
        Invoke-AllRelease $target
        Write-Host 'Git commit/tag/push: yes'
        Write-Host 'GitHub Release publish: yes'
    }
    'verify-artifacts' {
        Test-ElectronOutput (Get-EffectiveVersion)
    }
    'verify-release' {
        Invoke-VerifyRelease (Get-EffectiveVersion)
    }
    'clean' {
        Invoke-Clean
    }
    'rollback-list' {
        Show-RollbackCommits $Limit
    }
    'rollback-revert' {
        Invoke-RollbackRevert $Commit
    }
    'rollback-range' {
        Invoke-RollbackRange $FromCommit $ToCommit
    }
    'rollback-abort' {
        Invoke-RollbackAbort
    }
    'rollback-continue' {
        Invoke-RollbackContinue
    }
    'rollback-push' {
        Invoke-Push $false
    }
}

Write-Host ''
Write-Host "Release manager finished mode: $Mode"
