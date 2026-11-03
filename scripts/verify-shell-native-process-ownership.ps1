# scripts/verify-shell-native-process-ownership.ps1
# Win32 Native Process Ownership and Job Object Primitive Verifier
# Verifies kernel handle and Job Object containment, lifecycle, and teardown proof.

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

# --- Section 1: Strict Execution Environment Guards (Fail early, no SKIP) ---
if ($env:GITHUB_ACTIONS -ne 'true') { [Console]::Error.WriteLine("GUARD: GITHUB_ACTIONS=true required."); exit 1 }
if ($env:RUNNER_OS -ne 'Windows') { [Console]::Error.WriteLine("GUARD: RUNNER_OS must be 'Windows'."); exit 1 }
if ([System.Environment]::OSVersion.Platform -ne [System.PlatformID]::Win32NT) { [Console]::Error.WriteLine("GUARD: Win32NT required."); exit 1 }
if ($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { [Console]::Error.WriteLine("GUARD: PS 5.1 Desktop required."); exit 1 }
if (-not [System.Environment]::Is64BitProcess -or -not [System.Environment]::Is64BitOperatingSystem) { [Console]::Error.WriteLine("GUARD: 64-bit required."); exit 1 }

# --- Section 2: RUNNER_TEMP Validation and Ancestor Traversal ---
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

# --- Section 3: Safe Traversal, Nonce-Marked Lab, Immutable Victim, and Shared Gate ---
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
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snpo-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snpo-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
[void][System.IO.Directory]::CreateDirectory($labDir)
[void][System.IO.Directory]::CreateDirectory($labTempDir)
[void][System.IO.Directory]::CreateDirectory($victimDir)

$labMarkerPath = [System.IO.Path]::Combine($labDir, ".snpo-lab-marker")
[System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".snpo-victim-marker")
[System.IO.File]::WriteAllText($victimMarkerPath, $nonce, [System.Text.Encoding]::UTF8)

# Populate victim witness tree (immutable outside lab)
$victimWitness1 = [System.IO.Path]::Combine($victimDir, "victim-witness-1.dat")
[System.IO.File]::WriteAllBytes($victimWitness1, [System.Text.Encoding]::UTF8.GetBytes("VICTIM_BYTE_1_$nonce"))
$victimWitness2 = [System.IO.Path]::Combine($victimDir, "victim-witness-2.txt")
[System.IO.File]::WriteAllText($victimWitness2, "VICTIM_TEXT_2_$nonce", [System.Text.Encoding]::UTF8)
$victimSubDir = [System.IO.Path]::Combine($victimDir, "sub-victim")
[void][System.IO.Directory]::CreateDirectory($victimSubDir)
$victimSubFile = [System.IO.Path]::Combine($victimSubDir, "sub-witness.txt")
[System.IO.File]::WriteAllText($victimSubFile, "VICTIM_SUB_$nonce", [System.Text.Encoding]::UTF8)

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

# --- Section 4: Global Registries and State Tracking ---
$script:TrackedOwners = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedLeases = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedStreams = [System.Collections.Generic.List[System.IO.Stream]]::new()
$script:OwnerStopErrors = [System.Collections.Generic.List[string]]::new()
$script:StreamCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:LeaseCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:CompilationUncertain = $false
$script:PrimaryError = $null
$script:AllSuitesPassed = $false
$script:CleanupObservationHook = $null
$script:CleanupHookHits = 0

function Register-TrackedStream {
    param([System.IO.Stream]$s)
    if ($s -ne $null) { $script:TrackedStreams.Add($s) }
    return $s
}

function Close-AllTrackedStreams {
    foreach ($s in $script:TrackedStreams) {
        if ($s -ne $null) {
            try {
                $s.Dispose()
            } catch {
                $msg = "Stream disposal failed: $($_.Exception.Message)"
                Write-Diagnostic $msg
                $script:StreamCloseErrors.Add($msg)
            }
        }
    }
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
        return $owner
    } catch {
        # Bounded unwrap of InnerException to check for OwnedChildProcessStartException
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

function Stop-TrackedOwner {
    param($ownerRec, [int]$timeoutMs = 5000)
    if ($ownerRec -eq $null -or $ownerRec.Owner -eq $null) {
        Write-Diagnostic "Stop-TrackedOwner: missing or null owner record; uncertain."
        return $false
    }
    if ($ownerRec.Closed) {
        if (-not $ownerRec.Owner.IsClosed) {
            Write-Diagnostic "Stop-TrackedOwner: record marked closed but Owner.IsClosed is false ($($ownerRec.Tag))."
            return $false
        }
        return $true
    }
    $err = 0
    $ok = $ownerRec.Owner.TryStopAndClose($timeoutMs, [ref]$err)
    if ($ok -and $ownerRec.Owner.IsClosed) {
        $ownerRec.Closed = $true
        return $true
    }
    Write-Diagnostic "TryStopAndClose failed for owner ($($ownerRec.Tag)): error $err, IsClosed: $($ownerRec.Owner.IsClosed)"
    return $false
}

function Safe-AcquireLease {
    param([string]$path, [long]$size, [string]$sha256)
    try {
        $lease = [PosDesktop.Updates.Native.SourceFileLease]::Acquire($path, $size, $sha256)
        if ($lease -eq $null) {
            throw "Acquire returned null lease for path: $path"
        }
        $rec = [PSObject]@{ Lease = $lease; Path = $path; Closed = $false }
        $script:TrackedLeases.Add($rec)
        return $lease
    } catch {
        $curr = $_.Exception
        $retained = $null
        $depth = 0
        while ($curr -ne $null -and $depth -lt 5) {
            if ($curr -is [PosDesktop.Updates.Native.SourceFileLeaseAcquisitionException]) {
                $retained = $curr.RetainedLease
                break
            }
            $curr = $curr.InnerException
            $depth++
        }
        if ($retained -ne $null) {
            $rec = [PSObject]@{ Lease = $retained; Path = $path; Closed = $false }
            $script:TrackedLeases.Add($rec)
        }
        throw
    }
}

function Close-TrackedLease {
    param($leaseRec)
    if ($leaseRec -eq $null -or $leaseRec.Lease -eq $null) {
        Write-Diagnostic "Close-TrackedLease: missing or null lease record; uncertain."
        return $false
    }
    if ($leaseRec.Closed) {
        if (-not $leaseRec.Lease.IsClosed) {
            Write-Diagnostic "Close-TrackedLease: record marked closed but Lease.IsClosed is false ($($leaseRec.Path))."
            return $false
        }
        return $true
    }
    $cErr = 0
    $ok = $leaseRec.Lease.TryClose([ref]$cErr)
    if ($ok -and $leaseRec.Lease.IsClosed) {
        $leaseRec.Closed = $true
        return $true
    }
    Write-Diagnostic "TryClose failed for lease ($($leaseRec.Path)): error $cErr, IsClosed: $($leaseRec.Lease.IsClosed)"
    return $false
}

function Invoke-SharedCleanupSafetyGate {
    param([array]$TargetSpecs = $null, [scriptblock]$RestorationCallback = $null, [switch]$DryRun)

    if ($script:CompilationUncertain) {
        Write-Diagnostic "Teardown uncertainty: compilation state marked uncertain."
        return 'NEEDSATTENTION'
    }
    if ($null -ne $script:PrimaryError) {
        Write-Diagnostic "Teardown uncertainty: primary test error exists ($($script:PrimaryError.Message))."
        return 'NEEDSATTENTION'
    }
    if ($script:OwnerStopErrors.Count -gt 0) {
        Write-Diagnostic "Teardown uncertainty: owner stop errors recorded ($($script:OwnerStopErrors.Count) errors)."
        return 'NEEDSATTENTION'
    }
    if ($script:StreamCloseErrors.Count -gt 0) {
        Write-Diagnostic "Teardown uncertainty: stream closure errors recorded ($($script:StreamCloseErrors.Count) errors)."
        return 'NEEDSATTENTION'
    }
    if ($script:LeaseCloseErrors.Count -gt 0) {
        Write-Diagnostic "Teardown uncertainty: lease closure errors recorded ($($script:LeaseCloseErrors.Count) errors)."
        return 'NEEDSATTENTION'
    }

    foreach ($ownerRec in $script:TrackedOwners) {
        if ($ownerRec -eq $null -or $ownerRec.Owner -eq $null) {
            Write-Diagnostic "Teardown uncertainty: tracked owner record or Owner instance is null."
            return 'NEEDSATTENTION'
        }

        # Test harness observation hook check
        if ($null -ne $script:CleanupObservationHook -and $script:CleanupObservationHook.TargetOwner -eq $ownerRec.Owner) {
            $script:CleanupHookHits++
            Write-Diagnostic "Teardown uncertainty: test harness hook active for $($ownerRec.Tag)."
            return 'NEEDSATTENTION'
        }

        if (-not $ownerRec.Closed -or -not $ownerRec.Owner.IsClosed) {
            $obs = $ownerRec.Owner.Observe()
            Write-Diagnostic "Teardown uncertainty: owner ($($ownerRec.Tag)) not closed (IsClosed: $($ownerRec.Owner.IsClosed), RootState: $($obs.RootState), ActiveProcesses: $($obs.ActiveProcesses))."
            return 'NEEDSATTENTION'
        }

        $obsCheck = $ownerRec.Owner.Observe()
        if (-not $obsCheck.IsQuiescent) {
            Write-Diagnostic "Teardown uncertainty: owner ($($ownerRec.Tag)) is not quiescent (RootState: $($obsCheck.RootState), ActiveProcesses: $($obsCheck.ActiveProcesses))."
            return 'NEEDSATTENTION'
        }
    }

    foreach ($leaseRec in $script:TrackedLeases) {
        if ($leaseRec -eq $null -or $leaseRec.Lease -eq $null -or -not $leaseRec.Closed -or -not $leaseRec.Lease.IsClosed) {
            Write-Diagnostic "Teardown uncertainty: lease ($($leaseRec.Path)) is not closed."
            return 'NEEDSATTENTION'
        }
    }

    if ($null -ne $script:VictimSnapshot -and [System.IO.Directory]::Exists($victimDir)) {
        try {
            Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
        } catch {
            Write-Diagnostic "Teardown uncertainty: victim tree integrity check failed: $($_.Exception.Message)"
            return 'NEEDSATTENTION'
        }
    } else {
        Write-Diagnostic "Teardown uncertainty: victim snapshot or directory missing."
        return 'NEEDSATTENTION'
    }

    if (-not $DryRun) {
        if ($null -ne $TargetSpecs) {
            foreach ($spec in $TargetSpecs) {
                if ([System.IO.Directory]::Exists($spec.Path)) {
                    Invoke-DirectoryDeletion -Path $spec.Path -ExpectedMarkerName $spec.MarkerName -ExpectedNonce $spec.Nonce
                }
            }
        }
        if ($null -ne $RestorationCallback) { & $RestorationCallback }
    }

    return 'CLEANED'
}

function Wait-FileReady {
    param([string]$path, [int]$timeoutMs = 15000, [int]$intervalMs = 50)
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.ElapsedMilliseconds -lt $timeoutMs) {
        if ([System.IO.File]::Exists($path)) {
            [System.Threading.Thread]::Sleep(30)
            try {
                $content = [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8)
                if ($content.StartsWith("READY")) {
                    return $content
                }
            } catch {
                # Transient file sharing retry
            }
        }
        [System.Threading.Thread]::Sleep($intervalMs)
    }
    throw "Timed out waiting for ready handshake file after $($timeoutMs)ms: $path"
}

function Get-ExclusiveFileOpenProbe {
    param(
        [string]$Path,
        [string]$Tag = "Sentinel"
    )

    $openedStream = $null
    $caughtException = $null

    try {
        $openedStream = [System.IO.File]::Open(
            $Path,
            [System.IO.FileMode]::Open,
            [System.IO.FileAccess]::ReadWrite,
            [System.IO.FileShare]::None
        )
    } catch {
        $caughtException = $_.Exception
    }

    if ($openedStream -ne $null) {
        # Unexpected open success: file was not locked!
        [void](Register-TrackedStream $openedStream)
        try {
            $openedStream.Dispose()
        } catch {
            $msg = "Probe stream disposal failed for '$Path': $($_.Exception.Message)"
            Write-Diagnostic $msg
            $script:StreamCloseErrors.Add($msg)
        }
        Write-Diagnostic "Probe ($Tag): file '$Path' was unexpectedly unlocked (opened successfully, not Win32 error 32)."
        return [pscustomobject]@{
            OpenSucceeded      = $true
            IsSharingViolation = $false
            NativeCodeKnown    = $true
            NativeCode         = [int]0
        }
    }

    if ($caughtException -eq $null) {
        Write-Diagnostic "Probe ($Tag): File::Open returned null without exception on '$Path'."
        return [pscustomobject]@{
            OpenSucceeded      = $false
            IsSharingViolation = $false
            NativeCodeKnown    = $false
            NativeCode         = [int]-1
        }
    }

    $curr = $caughtException
    $actualIoEx = $null
    $depth = 0
    while ($curr -ne $null -and $depth -le 8) {
        if ($curr -is [System.IO.IOException]) {
            $actualIoEx = $curr
            break
        }
        $curr = $curr.InnerException
        $depth++
    }

    if ($actualIoEx -ne $null) {
        # Signed HResult low 16 via -band 65535 (never cast negative HResult to uint32)
        $hresult = $actualIoEx.HResult
        $nativeCode = [int]($hresult -band 65535)
        $isSharing = ($nativeCode -eq 32)

        if (-not $isSharing) {
            Write-Diagnostic "Probe ($Tag): File::Open failed with non-32 IOException. Wrapper: $($caughtException.GetType().FullName), Inner: $($actualIoEx.GetType().FullName), NativeCode: $nativeCode (HResult: $hresult), Message: $($actualIoEx.Message)"
        }

        return [pscustomobject]@{
            OpenSucceeded      = $false
            IsSharingViolation = [bool]$isSharing
            NativeCodeKnown    = $true
            NativeCode         = [int]$nativeCode
        }
    }

    $innermost = if ($caughtException.InnerException -ne $null) { $caughtException.InnerException } else { $caughtException }
    $innerCode = [int]($innermost.HResult -band 65535)
    Write-Diagnostic "Probe ($Tag): File::Open failed with non-IOException. Wrapper: $($caughtException.GetType().FullName), Inner: $($innermost.GetType().FullName), NativeCode: $innerCode (HResult: $($innermost.HResult)), Message: $($caughtException.Message)"

    return [pscustomobject]@{
        OpenSucceeded      = $false
        IsSharingViolation = $false
        NativeCodeKnown    = $false
        NativeCode         = [int]-1
    }
}

# --- Section 5: Synchronous Unified Single-Unit Compilation ---
$origTemp = $env:TEMP; $origTmp = $env:TMP; $origPoison = $env:POISON_SECRET_VAR
$codeProvider = $null
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $scriptDir ".."))
$ownedChildCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\OwnedChildProcess.cs")
$sourceFileLeaseCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\SourceFileLease.cs")

if (-not [System.IO.File]::Exists($ownedChildCsPath)) { throw "OwnedChildProcess.cs missing at: $ownedChildCsPath" }
if (-not [System.IO.File]::Exists($sourceFileLeaseCsPath)) { throw "SourceFileLease.cs missing at: $sourceFileLeaseCsPath" }

$ownedChildSource = [System.IO.File]::ReadAllText($ownedChildCsPath, [System.Text.Encoding]::UTF8)
$sourceFileLeaseSource = [System.IO.File]::ReadAllText($sourceFileLeaseCsPath, [System.Text.Encoding]::UTF8)

$fixtureProgramSource = @"
namespace PosDesktop.Updates.Native.Fixture
{
    using System;
    using System.Collections.Generic;
    using System.Diagnostics;
    using System.IO;
    using System.Reflection;
    using System.Text;
    using System.Threading;
    using PosDesktop.Updates.Native;

    public static class FixtureProgram
    {
        private static FileStream _leafSentinelStream;
        private static OwnedChildProcess _innerBrokerChild;

        public static int Main(string[] args)
        {
            if (args == null || args.Length == 0)
            {
                return 1;
            }

            string mode = args[0];

            if (string.Equals(mode, "echo", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 2) return 2;
                string readyPath = args[1];
                StringBuilder sb = new StringBuilder();
                sb.AppendLine("READY");
                sb.AppendLine("CWD:" + Environment.CurrentDirectory);
                sb.AppendLine("ARGCOUNT:" + args.Length);
                for (int i = 0; i < args.Length; i++)
                {
                    string b64 = Convert.ToBase64String(Encoding.UTF8.GetBytes(args[i]));
                    sb.AppendLine("ARG:" + b64);
                }

                string poison = Environment.GetEnvironmentVariable("POISON_SECRET_VAR");
                sb.AppendLine("ENV:POISON:" + (poison != null ? poison : "NONE"));

                string nonce = Environment.GetEnvironmentVariable("TEST_NONCE");
                sb.AppendLine("ENV:TEST_NONCE:" + (nonce != null ? nonce : "NONE"));

                string omega = Environment.GetEnvironmentVariable("TEST_OMEGA");
                sb.AppendLine("ENV:TEST_OMEGA:" + (omega != null ? omega : "NONE"));

                string customPath = Environment.GetEnvironmentVariable("CUSTOM_PATH");
                sb.AppendLine("ENV:CUSTOM_PATH:" + (customPath != null ? customPath : "NONE"));

                File.WriteAllText(readyPath, sb.ToString(), Encoding.UTF8);

                Thread.Sleep(300000);
                return 0;
            }

            if (string.Equals(mode, "descendant", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 4) return 2;
                string parentReadyPath = args[1];
                string leafReadyPath = args[2];
                string sentinelPath = args[3];

                string selfExe = Assembly.GetExecutingAssembly().Location;
                ProcessStartInfo psi = new ProcessStartInfo();
                psi.FileName = selfExe;
                psi.Arguments = "leaf \"" + leafReadyPath + "\" \"" + sentinelPath + "\"";
                psi.UseShellExecute = false;
                Process leafProc = Process.Start(psi);

                int waited = 0;
                bool leafOk = false;
                while (waited < 150)
                {
                    if (File.Exists(leafReadyPath))
                    {
                        try
                        {
                            string leafContent = File.ReadAllText(leafReadyPath);
                            if (leafContent.IndexOf("LEAF_READY", StringComparison.Ordinal) != -1)
                            {
                                leafOk = true;
                                break;
                            }
                        }
                        catch
                        {
                        }
                    }
                    Thread.Sleep(100);
                    waited++;
                }

                if (!leafOk)
                {
                    throw new InvalidOperationException("Descendant mode timed out waiting for leaf readiness at " + leafReadyPath);
                }

                File.WriteAllText(parentReadyPath, "READY\r\nPARENT_READY\r\n", Encoding.UTF8);

                Thread.Sleep(300000);
                GC.KeepAlive(leafProc);
                return 0;
            }

            if (string.Equals(mode, "leaf", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 3) return 2;
                string leafReadyPath = args[1];
                string sentinelPath = args[2];

                _leafSentinelStream = new FileStream(sentinelPath, FileMode.Create, FileAccess.ReadWrite, FileShare.None);
                File.WriteAllText(leafReadyPath, "READY\r\nLEAF_READY\r\n", Encoding.UTF8);

                Thread.Sleep(300000);
                GC.KeepAlive(_leafSentinelStream);
                return 0;
            }

            if (string.Equals(mode, "broker", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 4) return 2;
                string brokerReadyPath = args[1];
                string leafReadyPath = args[2];
                string sentinelPath = args[3];

                string selfExe = Assembly.GetExecutingAssembly().Location;
                string cwd = Path.GetDirectoryName(selfExe);
                string[] leafArgs = new string[] { "leaf", leafReadyPath, sentinelPath };

                Dictionary<string, string> env = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
                string sysRoot = Environment.GetEnvironmentVariable("SystemRoot");
                if (!string.IsNullOrEmpty(sysRoot)) env["SystemRoot"] = sysRoot;
                string windir = Environment.GetEnvironmentVariable("WINDIR");
                if (!string.IsNullOrEmpty(windir)) env["WINDIR"] = windir;
                string temp = Environment.GetEnvironmentVariable("TEMP");
                if (!string.IsNullOrEmpty(temp)) env["TEMP"] = temp;
                string tmp = Environment.GetEnvironmentVariable("TMP");
                if (!string.IsNullOrEmpty(tmp)) env["TMP"] = tmp;
                env["INNER_BROKER_NONCE"] = "1";

                _innerBrokerChild = OwnedChildProcess.Start(selfExe, leafArgs, cwd, env);

                int waited = 0;
                bool leafOk = false;
                while (waited < 150)
                {
                    if (File.Exists(leafReadyPath))
                    {
                        try
                        {
                            string leafContent = File.ReadAllText(leafReadyPath);
                            if (leafContent.IndexOf("LEAF_READY", StringComparison.Ordinal) != -1)
                            {
                                leafOk = true;
                                break;
                            }
                        }
                        catch
                        {
                        }
                    }
                    Thread.Sleep(100);
                    waited++;
                }

                if (!leafOk)
                {
                    throw new InvalidOperationException("Broker mode timed out waiting for leaf readiness at " + leafReadyPath);
                }

                File.WriteAllText(brokerReadyPath, "READY\r\nBROKER_READY\r\n", Encoding.UTF8);

                Thread.Sleep(300000);
                GC.KeepAlive(_innerBrokerChild);
                return 0;
            }

            if (string.Equals(mode, "sleep", StringComparison.OrdinalIgnoreCase))
            {
                int sleepMs = 30000;
                if (args.Length > 1)
                {
                    int p;
                    if (int.TryParse(args[1], out p) && p > 0)
                    {
                        sleepMs = p;
                    }
                }
                Thread.Sleep(sleepMs);
                return 0;
            }

            return 3;
        }
    }
}
"@

$omegaChar = [char]0x03A9
$fixtureExeName64 = "shell test owned child x64 $omegaChar.exe"
$fixtureExePath64 = [System.IO.Path]::Combine($labDir, $fixtureExeName64)
$fixtureExePath = $fixtureExePath64

$fixtureExeName86 = "shell test owned child x86 $omegaChar.exe"
$fixtureExePath86 = [System.IO.Path]::Combine($labDir, $fixtureExeName86)

try {
    # Synchronous unified compilations
    $script:CompilationUncertain = $true
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir

    # Unit 1: Add-Type parent in-memory types (SourceFileLease + OwnedChildProcess)
    $parentCombinedSource = $sourceFileLeaseSource + "`r`n" + $ownedChildSource
    Add-Type -TypeDefinition $parentCombinedSource
    Write-Diagnostic "Successfully compiled parent in-memory types via Add-Type."

    # Unit 2: Compile console fixture executables (x64 and x86) using CodeDom CSharpCodeProvider
    $fixtureCombinedSource = $ownedChildSource + "`r`n" + $fixtureProgramSource
    $codeProvider = [Microsoft.CSharp.CSharpCodeProvider]::new()

    # Compile x64 fixture executable
    $params64 = [System.CodeDom.Compiler.CompilerParameters]::new()
    $params64.GenerateExecutable = $true
    $params64.GenerateInMemory = $false
    $params64.OutputAssembly = $fixtureExePath64
    $params64.CompilerOptions = "/platform:x64"
    [void]$params64.ReferencedAssemblies.Add("System.dll")
    [void]$params64.ReferencedAssemblies.Add("System.Core.dll")

    $results64 = $codeProvider.CompileAssemblyFromSource($params64, @($fixtureCombinedSource))
    if ($results64.NativeCompilerReturnValue -ne 0 -or $results64.Errors.HasErrors) {
        $errMsgs = @()
        foreach ($err in $results64.Errors) { $errMsgs += $err.ToString() }
        throw "x64 Fixture compilation failed: $($errMsgs -join '; ')"
    }
    Write-Diagnostic "Successfully compiled x64 console fixture: $fixtureExePath64"

    # Compile x86 fixture executable
    $params86 = [System.CodeDom.Compiler.CompilerParameters]::new()
    $params86.GenerateExecutable = $true
    $params86.GenerateInMemory = $false
    $params86.OutputAssembly = $fixtureExePath86
    $params86.CompilerOptions = "/platform:x86"
    [void]$params86.ReferencedAssemblies.Add("System.dll")
    [void]$params86.ReferencedAssemblies.Add("System.Core.dll")

    $results86 = $codeProvider.CompileAssemblyFromSource($params86, @($fixtureCombinedSource))
    if ($results86.NativeCompilerReturnValue -ne 0 -or $results86.Errors.HasErrors) {
        $errMsgs = @()
        foreach ($err in $results86.Errors) { $errMsgs += $err.ToString() }
        throw "x86 Fixture compilation failed: $($errMsgs -join '; ')"
    }
    Write-Diagnostic "Successfully compiled x86 console fixture: $fixtureExePath86"

    $script:CompilationUncertain = $false
    $env:TEMP = $origTemp; $env:TMP = $origTmp

    if (-not [System.IO.File]::Exists($fixtureExePath64)) {
        throw "Fixture compilation failed: x64 binary missing at $fixtureExePath64"
    }
    if (-not [System.IO.File]::Exists($fixtureExePath86)) {
        throw "Fixture compilation failed: x86 binary missing at $fixtureExePath86"
    }

    $controlExeHash64 = Compute-Sha256OfFile $fixtureExePath64
    $controlExeHash = $controlExeHash64
    $fixtureExeLen64 = [System.IO.FileInfo]::new($fixtureExePath64).Length
    $fixtureExeLen = $fixtureExeLen64

    $controlExeHash86 = Compute-Sha256OfFile $fixtureExePath86
    $fixtureExeLen86 = [System.IO.FileInfo]::new($fixtureExePath86).Length
    Write-Diagnostic "Fixtures ready: x64 ($fixtureExeLen64 bytes, SHA: $controlExeHash64), x86 ($fixtureExeLen86 bytes, SHA: $controlExeHash86)."

    # Helper IO verification controls (positive + negative)
    Write-Diagnostic "Testing sentinel lock helper with actual IO positive and negative controls..."
    $controlFile = [System.IO.Path]::Combine($labDir, "helper-control-$nonce.dat")
    # Fresh lab file with parent FileStream held open with FileShare.None
    $parentControlFs = Register-TrackedStream ([System.IO.File]::Open($controlFile, [System.IO.FileMode]::Create, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None))

    # Positive control: locked file probe must fail with Win32 32, not opened
    $posCheck = Get-ExclusiveFileOpenProbe -Path $controlFile -Tag "Control-Locked"

    # Close parent stream independently without masking probe result; record closure failure into StreamCloseErrors
    try {
        $parentControlFs.Dispose()
    } catch {
        $msg = "Parent control stream disposal failed for '$controlFile': $($_.Exception.Message)"
        Write-Diagnostic $msg
        $script:StreamCloseErrors.Add($msg)
    }

    if ($posCheck.OpenSucceeded -or -not $posCheck.IsSharingViolation -or -not $posCheck.NativeCodeKnown -or $posCheck.NativeCode -ne 32) {
        throw "Sentinel lock helper positive control failed: expected locked file with Win32 32 (OpenSucceeded=$($posCheck.OpenSucceeded), IsSharingViolation=$($posCheck.IsSharingViolation), NativeCodeKnown=$($posCheck.NativeCodeKnown), NativeCode=$($posCheck.NativeCode))."
    }

    # Negative control 1: with parent stream closed, file is unlocked and open succeeds (OpenSucceeded=true, NativeCodeKnown=true, NativeCode=0, NOT sharing violation)
    $negCheckUnlocked = Get-ExclusiveFileOpenProbe -Path $controlFile -Tag "Control-Unlocked"
    if (-not $negCheckUnlocked.OpenSucceeded -or $negCheckUnlocked.IsSharingViolation -or -not $negCheckUnlocked.NativeCodeKnown -or $negCheckUnlocked.NativeCode -ne 0) {
        throw "Sentinel lock helper negative control 1 failed: expected unlocked open success (OpenSucceeded=$($negCheckUnlocked.OpenSucceeded), IsSharingViolation=$($negCheckUnlocked.IsSharingViolation), NativeCodeKnown=$($negCheckUnlocked.NativeCodeKnown), NativeCode=$($negCheckUnlocked.NativeCode))."
    }

    # Negative control 2: nonexistent lab file path fails with FileNotFound (OpenSucceeded=false, NativeCodeKnown=true, NativeCode=2, NOT 32)
    $controlNonexistent = [System.IO.Path]::Combine($labDir, "control-nonexistent-$nonce.dat")
    if ([System.IO.File]::Exists($controlNonexistent)) {
        throw "Sentinel lock helper negative control 2 failed: control nonexistent path '$controlNonexistent' unexpectedly exists; refusing to delete fixture."
    }
    $negCheckMissing = Get-ExclusiveFileOpenProbe -Path $controlNonexistent -Tag "Control-Nonexistent"
    if ($negCheckMissing.OpenSucceeded -or $negCheckMissing.IsSharingViolation -or -not $negCheckMissing.NativeCodeKnown -or $negCheckMissing.NativeCode -ne 2) {
        throw "Sentinel lock helper negative control 2 failed: expected missing file error 2 (OpenSucceeded=$($negCheckMissing.OpenSucceeded), IsSharingViolation=$($negCheckMissing.IsSharingViolation), NativeCodeKnown=$($negCheckMissing.NativeCodeKnown), NativeCode=$($negCheckMissing.NativeCode))."
    }
    Write-Diagnostic "Sentinel lock helper controls passed: positive (exact 32), unlocked (open succeeded, code 0), nonexistent (exact 2)."

    # --- Section 6: Test Suite 1 - Arg / Env / Private CWD / Lease Conflict / Clean Stop ---
    Write-Diagnostic "Starting Suite 1: Arg / Env / Private CWD / Exclusive Lease Conflict / Clean Stop..."

    $suite1Cwd = [System.IO.Path]::Combine($labDir, "suite1-cwd-$omegaChar")
    [void][System.IO.Directory]::CreateDirectory($suite1Cwd)
    $suite1Ready = [System.IO.Path]::Combine($suite1Cwd, "ready-$nonce.txt")

    $testArgs = @(
        "echo",
        $suite1Ready,
        "",
        "regular_arg",
        "argument with spaces",
        'arg with "embedded quotes"',
        'C:\test\path\with\backslashes\',
        'trailing\\',
        "Omega_$omegaChar",
        'shell&|><%$#;meta!'
    )

    # Ambient poison environment token MUST NOT leak to child
    $env:POISON_SECRET_VAR = "ambient_poison_leak_$nonce"

    $childEnv = [System.Collections.Generic.Dictionary[string, string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    if (-not [string]::IsNullOrEmpty($env:SystemRoot)) { $childEnv["SystemRoot"] = $env:SystemRoot }
    if (-not [string]::IsNullOrEmpty($env:WINDIR)) { $childEnv["WINDIR"] = $env:WINDIR }
    $childEnv["TEMP"] = $labTempDir
    $childEnv["TMP"] = $labTempDir
    $childEnv["TEST_NONCE"] = "nonce_$nonce"
    $childEnv["TEST_OMEGA"] = "val_$omegaChar"
    $childEnv["CUSTOM_PATH"] = "C:\special\bin"

    $owner1 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $testArgs -cwd $suite1Cwd -env $childEnv -tag "Suite1-Echo"

    if ($owner1.ProcessId -le 0) { throw "Suite 1: Child ProcessId is not positive: $($owner1.ProcessId)" }
    if ($owner1.CreationFileTimeTicks -le 0) { throw "Suite 1: CreationFileTimeTicks is not positive." }
    if ($owner1.IsClosed) { throw "Suite 1: Owner IsClosed is unexpectedly true after Start." }
    $expectedCanonExe = [System.IO.Path]::GetFullPath($fixtureExePath)
    if (-not [string]::Equals($owner1.ImagePath, $expectedCanonExe, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 1: ImagePath mismatch: expected '$expectedCanonExe', got '$($owner1.ImagePath)'"
    }

    # Bounded wait for child ready file handshake
    $readyContent1 = Wait-FileReady -path $suite1Ready -timeoutMs 15000
    Write-Diagnostic "Suite 1: Child signaled ready handshake."

    # Parse and verify echo payload
    $readyLines = $readyContent1.Split(@("`r`n", "`n"), [System.StringSplitOptions]::RemoveEmptyEntries)
    $cwdLine = ($readyLines | Where-Object { $_.StartsWith("CWD:") })
    if ($null -eq $cwdLine) { throw "Suite 1: Missing CWD line in ready file." }
    $actualCwd = $cwdLine.Substring(4)
    if (-not [string]::Equals($actualCwd, $suite1Cwd, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 1: CWD mismatch: expected '$suite1Cwd', got '$actualCwd'"
    }

    $argLines = @($readyLines | Where-Object { $_.StartsWith("ARG:") })
    if ($argLines.Count -ne $testArgs.Length) {
        throw "Suite 1: Argument count mismatch: expected $($testArgs.Length), got $($argLines.Count)"
    }
    for ($i = 0; $i -lt $testArgs.Length; $i++) {
        $b64 = $argLines[$i].Substring(4)
        $decoded = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String($b64))
        if (-not [string]::Equals($decoded, $testArgs[$i], [System.StringComparison]::Ordinal)) {
            throw "Suite 1: Argument [$i] mismatch: expected '$($testArgs[$i])', got '$decoded'"
        }
    }

    # Verify environment isolation: ambient poison MUST NOT leak, explicit values MUST match
    $poisonLine = ($readyLines | Where-Object { $_.StartsWith("ENV:POISON:") })
    if ($null -eq $poisonLine -or -not [string]::Equals($poisonLine.Substring(11), "NONE", [System.StringComparison]::Ordinal)) {
        throw "Suite 1: Security failure: ambient environment POISON_SECRET_VAR leaked to child! ($poisonLine)"
    }

    $nonceLine = ($readyLines | Where-Object { $_.StartsWith("ENV:TEST_NONCE:") })
    if ($null -eq $nonceLine -or -not [string]::Equals($nonceLine.Substring(15), "nonce_$nonce", [System.StringComparison]::Ordinal)) {
        throw "Suite 1: Environment TEST_NONCE mismatch."
    }

    $omegaLine = ($readyLines | Where-Object { $_.StartsWith("ENV:TEST_OMEGA:") })
    if ($null -eq $omegaLine -or -not [string]::Equals($omegaLine.Substring(15), "val_$omegaChar", [System.StringComparison]::Ordinal)) {
        throw "Suite 1: Environment TEST_OMEGA mismatch."
    }

    # Restore original poison variable
    $env:POISON_SECRET_VAR = $origPoison

    # Verify native observation: Root LIVE, JobCountKnown, ActiveProcesses >= 1
    $obs1 = $owner1.Observe()
    if ($obs1.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 1: Observation RootState is not Live: $($obs1.RootState)"
    }
    if (-not $obs1.JobCountKnown) { throw "Suite 1: JobCountKnown is false." }
    if ($obs1.ActiveProcesses -lt 1) { throw "Suite 1: ActiveProcesses is less than 1: $($obs1.ActiveProcesses)" }
    if ($obs1.IsQuiescent) { throw "Suite 1: IsQuiescent is unexpectedly true while running." }

    # Exclusive SourceFileLease on running fixture EXE MUST fail with specific Win32 sharing violation error 32
    $leaseFailedSharing32 = $false
    try {
        $dummyLease = Safe-AcquireLease -path $fixtureExePath -size $fixtureExeLen -sha256 $controlExeHash
    } catch {
        $curr = $_.Exception
        while ($curr -ne $null) {
            if ($curr -is [System.ComponentModel.Win32Exception] -and $curr.NativeErrorCode -eq 32) {
                $leaseFailedSharing32 = $true
                break
            }
            if (($curr.HResult -band 0xFFFF) -eq 32) {
                $leaseFailedSharing32 = $true
                break
            }
            $curr = $curr.InnerException
        }
    }
    if (-not $leaseFailedSharing32) {
        throw "Suite 1: SourceFileLease.Acquire on running EXE did not fail with expected Win32 sharing error 32."
    }
    Write-Diagnostic "Suite 1: SourceFileLease correctly failed sharing violation 32 while EXE mapped."

    # Verify live owner Dispose() throws InvalidOperationException before explicit stop
    $liveDisposeThrew = $false
    try {
        $owner1.Dispose()
    } catch [System.InvalidOperationException] {
        $liveDisposeThrew = $true
    }
    if (-not $liveDisposeThrew) {
        throw "Suite 1: Live owner Dispose() did not throw InvalidOperationException while child active."
    }
    # Child must still be live
    $obsLiveAfterDispose = $owner1.Observe()
    if ($obsLiveAfterDispose.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 1: Child RootState is not Live after illegal Dispose attempt: $($obsLiveAfterDispose.RootState)"
    }
    Write-Diagnostic "Suite 1: Proved live owner Dispose() throws InvalidOperationException and child remains live."

    # Zero-time stop probe: may return true if already settled/closed,
    # or false with error 258 (WAIT_TIMEOUT), but NEVER Win32 error 5 (access denied).
    $zeroStopErr1 = 0
    $zeroStopOk1 = $owner1.TryStopAndClose(0, [ref]$zeroStopErr1)
    if (-not $zeroStopOk1) {
        if ($zeroStopErr1 -ne 258) {
            throw "Suite 1: TryStopAndClose(0) returned unexpected error $zeroStopErr1 (expected 258 WAIT_TIMEOUT if not yet settled)."
        }
    }

    # Stop and close owner within full deadline
    $stopErr1 = 0
    $stopOk1 = $owner1.TryStopAndClose(5000, [ref]$stopErr1)
    if (-not $stopOk1 -or $stopErr1 -ne 0 -or -not $owner1.IsClosed) {
        throw "Suite 1: TryStopAndClose failed: ok=$stopOk1, error=$stopErr1, IsClosed=$($owner1.IsClosed)"
    }

    # Post-close observation
    $obsAfter1 = $owner1.Observe()
    if ($obsAfter1.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead) {
        throw "Suite 1: Post-close RootState is not Dead: $($obsAfter1.RootState)"
    }
    if (-not $obsAfter1.JobCountKnown -or $obsAfter1.ActiveProcesses -ne 0) {
        throw "Suite 1: Post-close ActiveProcesses is not 0: $($obsAfter1.ActiveProcesses)"
    }
    if (-not $obsAfter1.IsQuiescent) { throw "Suite 1: Post-close IsQuiescent is false." }
    if (-not $obsAfter1.IsClosed) { throw "Suite 1: Post-close observation IsClosed is false." }

    # Idempotent subsequent TryStopAndClose
    $idempotentErr = -1
    $idempotentOk = $owner1.TryStopAndClose(1000, [ref]$idempotentErr)
    if (-not $idempotentOk -or $idempotentErr -ne 0) {
        throw "Suite 1: Subsequent TryStopAndClose was not idempotent (ok=$idempotentOk, err=$idempotentErr)"
    }

    # Now that process is dead, exact same EXE is acquirable via SourceFileLease
    $postLease = Safe-AcquireLease -path $fixtureExePath -size $fixtureExeLen -sha256 $controlExeHash
    if ($postLease.IsClosed) { throw "Suite 1: Acquired lease IsClosed is true." }
    $cErrPost = 0
    if (-not $postLease.TryClose([ref]$cErrPost)) { throw "Suite 1: TryClose failed on post lease: $cErrPost" }

    # Exe SHA256 unchanged after process lifecycle
    $exeHashAfter1 = Compute-Sha256OfFile $fixtureExePath
    if (-not [string]::Equals($exeHashAfter1, $controlExeHash, [System.StringComparison]::Ordinal)) {
        throw "Suite 1: Exe SHA256 digest changed after child process exit!"
    }
    Write-Diagnostic "Suite 1: Passed successfully."

    # --- Section 7: Test Suite 2 - Root-versus-descendant and Job Isolation ---
    Write-Diagnostic "Starting Suite 2: Root-versus-descendant and Job Isolation..."

    $suite2Cwd = [System.IO.Path]::Combine($labDir, "suite2-cwd-$omegaChar")
    [void][System.IO.Directory]::CreateDirectory($suite2Cwd)
    $parentReady2 = [System.IO.Path]::Combine($suite2Cwd, "parent-ready-$nonce.txt")
    $leafReady2 = [System.IO.Path]::Combine($suite2Cwd, "leaf-ready-$nonce.txt")
    $sentinelFile2 = [System.IO.Path]::Combine($suite2Cwd, "sentinel2-$nonce.dat")

    $descendantArgs = @("descendant", $parentReady2, $leafReady2, $sentinelFile2)
    $owner2 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $descendantArgs -cwd $suite2Cwd -env $childEnv -tag "Suite2-RootDescendant"

    # Bounded wait for parent ready (guaranteed written ONLY AFTER leaf ready and sentinel locked)
    [void](Wait-FileReady -path $parentReady2 -timeoutMs 15000)
    if (-not [System.IO.File]::Exists($sentinelFile2)) {
        throw "Suite 2: Sentinel file does not exist at $sentinelFile2"
    }
    if (-not [System.IO.File]::Exists($leafReady2)) { throw "Suite 2: Leaf ready file missing." }
    $leafText2 = [System.IO.File]::ReadAllText($leafReady2)
    if ($leafText2.IndexOf("LEAF_READY", [System.StringComparison]::Ordinal) -eq -1) {
        throw "Suite 2: Leaf ready content does not contain LEAF_READY."
    }
    Write-Diagnostic "Suite 2: Parent and leaf grandchild are ready."

    # Observe job active processes >= 2 BEFORE first lock probe
    $obs2 = $owner2.Observe()
    if ($obs2.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 2: Observation RootState is not Live: $($obs2.RootState)"
    }
    if (-not $obs2.JobCountKnown) {
        throw "Suite 2: JobCountKnown is false."
    }
    if ($obs2.ActiveProcesses -lt 2) {
        throw "Suite 2: ActiveProcesses ($($obs2.ActiveProcesses)) is less than 2 (expected parent + grandchild leaf)."
    }
    Write-Diagnostic "Suite 2: Observed $($obs2.ActiveProcesses) active processes in Job Object."

    # Verify sentinel file is locked exclusively by grandchild leaf
    $sentinelProbe2 = Get-ExclusiveFileOpenProbe -Path $sentinelFile2 -Tag "Suite2-GrandchildLeafLive"
    if (-not $sentinelProbe2.IsSharingViolation) { throw "Suite 2: Sentinel file was unexpectedly unlocked or error was not Win32 32 while leaf alive!" }

    # Terminate root process ONLY
    $termErr2 = 0
    $termOk2 = $owner2.TryTerminateRootAndWait(5000, [ref]$termErr2)
    if (-not $termOk2 -or $termErr2 -ne 0) {
        throw "Suite 2: TryTerminateRootAndWait failed: ok=$termOk2, err=$termErr2"
    }

    # Root is DEAD BUT Job active processes >= 1 and IsQuiescent is false!
    $obsAfterRoot2 = $owner2.Observe()
    if ($obsAfterRoot2.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead) {
        throw "Suite 2: RootState was not proven Dead after TryTerminateRootAndWait: $($obsAfterRoot2.RootState)"
    }
    if ($obsAfterRoot2.ActiveProcesses -lt 1) {
        throw "Suite 2: ActiveProcesses ($($obsAfterRoot2.ActiveProcesses)) is 0; grandchild died prematurely!"
    }
    if ($obsAfterRoot2.IsQuiescent) {
        throw "Suite 2: IsQuiescent is true while grandchild descendant is still active in job!"
    }
    if ($owner2.IsClosed) {
        throw "Suite 2: Owner IsClosed is true while grandchild descendant is still active!"
    }

    # Sentinel file MUST still be locked by the living grandchild leaf!
    $sentinelStillProbe2 = Get-ExclusiveFileOpenProbe -Path $sentinelFile2 -Tag "Suite2-GrandchildLeafAfterRootDeath"
    if (-not $sentinelStillProbe2.IsSharingViolation) {
        throw "Suite 2: Sentinel file unlocked or error was not Win32 32 when root died; grandchild must still hold it!"
    }
    Write-Diagnostic "Suite 2: Proved root is DEAD while grandchild job member is still LIVE and holding lock."

    # Now stop and close the entire Job Object
    $stopErr2 = 0
    $stopOk2 = $owner2.TryStopAndClose(5000, [ref]$stopErr2)
    if (-not $stopOk2 -or $stopErr2 -ne 0 -or -not $owner2.IsClosed) {
        throw "Suite 2: TryStopAndClose failed on job with active grandchild: ok=$stopOk2, err=$stopErr2"
    }

    # Prove Job active processes is now 0 and sentinel is released
    $obsFinal2 = $owner2.Observe()
    if ($obsFinal2.ActiveProcesses -ne 0 -or -not $obsFinal2.IsQuiescent) {
        throw "Suite 2: Final observation is not quiescent or ActiveProcesses is not 0: $($obsFinal2.ActiveProcesses)"
    }

    # Sentinel file is NOW unlocked and acquirable
    $fsUnlocked2 = Register-TrackedStream ([System.IO.File]::Open($sentinelFile2, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None))
    $fsUnlocked2.Dispose()
    Write-Diagnostic "Suite 2: Passed successfully."

    # --- Section 8: Test Suite 3 - REAL host death KILL_ON_JOB_CLOSE proof ---
    Write-Diagnostic "Starting Suite 3: REAL host death KILL_ON_JOB_CLOSE proof..."

    $suite3Cwd = [System.IO.Path]::Combine($labDir, "suite3-cwd-$omegaChar")
    [void][System.IO.Directory]::CreateDirectory($suite3Cwd)
    $brokerReady3 = [System.IO.Path]::Combine($suite3Cwd, "broker-ready-$nonce.txt")
    $leafReady3 = [System.IO.Path]::Combine($suite3Cwd, "leaf-ready-$nonce.txt")
    $sentinelFile3 = [System.IO.Path]::Combine($suite3Cwd, "sentinel3-$nonce.dat")

    $brokerArgs = @("broker", $brokerReady3, $leafReady3, $sentinelFile3)
    $owner3 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $brokerArgs -cwd $suite3Cwd -env $childEnv -tag "Suite3-BrokerKillOnJobClose"

    # Wait for broker ready
    [void](Wait-FileReady -path $brokerReady3 -timeoutMs 15000)
    if (-not [System.IO.File]::Exists($leafReady3)) { throw "Suite 3: Leaf ready file missing." }
    $leafText3 = [System.IO.File]::ReadAllText($leafReady3)
    if ($leafText3.IndexOf("LEAF_READY", [System.StringComparison]::Ordinal) -eq -1) {
        throw "Suite 3: Leaf ready content does not contain LEAF_READY."
    }
    Write-Diagnostic "Suite 3: Broker and nested inner leaf are ready."

    # Sentinel is locked by inner leaf
    $sentinelProbe3 = Get-ExclusiveFileOpenProbe -Path $sentinelFile3 -Tag "Suite3-InnerLeafLive"
    if (-not $sentinelProbe3.IsSharingViolation) { throw "Suite 3: Sentinel file was not locked with Win32 error 32 by inner leaf!" }

    # Both broker and inner leaf are members of outer job
    $obs3 = $owner3.Observe()
    if ($obs3.ActiveProcesses -lt 2) {
        throw "Suite 3: ActiveProcesses ($($obs3.ActiveProcesses)) is less than 2."
    }

    # Terminate ONLY the broker root handle (DO NOT call outer TerminateJob or TryStopAndClose)
    $termErr3 = 0
    $termOk3 = $owner3.TryTerminateRootAndWait(5000, [ref]$termErr3)
    if (-not $termOk3 -or $termErr3 -ne 0) {
        throw "Suite 3: TryTerminateRootAndWait failed on broker: ok=$termOk3, err=$termErr3"
    }

    Write-Diagnostic "Suite 3: Broker root terminated; awaiting OS KILL_ON_JOB_CLOSE cascade on inner job..."

    # The broker process held the handle to the inner Job Object.
    # With broker death, OS closes the inner job handle -> KILL_ON_JOB_CLOSE kills the inner leaf.
    # We poll outer job ActiveProcesses until it reaches 0 WITHOUT calling outer TerminateJob!
    $swKillOnClose = [System.Diagnostics.Stopwatch]::StartNew()
    $killOnCloseSucceeded = $false
    while ($swKillOnClose.ElapsedMilliseconds -lt 15000) {
        $obsPoll3 = $owner3.Observe()
        if ($obsPoll3.ActiveProcesses -eq 0) {
            $killOnCloseSucceeded = $true
            break
        }
        [System.Threading.Thread]::Sleep(100)
    }

    if (-not $killOnCloseSucceeded) {
        $obsTimeout3 = $owner3.Observe()
        throw "Suite 3: Inner leaf was not terminated by KILL_ON_JOB_CLOSE within deadline (ActiveProcesses: $($obsTimeout3.ActiveProcesses))"
    }
    Write-Diagnostic "Suite 3: Proved OS KILL_ON_JOB_CLOSE terminated inner leaf (outer ActiveProcesses reached 0 without outer TerminateJob)."

    # Sentinel file lock must now be released
    $fsUnlocked3 = Register-TrackedStream ([System.IO.File]::Open($sentinelFile3, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None))
    $fsUnlocked3.Dispose()

    # Now clean up outer handles via TryStopAndClose
    $stopErr3 = 0
    $stopOk3 = $owner3.TryStopAndClose(5000, [ref]$stopErr3)
    if (-not $stopOk3 -or $stopErr3 -ne 0 -or -not $owner3.IsClosed) {
        throw "Suite 3: TryStopAndClose failed during post-proof cleanup: ok=$stopOk3, err=$stopErr3"
    }
    Write-Diagnostic "Suite 3: Passed successfully."

    # --- Section 9: Test Suite 4 - Start Failures, Malformed Input Rejection, Architecture Enforcement, and Timeout Bounds ---
    Write-Diagnostic "Starting Suite 4: Start failures, malformed input rejection, architecture enforcement, and timeout bounds..."

    # 1. Nonexistent executable path
    $nonexistentPath = [System.IO.Path]::Combine($labDir, "nonexistent-$nonce.exe")
    $fnfCaught = $false
    try {
        $badOwner1 = Safe-StartOwnedProcess -exePath $nonexistentPath -ArgumentVector @("echo") -cwd $labDir -env $childEnv -tag "Suite4-Nonexistent"
    } catch [System.IO.FileNotFoundException] {
        $fnfCaught = $true
    } catch [System.ArgumentException] {
        $fnfCaught = $true
    }
    if (-not $fnfCaught) { throw "Suite 4: Nonexistent executable path did not throw FileNotFoundException/ArgumentException." }

    # 2. Malformed executable paths
    $badExePaths = @(
        "C:/slash/path.exe",
        "C:\extra:colon\path.exe",
        "C:\dotdot\..\path.exe",
        "C:\trailing\backslash\",
        "C:\"
    )
    foreach ($badExe in $badExePaths) {
        $argCaught = $false
        try {
            $badOwner = Safe-StartOwnedProcess -exePath $badExe -ArgumentVector @("echo") -cwd $labDir -env $childEnv -tag "Suite4-BadExe"
        } catch [System.ArgumentException] {
            $argCaught = $true
        }
        if (-not $argCaught) { throw "Suite 4: Malformed executable path '$badExe' did not throw ArgumentException." }
    }

    # 3. Malformed working directories
    $badCwds = @(
        "C:/slash/cwd",
        "C:\",
        "C:\dotdot\..\cwd",
        "C:\extra:colon\cwd"
    )
    foreach ($badCwd in $badCwds) {
        $argCaught = $false
        try {
            $badOwner = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("echo") -cwd $badCwd -env $childEnv -tag "Suite4-BadCwd"
        } catch [System.ArgumentException] {
            $argCaught = $true
        }
        if (-not $argCaught) { throw "Suite 4: Malformed working directory '$badCwd' did not throw ArgumentException." }
    }

    # 4. Malformed arguments (NUL character)
    $argNulCaught = $false
    try {
        $nulArgs = @("echo", "bad" + [char]0 + "arg")
        $badOwner = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $nulArgs -cwd $labDir -env $childEnv -tag "Suite4-ArgNul"
    } catch [System.ArgumentException] {
        $argNulCaught = $true
    }
    if (-not $argNulCaught) { throw "Suite 4: Argument containing NUL did not throw ArgumentException." }

    # 5. Command line length exceeding 32766 chars
    $cmdLenCaught = $false
    try {
        $hugeArg = [string]::new('x', 33000)
        $badOwner = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("echo", $hugeArg) -cwd $labDir -env $childEnv -tag "Suite4-CmdLen"
    } catch [System.ArgumentException] {
        $cmdLenCaught = $true
    }
    if (-not $cmdLenCaught) { throw "Suite 4: Command line exceeding 32766 characters did not throw ArgumentException." }

    # 6. Malformed environment keys and values
    $badEnvList = @(
        @{ Key = "KEY=NAME"; Val = "val" },
        @{ Key = "KEY`0NAME"; Val = "val" },
        @{ Key = ""; Val = "val" },
        @{ Key = "KEY"; Val = "val`0bad" }
    )
    foreach ($badItem in $badEnvList) {
        $envCaught = $false
        try {
            $testEnvBad = [System.Collections.Generic.Dictionary[string, string]]::new()
            $testEnvBad[$badItem.Key] = $badItem.Val
            $badOwner = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("echo") -cwd $labDir -env $testEnvBad -tag "Suite4-BadEnv"
        } catch [System.ArgumentException] {
            $envCaught = $true
        }
        if (-not $envCaught) { throw "Suite 4: Malformed environment entry '$($badItem.Key)' did not throw ArgumentException." }
    }

    # 7. Duplicate environment keys (case-insensitive)
    $dupEnvCaught = $false
    try {
        $dupEnv = [System.Collections.Generic.Dictionary[string, string]]::new()
        $dupEnv["DUPLICATE_KEY"] = "val1"
        $dupEnv["duplicate_key"] = "val2"
        $badOwner = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("echo") -cwd $labDir -env $dupEnv -tag "Suite4-DupEnv"
    } catch [System.ArgumentException] {
        $dupEnvCaught = $true
    }
    if (-not $dupEnvCaught) { throw "Suite 4: Duplicate environment keys did not throw ArgumentException." }

    # 8. Genuine post-create failure test using x86 fixture:
    # On 64-bit OS, OwnedChildProcess enforces x64-only children via IsWow64Process2 immediately after CreateProcessW.
    # Launching an x86 executable must throw OwnedChildProcessStartException wrapping PlatformNotSupportedException.
    $x86ReadyPath = [System.IO.Path]::Combine($labDir, "x86-ready-$nonce.txt")
    $x86Caught = $false
    $x86RetainedOwner = $null
    try {
        $dummyX86 = Safe-StartOwnedProcess -exePath $fixtureExePath86 -ArgumentVector @("echo", $x86ReadyPath) -cwd $labDir -env $childEnv -tag "Suite4-X86Reject"
    } catch {
        $curr = $_.Exception
        while ($curr -ne $null) {
            if ($curr -is [System.PlatformNotSupportedException]) {
                $x86Caught = $true
            }
            if ($curr -is [PosDesktop.Updates.Native.OwnedChildProcessStartException]) {
                $x86RetainedOwner = $curr.RetainedOwner
            }
            $curr = $curr.InnerException
        }
    }
    if (-not $x86Caught) {
        throw "Suite 4: Starting x86 fixture executable did not throw PlatformNotSupportedException."
    }
    if ([System.IO.File]::Exists($x86ReadyPath)) {
        throw "Suite 4: Ready file was created by rejected x86 child; child should never have resumed!"
    }
    Write-Diagnostic "Suite 4: Proved post-create failure cleanly rejected x86 binary with PlatformNotSupportedException before thread resume."

    # Verify retained owner state and cleanup
    if ($x86RetainedOwner -ne $null) {
        $obsX86 = $x86RetainedOwner.Observe()
        # Retained owner stopped during exception cleanup in Start
        if ($x86RetainedOwner.IsClosed) {
            Write-Diagnostic "Suite 4: Retained x86 owner is properly closed."
        } else {
            $stopErrX86 = 0
            [void]$x86RetainedOwner.TryStopAndClose(5000, [ref]$stopErrX86)
        }
    }

    # Now verify SourceFileLease.Acquire succeeds on the x86 fixture (proves file handle was released)
    $x86Lease = Safe-AcquireLease -path $fixtureExePath86 -size $fixtureExeLen86 -sha256 $controlExeHash86
    $cErrX86 = 0
    if (-not $x86Lease.TryClose([ref]$cErrX86)) {
        throw "Suite 4: TryClose failed on post-x86 lease: $cErrX86"
    }
    Write-Diagnostic "Suite 4: Proved SourceFileLease acquired and closed on x86 fixture after post-create failure."

    # 9. Out-of-range timeout test on live owner:
    # TryTerminateRootAndWait and TryStopAndClose must return false with error 87 (ERROR_INVALID_PARAMETER)
    # when timeout < 0 or timeout > 60000, without terminating the child process.
    $ownerTimeoutCheck = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("sleep", "30000") -cwd $labDir -env $childEnv -tag "Suite4-TimeoutParamCheck"

    $negErr1 = 0
    $negOk1 = $ownerTimeoutCheck.TryTerminateRootAndWait(-1, [ref]$negErr1)
    if ($negOk1 -or $negErr1 -ne 87) {
        throw "Suite 4: TryTerminateRootAndWait(-1) did not fail with error 87 (got ok=$negOk1, err=$negErr1)"
    }

    $hugeErr1 = 0
    $hugeOk1 = $ownerTimeoutCheck.TryTerminateRootAndWait(60001, [ref]$hugeErr1)
    if ($hugeOk1 -or $hugeErr1 -ne 87) {
        throw "Suite 4: TryTerminateRootAndWait(60001) did not fail with error 87 (got ok=$hugeOk1, err=$hugeErr1)"
    }

    $negErr2 = 0
    $negOk2 = $ownerTimeoutCheck.TryStopAndClose(-1, [ref]$negErr2)
    if ($negOk2 -or $negErr2 -ne 87) {
        throw "Suite 4: TryStopAndClose(-1) did not fail with error 87 (got ok=$negOk2, err=$negErr2)"
    }

    $hugeErr2 = 0
    $hugeOk2 = $ownerTimeoutCheck.TryStopAndClose(60001, [ref]$hugeErr2)
    if ($hugeOk2 -or $hugeErr2 -ne 87) {
        throw "Suite 4: TryStopAndClose(60001) did not fail with error 87 (got ok=$hugeOk2, err=$hugeErr2)"
    }

    # Verify child is STILL LIVE after invalid timeout attempts (not killed!)
    $obsTimeoutCheck = $ownerTimeoutCheck.Observe()
    if ($obsTimeoutCheck.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 4: Child process was killed by invalid timeout parameters!"
    }
    Write-Diagnostic "Suite 4: Proved negative and out-of-range timeouts return error 87 without terminating child."

    # Clean up timeout check owner
    $toCheckCleanErr = 0
    [void]$ownerTimeoutCheck.TryStopAndClose(5000, [ref]$toCheckCleanErr)

    # 10. Ready timeout failure must fail and retain owner, not green
    $timeoutReadyPath = [System.IO.Path]::Combine($labDir, "unwritten-ready-$nonce.txt")
    $ownerTimeout = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("sleep", "30000") -cwd $labDir -env $childEnv -tag "Suite4-TimeoutTest"

    $timeoutCaught = $false
    try {
        [void](Wait-FileReady -path $timeoutReadyPath -timeoutMs 500)
    } catch {
        $timeoutCaught = $true
    }
    if (-not $timeoutCaught) { throw "Suite 4: Expected timeout on missing ready file did not occur." }

    # Retained owner must be properly stopped
    $stopErrTo = 0
    $stopOkTo = $ownerTimeout.TryStopAndClose(5000, [ref]$stopErrTo)
    if (-not $stopOkTo -or -not $ownerTimeout.IsClosed) { throw "Suite 4: Failed to clean up timeout test owner." }

    Write-Diagnostic "Suite 4: Passed successfully."

    # --- Section 10: Test Suite 5 - Safe Cleanup Gate Uncertainty Test ---
    Write-Diagnostic "Starting Suite 5: Safe cleanup gate uncertainty test..."

    # Start an active process that remains RUNNING
    $owner5 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("sleep", "300000") -cwd $labDir -env $childEnv -tag "Suite5-GateUncertainty"
    $owner5Rec = ($script:TrackedOwners | Where-Object { $_.Owner -eq $owner5 })

    # Activate test harness observation hook simulating uncertainty on this exact running owner
    $script:CleanupObservationHook = @{ TargetOwner = $owner5; ForceUncertain = $true }
    $script:CleanupHookHits = 0

    $gateResultUncertain = Invoke-SharedCleanupSafetyGate -DryRun
    if ($gateResultUncertain -ne 'NEEDSATTENTION') {
        throw "Suite 5: Shared cleanup gate returned '$gateResultUncertain' instead of 'NEEDSATTENTION' when hook active."
    }
    if ($script:CleanupHookHits -ne 1) {
        throw "Suite 5: Expected 1 hook hit, got $($script:CleanupHookHits)."
    }
    Write-Diagnostic "Suite 5: Proved shared cleanup gate returns NEEDSATTENTION and protects fixtures on uncertainty."

    # Clear hook
    $script:CleanupObservationHook = $null

    # Stop owner cleanly
    $stopOk5 = Stop-TrackedOwner $owner5Rec 5000
    if (-not $stopOk5 -or -not $owner5.IsClosed) {
        throw "Suite 5: Failed to cleanly stop owner after gate test."
    }

    # Now verify gate evaluates cleanly with all owners closed
    $gateResultClean = Invoke-SharedCleanupSafetyGate -DryRun
    if ($gateResultClean -ne 'CLEANED') {
        throw "Suite 5: Shared cleanup gate returned '$gateResultClean' instead of 'CLEANED' after owner closed."
    }
    Write-Diagnostic "Suite 5: Passed successfully."

    $script:AllSuitesPassed = $true
} catch {
    $script:PrimaryError = $_.Exception
    Write-Diagnostic "FATAL: Test execution failed: $($_.Exception.Message)"
} finally {
    # Restore original environment variables
    $env:TEMP = $origTemp
    $env:TMP = $origTmp
    $env:POISON_SECRET_VAR = $origPoison

    # Step 0: Dispose CodeDom provider independently if allocated
    if ($null -ne $codeProvider) {
        try {
            $codeProvider.Dispose()
        } catch {
            $cpMsg = "Exception disposing CodeDom provider: $($_.Exception.Message)"
            Write-Diagnostic $cpMsg
            $script:CompilationUncertain = $true
            if ($null -eq $script:PrimaryError) {
                $script:PrimaryError = $_.Exception
            }
        }
    }

    # Step 1: Dispose all tracked streams
    Close-AllTrackedStreams

    # Step 2: Stop and close all tracked owners independently
    foreach ($ownerRec in $script:TrackedOwners) {
        try {
            if (-not (Stop-TrackedOwner -ownerRec $ownerRec -timeoutMs 5000)) {
                $script:OwnerStopErrors.Add("Failed to stop owner $($ownerRec.Tag)")
            }
        } catch {
            $msg = "Exception stopping owner ($($ownerRec.Tag)): $($_.Exception.Message)"
            Write-Diagnostic $msg
            $script:OwnerStopErrors.Add($msg)
        }
    }

    # Step 3: Close all tracked leases independently
    foreach ($leaseRec in $script:TrackedLeases) {
        try {
            if (-not (Close-TrackedLease $leaseRec)) {
                $script:LeaseCloseErrors.Add("Failed to close lease for $($leaseRec.Path)")
            }
        } catch {
            $msg = "Exception closing lease ($($leaseRec.Path)): $($_.Exception.Message)"
            Write-Diagnostic $msg
            $script:LeaseCloseErrors.Add($msg)
        }
    }

    # Step 4: Shared cleanup safety gate
    $cleanupResult = Invoke-SharedCleanupSafetyGate -TargetSpecs @(
        @{ Path = $labDir; MarkerName = ".snpo-lab-marker"; Nonce = $nonce }
        @{ Path = $victimDir; MarkerName = ".snpo-victim-marker"; Nonce = $nonce }
    )

    if ($cleanupResult -ne 'CLEANED') {
        Write-Diagnostic "Teardown gate status '$cleanupResult'. Fixtures retained for diagnostic review."
        if ($null -ne $script:PrimaryError) {
            [Console]::Error.WriteLine("Primary error: $($script:PrimaryError.ToString())")
        }
        exit 1
    }
}

if (-not $script:AllSuitesPassed) {
    [Console]::Error.WriteLine("Verification failed: not all suites passed.")
    exit 1
}

Write-Diagnostic "All 5 test suites passed successfully and cleanup gate verified."
