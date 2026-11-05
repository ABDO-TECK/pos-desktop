# scripts/verify-shell-native-php-worker-pipe.ps1
# Win32 Native Worker Pipe Framing + Real PHP Integration Verifier
#
# Verifies:
# 1. Real Win32 kernel ROOT peer binding + small binary echo + Tx/Rx sequences + hold-gate rundown
# 2. 64 MiB full Native->PHP->Native roundtrip + C# pattern verification + 1024 frame cap exhaustion
# 3. Stable and Beta signed file-plan projection positive flow (Host Gate -> Worker -> Plan Validation)
# 4. Native gate and PHP full validator negative policy enforcement
# 5. PHP ZWP1 decoder fail-close protocol enforcement (bad magic, bad length, bad sequence, EOF)
# 6. Honest blocking failure governance (ReadFrame timeout cancellation and caller job termination)
#
# STRICT BOUNDARIES:
# - Runs exclusively in GitHub Actions on Windows under WinPS 5.1 Desktop (x64)
# - Pure in-memory projection; zero database queries, zero physical apply writes
# - Caller host authenticates native descriptors and bindings independently

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$FixtureFile,

    [Parameter(Mandatory = $true)]
    [string]$PhpPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

# --- Section 1: Strict Execution Environment Guards (Fail early, no SKIP) ---
if ($env:GITHUB_ACTIONS -ne 'true') { [Console]::Error.WriteLine("GUARD: GITHUB_ACTIONS=true required."); exit 1 }
if ($env:RUNNER_OS -ne 'Windows') { [Console]::Error.WriteLine("GUARD: RUNNER_OS must be 'Windows'."); exit 1 }
if ([System.Environment]::OSVersion.Platform -ne [System.PlatformID]::Win32NT) { [Console]::Error.WriteLine("GUARD: Win32NT required."); exit 1 }
if ($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { [Console]::Error.WriteLine("GUARD: PS 5.1 Desktop required."); exit 1 }
if (-not [System.Environment]::Is64BitProcess -or -not [System.Environment]::Is64BitOperatingSystem) { [Console]::Error.WriteLine("GUARD: 64-bit required."); exit 1 }

# --- Section 2: Parameter Validation ---
function Assert-ValidAbsolutePath {
    param([string]$rawPath, [string]$paramName)
    if ([string]::IsNullOrWhiteSpace($rawPath)) { throw "$paramName must not be empty or null." }
    if ($rawPath -match '^[\\/]{2}' -or $rawPath -match '^[\\/]' -or $rawPath -match '^\.[\\/]' -or $rawPath.Contains('..')) {
        throw "$paramName must not be UNC, relative, or contain dotdot: $rawPath"
    }
    if (@($rawPath.ToCharArray() | Where-Object { $_ -eq ':' }).Count -ne 1) { throw "$paramName contains invalid colons: $rawPath" }
    if ($rawPath -notmatch '^[a-zA-Z]:\\(?:[^\\/:*?"<>|]+\\)*[^\\/:*?"<>|]+$') { throw "$paramName is not a valid absolute DOS path: $rawPath" }
    if ($rawPath -match '^[a-zA-Z]:\\?$') { throw "$paramName must not be a drive root: $rawPath" }
    if (-not [System.IO.File]::Exists($rawPath)) { throw "$paramName file does not exist: $rawPath" }
    $canon = [System.IO.Path]::GetFullPath($rawPath)
    return $canon
}

$canonicalFixture = Assert-ValidAbsolutePath $FixtureFile "FixtureFile"
$canonicalPhp = Assert-ValidAbsolutePath $PhpPath "PhpPath"

# --- Section 3: RUNNER_TEMP Validation, Nonce-Marked Lab, and Immutable Victim ---
function Assert-ValidRunnerTemp {
    param([string]$rawPath)
    if ([string]::IsNullOrWhiteSpace($rawPath)) { throw "RUNNER_TEMP is empty or null." }
    if ($rawPath -match '^[\\/]{2}' -or $rawPath -match '^[\\/]' -or $rawPath -match '^\.[\\/]' -or $rawPath.Contains('..')) {
        throw "RUNNER_TEMP must not be UNC, relative, or contain dotdot: $rawPath"
    }
    if (@($rawPath.ToCharArray() | Where-Object { $_ -eq ':' }).Count -ne 1) { throw "RUNNER_TEMP contains invalid colons: $rawPath" }
    if ($rawPath -notmatch '^[a-zA-Z]:\\(?:[^\\/:*?"<>|]+\\)*[^\\/:*?"<>|]*$') { throw "RUNNER_TEMP is not a valid absolute DOS path: $rawPath" }
    if ($rawPath -match '^[a-zA-Z]:\\?$') { throw "RUNNER_TEMP must not be a drive root: $rawPath" }
    if (-not [System.IO.Directory]::Exists($rawPath)) { throw "RUNNER_TEMP directory does not exist: $rawPath" }
    $canonical = [System.IO.Path]::GetFullPath($rawPath); $root = [System.IO.Path]::GetPathRoot($canonical)
    if ([string]::Equals($canonical.TrimEnd('\'), $root.TrimEnd('\'), [System.StringComparison]::OrdinalIgnoreCase)) { throw "RUNNER_TEMP must not be a drive root: $canonical" }
    $curr = [System.IO.DirectoryInfo]::new($canonical)
    while ($curr -ne $null) {
        if (($curr.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Ancestor has reparse point: $($curr.FullName)" }
        $curr = $curr.Parent
    }
    return $canonical
}
$script:CanonicalRunnerTemp = Assert-ValidRunnerTemp $env:RUNNER_TEMP

function Assert-PathAncestorsAndRootNotReparse {
    param([string]$canonicalPath)
    $curr = [System.IO.DirectoryInfo]::new($canonicalPath)
    while ($curr -ne $null) {
        if (($curr.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Path or ancestor contains reparse point: $($curr.FullName)" }
        $curr = $curr.Parent
    }
}

function Walk-DirectorySafeTopDown {
    param(
        [string]$directoryPath, [string]$boundPrefix,
        [System.Collections.Generic.List[PSObject]]$discoveredFiles,
        [System.Collections.Generic.List[PSObject]]$discoveredDirs
    )
    $dirInfo = [System.IO.DirectoryInfo]::new($directoryPath)
    if (-not $dirInfo.Exists) { return }
    foreach ($entry in $dirInfo.GetFileSystemInfos()) {
        if (($entry.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Security check failed: entry is a reparse point: $($entry.FullName)" }
        $entryCanon = [System.IO.Path]::GetFullPath($entry.FullName)
        if (-not $entryCanon.StartsWith($boundPrefix, [System.StringComparison]::OrdinalIgnoreCase)) { throw "Security check failed: entry escaped temp bound: $entryCanon" }
        if ($entry -is [System.IO.DirectoryInfo]) {
            if ($null -ne $discoveredDirs) { $discoveredDirs.Add($entry) }
            Walk-DirectorySafeTopDown -directoryPath $entryCanon -boundPrefix $boundPrefix -discoveredFiles $discoveredFiles -discoveredDirs $discoveredDirs
        } else {
            if ($null -ne $discoveredFiles) { $discoveredFiles.Add($entry) }
        }
    }
}

$nonce = [System.Guid]::NewGuid().ToString("N")
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snpp-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snpp-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
[void][System.IO.Directory]::CreateDirectory($labDir)
[void][System.IO.Directory]::CreateDirectory($labTempDir)
[void][System.IO.Directory]::CreateDirectory($victimDir)

$labMarkerPath = [System.IO.Path]::Combine($labDir, ".snpp-lab-marker")
[System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".snpp-victim-marker")
[System.IO.File]::WriteAllText($victimMarkerPath, $nonce, [System.Text.Encoding]::UTF8)

# Populate victim witness tree (immutable outside lab)
$victimWitness1 = [System.IO.Path]::Combine($victimDir, "victim-witness-1.dat")
[System.IO.File]::WriteAllBytes($victimWitness1, [System.Text.Encoding]::UTF8.GetBytes("VICTIM_BYTE_1_$nonce"))
$victimWitness2 = [System.IO.Path]::Combine($victimDir, "victim-witness-2.txt")
[System.IO.File]::WriteAllText($victimWitness2, "VICTIM_TEXT_2_$nonce", [System.Text.Encoding]::UTF8)

function Write-Diagnostic {
    param([string]$msg)
    [Console]::WriteLine("[$([DateTime]::UtcNow.ToString('yyyy-MM-dd HH:mm:ss.fffZ'))] $msg")
}

function Compute-Sha256OfFile {
    param([string]$filePath)
    $fs = $null; $sha = $null
    try {
        $fs = [System.IO.File]::OpenRead($filePath)
        $sha = [System.Security.Cryptography.SHA256]::Create()
        $bytes = $sha.ComputeHash($fs); $sb = [System.Text.StringBuilder]::new($bytes.Length * 2)
        foreach ($b in $bytes) { [void]$sb.Append($b.ToString("x2")) }
        return $sb.ToString()
    } finally {
        if ($sha -ne $null) { $sha.Dispose() }
        if ($fs -ne $null) { $fs.Dispose() }
    }
}

function Compute-Sha256OfBytes {
    param([byte[]]$bytes)
    $sha = $null
    try {
        $sha = [System.Security.Cryptography.SHA256]::Create()
        $hash = $sha.ComputeHash($bytes); $sb = [System.Text.StringBuilder]::new($hash.Length * 2)
        foreach ($b in $hash) { [void]$sb.Append($b.ToString("x2")) }
        return $sb.ToString()
    } finally {
        if ($sha -ne $null) { $sha.Dispose() }
    }
}

function Capture-VictimSnapshot {
    param([string]$vPath)
    $canon = [System.IO.Path]::GetFullPath($vPath).TrimEnd('\'); Assert-PathAncestorsAndRootNotReparse $canon
    $files = [System.Collections.Generic.List[PSObject]]::new(); $dirs = [System.Collections.Generic.List[PSObject]]::new()
    Walk-DirectorySafeTopDown -directoryPath $canon -boundPrefix ($script:CanonicalRunnerTemp.TrimEnd('\') + '\') -discoveredFiles $files -discoveredDirs $dirs
    $items = [System.Collections.Generic.List[PSObject]]::new()
    foreach ($d in $dirs) { $items.Add([PSObject]@{ RelativePath = $d.FullName.Substring($canon.Length + 1); IsDirectory = $true; Length = [int64]0; Sha256 = "" }) }
    foreach ($f in $files) { $items.Add([PSObject]@{ RelativePath = $f.FullName.Substring($canon.Length + 1); IsDirectory = $false; Length = $f.Length; Sha256 = (Compute-Sha256OfFile $f.FullName) }) }
    return $items.ToArray()
}
$script:VictimSnapshot = Capture-VictimSnapshot $victimDir

function Assert-VictimTreeUnchanged {
    param([array]$ExpectedSnapshot, [string]$VictimPath)
    if (-not [System.IO.Directory]::Exists($VictimPath)) { throw "Victim directory missing: $VictimPath" }
    $victimCanon = [System.IO.Path]::GetFullPath($VictimPath).TrimEnd('\'); Assert-PathAncestorsAndRootNotReparse $victimCanon
    $files = [System.Collections.Generic.List[PSObject]]::new(); $dirs = [System.Collections.Generic.List[PSObject]]::new()
    Walk-DirectorySafeTopDown -directoryPath $victimCanon -boundPrefix ($script:CanonicalRunnerTemp.TrimEnd('\') + '\') -discoveredFiles $files -discoveredDirs $dirs
    $seen = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    foreach ($item in $ExpectedSnapshot) {
        $p = [System.IO.Path]::Combine($victimCanon, $item.RelativePath)
        if ($item.IsDirectory) {
            if (-not [System.IO.Directory]::Exists($p)) { throw "Victim missing directory: $($item.RelativePath)" }
        } else {
            if (-not [System.IO.File]::Exists($p)) { throw "Victim missing file: $($item.RelativePath)" }
            if ([System.IO.FileInfo]::new($p).Length -ne $item.Length) { throw "Victim size mismatch: $($item.RelativePath)" }
            if (-not [string]::Equals((Compute-Sha256OfFile $p), $item.Sha256, [System.StringComparison]::OrdinalIgnoreCase)) { throw "Victim hash mismatch: $($item.RelativePath)" }
        }
        [void]$seen.Add($item.RelativePath)
    }
    foreach ($d in $dirs) { $rel = $d.FullName.Substring($victimCanon.Length + 1); if (-not $seen.Contains($rel)) { throw "Victim directory contains extra dir: $rel" } }
    foreach ($f in $files) { $rel = $f.FullName.Substring($victimCanon.Length + 1); if (-not $seen.Contains($rel)) { throw "Victim directory contains extra file: $rel" } }
}

function Invoke-DirectoryDeletion {
    param([string]$Path, [string]$ExpectedMarkerName, [string]$ExpectedNonce)
    if (-not [System.IO.Directory]::Exists($Path)) { return }
    $canonical = [System.IO.Path]::GetFullPath($Path).TrimEnd('\'); $bound = $script:CanonicalRunnerTemp.TrimEnd('\') + '\'
    if (-not ($canonical + '\').StartsWith($bound, [System.StringComparison]::OrdinalIgnoreCase) -or
        [string]::Equals($canonical, $script:CanonicalRunnerTemp.TrimEnd('\'), [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing deletion outside RUNNER_TEMP or at root: $canonical"
    }
    Assert-PathAncestorsAndRootNotReparse $canonical
    if ([string]::IsNullOrEmpty($ExpectedMarkerName) -or [string]::IsNullOrEmpty($ExpectedNonce)) { throw "Deletion requires non-empty marker name and nonce for target: $canonical" }
    $mPath = [System.IO.Path]::Combine($canonical, $ExpectedMarkerName)
    if (-not [System.IO.File]::Exists($mPath)) { throw "Marker '$ExpectedMarkerName' missing in: $canonical" }
    $mFi = [System.IO.FileInfo]::new($mPath)
    if (($mFi.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Marker is a reparse point: $mPath" }
    $markerContent = [System.IO.File]::ReadAllText($mPath, [System.Text.Encoding]::UTF8)
    if (-not [string]::Equals($markerContent, $ExpectedNonce, [System.StringComparison]::Ordinal)) { throw "Marker nonce mismatch in '$mPath'" }

    $files = [System.Collections.Generic.List[PSObject]]::new(); $dirs = [System.Collections.Generic.List[PSObject]]::new()
    Walk-DirectorySafeTopDown -directoryPath $canonical -boundPrefix $bound -discoveredFiles $files -discoveredDirs $dirs
    foreach ($f in $files) {
        if ($f.IsReadOnly) { $f.IsReadOnly = $false }
        $f.Delete(); if ([System.IO.File]::Exists($f.FullName)) { throw "Failed to delete file: $($f.FullName)" }
    }
    foreach ($d in ($dirs | Sort-Object -Property { $_.FullName.Length } -Descending)) {
        $d.Delete(); if ([System.IO.Directory]::Exists($d.FullName)) { throw "Failed to delete directory: $($d.FullName)" }
    }
    [System.IO.Directory]::Delete($canonical); if ([System.IO.Directory]::Exists($canonical)) { throw "Failed to delete root: $canonical" }
}

# --- Section 4: Global Registries and Resource Tracking ---
$script:TrackedOwners = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedEndpoints = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedManagedAccepts = [System.Collections.Generic.List[PSObject]]::new()
$script:OwnerStopErrors = [System.Collections.Generic.List[string]]::new()
$script:EndpointCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:ManagedControlErrors = [System.Collections.Generic.List[string]]::new()
$script:PrimaryError = $null
$script:AssertionCount = 0
$script:WorkerLaunchCount = 0

function Assert-Condition {
    param([bool]$condition, [string]$message)
    if (-not $condition) {
        throw [System.InvalidOperationException]"ASSERTION FAILED: $message"
    }
    $script:AssertionCount++
}

function Register-TrackedOwner {
    param($Owner, [string]$Tag, [string]$ExePath)
    $rec = [PSObject]@{
        Owner = $Owner
        Tag = $Tag
        ExePath = $ExePath
        Closed = $false
    }
    $script:TrackedOwners.Add($rec)
    return $rec
}

function Register-TrackedEndpoint {
    param($Endpoint, [string]$Tag)
    $rec = [PSObject]@{
        Endpoint = $Endpoint
        Tag = $Tag
        Closed = $false
    }
    $script:TrackedEndpoints.Add($rec)
    return $rec
}

function Safe-StartOwnedProcess {
    param(
        [string]$exePath,
        [string[]]$ArgumentVector,
        [string]$cwd,
        [System.Collections.Generic.IDictionary[string, string]]$env,
        [string]$tag
    )
    $owner = $null
    try {
        $owner = [PosDesktop.Updates.Native.OwnedChildProcess]::Start($exePath, $ArgumentVector, $cwd, $env)
        if ($owner -eq $null) {
            throw "Start returned null owner for exe: $exePath"
        }
        $rec = Register-TrackedOwner -Owner $owner -Tag $tag -ExePath $exePath
        $script:WorkerLaunchCount++
        return $owner
    } catch {
        $curr = $_.Exception
        $retained = $null
        $depth = 0
        while ($curr -ne $null -and $depth -lt 5) {
            if ($curr -is [PosDesktop.Updates.Native.OwnedChildProcessStartException]) {
                $retained = $curr.RetainedOwner
                break
            }
            $curr = $curr.InnerException
            $depth++
        }
        if ($retained -ne $null) {
            [void](Register-TrackedOwner -Owner $retained -Tag "$tag-Retained" -ExePath $exePath)
        }
        throw
    }
}

function Safe-CreateEndpoint {
    param([string]$Tag = "Endpoint")
    $ep = $null
    try {
        $ep = [PosDesktop.Updates.Native.OwnedWorkerPipeEndpoint]::Create()
        if ($ep -eq $null) {
            throw "Create returned null endpoint."
        }
        $rec = Register-TrackedEndpoint -Endpoint $ep -Tag $Tag
        return $ep
    } catch {
        $curr = $_.Exception
        $retained = $null
        $depth = 0
        while ($curr -ne $null -and $depth -lt 5) {
            if ($curr -is [PosDesktop.Updates.Native.OwnedWorkerPipeEndpointException]) {
                $retained = $curr.RetainedEndpoint
                break
            }
            $curr = $curr.InnerException
            $depth++
        }
        if ($retained -ne $null) {
            [void](Register-TrackedEndpoint -Endpoint $retained -Tag "$Tag-Retained")
        }
        throw
    }
}

function Stop-TrackedOwner {
    param($ownerRec, [int]$timeoutMs = 5000)
    if ($ownerRec -eq $null -or $ownerRec.Owner -eq $null) {
        return $false
    }
    if ($ownerRec.Closed) {
        return $true
    }
    $err = 0
    $ok = $ownerRec.Owner.TryStopAndClose($timeoutMs, [ref]$err)
    if ($ok -and $ownerRec.Owner.IsClosed) {
        $ownerRec.Closed = $true
        return $true
    }
    $script:OwnerStopErrors.Add("Failed to stop owner $($ownerRec.Tag): Win32 err $err")
    return $false
}

function Close-TrackedEndpoint {
    param($endpointRec, [int]$timeoutMs = 5000)
    if ($endpointRec -eq $null -or $endpointRec.Endpoint -eq $null) {
        return $false
    }
    if ($endpointRec.Closed) {
        return $true
    }
    $err = 0
    $ok = $endpointRec.Endpoint.TryClose($timeoutMs, [ref]$err)
    if ($ok -and $endpointRec.Endpoint.State -eq [PosDesktop.Updates.Native.PipeEndpointState]::Closed) {
        $endpointRec.Closed = $true
        return $true
    }
    $script:EndpointCloseErrors.Add("Failed to close endpoint $($endpointRec.Tag): Win32 err $err")
    return $false
}

function Cleanup-TrackedManagedAccept {
    param($Record)
    if ($Record -eq $null) { return }

    $server = $Record.Server
    $asyncResult = $Record.AsyncResult

    # If EndWaitForConnection has not been collected yet, cancel and bounded rundown
    if (-not $Record.EndCollected -and $asyncResult -ne $null) {
        # Dispose/close managed server to cancel .NET-owned accept
        if ($server -ne $null -and -not $Record.Disposed) {
            try {
                $server.Dispose()
                $Record.Disposed = $true
            } catch {
                $script:ManagedControlErrors.Add("Failed to dispose server to cancel accept on $($Record.Tag): $($_.Exception.Message)")
            }
        }

        # Bounded wait for actual IAsyncResult completion (.NET framework rundown of overlapped accept)
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        $completed = $false
        while ($sw.ElapsedMilliseconds -lt 3000) {
            if ($asyncResult.IsCompleted) {
                $completed = $true
                break
            }
            [System.Threading.Thread]::Sleep(20)
        }

        if (-not $completed) {
            # Cannot prove completion: retain references, do NOT call EndWait, do NOT close wait handle
            $script:ManagedControlErrors.Add("Pending accept rundown timed out on $($Record.Tag); completion unproven, retaining references to prevent memory corruption")
            return
        }

        # Now that IsCompleted is true, call EndWaitForConnection once to clean up .NET state
        try {
            if ($server -ne $null) {
                $server.EndWaitForConnection($asyncResult)
            }
            $Record.EndCollected = $true
        } catch {
            $base = $_.Exception.GetBaseException()
            if ($base -is [System.ObjectDisposedException] -or $base -is [System.OperationCanceledException] -or $base -is [System.Threading.ThreadAbortException]) {
                # Expected cancellation exception when server was disposed
                $Record.EndCollected = $true
            } else {
                $script:ManagedControlErrors.Add("Unexpected exception ending wait on $($Record.Tag): $($_.Exception.Message)")
            }
        }

        # Close wait handle now that accept is completely rundown
        if (-not $Record.WaitHandleClosed -and $asyncResult.AsyncWaitHandle -ne $null) {
            try {
                $asyncResult.AsyncWaitHandle.Close()
                $Record.WaitHandleClosed = $true
            } catch {
                $script:ManagedControlErrors.Add("Failed to close AsyncWaitHandle on $($Record.Tag): $($_.Exception.Message)")
            }
        }
    }

    # Disconnect & Dispose server if not yet disposed
    if ($server -ne $null -and -not $Record.Disposed) {
        try {
            if ($server.IsConnected) {
                $server.Disconnect()
            }
        } catch {
            $script:ManagedControlErrors.Add("Failed to disconnect server on $($Record.Tag): $($_.Exception.Message)")
        }
        try {
            $server.Dispose()
            $Record.Disposed = $true
        } catch {
            $script:ManagedControlErrors.Add("Failed to dispose server on $($Record.Tag): $($_.Exception.Message)")
        }
    }
}

function Wait-ProcessDead {
    param($owner, [int]$timeoutMs = 5000)
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.ElapsedMilliseconds -lt $timeoutMs) {
        $obs = $owner.Observe()
        if ($obs.RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Dead) {
            return $true
        }
        [System.Threading.Thread]::Sleep(20)
    }
    return $false
}

# --- Section 5: Discover PHP Environment & Extensions ---
$phpDir = [System.IO.Path]::GetDirectoryName($canonicalPhp)
$phpExtDir = [System.IO.Path]::Combine($phpDir, "ext")
if (-not [System.IO.Directory]::Exists($phpExtDir)) {
    throw "PHP ext directory not found at: $phpExtDir"
}

function Resolve-PhpExtension {
    param([string]$extDir, [string]$name)
    if ([System.IO.File]::Exists([System.IO.Path]::Combine($extDir, "php_${name}.dll"))) {
        return "php_${name}.dll"
    }
    if ([System.IO.File]::Exists([System.IO.Path]::Combine($extDir, "${name}.dll"))) {
        return "${name}.dll"
    }
    return $name
}

$extOpenssl = Resolve-PhpExtension $phpExtDir "openssl"
$extMbstring = Resolve-PhpExtension $phpExtDir "mbstring"
$extZip = Resolve-PhpExtension $phpExtDir "zip"

function Build-SparsePhpEnvironment {
    param([string]$phpDir, [string]$labTempDir)
    $sysRoot = [System.Environment]::GetEnvironmentVariable("SystemRoot")
    if ([string]::IsNullOrEmpty($sysRoot)) { $sysRoot = "C:\Windows" }
    $sys32 = [System.IO.Path]::Combine($sysRoot, "System32")
    $sparsePath = "$phpDir;$sys32"

    $envMap = [System.Collections.Generic.Dictionary[string, string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    $envMap["GITHUB_ACTIONS"] = "true"
    $envMap["RUNNER_OS"] = "Windows"
    $envMap["SystemRoot"] = $sysRoot
    $envMap["PATH"] = $sparsePath
    $envMap["TEMP"] = $labTempDir
    $envMap["TMP"] = $labTempDir
    return $envMap
}
$sparseEnv = Build-SparsePhpEnvironment -phpDir $phpDir -labTempDir $labTempDir

$clientFixtureScript = [System.IO.Path]::GetFullPath([System.IO.Path]::Combine($PSScriptRoot, "shell-worker-pipe-php-client-fixture.php"))
Assert-ValidAbsolutePath $clientFixtureScript "shell-worker-pipe-php-client-fixture.php"

function Build-PhpClientArgs {
    param(
        [string]$clientScript,
        [string]$phpExtDir,
        [string]$extOpenssl,
        [string]$extMbstring,
        [string]$extZip,
        [string]$mode,
        [string]$endpoint,
        [string]$fixturePath = $null,
        [string]$resultFile = $null,
        [string]$readyFile = $null,
        [string]$holdGateFile = $null,
        [int]$expectedFrames = 0
    )
    $argsList = [System.Collections.Generic.List[string]]::new()
    $argsList.Add("-n")
    $argsList.Add("-dmemory_limit=256M")
    $argsList.Add("-dextension_dir=$phpExtDir")
    $argsList.Add("-dextension=$extOpenssl")
    $argsList.Add("-dextension=$extMbstring")
    $argsList.Add("-dextension=$extZip")
    $argsList.Add($clientScript)
    $argsList.Add("--mode=$mode")
    $argsList.Add("--endpoint=$endpoint")
    if (-not [string]::IsNullOrEmpty($fixturePath)) {
        $argsList.Add("--fixture=$fixturePath")
    }
    if (-not [string]::IsNullOrEmpty($resultFile)) {
        $argsList.Add("--result-file=$resultFile")
    }
    if (-not [string]::IsNullOrEmpty($readyFile)) {
        $argsList.Add("--ready-file=$readyFile")
    }
    if (-not [string]::IsNullOrEmpty($holdGateFile)) {
        $argsList.Add("--hold-gate-file=$holdGateFile")
    }
    if ($expectedFrames -gt 0) {
        $argsList.Add("--expected-frames=$expectedFrames")
    }
    return $argsList.ToArray()
}

# --- Section 6: Compile Production C# and Test Helpers via Add-Type ---
$backendDir = [System.IO.Path]::GetFullPath([System.IO.Path]::Combine($PSScriptRoot, "..", "backend"))
$ownedChildCsPath = [System.IO.Path]::Combine($backendDir, "Native", "OwnedChildProcess.cs")
$ownedPipeCsPath = [System.IO.Path]::Combine($backendDir, "Native", "OwnedWorkerPipeEndpoint.cs")
$verifierCsPath = [System.IO.Path]::Combine($backendDir, "Native", "NativeBrokerManifestVerifier.cs")
$bindingCsPath = [System.IO.Path]::Combine($backendDir, "Native", "NativeBrokerContextBinding.cs")

Assert-ValidAbsolutePath $ownedChildCsPath "OwnedChildProcess.cs"
Assert-ValidAbsolutePath $ownedPipeCsPath "OwnedWorkerPipeEndpoint.cs"
Assert-ValidAbsolutePath $verifierCsPath "NativeBrokerManifestVerifier.cs"
Assert-ValidAbsolutePath $bindingCsPath "NativeBrokerContextBinding.cs"

$ownedChildSource = [System.IO.File]::ReadAllText($ownedChildCsPath, [System.Text.Encoding]::UTF8)
$ownedPipeSource = [System.IO.File]::ReadAllText($ownedPipeCsPath, [System.Text.Encoding]::UTF8)
$verifierSource = [System.IO.File]::ReadAllText($verifierCsPath, [System.Text.Encoding]::UTF8)
$bindingSource = [System.IO.File]::ReadAllText($bindingCsPath, [System.Text.Encoding]::UTF8)

$helperSource = @"
namespace PosDesktop.Updates.Native.Tests
{
    using System;
    using System.Security.Cryptography;

    public static class PhpPipeTestHelpers
    {
        public static byte[] CreatePattern64MB()
        {
            byte[] buf = new byte[67108864];
            uint val = 0x12345678;
            for (int i = 0; i < buf.Length; i += 4)
            {
                val = val * 1664525u + 1013904223u;
                buf[i] = (byte)(val & 0xFF);
                buf[i + 1] = (byte)((val >> 8) & 0xFF);
                buf[i + 2] = (byte)((val >> 16) & 0xFF);
                buf[i + 3] = (byte)((val >> 24) & 0xFF);
            }
            return buf;
        }

        public static bool VerifyPattern64MB(byte[] buf)
        {
            if (buf == null || buf.Length != 67108864) return false;
            uint val = 0x12345678;
            for (int i = 0; i < buf.Length; i += 4)
            {
                val = val * 1664525u + 1013904223u;
                if (buf[i] != (byte)(val & 0xFF) ||
                    buf[i + 1] != (byte)((val >> 8) & 0xFF) ||
                    buf[i + 2] != (byte)((val >> 16) & 0xFF) ||
                    buf[i + 3] != (byte)((val >> 24) & 0xFF))
                {
                    return false;
                }
            }
            return true;
        }
    }
}
"@

$combinedSource = $ownedChildSource + "`r`n" + $ownedPipeSource + "`r`n" + $verifierSource + "`r`n" + $bindingSource + "`r`n" + $helperSource
try {
    Add-Type -TypeDefinition $combinedSource -Language CSharp
    Write-Diagnostic "Successfully compiled production C# and test helpers via Add-Type."
} catch {
    [Console]::Error.WriteLine("ERROR: Add-Type compilation failed:")
    [Console]::Error.WriteLine($_.Exception.ToString())
    exit 1
}

# --- Section 7: Parse Fixture JSON and Initialize Verifier ---
$fixtureRaw = [System.IO.File]::ReadAllText($canonicalFixture, [System.Text.Encoding]::UTF8)
$fixture = ConvertFrom-Json -InputObject $fixtureRaw

$keyId = [string]$fixture.public_key.key_id
$modulusBytes = [System.Convert]::FromBase64String([string]$fixture.public_key.modulus_base64)
$exponentBytes = [System.Convert]::FromBase64String([string]$fixture.public_key.exponent_base64)

$trustedKey = New-Object PosDesktop.Updates.Native.NativeBrokerTrustedKey($keyId, $modulusBytes, $exponentBytes)
$verifier = New-Object PosDesktop.Updates.Native.NativeBrokerManifestVerifier([PosDesktop.Updates.Native.NativeBrokerTrustedKey[]]@($trustedKey))

# Host test helper constructing strict valid NativeBrokerBindingContext from case context
function New-NativeBindingContextFromCaseContext {
    param(
        $ContextData,
        [string]$DefaultKeyId
    )
    $srcIdentity = New-Object PosDesktop.Updates.Native.NativeBrokerEndpointIdentity(
        [string]$ContextData.source.app_version,
        [string]$ContextData.source.channel,
        [string]$ContextData.source.native_version,
        [string]$ContextData.source.inventory_sha256,
        [string]$ContextData.source.native_shell_sha256
    )
    $tgtIdentity = New-Object PosDesktop.Updates.Native.NativeBrokerEndpointIdentity(
        [string]$ContextData.target.app_version,
        [string]$ContextData.target.channel,
        [string]$ContextData.target.native_version,
        [string]$ContextData.target.inventory_sha256,
        [string]$ContextData.target.native_shell_sha256
    )
    $dataContract = New-Object PosDesktop.Updates.Native.NativeBrokerDataContractContext(
        [string]$ContextData.data_contract.schema_sha256,
        [string]$ContextData.data_contract.ledger_sha256
    )
    $expKeyId = $DefaultKeyId
    if ($ContextData.PSObject.Properties['expected_key_id'] -ne $null -and -not [string]::IsNullOrEmpty($ContextData.expected_key_id)) {
        $expKeyId = [string]$ContextData.expected_key_id
    }
    return New-Object PosDesktop.Updates.Native.NativeBrokerBindingContext(
        $srcIdentity,
        $tgtIdentity,
        [string]$ContextData.expected_manifest_sha256,
        $dataContract,
        $expKeyId
    )
}

# Host test helper executing staged native broker verification and binding BEFORE any worker start
function Invoke-HostBrokerGateAndBind {
    param(
        $HostVerifier,
        [byte[]]$RawManifest,
        [string]$SignatureBase64,
        $BindingContext
    )
    $envelope = $HostVerifier.VerifyAndParse($RawManifest, $SignatureBase64)
    $boundResult = [PosDesktop.Updates.Native.NativeBrokerContextBinding]::Bind($envelope, $BindingContext)
    return $boundResult
}

# --- Section 8: Execute Test Suites ---
try {
    # ========================================================================
    # Suite A: Kernel Root Peer Binding + Small Binary Echo + Sequence + Hold-Gate
    # ========================================================================
    Write-Diagnostic "Starting Suite A: Kernel Root Peer Binding & Small Binary Echo..."

    $epA = Safe-CreateEndpoint -Tag "EpA"
    $epARec = $script:TrackedEndpoints[-1]
    $resA = [System.IO.Path]::Combine($labDir, "result-a.json")
    $readyA = [System.IO.Path]::Combine($labDir, "ready-a.txt")
    $gateA = [System.IO.Path]::Combine($labDir, "gate-a.txt")

    $argsA = Build-PhpClientArgs -clientScript $clientFixtureScript -phpExtDir $phpExtDir `
        -extOpenssl $extOpenssl -extMbstring $extMbstring -extZip $extZip `
        -mode "echo" -endpoint $epA.EndpointName -resultFile $resA -readyFile $readyA -holdGateFile $gateA -expectedFrames 1

    $ownerA = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $argsA -cwd $labDir -env $sparseEnv -tag "WorkerA"
    $ownerARec = $script:TrackedOwners[-1]

    $peerA = $epA.ConnectOwnedWorker($ownerA, 5000)
    Assert-Condition ($peerA -ne $null) "Suite A: Peer observation returned"
    Assert-Condition ($peerA.ProcessId -eq $ownerA.ProcessId) "Suite A: Kernel peer PID strictly matches owned root process"
    Assert-Condition ($peerA.CanApply -eq $false) "Suite A: Peer observation CanApply is false"
    Assert-Condition ($epA.State -eq [PosDesktop.Updates.Native.PipeEndpointState]::Bound) "Suite A: Endpoint is in Bound state"

    # Write small opaque binary payload containing all byte values
    $payloadA = New-Object byte[] 64
    for ($i = 0; $i -lt 64; $i++) { $payloadA[$i] = [byte]($i * 4) }

    $epA.WriteFrame($payloadA, 5000)
    Assert-Condition ($epA.NextTxSequence -eq [System.UInt64]2) "Suite A: NextTxSequence incremented to 2"

    $frameA = $epA.ReadFrame(5000)
    Assert-Condition ($frameA -ne $null) "Suite A: ReadFrame returned frame"
    Assert-Condition ($frameA.Sequence -eq [System.UInt64]1) "Suite A: Frame sequence is 1"
    Assert-Condition ($frameA.CanApply -eq $false) "Suite A: Frame CanApply is false"
    Assert-Condition ($frameA.PayloadLength -eq 64) "Suite A: Echo payload length is 64"

    $copiedBytesA = $frameA.CopyPayload()
    for ($i = 0; $i -lt 64; $i++) {
        if ($copiedBytesA[$i] -ne $payloadA[$i]) {
            throw "Suite A: Payload byte mismatch at ${i}: expected $($payloadA[$i]), got $($copiedBytesA[$i])"
        }
    }

    # Close endpoint first, then release hold gate
    $closedA = Close-TrackedEndpoint $epARec 5000
    Assert-Condition $closedA "Suite A: Endpoint closed successfully"

    [System.IO.File]::WriteAllText($gateA, "RELEASE`n", [System.Text.Encoding]::UTF8)

    # Wait for natural root death
    $deadA = Wait-ProcessDead -owner $ownerA -timeoutMs 5000
    Assert-Condition $deadA "Suite A: Worker root naturally exited after release"
    $obsA = $ownerA.Observe()
    Assert-Condition ($obsA.RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Dead) "Suite A: Root state is Dead"
    Assert-Condition ($obsA.JobCountKnown -and $obsA.ActiveProcesses -eq 0) "Suite A: Job active process count is known and 0"
    Assert-Condition ($obsA.IsQuiescent -eq $true) "Suite A: Child root is quiescent"

    $stoppedA = Stop-TrackedOwner $ownerARec 5000
    Assert-Condition $stoppedA "Suite A: Owner stopped and closed"

    # Verify result marker
    Assert-Condition ([System.IO.File]::Exists($resA)) "Suite A: Result marker file exists"
    $resDocA = ConvertFrom-Json -InputObject ([System.IO.File]::ReadAllText($resA, [System.Text.Encoding]::UTF8))
    Assert-Condition ($resDocA.ok -eq $true) "Suite A: Result marker ok is true"
    Assert-Condition ($resDocA.cleanup_ok -eq $true) "Suite A: Result marker cleanup_ok is true"
    Assert-Condition ($resDocA.can_apply -eq $false) "Suite A: Result marker can_apply is false"
    Assert-Condition ($resDocA.echo_count -eq 1) "Suite A: Echo count is 1"

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite A completed successfully."

    # ========================================================================
    # Suite B: 64 MiB Real Native->PHP->Native Roundtrip & 1024 Frame Cap
    # ========================================================================
    Write-Diagnostic "Starting Suite B: 64 MiB Pattern Verify & 1024 Frame Cap..."

    # Sub-test B1: 64 MiB Full Roundtrip with LCG Pattern
    $epB1 = Safe-CreateEndpoint -Tag "EpB1"
    $epB1Rec = $script:TrackedEndpoints[-1]
    $resB1 = [System.IO.Path]::Combine($labDir, "result-b1.json")
    $gateB1 = [System.IO.Path]::Combine($labDir, "gate-b1.txt")

    $argsB1 = Build-PhpClientArgs -clientScript $clientFixtureScript -phpExtDir $phpExtDir `
        -extOpenssl $extOpenssl -extMbstring $extMbstring -extZip $extZip `
        -mode "echo" -endpoint $epB1.EndpointName -resultFile $resB1 -holdGateFile $gateB1 -expectedFrames 1

    $ownerB1 = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $argsB1 -cwd $labDir -env $sparseEnv -tag "WorkerB1"
    $ownerB1Rec = $script:TrackedOwners[-1]

    [void]$epB1.ConnectOwnedWorker($ownerB1, 5000)

    Write-Diagnostic "Suite B1: Generating 64 MiB pattern..."
    $pattern64 = [PosDesktop.Updates.Native.Tests.PhpPipeTestHelpers]::CreatePattern64MB()
    Write-Diagnostic "Suite B1: Transmitting 64 MiB frame to PHP worker..."
    $epB1.WriteFrame($pattern64, 30000)
    Assert-Condition ($epB1.NextTxSequence -eq [System.UInt64]2) "Suite B1: NextTxSequence is 2"

    Write-Diagnostic "Suite B1: Receiving 64 MiB echo frame from PHP worker..."
    $frameB1 = $epB1.ReadFrame(30000)
    Assert-Condition ($frameB1.Sequence -eq [System.UInt64]1) "Suite B1: Echo frame sequence is 1"
    Assert-Condition ($frameB1.PayloadLength -eq 67108864) "Suite B1: Echo payload length is 64 MiB"

    Write-Diagnostic "Suite B1: Verifying 64 MiB pattern bytes..."
    $copiedBytesB1 = $frameB1.CopyPayload()
    $patternOk = [PosDesktop.Updates.Native.Tests.PhpPipeTestHelpers]::VerifyPattern64MB($copiedBytesB1)
    Assert-Condition $patternOk "Suite B1: 64 MiB echo pattern verification passed"

    # Close endpoint and release hold gate
    [void](Close-TrackedEndpoint $epB1Rec 5000)
    [System.IO.File]::WriteAllText($gateB1, "RELEASE`n", [System.Text.Encoding]::UTF8)
    Assert-Condition (Wait-ProcessDead -owner $ownerB1 -timeoutMs 5000) "Suite B1: Worker exited naturally after release"
    [void](Stop-TrackedOwner $ownerB1Rec 5000)

    Assert-Condition ([System.IO.File]::Exists($resB1)) "Suite B1: Result marker file exists"
    $resDocB1 = ConvertFrom-Json -InputObject ([System.IO.File]::ReadAllText($resB1, [System.Text.Encoding]::UTF8))
    Assert-Condition ($resDocB1.ok -eq $true) "Suite B1: Result marker ok is true"
    Assert-Condition ($resDocB1.cleanup_ok -eq $true) "Suite B1: Result marker cleanup_ok is true"

    # Sub-test B2: 1024 Roundtrips & Cap Exhaustion
    Write-Diagnostic "Suite B2: Running 1024 roundtrips to verify sequence progression and cap..."
    $epB2 = Safe-CreateEndpoint -Tag "EpB2"
    $epB2Rec = $script:TrackedEndpoints[-1]
    $resB2 = [System.IO.Path]::Combine($labDir, "result-b2.json")
    $gateB2 = [System.IO.Path]::Combine($labDir, "gate-b2.txt")

    $argsB2 = Build-PhpClientArgs -clientScript $clientFixtureScript -phpExtDir $phpExtDir `
        -extOpenssl $extOpenssl -extMbstring $extMbstring -extZip $extZip `
        -mode "echo" -endpoint $epB2.EndpointName -resultFile $resB2 -holdGateFile $gateB2 -expectedFrames 1024

    $ownerB2 = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $argsB2 -cwd $labDir -env $sparseEnv -tag "WorkerB2"
    $ownerB2Rec = $script:TrackedOwners[-1]

    [void]$epB2.ConnectOwnedWorker($ownerB2, 5000)

    for ($i = 1; $i -le 1024; $i++) {
        $tiny = [System.BitConverter]::GetBytes([int]$i)
        $epB2.WriteFrame($tiny, 5000)
        $f = $epB2.ReadFrame(5000)
        if ($f.Sequence -ne [System.UInt64]$i) {
            throw "Suite B2: Sequence mismatch at frame ${i}: expected ${i}, got $($f.Sequence)"
        }
    }

    Assert-Condition ($epB2.NextTxSequence -eq [System.UInt64]1025) "Suite B2: NextTxSequence is 1025 after 1024 frames"
    Assert-Condition ($epB2.ExpectedRxSequence -eq [System.UInt64]1025) "Suite B2: ExpectedRxSequence is 1025 after 1024 frames"

    # Frame 1025 must throw cap exhaustion on Native side
    $capExhaustedCaught = $false
    try {
        $epB2.WriteFrame([byte[]]@(1, 2, 3, 4), 5000)
    } catch {
        $baseEx = $_.Exception.GetBaseException()
        if ($baseEx -is [System.InvalidOperationException]) {
            $capExhaustedCaught = $true
        }
    }
    Assert-Condition $capExhaustedCaught "Suite B2: Frame limit cap of 1024 enforced on Tx"

    [void](Close-TrackedEndpoint $epB2Rec 5000)
    [System.IO.File]::WriteAllText($gateB2, "RELEASE`n", [System.Text.Encoding]::UTF8)
    Assert-Condition (Wait-ProcessDead -owner $ownerB2 -timeoutMs 5000) "Suite B2: Worker exited after cap close and gate release"
    [void](Stop-TrackedOwner $ownerB2Rec 5000)

    Assert-Condition ([System.IO.File]::Exists($resB2)) "Suite B2: Result marker file exists"
    $resDocB2 = ConvertFrom-Json -InputObject ([System.IO.File]::ReadAllText($resB2, [System.Text.Encoding]::UTF8))
    Assert-Condition ($resDocB2.ok -eq $true) "Suite B2: Result marker ok is true"
    Assert-Condition ($resDocB2.cleanup_ok -eq $true) "Suite B2: Result marker cleanup_ok is true"

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite B completed successfully."

    # ========================================================================
    # Suite C: Stable and Beta Signed Plan Positive Flow
    # ========================================================================
    Write-Diagnostic "Starting Suite C: Stable and Beta Signed Plan Positive Flow..."

    $positiveCaseIds = @("valid_stable", "valid_beta")
    foreach ($targetCaseId in $positiveCaseIds) {
        $targetCase = $null
        foreach ($c in $fixture.cases) {
            if ([string]$c.id -eq $targetCaseId) {
                $targetCase = $c
                break
            }
        }
        if ($targetCase -eq $null) {
            throw "Suite C: Fixture case '$targetCaseId' not found."
        }

        Write-Diagnostic "Suite C: Testing case '$targetCaseId'..."

        # 1. Native Broker Signature Verification and Context Binding via Host Helper
        $rawBytes = [System.Convert]::FromBase64String([string]$targetCase.raw_manifest_base64)
        $sigBase64 = [string]$targetCase.signature_base64
        $ctxData = $targetCase.context

        $bindingContext = New-NativeBindingContextFromCaseContext -ContextData $ctxData -DefaultKeyId $keyId

        $boundResult = Invoke-HostBrokerGateAndBind -HostVerifier $verifier -RawManifest $rawBytes -SignatureBase64 $sigBase64 -BindingContext $bindingContext
        Assert-Condition ($boundResult -ne $null) "Suite C ($targetCaseId): Context binding returned bound result"
        Assert-Condition ($boundResult.CanApply -eq $false) "Suite C ($targetCaseId): Bound result CanApply is strictly false"

        # 2. Create Pipe Endpoint and Launch Real PHP Worker
        $epC = Safe-CreateEndpoint -Tag "EpC-$targetCaseId"
        $epCRec = $script:TrackedEndpoints[-1]
        $resC = [System.IO.Path]::Combine($labDir, "result-c-$targetCaseId.json")
        $gateC = [System.IO.Path]::Combine($labDir, "gate-c-$targetCaseId.txt")

        $argsC = Build-PhpClientArgs -clientScript $clientFixtureScript -phpExtDir $phpExtDir `
            -extOpenssl $extOpenssl -extMbstring $extMbstring -extZip $extZip `
            -mode "plan" -endpoint $epC.EndpointName -fixturePath $canonicalFixture -resultFile $resC -holdGateFile $gateC

        $ownerC = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $argsC -cwd $labDir -env $sparseEnv -tag "WorkerC-$targetCaseId"
        $ownerCRec = $script:TrackedOwners[-1]

        [void]$epC.ConnectOwnedWorker($ownerC, 5000)

        # 3. Construct and Transmit Framed JSON Request with EXACT 5 keys and EXACT 6 context keys
        $requestPayload = @{
            raw_manifest_base64 = [string]$targetCase.raw_manifest_base64
            signature = [string]$targetCase.signature_base64
            expected_manifest_sha256 = [string]$ctxData.expected_manifest_sha256
            source_context = @{
                version = [string]$ctxData.source.app_version
                native_version = [string]$ctxData.source.native_version
                channel = [string]$ctxData.source.channel
                inventory_sha256 = [string]$ctxData.source.inventory_sha256
                native_shell_sha256 = [string]$ctxData.source.native_shell_sha256
                data_compatibility = @{
                    format = [int]1
                    metadata_migration = "059_add_update_compatibility_metadata.sql"
                    schema_sha256 = [string]$ctxData.data_contract.schema_sha256
                    ledger_sha256 = [string]$ctxData.data_contract.ledger_sha256
                }
            }
            target_context = @{
                version = [string]$ctxData.target.app_version
                native_version = [string]$ctxData.target.native_version
                channel = [string]$ctxData.target.channel
                inventory_sha256 = [string]$ctxData.target.inventory_sha256
                native_shell_sha256 = [string]$ctxData.target.native_shell_sha256
                data_compatibility = @{
                    format = [int]1
                    metadata_migration = "059_add_update_compatibility_metadata.sql"
                    schema_sha256 = [string]$ctxData.data_contract.schema_sha256
                    ledger_sha256 = [string]$ctxData.data_contract.ledger_sha256
                }
            }
        }

        $requestJson = ConvertTo-Json -InputObject $requestPayload -Depth 10 -Compress
        $requestBytes = [System.Text.Encoding]::UTF8.GetBytes($requestJson)

        $epC.WriteFrame($requestBytes, 5000)

        # 4. Receive Framed File Plan Response
        $frameC = $epC.ReadFrame(5000)
        Assert-Condition ($frameC -ne $null) "Suite C ($targetCaseId): Received frame"
        Assert-Condition ($frameC.Sequence -eq [System.UInt64]1) "Suite C ($targetCaseId): Frame sequence is 1"

        $replyBytes = $frameC.CopyPayload()
        $replyJson = [System.Text.Encoding]::UTF8.GetString($replyBytes)
        $replyDoc = ConvertFrom-Json -InputObject $replyJson

        Assert-Condition ($replyDoc.ok -eq $true) "Suite C ($targetCaseId): Response ok is true"
        Assert-Condition ($replyDoc.can_apply -eq $false) "Suite C ($targetCaseId): Response can_apply is false"
        Assert-Condition ([string]$replyDoc.manifest_sha256 -eq [string]$ctxData.expected_manifest_sha256) "Suite C ($targetCaseId): Manifest SHA matches pin"

        # Plan SHA-256 matches exact UTF-8 plan_json bytes
        $planJsonStr = [string]$replyDoc.plan_json
        $planBytes = [System.Text.Encoding]::UTF8.GetBytes($planJsonStr)
        $expectedPlanSha = Compute-Sha256OfBytes $planBytes
        Assert-Condition ([string]$replyDoc.plan_sha256 -eq $expectedPlanSha) "Suite C ($targetCaseId): Plan SHA-256 matches actual UTF-8 plan_json bytes"

        # Verify plan structure and complete inventory projection
        $planDoc = ConvertFrom-Json -InputObject $planJsonStr
        Assert-Condition ($planDoc.can_apply -eq $false) "Suite C ($targetCaseId): Plan can_apply is strictly false"
        Assert-Condition ($planDoc.format -eq 1) "Suite C ($targetCaseId): Plan format is 1"
        Assert-Condition ($planDoc.operation -eq 'plan_signed_files') "Suite C ($targetCaseId): Plan operation is plan_signed_files"
        Assert-Condition ([string]$planDoc.source.version -eq [string]$ctxData.source.app_version) "Suite C ($targetCaseId): Source version matches"
        Assert-Condition ([string]$planDoc.target.version -eq [string]$ctxData.target.app_version) "Suite C ($targetCaseId): Target version matches"
        Assert-Condition ([string]$planDoc.source.channel -eq [string]$ctxData.source.channel) "Suite C ($targetCaseId): Source channel matches"
        Assert-Condition ([string]$planDoc.target.channel -eq [string]$ctxData.target.channel) "Suite C ($targetCaseId): Target channel matches"
        Assert-Condition ([string]$planDoc.source.native_version -eq [string]$ctxData.source.native_version) "Suite C ($targetCaseId): Source native version matches"
        Assert-Condition ([string]$planDoc.target.native_version -eq [string]$ctxData.target.native_version) "Suite C ($targetCaseId): Target native version matches"
        Assert-Condition ([string]$planDoc.source.data_compatibility.schema_sha256 -eq [string]$ctxData.data_contract.schema_sha256) "Suite C ($targetCaseId): Source schema SHA matches"
        Assert-Condition ([string]$planDoc.source.data_compatibility.ledger_sha256 -eq [string]$ctxData.data_contract.ledger_sha256) "Suite C ($targetCaseId): Source ledger SHA matches"
        Assert-Condition ([string]$planDoc.target.data_compatibility.schema_sha256 -eq [string]$ctxData.data_contract.schema_sha256) "Suite C ($targetCaseId): Target schema SHA matches"
        Assert-Condition ([string]$planDoc.target.data_compatibility.ledger_sha256 -eq [string]$ctxData.data_contract.ledger_sha256) "Suite C ($targetCaseId): Target ledger SHA matches"

        # Compare COMPLETE source and target file tables against manifest doc inventories
        $manifestDoc = ConvertFrom-Json -InputObject ([System.Text.Encoding]::UTF8.GetString($rawBytes))
        $expectedSrcFiles = $manifestDoc.source_inventory.files
        $expectedTgtFiles = $manifestDoc.target_inventory.files

        Assert-Condition (@($planDoc.source_files).Count -eq @($expectedSrcFiles.PSObject.Properties).Count) "Suite C ($targetCaseId): Source file count matches inventory"
        foreach ($sf in $planDoc.source_files) {
            $pName = [string]$sf.path
            $expProp = $expectedSrcFiles.PSObject.Properties[$pName]
            Assert-Condition ($expProp -ne $null) "Suite C ($targetCaseId): Source file '$pName' exists in inventory"
            Assert-Condition ([string]$sf.sha256 -eq [string]$expProp.Value.sha256) "Suite C ($targetCaseId): Source file '$pName' sha matches"
            Assert-Condition ([int64]$sf.size -eq [int64]$expProp.Value.size) "Suite C ($targetCaseId): Source file '$pName' size matches"
        }

        Assert-Condition (@($planDoc.target_files).Count -eq @($expectedTgtFiles.PSObject.Properties).Count) "Suite C ($targetCaseId): Target file count matches inventory"
        foreach ($tf in $planDoc.target_files) {
            $pName = [string]$tf.path
            $expProp = $expectedTgtFiles.PSObject.Properties[$pName]
            Assert-Condition ($expProp -ne $null) "Suite C ($targetCaseId): Target file '$pName' exists in inventory"
            Assert-Condition ([string]$tf.sha256 -eq [string]$expProp.Value.sha256) "Suite C ($targetCaseId): Target file '$pName' sha matches"
            Assert-Condition ([int64]$tf.size -eq [int64]$expProp.Value.size) "Suite C ($targetCaseId): Target file '$pName' size matches"
        }

        # Verify union actions
        foreach ($act in $planDoc.actions) {
            $actKind = [string]$act.kind
            if ($actKind -eq 'retain') {
                Assert-Condition ($act.source -ne $null -and $act.target -ne $null) "Suite C ($targetCaseId): Retain action has both source and target"
                Assert-Condition ([string]$act.source.sha256 -eq [string]$act.target.sha256) "Suite C ($targetCaseId): Retain action sha matches"
                Assert-Condition ([int64]$act.source.size -eq [int64]$act.target.size) "Suite C ($targetCaseId): Retain action size matches"
            } elseif ($actKind -eq 'replace') {
                Assert-Condition ($act.source -ne $null -and $act.target -ne $null) "Suite C ($targetCaseId): Replace action has both source and target"
            } elseif ($actKind -eq 'create') {
                Assert-Condition ($act.source -eq $null -and $act.target -ne $null) "Suite C ($targetCaseId): Create action has target only"
            } elseif ($actKind -eq 'delete') {
                Assert-Condition ($act.source -ne $null -and $act.target -eq $null) "Suite C ($targetCaseId): Delete action has source only"
            } else {
                throw "Suite C ($targetCaseId): Unexpected action kind '$actKind'"
            }
        }

        # Close endpoint and release hold gate
        [void](Close-TrackedEndpoint $epCRec 5000)
        [System.IO.File]::WriteAllText($gateC, "RELEASE`n", [System.Text.Encoding]::UTF8)
        Assert-Condition (Wait-ProcessDead -owner $ownerC -timeoutMs 5000) "Suite C ($targetCaseId): Worker exited naturally"
        [void](Stop-TrackedOwner $ownerCRec 5000)

        Assert-Condition ([System.IO.File]::Exists($resC)) "Suite C ($targetCaseId): Result file exists"
        $resDocC = ConvertFrom-Json -InputObject ([System.IO.File]::ReadAllText($resC, [System.Text.Encoding]::UTF8))
        Assert-Condition ($resDocC.ok -eq $true) "Suite C ($targetCaseId): Result ok is true"
        Assert-Condition ($resDocC.cleanup_ok -eq $true) "Suite C ($targetCaseId): Result cleanup_ok is true"
    }

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite C completed successfully."

    # ========================================================================
    # Suite D: Native Gate Negatives & PHP Full Validator Negatives
    # ========================================================================
    Write-Diagnostic "Starting Suite D: Native Gate & PHP Full Validator Negatives..."

    # D1: Native Gate Negatives (Host helper rejects before any endpoint/worker creation)
    $launchesBeforeD1 = $script:WorkerLaunchCount
    $negativeCase = $null
    foreach ($c in $fixture.cases) {
        if ([string]$c.group -eq 'negative_signature' -or [bool]$c.expected_verifier_rejected) {
            $negativeCase = $c
            break
        }
    }
    if ($negativeCase -eq $null) {
        throw "Suite D: Negative signature case not found in fixture."
    }

    Write-Diagnostic "Suite D1: Testing native rejection on '$($negativeCase.id)' via host gate helper..."
    $tamperedRaw = [System.Convert]::FromBase64String([string]$negativeCase.raw_manifest_base64)
    $tamperedSig = [string]$negativeCase.signature_base64

    # Build valid context from fixture case context (reusing helper function)
    $validCtxD1 = New-NativeBindingContextFromCaseContext -ContextData $negativeCase.context -DefaultKeyId $keyId

    $verifierRejected = $false
    try {
        [void](Invoke-HostBrokerGateAndBind -HostVerifier $verifier -RawManifest $tamperedRaw -SignatureBase64 $tamperedSig -BindingContext $validCtxD1)
    } catch {
        $baseEx = $_.Exception.GetBaseException()
        if ($baseEx -is [PosDesktop.Updates.Native.NativeBrokerSignatureRejectedException]) {
            $verifierRejected = $true
        }
    }
    Assert-Condition $verifierRejected "Suite D1: Verifier rejected invalid signature in host gate flow"
    Assert-Condition ($script:WorkerLaunchCount -eq $launchesBeforeD1) "Suite D1: Zero workers launched on native gate failure"

    # Additional Native Bind negative: wrong expected key ID
    $wrongKeyCase = $null
    foreach ($c in $fixture.cases) {
        if ([string]$c.id -eq 'negative_wrong_trusted_key_id') {
            $wrongKeyCase = $c
            break
        }
    }
    if ($wrongKeyCase -ne $null) {
        Write-Diagnostic "Suite D1: Testing native bind rejection on wrong expected key ID..."
        $wkRaw = [System.Convert]::FromBase64String([string]$wrongKeyCase.raw_manifest_base64)
        $wkSig = [string]$wrongKeyCase.signature_base64
        $wkCtx = New-NativeBindingContextFromCaseContext -ContextData $wrongKeyCase.context -DefaultKeyId "wrong_expected_key_id"

        $bindRejected = $false
        try {
            [void](Invoke-HostBrokerGateAndBind -HostVerifier $verifier -RawManifest $wkRaw -SignatureBase64 $wkSig -BindingContext $wkCtx)
        } catch {
            $baseEx = $_.Exception.GetBaseException()
            if ($baseEx -is [PosDesktop.Updates.Native.NativeBrokerBindingException]) {
                if ($baseEx.ReasonCode -eq 'trusted_key_id_mismatch') {
                    $bindRejected = $true
                }
            }
        }
        Assert-Condition $bindRejected "Suite D1: NativeBrokerContextBinding rejected wrong expected key ID"
        Assert-Condition ($script:WorkerLaunchCount -eq $launchesBeforeD1) "Suite D1: Zero workers launched on native bind failure"
    }

    # D2: PHP Full Validator Negatives (Limit boundary cases accepted by Native Bind but rejected by PHP)
    $limitNegativeIds = @("limit_signed_other_file_entry", "limit_invalid_non_shell_inventory_entry")
    foreach ($limitId in $limitNegativeIds) {
        $limitCase = $null
        foreach ($c in $fixture.cases) {
            if ([string]$c.id -eq $limitId) {
                $limitCase = $c
                break
            }
        }
        if ($limitCase -eq $null) {
            throw "Suite D2: Limit case '$limitId' not found in fixture."
        }

        Write-Diagnostic "Suite D2: Testing PHP validator rejection on '$limitId'..."

        # Native Bind succeeds (honest layer boundary)
        $lRaw = [System.Convert]::FromBase64String([string]$limitCase.raw_manifest_base64)
        $lSig = [string]$limitCase.signature_base64
        $lCtx = $limitCase.context
        $lBindingCtx = New-NativeBindingContextFromCaseContext -ContextData $lCtx -DefaultKeyId $keyId

        $lBound = Invoke-HostBrokerGateAndBind -HostVerifier $verifier -RawManifest $lRaw -SignatureBase64 $lSig -BindingContext $lBindingCtx
        Assert-Condition ($lBound -ne $null) "Suite D2 ($limitId): Native Bind accepted limit descriptor"

        # Now launch PHP worker: full PHP FilePlan validator MUST reject!
        $epD2 = Safe-CreateEndpoint -Tag "EpD2-$limitId"
        $epD2Rec = $script:TrackedEndpoints[-1]
        $resD2 = [System.IO.Path]::Combine($labDir, "result-d2-$limitId.json")
        $gateD2 = [System.IO.Path]::Combine($labDir, "gate-d2-$limitId.txt")

        $argsD2 = Build-PhpClientArgs -clientScript $clientFixtureScript -phpExtDir $phpExtDir `
            -extOpenssl $extOpenssl -extMbstring $extMbstring -extZip $extZip `
            -mode "plan" -endpoint $epD2.EndpointName -fixturePath $canonicalFixture -resultFile $resD2 -holdGateFile $gateD2

        $ownerD2 = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $argsD2 -cwd $labDir -env $sparseEnv -tag "WorkerD2-$limitId"
        $ownerD2Rec = $script:TrackedOwners[-1]

        [void]$epD2.ConnectOwnedWorker($ownerD2, 5000)

        $reqObjD2 = @{
            raw_manifest_base64 = [string]$limitCase.raw_manifest_base64
            signature = [string]$limitCase.signature_base64
            expected_manifest_sha256 = [string]$lCtx.expected_manifest_sha256
            source_context = @{
                version = [string]$lCtx.source.app_version
                native_version = [string]$lCtx.source.native_version
                channel = [string]$lCtx.source.channel
                inventory_sha256 = [string]$lCtx.source.inventory_sha256
                native_shell_sha256 = [string]$lCtx.source.native_shell_sha256
                data_compatibility = @{
                    format = [int]1
                    metadata_migration = "059_add_update_compatibility_metadata.sql"
                    schema_sha256 = [string]$lCtx.data_contract.schema_sha256
                    ledger_sha256 = [string]$lCtx.data_contract.ledger_sha256
                }
            }
            target_context = @{
                version = [string]$lCtx.target.app_version
                native_version = [string]$lCtx.target.native_version
                channel = [string]$lCtx.target.channel
                inventory_sha256 = [string]$lCtx.target.inventory_sha256
                native_shell_sha256 = [string]$lCtx.target.native_shell_sha256
                data_compatibility = @{
                    format = [int]1
                    metadata_migration = "059_add_update_compatibility_metadata.sql"
                    schema_sha256 = [string]$lCtx.data_contract.schema_sha256
                    ledger_sha256 = [string]$lCtx.data_contract.ledger_sha256
                }
            }
        }
        $reqBytesD2 = [System.Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject $reqObjD2 -Depth 10 -Compress))
        $epD2.WriteFrame($reqBytesD2, 5000)

        $frameD2 = $epD2.ReadFrame(5000)
        $replyD2Bytes = $frameD2.CopyPayload()
        $replyD2 = ConvertFrom-Json -InputObject ([System.Text.Encoding]::UTF8.GetString($replyD2Bytes))

        Assert-Condition ($replyD2.ok -eq $false) "Suite D2 ($limitId): Full PHP FilePlan validator rejected invalid descriptor"
        Assert-Condition ($replyD2.can_apply -eq $false) "Suite D2 ($limitId): can_apply is false"
        Assert-Condition ($replyD2.PSObject.Properties['plan_json'] -eq $null) "Suite D2 ($limitId): Zero plan leaked on rejection"
        Assert-Condition (-not [string]::IsNullOrEmpty($replyD2.error)) "Suite D2 ($limitId): Error reason code provided"

        [void](Close-TrackedEndpoint $epD2Rec 5000)
        [System.IO.File]::WriteAllText($gateD2, "RELEASE`n", [System.Text.Encoding]::UTF8)
        Assert-Condition (Wait-ProcessDead -owner $ownerD2 -timeoutMs 5000) "Suite D2 ($limitId): Worker exited naturally"
        [void](Stop-TrackedOwner $ownerD2Rec 5000)

        Assert-Condition ([System.IO.File]::Exists($resD2)) "Suite D2 ($limitId): Result file exists"
        $resDocD2 = ConvertFrom-Json -InputObject ([System.IO.File]::ReadAllText($resD2, [System.Text.Encoding]::UTF8))
        Assert-Condition ($resDocD2.ok -eq $false) "Suite D2 ($limitId): Result ok is false"
        Assert-Condition ($resDocD2.cleanup_ok -eq $true) "Suite D2 ($limitId): Result cleanup_ok is true"
    }

    # D3: Manifest Pin Mutation
    # GHA CONTROL ONLY (Not production flow): Bypasses Native Gate intentionally to verify PHP FilePlanService inner pin defense independently.
    Write-Diagnostic "Suite D3: Testing manifest pin preflight mutation rejection (GHA control flow)..."
    $stableCase = $null
    foreach ($c in $fixture.cases) {
        if ([string]$c.id -eq 'valid_stable') { $stableCase = $c; break }
    }

    $epD3 = Safe-CreateEndpoint -Tag "EpD3-Pin"
    $epD3Rec = $script:TrackedEndpoints[-1]
    $resD3 = [System.IO.Path]::Combine($labDir, "result-d3-pin.json")
    $gateD3 = [System.IO.Path]::Combine($labDir, "gate-d3-pin.txt")

    $argsD3 = Build-PhpClientArgs -clientScript $clientFixtureScript -phpExtDir $phpExtDir `
        -extOpenssl $extOpenssl -extMbstring $extMbstring -extZip $extZip `
        -mode "plan" -endpoint $epD3.EndpointName -fixturePath $canonicalFixture -resultFile $resD3 -holdGateFile $gateD3

    $ownerD3 = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $argsD3 -cwd $labDir -env $sparseEnv -tag "WorkerD3-Pin"
    $ownerD3Rec = $script:TrackedOwners[-1]

    [void]$epD3.ConnectOwnedWorker($ownerD3, 5000)

    $reqObjD3 = @{
        raw_manifest_base64 = [string]$stableCase.raw_manifest_base64
        signature = [string]$stableCase.signature_base64
        expected_manifest_sha256 = "0000000000000000000000000000000000000000000000000000000000000000"
        source_context = @{
            version = "1.0.0"; native_version = "1.0.0"; channel = "stable"
            inventory_sha256 = [string]$stableCase.context.source.inventory_sha256
            native_shell_sha256 = [string]$stableCase.context.source.native_shell_sha256
            data_compatibility = @{
                format = [int]1; metadata_migration = "059_add_update_compatibility_metadata.sql"
                schema_sha256 = [string]$stableCase.context.data_contract.schema_sha256
                ledger_sha256 = [string]$stableCase.context.data_contract.ledger_sha256
            }
        }
        target_context = @{
            version = "1.0.1"; native_version = "1.0.1"; channel = "stable"
            inventory_sha256 = [string]$stableCase.context.target.inventory_sha256
            native_shell_sha256 = [string]$stableCase.context.target.native_shell_sha256
            data_compatibility = @{
                format = [int]1; metadata_migration = "059_add_update_compatibility_metadata.sql"
                schema_sha256 = [string]$stableCase.context.data_contract.schema_sha256
                ledger_sha256 = [string]$stableCase.context.data_contract.ledger_sha256
            }
        }
    }
    $reqBytesD3 = [System.Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject $reqObjD3 -Depth 10 -Compress))
    $epD3.WriteFrame($reqBytesD3, 5000)

    $frameD3 = $epD3.ReadFrame(5000)
    $replyD3Bytes = $frameD3.CopyPayload()
    $replyD3 = ConvertFrom-Json -InputObject ([System.Text.Encoding]::UTF8.GetString($replyD3Bytes))

    Assert-Condition ($replyD3.ok -eq $false) "Suite D3: Manifest pin mismatch rejected"
    Assert-Condition ($replyD3.can_apply -eq $false) "Suite D3: can_apply is false"

    [void](Close-TrackedEndpoint $epD3Rec 5000)
    [System.IO.File]::WriteAllText($gateD3, "RELEASE`n", [System.Text.Encoding]::UTF8)
    Assert-Condition (Wait-ProcessDead -owner $ownerD3 -timeoutMs 5000) "Suite D3: Worker exited naturally"
    [void](Stop-TrackedOwner $ownerD3Rec 5000)

    Assert-Condition ([System.IO.File]::Exists($resD3)) "Suite D3: Result file exists"
    $resDocD3 = ConvertFrom-Json -InputObject ([System.IO.File]::ReadAllText($resD3, [System.Text.Encoding]::UTF8))
    Assert-Condition ($resDocD3.ok -eq $false) "Suite D3: Result ok is false"
    Assert-Condition ($resDocD3.cleanup_ok -eq $true) "Suite D3: Result cleanup_ok is true"

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite D completed successfully."

    # ========================================================================
    # Suite E: PHP Decoder Protocol Fail-Close Enforcement (Managed Server)
    # ========================================================================
    Write-Diagnostic "Starting Suite E: PHP Decoder Protocol Fail-Close Enforcement..."

    $failCloseTests = @(
        @{
            Name = "bad_magic"
            Bytes = [byte[]]@(
                0x58, 0x58, 0x58, 0x58, # 'XXXX'
                0x04, 0x00, 0x00, 0x00, # len = 4
                0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00 # seq = 1
            )
            Payload = [byte[]]@(1, 2, 3, 4)
            ExpectedError = "magic"
        },
        @{
            Name = "bad_length_zero"
            Bytes = [byte[]]@(
                0x5A, 0x57, 0x50, 0x31, # 'ZWP1'
                0x00, 0x00, 0x00, 0x00, # len = 0
                0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00 # seq = 1
            )
            Payload = $null
            ExpectedError = "length"
        },
        @{
            Name = "bad_length_huge"
            Bytes = [byte[]]@(
                0x5A, 0x57, 0x50, 0x31, # 'ZWP1'
                0x01, 0x00, 0x00, 0x04, # len = 67108865 (> 64 MiB)
                0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00 # seq = 1
            )
            Payload = $null
            ExpectedError = "length"
        },
        @{
            Name = "bad_seq_hi"
            Bytes = [byte[]]@(
                0x5A, 0x57, 0x50, 0x31, # 'ZWP1'
                0x04, 0x00, 0x00, 0x00, # len = 4
                0x01, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00 # seq_hi = 1
            )
            Payload = [byte[]]@(1, 2, 3, 4)
            ExpectedError = "sequence"
        },
        @{
            Name = "bad_seq_mismatch"
            Bytes = [byte[]]@(
                0x5A, 0x57, 0x50, 0x31, # 'ZWP1'
                0x04, 0x00, 0x00, 0x00, # len = 4
                0x02, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00 # seq = 2 (expected 1)
            )
            Payload = [byte[]]@(1, 2, 3, 4)
            ExpectedError = "sequence"
        },
        @{
            Name = "truncated_header"
            Bytes = [byte[]]@(
                0x5A, 0x57, 0x50, 0x31, # 'ZWP1'
                0x04, 0x00, 0x00, 0x00  # only 8 bytes total
            )
            Payload = $null
            ExpectedError = "EOF"
        }
    )

    foreach ($fc in $failCloseTests) {
        $fcName = $fc.Name
        Write-Diagnostic "Suite E: Running sub-test '$fcName'..."

        $guidE = [System.Guid]::NewGuid().ToString("N")
        $basenameE = "pos-worker-$guidE"
        $resE = [System.IO.Path]::Combine($labDir, "result-e-$fcName.json")
        $gateE = [System.IO.Path]::Combine($labDir, "gate-e-$fcName.txt")

        $serverE = $null
        $asyncConn = $null
        $acceptRec = $null

        try {
            $serverE = New-Object System.IO.Pipes.NamedPipeServerStream($basenameE, [System.IO.Pipes.PipeDirection]::InOut, 1, [System.IO.Pipes.PipeTransmissionMode]::Byte, [System.IO.Pipes.PipeOptions]::Asynchronous)
            $asyncConn = $serverE.BeginWaitForConnection($null, $null)
            $acceptRec = [PSObject]@{
                Server = $serverE
                AsyncResult = $asyncConn
                EndCollected = $false
                WaitHandleClosed = $false
                Disposed = $false
                Tag = "SuiteE-$fcName"
            }
            $script:TrackedManagedAccepts.Add($acceptRec)

            $argsE = Build-PhpClientArgs -clientScript $clientFixtureScript -phpExtDir $phpExtDir `
                -extOpenssl $extOpenssl -extMbstring $extMbstring -extZip $extZip `
                -mode "expect_fail" -endpoint $basenameE -resultFile $resE -holdGateFile $gateE

            $ownerE = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $argsE -cwd $labDir -env $sparseEnv -tag "WorkerE-$fcName"
            $ownerERec = $script:TrackedOwners[-1]

            $connOk = $asyncConn.AsyncWaitHandle.WaitOne(5000)
            Assert-Condition $connOk "Suite E ($fcName): Managed pipe server connection established within 5000ms"

            $serverE.EndWaitForConnection($asyncConn)
            $acceptRec.EndCollected = $true
            if ($asyncConn.AsyncWaitHandle -ne $null) {
                $asyncConn.AsyncWaitHandle.Close()
                $acceptRec.WaitHandleClosed = $true
            }

            # Transmit bad frame header bytes
            $serverE.Write($fc.Bytes, 0, $fc.Bytes.Length)
            if ($fc.Payload -ne $null) {
                $serverE.Write($fc.Payload, 0, $fc.Payload.Length)
            }
            $serverE.Flush()

            if ($fcName -eq 'truncated_header') {
                $serverE.Disconnect()
            }

            # Release hold gate
            [System.IO.File]::WriteAllText($gateE, "RELEASE`n", [System.Text.Encoding]::UTF8)

            # Worker must catch failure, record expected rejection marker, and exit
            Assert-Condition (Wait-ProcessDead -owner $ownerE -timeoutMs 5000) "Suite E ($fcName): Worker exited after fault"
            [void](Stop-TrackedOwner $ownerERec 5000)

            Assert-Condition ([System.IO.File]::Exists($resE)) "Suite E ($fcName): Result marker exists"
            $resDocE = ConvertFrom-Json -InputObject ([System.IO.File]::ReadAllText($resE, [System.Text.Encoding]::UTF8))
            Assert-Condition ($resDocE.ok -eq $false) "Suite E ($fcName): Marker ok is false"
            Assert-Condition ($resDocE.cleanup_ok -eq $true) "Suite E ($fcName): Marker cleanup_ok is true"
            Assert-Condition ($resDocE.can_apply -eq $false) "Suite E ($fcName): Marker can_apply is false"
            Assert-Condition ($resDocE.is_faulted -eq $true) "Suite E ($fcName): Transport faulted"
            Assert-Condition ([string]$resDocE.error).ToLowerInvariant().Contains($fc.ExpectedError.ToLowerInvariant()) `
                "Suite E ($fcName): Error message contains '$($fc.ExpectedError)' (got: $($resDocE.error))"

        } finally {
            if ($acceptRec -ne $null) {
                Cleanup-TrackedManagedAccept -Record $acceptRec
            }
        }
    }

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite E completed successfully."

    # ========================================================================
    # Suite F: Honest Blocking Governance (Timeout & Owned Job Termination)
    # ========================================================================
    Write-Diagnostic "Starting Suite F: Honest Blocking Governance..."

    $epF = Safe-CreateEndpoint -Tag "EpF"
    $epFRec = $script:TrackedEndpoints[-1]
    $resF = [System.IO.Path]::Combine($labDir, "result-f.json")

    $argsF = Build-PhpClientArgs -clientScript $clientFixtureScript -phpExtDir $phpExtDir `
        -extOpenssl $extOpenssl -extMbstring $extMbstring -extZip $extZip `
        -mode "stall" -endpoint $epF.EndpointName -resultFile $resF

    $ownerF = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $argsF -cwd $labDir -env $sparseEnv -tag "WorkerF"
    $ownerFRec = $script:TrackedOwners[-1]

    [void]$epF.ConnectOwnedWorker($ownerF, 5000)

    # Native caller enforces 1000ms deadline; stalling PHP worker causes timeout
    $swF = [System.Diagnostics.Stopwatch]::StartNew()
    $timeoutFTriggered = $false
    try {
        [void]$epF.ReadFrame(1000)
    } catch {
        $baseEx = $_.Exception.GetBaseException()
        if ($baseEx -is [System.TimeoutException]) {
            $timeoutFTriggered = $true
        }
    }
    Assert-Condition $timeoutFTriggered "Suite F: ReadFrame timed out as expected against stalling PHP worker"
    Assert-Condition ($swF.ElapsedMilliseconds -ge 900) "Suite F: ReadFrame respected timeout budget ($($swF.ElapsedMilliseconds) ms)"

    # Caller stops OWN worker job and proves clean kernel rundown
    $stopOkF = Stop-TrackedOwner $ownerFRec 5000
    Assert-Condition $stopOkF "Suite F: Caller successfully stopped stalling worker job"

    $obsF = $ownerF.Observe()
    Assert-Condition ($obsF.RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Dead) "Suite F: Worker root state is Dead"
    Assert-Condition ($obsF.JobCountKnown -and $obsF.ActiveProcesses -eq 0) "Suite F: Job active process count is known and 0"
    Assert-Condition ($obsF.IsQuiescent -eq $true) "Suite F: Child root is quiescent"
    Assert-Condition ($ownerF.IsClosed -eq $true) "Suite F: Owner handle is closed"

    # Close pipe endpoint
    $closeOkF = Close-TrackedEndpoint $epFRec 5000
    Assert-Condition $closeOkF "Suite F: Endpoint closed cleanly"
    Assert-Condition ($epF.State -eq [PosDesktop.Updates.Native.PipeEndpointState]::Closed) "Suite F: Endpoint state is Closed"

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite F completed successfully."

} catch {
    $script:PrimaryError = $_.Exception
    [Console]::Error.WriteLine("FATAL ERROR in test execution:")
    [Console]::Error.WriteLine($_.Exception.ToString())
} finally {
    Write-Diagnostic "Running global cleanup rundown..."

    # 1. Drain and stop all tracked child process owners bounded
    foreach ($rec in $script:TrackedOwners) {
        if (-not $rec.Closed -and $rec.Owner -ne $null) {
            try {
                [void](Stop-TrackedOwner $rec 5000)
            } catch {
                $script:OwnerStopErrors.Add("Cleanup exception stopping $($rec.Tag): $($_.Exception.Message)")
            }
        }
    }

    # 2. Close all tracked pipe endpoints bounded
    foreach ($rec in $script:TrackedEndpoints) {
        if (-not $rec.Closed -and $rec.Endpoint -ne $null) {
            try {
                [void](Close-TrackedEndpoint $rec 5000)
            } catch {
                $script:EndpointCloseErrors.Add("Cleanup exception closing $($rec.Tag): $($_.Exception.Message)")
            }
        }
    }

    # 2b. Dispose all tracked managed pipe accepts bounded
    foreach ($rec in $script:TrackedManagedAccepts) {
        if (-not $rec.Disposed -or -not $rec.EndCollected) {
            Cleanup-TrackedManagedAccept -Record $rec
        }
    }

    # 3. Assert victim witness tree remains untouched
    try {
        Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    } catch {
        [Console]::Error.WriteLine("CRITICAL: Victim witness tree altered: $($_.Exception.Message)")
        if ($script:PrimaryError -eq $null) { $script:PrimaryError = $_.Exception }
    }

    # 4. Safe deletion of lab directory only if completely drained and no errors
    $hasPendingAccepts = $false
    foreach ($rec in $script:TrackedManagedAccepts) {
        if (-not $rec.EndCollected -or -not $rec.Disposed) {
            $hasPendingAccepts = $true
            $script:ManagedControlErrors.Add("Accept record for $($rec.Tag) was not completely collected or disposed (EndCollected=$($rec.EndCollected), Disposed=$($rec.Disposed))")
        }
    }

    $hasUncertainCleanup = ($script:OwnerStopErrors.Count -gt 0 -or $script:EndpointCloseErrors.Count -gt 0 -or $script:ManagedControlErrors.Count -gt 0 -or $hasPendingAccepts)
    if (-not $hasUncertainCleanup -and $script:PrimaryError -eq $null) {
        try {
            Invoke-DirectoryDeletion -Path $labDir -ExpectedMarkerName ".snpp-lab-marker" -ExpectedNonce $nonce
            Invoke-DirectoryDeletion -Path $victimDir -ExpectedMarkerName ".snpp-victim-marker" -ExpectedNonce $nonce
            Write-Diagnostic "Successfully cleaned up lab and victim directories."
        } catch {
            [Console]::Error.WriteLine("Cleanup deletion error: $($_.Exception.Message)")
        }
    } else {
        [Console]::WriteLine("LAB RETAINED for inspection: $labDir (Errors present or uncertain state).")
    }

    if ($script:PrimaryError -ne $null) {
        if ($hasUncertainCleanup) {
            $allCleanupErrors = @($script:OwnerStopErrors) + @($script:EndpointCloseErrors) + @($script:ManagedControlErrors)
            [Console]::Error.WriteLine("CLEANUP ERRORS during rundown: $($allCleanupErrors -join '; ')")
        }
        throw $script:PrimaryError
    }

    if ($hasUncertainCleanup) {
        $allCleanupErrors = @($script:OwnerStopErrors) + @($script:EndpointCloseErrors) + @($script:ManagedControlErrors)
        throw [System.InvalidOperationException]"Cleanup errors occurred during rundown: $($allCleanupErrors -join '; ')"
    }
}

[Console]::WriteLine("ALL 6 NATIVE-PHP WORKER PIPE TEST SUITES PASSED SUCCESSFULLY.")
[Console]::WriteLine("Total assertions verified: $($script:AssertionCount)")
exit 0
