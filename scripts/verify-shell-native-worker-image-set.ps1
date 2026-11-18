# scripts/verify-shell-native-worker-image-set.ps1
# Win32 Native Worker Image Set Leases + Real Copied PHP Integration Verifier
#
# Verifies:
# 1. Auth + Bind -> Map: Source-only metadata, cap flags false, native verify/bind before launch,
#    pinned files in payload root, RevalidateAll, tampered file fails Acquire, retained set captured.
# 2. Real Copied PHP with -n, explicit extensions, UTC, clean sparse environment, Win32 kernel ROOT
#    peer pipe connection, RPC request/reply, root live + peer match, RevalidateAll while live,
#    close pipe -> wait root dead (job 0) < 5s -> close leases.
# 3. Write open probe denied (Win32 error 32 / sharing violation), read probe allowed, parent dir rename denied 32.
# 4. Session policy/canonical negatives: non-canonical duplicate field / extra key / sig tamper / raw sha pin mismatch.
# 5. Frame size limit / oversized frame (>24 MiB): header rejected before body allocation, transport faults.
# 6. External blocking governance: connect then stall, parent owns job deadline, terminates child, root dead / job 0.
#
# STRICT BOUNDARIES:
# - Runs exclusively in GitHub Actions on Windows under WinPS 5.1 Desktop (x64)
# - Pure in-memory projection; zero database queries, zero physical apply writes
# - Caller host authenticates native descriptors and bindings independently

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$FixtureFile
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
    if (-not [System.IO.File]::Exists($rawPath) -and -not [System.IO.Directory]::Exists($rawPath)) {
        throw "$paramName path does not exist: $rawPath"
    }
    $canon = [System.IO.Path]::GetFullPath($rawPath)
    return $canon
}

$canonicalFixture = Assert-ValidAbsolutePath $FixtureFile "FixtureFile"

# --- Section 3: Diagnostic and Hashing Helpers ---
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
        [string]$directoryPath,
        [string]$boundPrefix,
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

# --- Section 4: Global Tracking and Governance ---
$script:TrackedOwners = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedEndpoints = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedSetLeases = [System.Collections.Generic.List[PSObject]]::new()
$script:OwnerStopErrors = [System.Collections.Generic.List[string]]::new()
$script:EndpointCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:LeaseCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:PrimaryError = $null
$script:AllSuitesPassed = $false
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

function Register-TrackedSetLease {
    param($SetLease, [string]$Tag)
    $rec = [PSObject]@{
        SetLease = $SetLease
        Tag = $Tag
        Closed = $false
    }
    $script:TrackedSetLeases.Add($rec)
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
        [void](Register-TrackedOwner -Owner $owner -Tag $tag -ExePath $exePath)
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
        [void](Register-TrackedEndpoint -Endpoint $ep -Tag $Tag)
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

function Safe-AcquireSetLease {
    param([string]$payloadRoot, $map, [string]$Tag = "SetLease")
    $setLease = $null
    try {
        $setLease = [PosDesktop.Updates.Native.WorkerImageSetLease]::Acquire($payloadRoot, $map)
        if ($setLease -eq $null) {
            throw "Acquire returned null set lease."
        }
        [void](Register-TrackedSetLease -SetLease $setLease -Tag $Tag)
        return $setLease
    } catch {
        $curr = $_.Exception
        $retained = $null
        $depth = 0
        while ($curr -ne $null -and $depth -lt 5) {
            if ($curr -is [PosDesktop.Updates.Native.WorkerImageSetLeaseAcquisitionException]) {
                $retained = $curr.RetainedSet
                break
            }
            $curr = $curr.InnerException
            $depth++
        }
        if ($retained -ne $null) {
            [void](Register-TrackedSetLease -SetLease $retained -Tag "$Tag-Retained")
        }
        throw
    }
}

function Stop-TrackedOwner {
    param($ownerRec, [int]$timeoutMs = 5000)
    if ($ownerRec -eq $null -or $ownerRec.Owner -eq $null) { return $false }
    if ($ownerRec.Closed) {
        if (-not $ownerRec.Owner.IsClosed) { return $false }
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

function Stop-AllTrackedOwners {
    foreach ($ownerRec in $script:TrackedOwners) {
        if ($ownerRec -ne $null -and $ownerRec.Owner -ne $null -and -not $ownerRec.Closed) {
            try {
                [void](Stop-TrackedOwner -ownerRec $ownerRec -timeoutMs 5000)
            } catch {
                $script:OwnerStopErrors.Add("Exception stopping owner $($ownerRec.Tag): $($_.Exception.Message)")
            }
        }
    }
}

function Close-TrackedEndpoint {
    param($endpointRec, [int]$timeoutMs = 5000)
    if ($endpointRec -eq $null -or $endpointRec.Endpoint -eq $null) { return $true }
    if ($endpointRec.Closed) {
        if ($endpointRec.Endpoint.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::Closed) { return $false }
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

function Close-AllTrackedEndpoints {
    foreach ($epRec in $script:TrackedEndpoints) {
        if ($epRec -ne $null -and $epRec.Endpoint -ne $null -and -not $epRec.Closed) {
            try {
                [void](Close-TrackedEndpoint -endpointRec $epRec -timeoutMs 5000)
            } catch {
                $script:EndpointCloseErrors.Add("Exception closing endpoint $($epRec.Tag): $($_.Exception.Message)")
            }
        }
    }
}

function Close-TrackedSetLease {
    param($leaseRec)
    if ($leaseRec -eq $null -or $leaseRec.SetLease -eq $null) { return $true }
    if ($leaseRec.Closed) {
        if (-not $leaseRec.SetLease.IsClosed) { return $false }
        return $true
    }
    $err = 0
    $ok = $leaseRec.SetLease.TryClose([ref]$err)
    if ($ok -and $leaseRec.SetLease.IsClosed) {
        $leaseRec.Closed = $true
        return $true
    }
    $script:LeaseCloseErrors.Add("Failed to close set lease $($leaseRec.Tag): Win32 err $err")
    return $false
}

function Close-AllTrackedSetLeases {
    for ($i = $script:TrackedSetLeases.Count - 1; $i -ge 0; $i--) {
        $leaseRec = $script:TrackedSetLeases[$i]
        if ($leaseRec -ne $null -and $leaseRec.SetLease -ne $null -and -not $leaseRec.Closed) {
            try {
                [void](Close-TrackedSetLease -leaseRec $leaseRec)
            } catch {
                $script:LeaseCloseErrors.Add("Exception closing set lease $($leaseRec.Tag): $($_.Exception.Message)")
            }
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
        [System.Threading.Thread]::Sleep(50)
    }
    return $false
}

# --- Section 5: Environment Sanitization Helper ---
function Build-SparseWorkerEnvironment {
    param([string]$payloadRoot, [string]$labTempDir)
    $sysRoot = [System.Environment]::GetEnvironmentVariable("SystemRoot")
    if ([string]::IsNullOrEmpty($sysRoot)) { $sysRoot = "C:\Windows" }
    $sys32 = [System.IO.Path]::Combine($sysRoot, "System32")
    $payloadPhpDir = [System.IO.Path]::Combine($payloadRoot, "resources\portable\php")
    $sparsePath = "$payloadPhpDir;$sys32"

    $envMap = [System.Collections.Generic.Dictionary[string, string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    $envMap["GITHUB_ACTIONS"] = "true"
    $envMap["RUNNER_OS"] = "Windows"
    $envMap["SystemRoot"] = $sysRoot
    $envMap["PATH"] = $sparsePath
    $envMap["TEMP"] = $labTempDir
    $envMap["TMP"] = $labTempDir
    $envMap["TZ"] = "UTC"
    return $envMap
}

# --- Helper: Construct Native Binding Context from Fixture Case ---
function New-NativeBindingContextFromCase {
    param($CaseData, [string]$DefaultKeyId)
    $srcCtx = $CaseData.source_context
    $tgtCtx = $CaseData.target_context

    $srcIdent = [PosDesktop.Updates.Native.NativeBrokerEndpointIdentity]::new(
        [string]$srcCtx.version,
        [string]$srcCtx.channel,
        [string]$srcCtx.native_version,
        [string]$srcCtx.inventory_sha256,
        [string]$srcCtx.native_shell_sha256
    )

    $tgtIdent = [PosDesktop.Updates.Native.NativeBrokerEndpointIdentity]::new(
        [string]$tgtCtx.version,
        [string]$tgtCtx.channel,
        [string]$tgtCtx.native_version,
        [string]$tgtCtx.inventory_sha256,
        [string]$tgtCtx.native_shell_sha256
    )

    $dc = $srcCtx.data_compatibility
    $contractCtx = [PosDesktop.Updates.Native.NativeBrokerDataContractContext]::new(
        [string]$dc.schema_sha256,
        [string]$dc.ledger_sha256
    )

    $expKey = $DefaultKeyId
    return [PosDesktop.Updates.Native.NativeBrokerBindingContext]::new(
        $srcIdent,
        $tgtIdent,
        [string]$CaseData.expected_manifest_sha256,
        $contractCtx,
        $expKey
    )
}

# Helper: Build argument vector for real worker launch under compiled backend.phar
function Build-RealWorkerArgs {
    param(
        [string]$pharPath,
        [string]$phpExtDir,
        [string]$endpointName
    )
    $argsList = [System.Collections.Generic.List[string]]::new()
    $argsList.Add("-n")
    $argsList.Add("-dmemory_limit=256M")
    $argsList.Add("-dextension_dir=$phpExtDir")
    $argsList.Add("-dextension=php_openssl.dll")
    $argsList.Add("-dextension=php_mbstring.dll")
    $argsList.Add("-dextension=php_zip.dll")
    $argsList.Add($pharPath)
    $argsList.Add("shell-worker-plan")
    $argsList.Add("--endpoint=$endpointName")
    return $argsList.ToArray()
}

# --- TOP GLOBAL TRY COVERS LAB INIT, COMPILATION, SETUP, AND ALL SUITES ---
$nonce = [System.Guid]::NewGuid().ToString("N")
$labDir = $null
$victimDir = $null
$labTempDir = $null
$script:VictimSnapshot = $null
$canonicalPayloadRoot = $null

try {
    Write-Diagnostic "Beginning Native Worker Image Set Lease Integration Test Suites..."

    # 1. Read and parse fixture JSON
    $fixtureRawText = [System.IO.File]::ReadAllText($canonicalFixture, [System.Text.Encoding]::UTF8)
    $fixtureDoc = ConvertFrom-Json -InputObject $fixtureRawText

    if ($fixtureDoc.payload_root -eq $null -or [string]::IsNullOrWhiteSpace([string]$fixtureDoc.payload_root)) {
        throw "Fixture JSON missing mandatory payload_root."
    }
    $canonicalPayloadRoot = Assert-ValidAbsolutePath ([string]$fixtureDoc.payload_root) "payload_root"
    Assert-PathAncestorsAndRootNotReparse $canonicalPayloadRoot

    # 2. RUNNER_TEMP Validation
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

    # 3. Create nonce-marked lab and immutable victim directories
    $labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "is-lab-$nonce")
    $victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "is-victim-$nonce")
    $labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
    [void][System.IO.Directory]::CreateDirectory($labDir)
    [void][System.IO.Directory]::CreateDirectory($labTempDir)
    [void][System.IO.Directory]::CreateDirectory($victimDir)

    $labMarkerPath = [System.IO.Path]::Combine($labDir, ".is-lab-marker")
    [System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
    $victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".is-victim-marker")
    [System.IO.File]::WriteAllText($victimMarkerPath, $nonce, [System.Text.Encoding]::UTF8)

    # Populate victim witness tree (immutable outside lab)
    $victimWitness1 = [System.IO.Path]::Combine($victimDir, "victim-witness-1.dat")
    [System.IO.File]::WriteAllBytes($victimWitness1, [System.Text.Encoding]::UTF8.GetBytes("VICTIM_BYTE_1_$nonce"))
    $victimWitness2 = [System.IO.Path]::Combine($victimDir, "victim-witness-2.txt")
    [System.IO.File]::WriteAllText($victimWitness2, "VICTIM_TEXT_2_$nonce", [System.Text.Encoding]::UTF8)

    $script:VictimSnapshot = Capture-VictimSnapshot $victimDir

    # 4. Compile Production C# via Add-Type
    $backendDir = [System.IO.Path]::GetFullPath([System.IO.Path]::Combine($PSScriptRoot, "..", "backend"))
    $workerLeaseCsPath = [System.IO.Path]::Combine($backendDir, "Native", "WorkerImageReadLease.cs")
    $verifierCsPath = [System.IO.Path]::Combine($backendDir, "Native", "NativeBrokerManifestVerifier.cs")
    $bindingCsPath = [System.IO.Path]::Combine($backendDir, "Native", "NativeBrokerContextBinding.cs")
    $ownedChildCsPath = [System.IO.Path]::Combine($backendDir, "Native", "OwnedChildProcess.cs")
    $ownedPipeCsPath = [System.IO.Path]::Combine($backendDir, "Native", "OwnedWorkerPipeEndpoint.cs")
    $workerImageSetCsPath = [System.IO.Path]::Combine($backendDir, "Native", "NativeBrokerWorkerImageSet.cs")

    Assert-ValidAbsolutePath $workerLeaseCsPath "WorkerImageReadLease.cs"
    Assert-ValidAbsolutePath $verifierCsPath "NativeBrokerManifestVerifier.cs"
    Assert-ValidAbsolutePath $bindingCsPath "NativeBrokerContextBinding.cs"
    Assert-ValidAbsolutePath $ownedChildCsPath "OwnedChildProcess.cs"
    Assert-ValidAbsolutePath $ownedPipeCsPath "OwnedWorkerPipeEndpoint.cs"
    Assert-ValidAbsolutePath $workerImageSetCsPath "NativeBrokerWorkerImageSet.cs"

    $workerLeaseSource = [System.IO.File]::ReadAllText($workerLeaseCsPath, [System.Text.Encoding]::UTF8)
    $verifierSource = [System.IO.File]::ReadAllText($verifierCsPath, [System.Text.Encoding]::UTF8)
    $bindingSource = [System.IO.File]::ReadAllText($bindingCsPath, [System.Text.Encoding]::UTF8)
    $ownedChildSource = [System.IO.File]::ReadAllText($ownedChildCsPath, [System.Text.Encoding]::UTF8)
    $ownedPipeSource = [System.IO.File]::ReadAllText($ownedPipeCsPath, [System.Text.Encoding]::UTF8)
    $workerImageSetSource = [System.IO.File]::ReadAllText($workerImageSetCsPath, [System.Text.Encoding]::UTF8)

    $env:TEMP = $labTempDir; $env:TMP = $labTempDir
    $combinedInMemory = $workerLeaseSource + "`r`n" + $verifierSource + "`r`n" + $bindingSource + "`r`n" + $ownedChildSource + "`r`n" + $ownedPipeSource + "`r`n" + $workerImageSetSource
    Add-Type -TypeDefinition $combinedInMemory
    Write-Diagnostic "Successfully compiled native C# types via Add-Type."

    # 5. Derive and validate canonical PHP under payload root
    $relPhpExe = [PosDesktop.Updates.Native.NativeBrokerWorkerImageMap]::RequiredPhpExe.Replace('/', '\')
    $canonicalPhp = Assert-ValidAbsolutePath ([System.IO.Path]::Combine($canonicalPayloadRoot, $relPhpExe)) "RequiredPhpExe"
    Assert-PathAncestorsAndRootNotReparse $canonicalPhp
    $sparseEnv = Build-SparseWorkerEnvironment -payloadRoot $canonicalPayloadRoot -labTempDir $labTempDir

    # 6. Extract Trusted Public Key from Fixture
    $pk = $fixtureDoc.public_key
    $keyId = [string]$pk.key_id
    $modBytes = [System.Convert]::FromBase64String([string]$pk.modulus_base64)
    $expBytes = [System.Convert]::FromBase64String([string]$pk.exponent_base64)
    $trustedKey = [PosDesktop.Updates.Native.NativeBrokerTrustedKey]::new($keyId, $modBytes, $expBytes)
    $verifier = [PosDesktop.Updates.Native.NativeBrokerManifestVerifier]::new([PosDesktop.Updates.Native.NativeBrokerTrustedKey[]]@($trustedKey))

    $baselineCase = $fixtureDoc.cases | Where-Object { $_.id -eq 'positive_stable_transition' }
    if ($baselineCase -eq $null) {
        throw "Baseline positive case 'positive_stable_transition' missing from fixture."
    }

    # ========================================================================
    # Suite 1: Auth + Bind -> Map Projection & Image Set Lease Acquisition
    # ========================================================================
    Write-Diagnostic "Starting Suite 1: Auth + Bind -> Map & Set Lease..."

    $rawBytes = [System.Convert]::FromBase64String([string]$baselineCase.raw_manifest_base64)
    $sigStr = [string]$baselineCase.signature_base64
    $bindingCtx = New-NativeBindingContextFromCase -CaseData $baselineCase -DefaultKeyId $keyId

    # 1. Cryptographic Envelope Verification
    $envelope = $verifier.VerifyAndParse($rawBytes, $sigStr)
    Assert-Condition ($envelope -ne $null) "Suite 1: Envelope verified"
    Assert-Condition ($envelope.CanApply -eq $false) "Suite 1: envelope.CanApply is false"
    Assert-Condition ($envelope.ManifestSha256 -eq [string]$baselineCase.expected_manifest_sha256) "Suite 1: envelope.ManifestSha256 matches"

    # 2. Context Binding
    $bound = [PosDesktop.Updates.Native.NativeBrokerContextBinding]::Bind($envelope, $bindingCtx)
    Assert-Condition ($bound -ne $null) "Suite 1: Context bound"
    Assert-Condition ($bound.CanApply -eq $false) "Suite 1: bound.CanApply is false"

    # 3. Map Projection
    $map = [PosDesktop.Updates.Native.NativeBrokerWorkerImageMap]::FromBoundResult($bound)
    Assert-Condition ($map -ne $null) "Suite 1: Map projected"
    Assert-Condition ($map.CanApply -eq $false) "Suite 1: map.CanApply is false"
    Assert-Condition ($map.CanLaunch -eq $false) "Suite 1: map.CanLaunch is false"
    Assert-Condition ($map.Count -gt 0) "Suite 1: map.Count > 0"
    Assert-Condition ($map.Contains("resources/portable/php/php.exe")) "Suite 1: map contains php.exe"
    Assert-Condition ($map.Contains("resources/app.asar.unpacked/backend/backend.phar")) "Suite 1: map contains backend.phar"
    Assert-Condition ($map.Contains("resources/portable/php/ext/php_openssl.dll")) "Suite 1: map contains php_openssl.dll"
    Assert-Condition ($map.Contains("resources/app.asar.unpacked/backend/certs/update_public_key.pem")) "Suite 1: map contains update_public_key.pem"

    # 4. Acquire Image Set Lease over valid Payload Root
    $setLease = Safe-AcquireSetLease -payloadRoot $canonicalPayloadRoot -map $map -Tag "Suite1-Valid"
    $setLeaseRec = $script:TrackedSetLeases[-1]

    Assert-Condition ($setLease.Count -eq $map.Count) "Suite 1: setLease.Count equals map.Count"
    Assert-Condition ($setLease.CanApply -eq $false) "Suite 1: setLease.CanApply is false"
    Assert-Condition ($setLease.CanLaunch -eq $false) "Suite 1: setLease.CanLaunch is false"
    Assert-Condition ($setLease.IsClosed -eq $false) "Suite 1: setLease.IsClosed is false"

    # Full SHA-256 byte revalidation (void return, succeeds if no throw)
    $setLease.RevalidateAll($true)
    Assert-Condition ($setLease.IsClosed -eq $false) "Suite 1: RevalidateAll(true) succeeded on acquired set lease"

    # Clean release
    $closeLeaseOk = Close-TrackedSetLease $setLeaseRec
    Assert-Condition $closeLeaseOk "Suite 1: Set lease closed cleanly"
    Assert-Condition ($setLease.IsClosed -eq $true) "Suite 1: Set lease state is closed"

    # 5. Tampered File Fails Acquire with Retained Set Captured
    Write-Diagnostic "Suite 1: Testing tampered payload file rejection..."
    $tamperLab = [System.IO.Path]::Combine($labDir, "tamper-payload")
    [void][System.IO.Directory]::CreateDirectory($tamperLab)

    # Shallow clone payload files for tamper testing
    $allPayloadFiles = [System.Collections.Generic.List[PSObject]]::new()
    Walk-DirectorySafeTopDown -directoryPath $canonicalPayloadRoot -boundPrefix ($canonicalPayloadRoot.TrimEnd('\') + '\') -discoveredFiles $allPayloadFiles -discoveredDirs $null
    foreach ($pf in $allPayloadFiles) {
        $rel = $pf.FullName.Substring($canonicalPayloadRoot.Length + 1)
        $destPath = [System.IO.Path]::Combine($tamperLab, $rel)
        $parentD = [System.IO.Path]::GetDirectoryName($destPath)
        if (-not [System.IO.Directory]::Exists($parentD)) {
            [void][System.IO.Directory]::CreateDirectory($parentD)
        }
        [System.IO.File]::Copy($pf.FullName, $destPath, $true)
    }

    # Tamper backend.phar bytes in clone
    $tamperedPhar = [System.IO.Path]::Combine($tamperLab, "resources\app.asar.unpacked\backend\backend.phar")
    Assert-Condition ([System.IO.File]::Exists($tamperedPhar)) "Suite 1: Tamper target exists"
    [System.IO.File]::WriteAllBytes($tamperedPhar, [System.Text.Encoding]::UTF8.GetBytes("TAMPERED_PHAR_CONTENT_CORRUPTED"))

    $tamperCaught = $false
    try {
        [void][PosDesktop.Updates.Native.WorkerImageSetLease]::Acquire($tamperLab, $map)
    } catch {
        $currEx = $_.Exception
        while ($currEx -ne $null) {
            if ($currEx -is [PosDesktop.Updates.Native.WorkerImageSetLeaseAcquisitionException]) {
                $tamperCaught = $true
                if ($currEx.RetainedSet -ne $null) {
                    $retRec = Register-TrackedSetLease -SetLease $currEx.RetainedSet -Tag "Suite1-TamperRetained"
                    [void](Close-TrackedSetLease $retRec)
                }
                break
            }
            if ($currEx -is [System.InvalidOperationException] -and ($currEx.Message.Contains("mismatch") -or $currEx.Message.Contains("SHA-256"))) {
                $tamperCaught = $true
                break
            }
            $currEx = $currEx.InnerException
        }
    }
    Assert-Condition $tamperCaught "Suite 1: Tampered file threw WorkerImageSetLeaseAcquisitionException or mismatch InvalidOperationException"

    # 6. Negative Map Cases (FromBoundResult rejects malformed / missing files)
    Write-Diagnostic "Suite 1: Running negative map cases..."
    if ($fixtureDoc.negative_map_cases -ne $null) {
        foreach ($nmc in $fixtureDoc.negative_map_cases) {
            $nmcId = [string]$nmc.id
            $nmcRaw = [System.Convert]::FromBase64String([string]$nmc.raw_manifest_base64)
            $nmcSig = [string]$nmc.signature_base64
            $nmcEnv = $verifier.VerifyAndParse($nmcRaw, $nmcSig)
            Assert-Condition ($nmcEnv -ne $null) "Suite 1 ($nmcId): Negative map envelope verified"

            $nmcCase = [pscustomobject]@{
                source_context = $baselineCase.source_context
                target_context = $baselineCase.target_context
                expected_manifest_sha256 = $nmc.expected_manifest_sha256
            }
            $nmcBindingCtx = New-NativeBindingContextFromCase -CaseData $nmcCase -DefaultKeyId $keyId
            $nmcBound = [PosDesktop.Updates.Native.NativeBrokerContextBinding]::Bind($nmcEnv, $nmcBindingCtx)
            Assert-Condition ($nmcBound -ne $null) "Suite 1 ($nmcId): Negative map bound"

            $nmcRejected = $false
            try {
                [void][PosDesktop.Updates.Native.NativeBrokerWorkerImageMap]::FromBoundResult($nmcBound)
            } catch {
                $baseEx = $_.Exception.GetBaseException()
                if ($baseEx -is [System.FormatException]) {
                    $expSub = [string]$nmc.expected_message_substring
                    if ([string]::IsNullOrEmpty($expSub) -or $baseEx.Message.Contains($expSub)) {
                        $nmcRejected = $true
                    }
                }
            }
            Assert-Condition $nmcRejected "Suite 1 ($nmcId): FromBoundResult rejected invalid map descriptor"
        }
    }

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite 1 completed successfully."

    # ========================================================================
    # Suite 2: Real Copied PHP Execution Under Lease + RPC Request/Reply
    # ========================================================================
    Write-Diagnostic "Starting Suite 2: Real Copied PHP Worker Execution..."

    # Acquire fresh lease on payload root
    $setLease2 = Safe-AcquireSetLease -payloadRoot $canonicalPayloadRoot -map $map -Tag "Suite2"
    $setLease2Rec = $script:TrackedSetLeases[-1]

    # Create owned pipe endpoint
    $ep2 = Safe-CreateEndpoint -Tag "Ep2"
    $ep2Rec = $script:TrackedEndpoints[-1]

    $pharPath2 = [System.IO.Path]::Combine($canonicalPayloadRoot, "resources\app.asar.unpacked\backend\backend.phar")
    $extDir2 = [System.IO.Path]::Combine($canonicalPayloadRoot, "resources\portable\php\ext")
    $args2 = Build-RealWorkerArgs -pharPath $pharPath2 -phpExtDir $extDir2 -endpointName $ep2.EndpointName

    $owner2 = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $args2 -cwd $labDir -env $sparseEnv -tag "WorkerSuite2"
    $owner2Rec = $script:TrackedOwners[-1]

    # Connect pipe with 5000ms timeout and capture peer observation
    $obsPeer2 = $ep2.ConnectOwnedWorker($owner2, 5000)
    Assert-Condition ($obsPeer2 -ne $null) "Suite 2: Connected to real copied PHP worker pipe within 5000ms"
    Assert-Condition ($obsPeer2.CanApply -eq $false) "Suite 2: Peer observation CanApply is false"
    Assert-Condition ($obsPeer2.ProcessId -eq $owner2.ProcessId) "Suite 2: Pipe peer PID matches owned worker child PID"
    Assert-Condition ($obsPeer2.CreationFileTimeTicks -eq $owner2.CreationFileTimeTicks) "Suite 2: Pipe peer creation time matches child creation time"

    $obs2 = $owner2.Observe()
    Assert-Condition ($obs2.RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Live) "Suite 2: Worker root process is Live"

    # Send RPC Request Frame (ZWP1)
    $reqBytes2 = [System.Text.Encoding]::UTF8.GetBytes([string]$baselineCase.request_wire_json)
    $ep2.WriteFrame($reqBytes2, 5000)

    # Read RPC Reply Frame (ZWP1)
    $frame2 = $ep2.ReadFrame(5000)
    $replyBytes2 = $frame2.CopyPayload()
    $replyJson2 = [System.Text.Encoding]::UTF8.GetString($replyBytes2)
    $reply2 = ConvertFrom-Json -InputObject $replyJson2

    Assert-Condition ($reply2.ok -eq $true) "Suite 2: Worker replied ok=true"
    Assert-Condition ($reply2.can_apply -eq $false) "Suite 2: Worker replied can_apply=false"
    Assert-Condition ($reply2.manifest_sha256 -eq [string]$baselineCase.expected_manifest_sha256) "Suite 2: Reply manifest_sha256 matches"
    Assert-Condition (-not [string]::IsNullOrEmpty($reply2.plan_json)) "Suite 2: Reply plan_json is present"
    Assert-Condition ($reply2.plan_sha256 -eq (Compute-Sha256OfBytes ([System.Text.Encoding]::UTF8.GetBytes($reply2.plan_json)))) "Suite 2: Reply plan_sha256 matches computed digest"
    Assert-Condition ($reply2.plan_sha256 -eq [string]$fixtureDoc.expected_plan_sha256) "Suite 2: Reply plan_sha256 matches expected fixture pin"

    # Semantic assertions on plan projection
    $planDoc2 = ConvertFrom-Json -InputObject ([string]$reply2.plan_json)
    Assert-Condition ($planDoc2.can_apply -eq $false) "Suite 2: Plan projection can_apply is false"
    Assert-Condition ($planDoc2.manifest_sha256 -eq [string]$baselineCase.expected_manifest_sha256) "Suite 2: Plan projection manifest_sha256 matches"
    Assert-Condition ($planDoc2.source_files.Count -eq $baselineCase.selected_files_count) "Suite 2: Plan contains complete source inventory"
    Assert-Condition ($planDoc2.target_files.Count -eq $baselineCase.selected_files_count) "Suite 2: Plan contains complete target inventory"
    Assert-Condition ($planDoc2.actions.Count -eq $baselineCase.selected_files_count) "Suite 2: Plan contains actions for all inventory entries"
    foreach ($leasedImage in $map.Entries) {
        $sourceFile = $planDoc2.source_files | Where-Object { $_.path -ceq $leasedImage.RelativePath }
        $targetFile = $planDoc2.target_files | Where-Object { $_.path -ceq $leasedImage.RelativePath }
        $action = $planDoc2.actions | Where-Object { $_.path -ceq $leasedImage.RelativePath }
        Assert-Condition ($sourceFile.sha256 -ceq $leasedImage.Sha256 -and $sourceFile.size -eq $leasedImage.Size) "Suite 2: Source image matches lease for $($leasedImage.RelativePath)"
        Assert-Condition ($targetFile.sha256 -ceq $leasedImage.Sha256 -and $targetFile.size -eq $leasedImage.Size) "Suite 2: Target image matches lease for $($leasedImage.RelativePath)"
        Assert-Condition ($action.kind -ceq "retain") "Suite 2: Plan retains $($leasedImage.RelativePath)"
    }
    $versionAction = $planDoc2.actions | Where-Object { $_.path -ceq "resources/app.asar.unpacked/version.json" }
    Assert-Condition ($versionAction.kind -ceq "replace") "Suite 2: Plan replaces version metadata"
    Assert-Condition ($versionAction.source.sha256 -cne $versionAction.target.sha256) "Suite 2: Plan pins the changed version metadata hashes"

    # While child process root is live, verify leases remain valid
    $setLease2.RevalidateAll($true)
    Assert-Condition ($setLease2.IsClosed -eq $false) "Suite 2: RevalidateAll(true) succeeded while worker is live under lease"

    # Close host endpoint -> sends EOF to worker -> triggers clean exit
    [void](Close-TrackedEndpoint $ep2Rec 5000)

    # Wait for worker process to exit naturally
    Assert-Condition (Wait-ProcessDead -owner $owner2 -timeoutMs 5000) "Suite 2: Worker process exited naturally within 5000ms after pipe EOF"

    $obs2Dead = $owner2.Observe()
    Assert-Condition ($obs2Dead.RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Dead) "Suite 2: Worker root state is Dead"
    Assert-Condition ($obs2Dead.JobCountKnown -and $obs2Dead.ActiveProcesses -eq 0) "Suite 2: Job active process count is 0"
    Assert-Condition ($obs2Dead.IsQuiescent -eq $true) "Suite 2: Child root is quiescent"

    # Now that Root is DEAD and Job count is 0: close leases
    [void](Close-TrackedSetLease $setLease2Rec)
    Assert-Condition ($setLease2.IsClosed -eq $true) "Suite 2: Set lease is closed after root process dead"

    [void](Stop-TrackedOwner $owner2Rec 5000)

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite 2 completed successfully."

    # ========================================================================
    # Suite 3: Write Open Denied (Win32 Error 32), Read Allowed, Rename Denied
    # ========================================================================
    Write-Diagnostic "Starting Suite 3: Locking & Sharing Violation Verification..."

    $setLease3 = Safe-AcquireSetLease -payloadRoot $canonicalPayloadRoot -map $map -Tag "Suite3"
    $setLease3Rec = $script:TrackedSetLeases[-1]

    $ep3 = Safe-CreateEndpoint -Tag "Ep3"
    $ep3Rec = $script:TrackedEndpoints[-1]
    $args3 = Build-RealWorkerArgs -pharPath $pharPath2 -phpExtDir $extDir2 -endpointName $ep3.EndpointName

    $owner3 = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $args3 -cwd $labDir -env $sparseEnv -tag "WorkerSuite3"
    $owner3Rec = $script:TrackedOwners[-1]
    $obsPeer3 = $ep3.ConnectOwnedWorker($owner3, 5000)
    Assert-Condition ($obsPeer3 -ne $null) "Suite 3: Worker connected"

    # Ensure worker root is live during probes
    Assert-Condition ($owner3.Observe().RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Live) "Suite 3: Worker root is Live"

    # Probe 1: Write open denied on php.exe (Win32 error 32 / ERROR_SHARING_VIOLATION = 0x80070020)
    $probeFs1 = $null
    $writeExeDenied = $false
    try {
        $probeFs1 = [System.IO.File]::Open($canonicalPhp, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    } catch {
        $baseEx = $_.Exception.GetBaseException()
        if ($baseEx -is [System.IO.IOException]) {
            if ($baseEx.HResult -eq [int]0x80070020 -or $baseEx.Message.Contains("another process") -or $baseEx.Message.Contains("sharing")) {
                $writeExeDenied = $true
            }
        }
    } finally {
        if ($probeFs1 -ne $null) {
            $probeFs1.Dispose()
        }
    }
    Assert-Condition $writeExeDenied "Suite 3: Write open probe denied with Win32 Error 32 on php.exe"

    # Probe 2: Write open denied on backend.phar
    $probeFs2 = $null
    $writePharDenied = $false
    try {
        $probeFs2 = [System.IO.File]::Open($pharPath2, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
    } catch {
        $baseEx = $_.Exception.GetBaseException()
        if ($baseEx -is [System.IO.IOException]) {
            if ($baseEx.HResult -eq [int]0x80070020 -or $baseEx.Message.Contains("another process") -or $baseEx.Message.Contains("sharing")) {
                $writePharDenied = $true
            }
        }
    } finally {
        if ($probeFs2 -ne $null) {
            $probeFs2.Dispose()
        }
    }
    Assert-Condition $writePharDenied "Suite 3: Write open probe denied with Win32 Error 32 on backend.phar"

    # Probe 3: Read open allowed with FileShare.Read
    $readFs3 = $null
    $readAllowed = $false
    try {
        $readFs3 = [System.IO.File]::Open($canonicalPhp, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read)
        if ($readFs3.Length -gt 0) {
            $readAllowed = $true
        }
    } catch {
        $readAllowed = $false
    } finally {
        if ($readFs3 -ne $null) {
            $readFs3.Dispose()
        }
    }
    Assert-Condition $readAllowed "Suite 3: Read open probe allowed with FileShare.Read on php.exe"

    # Probe 4: Parent directory rename denied (Win32 Error 32)
    $renameDenied = $false
    $parentDirToRename = [System.IO.Path]::Combine($canonicalPayloadRoot, "resources\portable\php\ext")
    $renameTarget = [System.IO.Path]::Combine($canonicalPayloadRoot, "resources\portable\php\ext-renamed")
    try {
        [System.IO.Directory]::Move($parentDirToRename, $renameTarget)
    } catch {
        $baseEx = $_.Exception.GetBaseException()
        if ($baseEx -is [System.IO.IOException]) {
            if ($baseEx.HResult -eq [int]0x80070020 -or $baseEx.Message.Contains("another process") -or $baseEx.Message.Contains("sharing") -or $baseEx.Message.Contains("access")) {
                $renameDenied = $true
            }
        }
    }
    Assert-Condition $renameDenied "Suite 3: Parent directory rename denied with Win32 Error 32"

    # Bounded shutdown
    [void](Close-TrackedEndpoint $ep3Rec 5000)
    Assert-Condition (Wait-ProcessDead -owner $owner3 -timeoutMs 5000) "Suite 3: Worker exited naturally"
    [void](Stop-TrackedOwner $owner3Rec 5000)
    [void](Close-TrackedSetLease $setLease3Rec)

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite 3 completed successfully."

    # ========================================================================
    # Suite 4: Session Policy & Canonical Negatives
    # ========================================================================
    Write-Diagnostic "Starting Suite 4: Session Policy & Canonical Negatives..."

    $negativeCases = @($fixtureDoc.cases | Where-Object { $_.is_negative -eq $true })
    Assert-Condition ($negativeCases.Count -eq 5) "Suite 4: All five signed-plan session rejection cases are present"

    foreach ($negCase in $negativeCases) {
        $negId = [string]$negCase.id
        Write-Diagnostic "Suite 4: Testing negative case '$negId'..."

        $setLease4 = Safe-AcquireSetLease -payloadRoot $canonicalPayloadRoot -map $map -Tag "Suite4-$negId"
        $setLease4Rec = $script:TrackedSetLeases[-1]

        $ep4 = Safe-CreateEndpoint -Tag "Ep4-$negId"
        $ep4Rec = $script:TrackedEndpoints[-1]
        $args4 = Build-RealWorkerArgs -pharPath $pharPath2 -phpExtDir $extDir2 -endpointName $ep4.EndpointName

        $owner4 = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $args4 -cwd $labDir -env $sparseEnv -tag "Worker4-$negId"
        $owner4Rec = $script:TrackedOwners[-1]
        $obsPeer4 = $ep4.ConnectOwnedWorker($owner4, 5000)
        Assert-Condition ($obsPeer4 -ne $null) "Suite 4 ($negId): Worker connected"

        $reqJson4 = [string]$negCase.request_wire_json
        $reqBytes4 = [System.Text.Encoding]::UTF8.GetBytes($reqJson4)
        $ep4.WriteFrame($reqBytes4, 5000)

        # Worker MUST reject and reply with ok=false, can_apply=false, plan_json empty
        $frame4 = $ep4.ReadFrame(5000)
        $replyBytes4 = $frame4.CopyPayload()
        $reply4 = ConvertFrom-Json -InputObject ([System.Text.Encoding]::UTF8.GetString($replyBytes4))

        Assert-Condition ($reply4.ok -eq $false) "Suite 4 ($negId): Worker rejected invalid request (ok=false)"
        Assert-Condition ($reply4.can_apply -eq $false) "Suite 4 ($negId): can_apply is false"
        Assert-Condition (([string]$reply4.error).Contains([string]$negCase.expected_error_token)) "Suite 4 ($negId): Rejected for the expected reason"
        Assert-Condition ([System.Text.Encoding]::UTF8.GetByteCount([string]$reply4.error) -le 500) "Suite 4 ($negId): Error is bounded in bytes"
        Assert-Condition ($reply4.PSObject.Properties['plan_json'] -eq $null -or [string]::IsNullOrEmpty([string]$reply4.plan_json)) `
            "Suite 4 ($negId): Zero plan leaked on rejection"

        [void](Close-TrackedEndpoint $ep4Rec 5000)
        Assert-Condition (Wait-ProcessDead -owner $owner4 -timeoutMs 5000) "Suite 4 ($negId): Worker exited naturally"
        [void](Stop-TrackedOwner $owner4Rec 5000)
        [void](Close-TrackedSetLease $setLease4Rec)
    }

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite 4 completed successfully."

    # ========================================================================
    # Suite 5: Frame Size Limit & Oversized Frame (>24 MiB) Enforcement
    # ========================================================================
    Write-Diagnostic "Starting Suite 5: Oversized Frame (>24 MiB) Enforcement..."

    $setLease5 = Safe-AcquireSetLease -payloadRoot $canonicalPayloadRoot -map $map -Tag "Suite5"
    $setLease5Rec = $script:TrackedSetLeases[-1]

    $ep5 = Safe-CreateEndpoint -Tag "Ep5"
    $ep5Rec = $script:TrackedEndpoints[-1]
    $args5 = Build-RealWorkerArgs -pharPath $pharPath2 -phpExtDir $extDir2 -endpointName $ep5.EndpointName

    $owner5 = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $args5 -cwd $labDir -env $sparseEnv -tag "WorkerSuite5"
    $owner5Rec = $script:TrackedOwners[-1]
    $obsPeer5 = $ep5.ConnectOwnedWorker($owner5, 5000)
    Assert-Condition ($obsPeer5 -ne $null) "Suite 5: Worker connected"

    # Construct oversized payload (> 24 MiB = 25,165,824 bytes -> 25,165,825 bytes)
    # OwnedWorkerPipeEndpoint writes frame header with length 25,165,825 bytes.
    # PHP transport early-rejects frame length > 24 MiB and closes named pipe.
    # Host call throws IOException / peer close failure and faults transport.
    $oversizedBytes = [byte[]]::new(25165825)
    $hostWriteFailed = $false
    try {
        $ep5.WriteFrame($oversizedBytes, 5000)
    } catch {
        $hostWriteFailed = $true
        Write-Diagnostic "Suite 5: Host WriteFrame failed as expected after worker closed pipe: $($_.Exception.Message)"
    }
    Assert-Condition $hostWriteFailed "Suite 5: Host WriteFrame failed due to worker peer early-rejecting oversized frame (>24 MiB)"

    # Worker detects oversized length, faults transport, and exits
    Assert-Condition (Wait-ProcessDead -owner $owner5 -timeoutMs 5000) "Suite 5: Worker exited after oversized frame fault"

    $obs5 = $owner5.Observe()
    Assert-Condition ($obs5.RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Dead) "Suite 5: Worker root is Dead"
    Assert-Condition ($obs5.JobCountKnown -and $obs5.ActiveProcesses -eq 0) "Suite 5: Active process count in Job is 0"
    Assert-Condition ($obs5.IsQuiescent -eq $true) "Suite 5: Worker is quiescent"

    [void](Close-TrackedEndpoint $ep5Rec 5000)
    [void](Stop-TrackedOwner $owner5Rec 5000)
    [void](Close-TrackedSetLease $setLease5Rec)

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite 5 completed successfully."

    # ========================================================================
    # Suite 6: External Blocking Governance (Timeout & Owned Job Termination)
    # ========================================================================
    Write-Diagnostic "Starting Suite 6: External Blocking Governance..."

    $setLease6 = Safe-AcquireSetLease -payloadRoot $canonicalPayloadRoot -map $map -Tag "Suite6"
    $setLease6Rec = $script:TrackedSetLeases[-1]

    $ep6 = Safe-CreateEndpoint -Tag "Ep6"
    $ep6Rec = $script:TrackedEndpoints[-1]
    $args6 = Build-RealWorkerArgs -pharPath $pharPath2 -phpExtDir $extDir2 -endpointName $ep6.EndpointName

    $owner6 = Safe-StartOwnedProcess -exePath $canonicalPhp -ArgumentVector $args6 -cwd $labDir -env $sparseEnv -tag "WorkerSuite6"
    $owner6Rec = $script:TrackedOwners[-1]
    $obsPeer6 = $ep6.ConnectOwnedWorker($owner6, 5000)
    Assert-Condition ($obsPeer6 -ne $null) "Suite 6: Worker connected"

    # Host stalls: do NOT write any request frame; wait for ReadFrame timeout
    $sw6 = [System.Diagnostics.Stopwatch]::StartNew()
    $timeoutTriggered = $false
    try {
        [void]$ep6.ReadFrame(1000)
    } catch {
        $baseEx = $_.Exception.GetBaseException()
        if ($baseEx -is [System.TimeoutException]) {
            $timeoutTriggered = $true
        }
    }
    Assert-Condition $timeoutTriggered "Suite 6: Host ReadFrame timed out as expected against stalling channel"
    Assert-Condition ($sw6.ElapsedMilliseconds -ge 900) "Suite 6: ReadFrame respected 1000ms deadline budget ($($sw6.ElapsedMilliseconds) ms)"

    # Host owns deadline and stops worker job
    $stopOk6 = Stop-TrackedOwner $owner6Rec 5000
    Assert-Condition $stopOk6 "Suite 6: Host successfully stopped stalling worker process"

    $obs6 = $owner6.Observe()
    Assert-Condition ($obs6.RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Dead) "Suite 6: Worker root state is Dead"
    Assert-Condition ($obs6.JobCountKnown -and $obs6.ActiveProcesses -eq 0) "Suite 6: Job active process count is 0"
    Assert-Condition ($obs6.IsQuiescent -eq $true) "Suite 6: Child root is quiescent"

    # Close endpoint and lease
    [void](Close-TrackedEndpoint $ep6Rec 5000)
    [void](Close-TrackedSetLease $setLease6Rec)
    Assert-Condition ($setLease6.IsClosed -eq $true) "Suite 6: Set lease is closed after worker death"

    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "PASS: Suite 6 completed successfully."

    $script:AllSuitesPassed = $true
    Write-Diagnostic "ALL 6 SUITES PASSED! Total assertions verified: $($script:AssertionCount), Worker launches: $($script:WorkerLaunchCount)"

} catch {
    $script:PrimaryError = $_.Exception
    [Console]::Error.WriteLine("CRITICAL ERROR: Verification suite failed: $($_.Exception.Message)")
    [Console]::Error.WriteLine($_.ScriptStackTrace)
} finally {
    Write-Diagnostic "Entering final teardown and cleanup phase..."

    # 1. Stop all tracked owned child processes first
    Stop-AllTrackedOwners

    # 2. Check that ALL tracked owners are closed and proven Dead and quiescent
    $allOwnersProvenDeadAndQuiescent = $true
    foreach ($ownerRec in $script:TrackedOwners) {
        if ($ownerRec -eq $null -or $ownerRec.Owner -eq $null -or -not $ownerRec.Closed -or -not $ownerRec.Owner.IsClosed) {
            Write-Diagnostic "Teardown safety hold: owner ($($ownerRec.Tag)) is not closed."
            $allOwnersProvenDeadAndQuiescent = $false
            break
        }
        $obs = $ownerRec.Owner.Observe()
        if ($obs.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead -or
            -not $obs.JobCountKnown -or
            $obs.ActiveProcesses -ne 0 -or
            -not $obs.IsQuiescent) {
            Write-Diagnostic "Teardown safety hold: owner ($($ownerRec.Tag)) is NOT confirmed Dead and quiescent. Image set leases MUST NOT be unlocked!"
            $allOwnersProvenDeadAndQuiescent = $false
            break
        }
    }

    # 3. Only close image set leases IF all owners are proven Dead + quiescent
    if ($allOwnersProvenDeadAndQuiescent) {
        Close-AllTrackedSetLeases
    } else {
        Write-Diagnostic "Teardown safety hold active: retaining all image set leases locked because owned child processes were not proven dead/quiescent."
    }

    # 4. Close all tracked endpoints
    Close-AllTrackedEndpoints

    # 5. Check if victim tree was untouched
    $victimUntouched = $false
    try {
        if ($script:VictimSnapshot -ne $null -and $victimDir -ne $null -and [System.IO.Directory]::Exists($victimDir)) {
            Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
            $victimUntouched = $true
        }
    } catch {
        Write-Diagnostic "Victim witness tree verification failed: $($_.Exception.Message)"
        if ($script:PrimaryError -eq $null) { $script:PrimaryError = $_.Exception }
    }

    # 6. Safety Gate for Deletion:
    # Retain lab if unproven owner, cleanup failures, or victim alteration.
    $canDeleteLab = $allOwnersProvenDeadAndQuiescent -and
                    ($script:OwnerStopErrors.Count -eq 0) -and
                    ($script:LeaseCloseErrors.Count -eq 0) -and
                    ($script:EndpointCloseErrors.Count -eq 0) -and
                    $victimUntouched

    if ($canDeleteLab) {
        try {
            if ($victimDir -ne $null -and [System.IO.Directory]::Exists($victimDir)) {
                Invoke-DirectoryDeletion -Path $victimDir -ExpectedMarkerName ".is-victim-marker" -ExpectedNonce $nonce
            }
        } catch {
            Write-Diagnostic "Warning: Victim cleanup error: $($_.Exception.Message)"
        }
        try {
            if ($labDir -ne $null -and [System.IO.Directory]::Exists($labDir)) {
                Invoke-DirectoryDeletion -Path $labDir -ExpectedMarkerName ".is-lab-marker" -ExpectedNonce $nonce
            }
        } catch {
            Write-Diagnostic "Warning: Lab cleanup error: $($_.Exception.Message)"
        }
    } else {
        Write-Diagnostic "SAFETY GATE DENIAL: Unproven owner, cleanup failure, or victim modification. LAB RETAINED nonzero."
        if ($script:PrimaryError -ne $null) {
            Write-Diagnostic "Primary test error recorded prior to safety gate denial: $($script:PrimaryError.Message)"
        }
        exit 2
    }

    if ($script:OwnerStopErrors.Count -gt 0) {
        [Console]::Error.WriteLine("Owner stop cleanup errors encountered: $($script:OwnerStopErrors -join '; ')")
    }
    if ($script:EndpointCloseErrors.Count -gt 0) {
        [Console]::Error.WriteLine("Endpoint close cleanup errors encountered: $($script:EndpointCloseErrors -join '; ')")
    }
    if ($script:LeaseCloseErrors.Count -gt 0) {
        [Console]::Error.WriteLine("Lease close cleanup errors encountered: $($script:LeaseCloseErrors -join '; ')")
    }

    if ($script:PrimaryError -ne $null) {
        Write-Diagnostic "Exiting with error due to prior test failure: $($script:PrimaryError.Message)"
        exit 1
    }

    if (-not $script:AllSuitesPassed) {
        Write-Diagnostic "Not all suites passed; exiting with failure."
        exit 1
    }

    Write-Diagnostic "Verification completed successfully. Clean exit 0."
    exit 0
}
