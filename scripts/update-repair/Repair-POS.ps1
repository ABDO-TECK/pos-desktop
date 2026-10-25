[CmdletBinding()]
param(
    [string]$InstallRoot = "$env:LOCALAPPDATA\Programs\POS Desktop",
    [string]$AssetsDirectory = '',
    [string]$UserDataRoot = ''
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Get-Prop($obj, [string]$prop, $default = $null) {
    if ($null -ne $obj -and $null -ne $obj.PSObject -and $null -ne $obj.PSObject.Properties[$prop]) {
        return $obj.$prop
    }
    return $default
}

function Assert-NoReparse([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force
    while ($null -ne $item) {
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw "Reparse path rejected: $($item.FullName)"
        }
        if ($item -is [IO.FileInfo]) { $item = $item.Directory } else { $item = $item.Parent }
    }
}

function Assert-Closed {
    # Fail closed if process inventory is unavailable; never stop a running process.
    $processes = Get-CimInstance Win32_Process -ErrorAction Stop
    $knownServiceNames = @(
        'POS Desktop.exe',
        'php.exe',
        'php-cgi.exe',
        'mysqld.exe',
        'mariadbd.exe',
        'java.exe',
        'javaw.exe',
        'qz-tray.exe'
    )
    foreach ($process in $processes) {
        if ($process.ExecutablePath -and $process.ExecutablePath.StartsWith($script:InstallPrefix, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Close POS Desktop and its services before running repair.'
        }
        if ((-not $process.ExecutablePath -or [string]::IsNullOrWhiteSpace($process.ExecutablePath)) -and ($process.Name -in $knownServiceNames)) {
            throw 'Cannot verify whether POS Desktop or its services are closed.'
        }
    }
}

function Get-AllUserDataRoots([string]$CustomRoot) {
    $progData = if ($env:ProgramData) { $env:ProgramData } else { 'C:\ProgramData' }
    $roaming = if ($env:APPDATA) { $env:APPDATA } else { '' }
    $local = if ($env:LOCALAPPDATA) { $env:LOCALAPPDATA } else { '' }

    $candidates = @()
    if ($progData) { $candidates += (Join-Path $progData 'POS System') }
    if ($roaming) {
        $candidates += (Join-Path $roaming 'POS System')
        $candidates += (Join-Path $roaming 'POS Desktop')
        $candidates += (Join-Path $roaming 'pos-desktop')
    }
    if ($local) {
        $candidates += (Join-Path $local 'POS System')
        $candidates += (Join-Path $local 'POS Desktop')
        $candidates += (Join-Path $local 'pos-desktop')
        $candidates += (Join-Path $local 'pos-desktop-updater')
    }
    if ($CustomRoot) { $candidates += $CustomRoot }
    if ($env:POS_DATA_ROOT) { $candidates += $env:POS_DATA_ROOT }

    $resolved = @()
    foreach ($c in $candidates) {
        if ([string]::IsNullOrWhiteSpace($c)) { continue }
        $full = [IO.Path]::GetFullPath($c)
        if (Test-Path -LiteralPath $full) {
            $resolved += $full
        }
    }
    return @($resolved | Sort-Object -Unique)
}

function Assert-UserDataSafe([string]$CustomDataRoot, [string]$InstalledVersion) {
    # Check ALL 8 canonical roots PLUS explicit custom root (never replace canonical roots)
    $roots = Get-AllUserDataRoots $CustomDataRoot

    foreach ($target in $roots) {
        Assert-NoReparse $target

        # Check exclusive lock indicators
        foreach ($lockName in @('update-operation.lock', 'update-coordination.gate', 'recovery.lock')) {
            $lockPath = Join-Path $target $lockName
            if (Test-Path -LiteralPath $lockPath) {
                throw "Active update lock detected: $lockPath. Refuse repair while operation is active or pending."
            }
        }
        $saleLocks = Get-ChildItem -LiteralPath $target -Filter 'sale-operation-*.lock' -ErrorAction Stop
        if ($saleLocks -and $saleLocks.Count -gt 0) {
            throw "Active sale coordination lock detected in $target. Refuse repair while sale is active."
        }

        # Check update-state.json
        $stateFile = Join-Path $target 'update-state.json'
        if (Test-Path -LiteralPath $stateFile) {
            Assert-NoReparse $stateFile
            try {
                $rawContent = [IO.File]::ReadAllText($stateFile)
                $state = ConvertFrom-Json -InputObject $rawContent
            } catch {
                throw "Corrupted update-state.json in $target; clean state required: $_"
            }
            if ($null -eq $state) {
                throw "Empty or unparseable update-state.json in $target; clean state required."
            }
            $stateVal = Get-Prop $state 'state' (Get-Prop $state 'status' '')
            if ($stateVal -notin @('completed', 'idle')) {
                throw "Pending or active update state '$stateVal' in $stateFile; clean state required."
            }
            if ((Get-Prop $state 'requires_recovery') -or
                (Get-Prop $state 'recovery_in_progress') -or
                (Get-Prop $state 'rollback_in_progress') -or
                (Get-Prop $state 'failed') -or
                (Get-Prop $state 'error') -or
                (Get-Prop $state 'primary_error')) {
                throw "Recovery or failure flags detected in $stateFile; clean state required."
            }
        }

        # Check update-jobs directory (fail closed: reject corrupt/unreadable files)
        $jobsDir = Join-Path $target 'update-jobs'
        if (Test-Path -LiteralPath $jobsDir) {
            Assert-NoReparse $jobsDir
            $jobFiles = Get-ChildItem -LiteralPath $jobsDir -Filter 'job_*.json' -ErrorAction Stop
            foreach ($jf in $jobFiles) {
                Assert-NoReparse $jf.FullName
                if ($jf.Name -notmatch '^job_(\d+)\.json$') {
                    throw "Malformed job file naming in ${jobsDir}: $($jf.Name)"
                }
                $expectedNumericId = [int]$Matches[1]
                try {
                    $rawJob = [IO.File]::ReadAllText($jf.FullName)
                    $job = ConvertFrom-Json -InputObject $rawJob
                } catch {
                    throw "Corrupted job file in $($jf.FullName); clean state required: $_"
                }
                if ($null -eq $job) {
                    throw "Empty or unparseable job file in $($jf.FullName); clean state required."
                }
                $actualJobId = Get-Prop $job 'job_id'
                if ($null -eq $actualJobId -or [int]$actualJobId -ne $expectedNumericId) {
                    throw "Job ID mismatch in $($jf.FullName): expected $expectedNumericId, found $actualJobId"
                }
                $operationId = Get-Prop $job 'operation_id'
                if ($null -eq $operationId -or [string]::IsNullOrWhiteSpace($operationId.ToString())) {
                    throw "Missing operation_id in job file $($jf.FullName)"
                }

                # Any non-empty settlement_error blocks even contradictory completed record
                $settlementError = Get-Prop $job 'settlement_error'
                if ($null -ne $settlementError -and -not [string]::IsNullOrWhiteSpace($settlementError.ToString())) {
                    throw "Settlement error detected in job file $($jf.FullName): $settlementError"
                }

                $status = Get-Prop $job 'status'
                if ($status -ne 'completed') {
                    throw "Incomplete update job in $($jf.FullName) (status: $status)"
                }
                $result = Get-Prop $job 'result'
                if ($null -eq $result -or (Get-Prop $result 'ok') -ne $true) {
                    throw "Update job in $($jf.FullName) lacks verified result.ok === true"
                }
                if (Get-Prop $result 'error') {
                    throw "Update job in $($jf.FullName) reports failure in result: $(Get-Prop $result 'error')"
                }

                # Require data object if present to be non-null
                if ($null -ne $result.PSObject.Properties['data']) {
                    $data = Get-Prop $result 'data'
                    if ($null -eq $data) {
                        throw "Update job in $($jf.FullName) has invalid or null data object"
                    }
                    $handoff = Get-Prop $data 'requires_desktop_handoff'
                    if ($handoff -eq $true) {
                        throw "Pending desktop handoff apply job not completed on installed version in $($jf.FullName)"
                    }
                }
            }
        }
    }
}

try {
    $InstallRoot = (Resolve-Path -LiteralPath $InstallRoot).Path.TrimEnd('\')
    Assert-NoReparse $InstallRoot
    $script:InstallPrefix = $InstallRoot + '\'

    if (-not (Test-Path -LiteralPath "$InstallRoot\POS Desktop.exe") -or -not (Test-Path -LiteralPath "$InstallRoot\resources\app.asar")) {
        throw 'Select the actual POS Desktop installation folder.'
    }
    $root = "$InstallRoot\resources\app.asar.unpacked"
    $php = "$InstallRoot\resources\portable\php\php.exe"
    foreach ($entry in @($root, $php, "$root\backend\backend.phar", "$root\version.json")) {
        Assert-NoReparse $entry
    }

    # Reject all junctions/symlinks inside deployed application
    foreach ($entry in (Get-ChildItem -LiteralPath $root -Recurse -Force)) {
        if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw 'Reparse entry rejected inside deployed application.'
        }
    }

    Assert-Closed

    $versionJsonFile = "$root\version.json"
    if (-not (Test-Path -LiteralPath $versionJsonFile)) {
        throw "version.json missing in $root"
    }
    $installedVersionRaw = [IO.File]::ReadAllText($versionJsonFile)
    $installedVersionObj = ConvertFrom-Json -InputObject $installedVersionRaw
    $installedVersion = Get-Prop $installedVersionObj 'version' ''

    # Check read-only user data safety across all 8 canonical roots + custom root before touching anything
    Assert-UserDataSafe $UserDataRoot $installedVersion

    # Resolve default assets directory if embedded assets/ folder exists
    if (-not $AssetsDirectory -or -not (Test-Path -LiteralPath $AssetsDirectory)) {
        $embeddedAssets = Join-Path $PSScriptRoot 'assets'
        if (Test-Path -LiteralPath $embeddedAssets) {
            $AssetsDirectory = $embeddedAssets
        }
    }

    # Verify RSA signature of repair-engine.zip using pinned public key
    $engineZip = Join-Path $PSScriptRoot 'repair-engine.zip'
    $engineSig = Join-Path $PSScriptRoot 'repair-engine.sig'
    if (-not (Test-Path -LiteralPath $engineZip) -or -not (Test-Path -LiteralPath $engineSig)) {
        throw 'Repair engine package or signature missing.'
    }
    $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider
    $rsa.FromXmlString('@PUBLIC_KEY_XML@')
    $valid = $rsa.VerifyData([IO.File]::ReadAllBytes($engineZip), 'SHA256', [Convert]::FromBase64String([IO.File]::ReadAllText($engineSig).Trim()))
    $rsa.Dispose()
    if (-not $valid) {
        throw 'RSA repair-engine signature rejected. Download the original repair ZIP from GitHub.'
    }

    # Safety check on Temp base and ancestors BEFORE creating work directory
    $tempBase = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')
    Assert-NoReparse $tempBase

    $work = Join-Path $tempBase ('POS-Update-Repair-' + [guid]::NewGuid().ToString('N'))
    $workFull = [IO.Path]::GetFullPath($work)

    # Reject work directory if located under InstallRoot or any canonical/custom data root
    $forbiddenRoots = @($InstallRoot) + (Get-AllUserDataRoots $UserDataRoot)
    foreach ($fr in $forbiddenRoots) {
        $frFull = [IO.Path]::GetFullPath($fr).TrimEnd('\')
        if ($workFull.Equals($frFull, [StringComparison]::OrdinalIgnoreCase) -or
            $workFull.StartsWith($frFull + '\', [StringComparison]::OrdinalIgnoreCase)) {
            throw "Temporary work directory rejected: located inside installation or user data tree: $workFull"
        }
    }

    New-Item -ItemType Directory -Path $work | Out-Null
    Assert-NoReparse $work

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::ExtractToDirectory($engineZip, "$work\tool")

    # Read signed configuration from extracted engine
    $configFile = "$work\tool\repair-config.json"
    if (-not (Test-Path -LiteralPath $configFile)) {
        throw "repair-config.json missing from verified repair engine."
    }
    $config = ConvertFrom-Json -InputObject ([IO.File]::ReadAllText($configFile))

    # Verify signed configuration mandatory fields
    $toolVersion = Get-Prop $config 'tool_version' ''
    if ($toolVersion -ne '1.0.0') {
        throw "Invalid tool_version in signed config: $toolVersion"
    }
    $targetVersion = Get-Prop $config 'target_version' ''
    if ($targetVersion -ne '0.0.12-beta.1') {
        throw "Invalid target_version in signed config: $targetVersion"
    }

    # Mandatory native_hashes verification: covers POS Desktop.exe, app.asar, php.exe, extension DLLs, and portable binaries
    $nativeHashes = Get-Prop $config 'native_hashes'
    if ($null -eq $nativeHashes) {
        throw 'Mandatory native_hashes missing or malformed in signed repair configuration.'
    }
    $nativeCount = @($nativeHashes.PSObject.Properties).Count
    if ($nativeCount -lt 323) {
        throw "Incomplete native_hashes in signed configuration: expected at least 323 public entries, found $nativeCount"
    }

    # Verify critical entry pins and required PHP extensions
    $requiredPins = @(
        'POS Desktop.exe',
        'resources/app.asar',
        'resources/portable/php/php.exe',
        'resources/portable/php/ext/php_openssl.dll',
        'resources/portable/php/ext/php_curl.dll',
        'resources/portable/php/ext/php_mbstring.dll',
        'resources/portable/php/ext/php_zip.dll'
    )
    foreach ($req in $requiredPins) {
        if (-not $nativeHashes.PSObject.Properties[$req]) {
            throw "Required native pin missing in configuration: $req"
        }
    }

    # Strict verification of all native binary pins BEFORE spawning PHP
    foreach ($prop in $nativeHashes.PSObject.Properties) {
        $rel = $prop.Name.Replace('/', '\')
        if ($rel.Contains('..') -or $rel.StartsWith('\') -or $rel.Contains(':')) {
            throw "Unsafe path in native pin: $rel"
        }
        $fullPath = Join-Path $InstallRoot $rel
        if (-not (Test-Path -LiteralPath $fullPath)) {
            throw "Required public native binary missing: $rel"
        }
        Assert-NoReparse $fullPath
        $actualHash = (Get-FileHash -LiteralPath $fullPath -Algorithm SHA256).Hash.ToLowerInvariant()
        $expectedHash = $prop.Value.ToString().ToLowerInvariant()
        if ($actualHash -ne $expectedHash) {
            throw "Public native binary hash mismatch for $rel. Expected $expectedHash, got $actualHash"
        }
    }

    # Prepare hardened portable PHP invocation
    $phpDir = Split-Path -Parent $php
    $extDir = Join-Path $phpDir 'ext'
    $phpArgs = @(
        '-n',
        '-d', "extension_dir=$extDir",
        '-d', 'extension=openssl',
        '-d', 'extension=zip',
        '-d', 'extension=curl',
        '-d', 'extension=mbstring',
        '-d', 'auto_prepend_file=',
        '-d', 'auto_append_file='
    )

    & $php @phpArgs "$work\tool\repair.php" prepare $root $work $AssetsDirectory $UserDataRoot
    if ($LASTEXITCODE) {
        throw "Preparation failed. No application files replaced. Details: $work"
    }

    Assert-Closed

    & $php @phpArgs "$work\tool\repair.php" apply $root $work $AssetsDirectory $UserDataRoot
    if ($LASTEXITCODE) {
        throw "Repair failed; keep recovery files for inspection: $work"
    }

    Write-Host "Updated to $targetVersion. Reopen POS Desktop. Recovery snapshot: $work"
} catch {
    Write-Error $_
    exit 1
}
