# scripts/verify-shell-native-worker-image-lease.ps1
# Win32 Native Worker Image Read Lease Verifier
# Verifies launch-readable image leases, ancestor namespace locking, and OwnedChildProcess execution.

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

# --- Global Registries and State Tracking ---
$script:TrackedOwners = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedLeases = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedSourceLeases = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedStreams = [System.Collections.Generic.List[System.IO.Stream]]::new()
$script:OwnerStopErrors = [System.Collections.Generic.List[string]]::new()
$script:StreamCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:LeaseCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:SourceLeaseCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:CompilationUncertain = $false
$script:PrimaryError = $null
$script:AllSuitesPassed = $false

function Write-Diagnostic {
    param([string]$msg)
    [Console]::WriteLine("[$([DateTime]::UtcNow.ToString('yyyy-MM-dd HH:mm:ss.fffZ'))] $msg")
}

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

function Stop-AllTrackedOwners {
    foreach ($ownerRec in $script:TrackedOwners) {
        if ($ownerRec -ne $null -and $ownerRec.Owner -ne $null -and -not $ownerRec.Closed) {
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
    }
}

function Register-TrackedLease {
    param($Lease, [string]$Path)
    if ($Lease -eq $null) { return $null }
    foreach ($rec in $script:TrackedLeases) {
        if ([object]::ReferenceEquals($rec.Lease, $Lease)) {
            return $rec
        }
    }
    $p = $Path
    if ([string]::IsNullOrEmpty($p) -and $Lease.Identity -ne $null -and -not [string]::IsNullOrEmpty($Lease.Identity.CanonicalPath)) {
        $p = $Lease.Identity.CanonicalPath
    }
    if ([string]::IsNullOrEmpty($p)) {
        $p = "unknown-image-lease-path"
    }
    $rec = [PSObject]@{
        Lease = $Lease
        Path = $p
        Closed = $false
    }
    $script:TrackedLeases.Add($rec)
    return $rec
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

function Close-AllTrackedLeases {
    foreach ($leaseRec in $script:TrackedLeases) {
        if ($leaseRec -ne $null -and $leaseRec.Lease -ne $null -and -not $leaseRec.Closed) {
            try {
                if (-not (Close-TrackedLease -leaseRec $leaseRec)) {
                    $script:LeaseCloseErrors.Add("Failed to close lease $($leaseRec.Path)")
                }
            } catch {
                $msg = "Exception closing lease ($($leaseRec.Path)): $($_.Exception.Message)"
                Write-Diagnostic $msg
                $script:LeaseCloseErrors.Add($msg)
            }
        }
    }
}

function Register-TrackedSourceLease {
    param($Lease, [string]$Path)
    if ($Lease -eq $null) { return $null }
    foreach ($rec in $script:TrackedSourceLeases) {
        if ([object]::ReferenceEquals($rec.Lease, $Lease)) {
            return $rec
        }
    }
    $rec = [PSObject]@{
        Lease = $Lease
        Path = $Path
        Closed = $false
    }
    $script:TrackedSourceLeases.Add($rec)
    return $rec
}

function Close-AllTrackedSourceLeases {
    foreach ($leaseRec in $script:TrackedSourceLeases) {
        if ($leaseRec -ne $null -and $leaseRec.Lease -ne $null -and -not $leaseRec.Closed) {
            try {
                $cErr = 0
                if ($leaseRec.Lease.TryClose([ref]$cErr)) {
                    $leaseRec.Closed = $true
                } else {
                    $script:SourceLeaseCloseErrors.Add("Failed to close source lease $($leaseRec.Path): error $cErr")
                }
            } catch {
                $msg = "Exception closing source lease ($($leaseRec.Path)): $($_.Exception.Message)"
                Write-Diagnostic $msg
                $script:SourceLeaseCloseErrors.Add($msg)
            }
        }
    }
}

function Compute-Sha256OfFile {
    param([string]$filePath)
    $fs = $null
    $sha = $null
    $primaryEx = $null
    try {
        $fs = [System.IO.File]::OpenRead($filePath)
        [void](Register-TrackedStream $fs)
        $sha = [System.Security.Cryptography.SHA256]::Create()
        $bytes = $sha.ComputeHash($fs)
        $sb = [System.Text.StringBuilder]::new($bytes.Length * 2)
        foreach ($b in $bytes) { [void]$sb.Append($b.ToString("x2")) }
        return $sb.ToString()
    } catch {
        $primaryEx = $_.Exception
        throw
    } finally {
        $shaEx = $null
        if ($sha -ne $null) {
            try { $sha.Dispose() } catch {
                $shaEx = $_.Exception
                $msg = "SHA disposal failed for '$filePath': $($_.Exception.Message)"
                Write-Diagnostic $msg
                $script:StreamCloseErrors.Add($msg)
            }
        }
        $fsEx = $null
        if ($fs -ne $null) {
            try { $fs.Dispose() } catch {
                $fsEx = $_.Exception
                $msg = "FileStream disposal failed for '$filePath': $($_.Exception.Message)"
                Write-Diagnostic $msg
                $script:StreamCloseErrors.Add($msg)
            }
        }
        if ($shaEx -ne $null -or $fsEx -ne $null) {
            $cleanupErrors = [System.Collections.Generic.List[System.Exception]]::new()
            if ($primaryEx -ne $null) { $cleanupErrors.Add($primaryEx) }
            if ($shaEx -ne $null) { $cleanupErrors.Add($shaEx) }
            if ($fsEx -ne $null) { $cleanupErrors.Add($fsEx) }
            if ($primaryEx -eq $null) {
                if ($cleanupErrors.Count -eq 1) { throw $cleanupErrors[0] }
                else { throw [System.AggregateException]::new("Disposal failure during Compute-Sha256OfFile", $cleanupErrors.ToArray()) }
            } else {
                throw [System.AggregateException]::new("ComputeHash failed and disposal also failed during Compute-Sha256OfFile", $cleanupErrors.ToArray())
            }
        }
    }
}

# --- Section 3: Safe Traversal, Nonce-Marked Lab, and Protected Victim Witness ---
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

$nonce = [System.Guid]::NewGuid().ToString("N")
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "img-lease-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "img-lease-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
[void][System.IO.Directory]::CreateDirectory($labDir)
[void][System.IO.Directory]::CreateDirectory($labTempDir)
[void][System.IO.Directory]::CreateDirectory($victimDir)

$labMarkerPath = [System.IO.Path]::Combine($labDir, ".img-lease-lab-marker")
[System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".img-lease-victim-marker")
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
    param([PSObject[]]$ExpectedSnapshot, [string]$VictimPath)
    $current = Capture-VictimSnapshot $VictimPath
    if ($ExpectedSnapshot.Length -ne $current.Length) {
        throw "Victim snapshot item count mismatch: expected $($ExpectedSnapshot.Length), got $($current.Length)"
    }
    for ($i = 0; $i -lt $ExpectedSnapshot.Length; $i++) {
        $exp = $ExpectedSnapshot[$i]
        $act = $current[$i]
        if ($exp.RelativePath -ne $act.RelativePath -or $exp.IsDirectory -ne $act.IsDirectory -or $exp.Length -ne $act.Length -or $exp.Sha256 -ne $act.Sha256) {
            throw "Victim mutation detected at index ${i}: expected $($exp.RelativePath), got $($act.RelativePath)"
        }
    }
}

function Invoke-DirectoryDeletion {
    param(
        [string]$Path,
        [string]$ExpectedMarkerName,
        [string]$ExpectedNonce
    )
    $canon = [System.IO.Path]::GetFullPath($Path).TrimEnd('\')
    Assert-PathAncestorsAndRootNotReparse $canon
    $bound = ($script:CanonicalRunnerTemp.TrimEnd('\') + '\')
    if (-not $canon.StartsWith($bound, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Path escapes runner temp bounds: $canon"
    }

    $marker = [System.IO.Path]::Combine($canon, $ExpectedMarkerName)
    if (-not [System.IO.File]::Exists($marker)) {
        throw "Marker file missing in target directory: $marker"
    }
    $actualNonce = [System.IO.File]::ReadAllText($marker, [System.Text.Encoding]::UTF8).Trim()
    if ($actualNonce -ne $ExpectedNonce) {
        throw "Nonce mismatch in marker: expected '$ExpectedNonce', got '$actualNonce'"
    }

    $files = [System.Collections.Generic.List[PSObject]]::new()
    $dirs = [System.Collections.Generic.List[PSObject]]::new()
    Walk-DirectorySafeTopDown -directoryPath $canon -boundPrefix $bound -discoveredFiles $files -discoveredDirs $dirs

    foreach ($f in $files) {
        [System.IO.File]::Delete($f.FullName)
    }
    for ($i = $dirs.Count - 1; $i -ge 0; $i--) {
        [System.IO.Directory]::Delete($dirs[$i].FullName, $false)
    }
    [System.IO.Directory]::Delete($canon, $false)
}

function Invoke-SharedCleanupSafetyGate {
    param(
        [PSObject[]]$TargetSpecs = $null,
        [scriptblock]$RestorationCallback = $null
    )

    if ($script:CompilationUncertain) {
        Write-Diagnostic "Teardown uncertainty: compilation step was uncertain."
        return 'NEEDSATTENTION'
    }

    if ($script:OwnerStopErrors.Count -gt 0 -or $script:LeaseCloseErrors.Count -gt 0 -or $script:SourceLeaseCloseErrors.Count -gt 0 -or $script:StreamCloseErrors.Count -gt 0) {
        Write-Diagnostic "Teardown uncertainty: tracked teardown error lists are non-empty."
        return 'NEEDSATTENTION'
    }

    foreach ($ownerRec in $script:TrackedOwners) {
        if ($ownerRec -eq $null -or $ownerRec.Owner -eq $null -or -not $ownerRec.Closed -or -not $ownerRec.Owner.IsClosed) {
            $obs = if ($ownerRec -ne $null -and $ownerRec.Owner -ne $null) { $ownerRec.Owner.Observe() } else { $null }
            Write-Diagnostic "Teardown uncertainty: owner ($($ownerRec.Tag)) not closed."
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
            Write-Diagnostic "Teardown uncertainty: image lease ($($leaseRec.Path)) is not closed."
            return 'NEEDSATTENTION'
        }
    }

    foreach ($sRec in $script:TrackedSourceLeases) {
        if ($sRec -eq $null -or $sRec.Lease -eq $null -or -not $sRec.Closed -or -not $sRec.Lease.IsClosed) {
            Write-Diagnostic "Teardown uncertainty: source lease ($($sRec.Path)) is not closed."
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

    if ($null -ne $TargetSpecs) {
        foreach ($spec in $TargetSpecs) {
            if ([System.IO.Directory]::Exists($spec.Path)) {
                Invoke-DirectoryDeletion -Path $spec.Path -ExpectedMarkerName $spec.MarkerName -ExpectedNonce $spec.Nonce
            }
        }
    }
    if ($null -ne $RestorationCallback) { & $RestorationCallback }

    return 'CLEANED'
}

# --- Section 4: Safe Process and Lease Wrappers ---
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

function Safe-AcquireImageLease {
    param([string]$path, [long]$size, [object]$sha256)
    $lease = $null
    try {
        if ($null -eq $sha256) {
            # PowerShell .NET method binder coerces $null to empty string ("") for string parameters.
            # Call test helper shim to pass an actual typed C# null.
            $lease = [PosDesktop.Updates.Native.Testing.NativeLinkHelper]::AcquireWithNullSha($path, $size)
        } else {
            $lease = [PosDesktop.Updates.Native.WorkerImageReadLease]::Acquire($path, $size, $sha256)
        }
        if ($lease -eq $null) {
            throw "Acquire returned null lease for path: $path"
        }
        [void](Register-TrackedLease -Lease $lease -Path $path)
        return $lease
    } catch {
        $curr = $_.Exception
        $retained = $null
        $depth = 0
        while ($curr -ne $null -and $depth -lt 5) {
            if ($curr -is [PosDesktop.Updates.Native.WorkerImageReadLeaseAcquisitionException]) {
                $retained = $curr.RetainedLease
                break
            }
            $curr = $curr.InnerException
            $depth++
        }
        if ($retained -ne $null) {
            [void](Register-TrackedLease -Lease $retained -Path $path)
        }
        throw
    }
}

function Safe-AcquireSourceLease {
    param([string]$path, [long]$size, [string]$sha256)
    $lease = $null
    try {
        $lease = [PosDesktop.Updates.Native.SourceFileLease]::Acquire($path, $size, $sha256)
        if ($lease -eq $null) {
            throw "SourceFileLease.Acquire returned null lease for path: $path"
        }
        [void](Register-TrackedSourceLease -Lease $lease -Path $path)
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
            [void](Register-TrackedSourceLease -Lease $retained -Path $path)
        }
        throw
    }
}

function Unwrap-Exception {
    param([System.Exception]$ex, [int]$maxDepth = 10)
    $curr = $ex
    $depth = 0
    while ($curr -ne $null -and $depth -lt $maxDepth) {
        if ($curr -is [System.Management.Automation.MethodInvocationException] -or
            $curr -is [System.Reflection.TargetInvocationException]) {
            if ($curr.InnerException -ne $null) {
                $curr = $curr.InnerException
                $depth++
                continue
            }
        }
        break
    }
    return $curr
}

function Find-ExceptionOfType {
    param([System.Exception]$ex, [type]$targetType, [int]$maxDepth = 10)
    $curr = $ex
    $depth = 0
    while ($curr -ne $null -and $depth -lt $maxDepth) {
        if ($targetType.IsAssignableFrom($curr.GetType())) {
            return $curr
        }
        $curr = $curr.InnerException
        $depth++
    }
    return $null
}

function Get-Win32ErrorCode {
    param([System.Exception]$ex)
    if ($ex -eq $null) { return -1 }

    $win32Ex = Find-ExceptionOfType -ex $ex -targetType ([System.ComponentModel.Win32Exception])
    if ($win32Ex -ne $null -and $win32Ex.NativeErrorCode -gt 0) {
        return $win32Ex.NativeErrorCode
    }

    $curr = $ex
    $depth = 0
    while ($curr -ne $null -and $depth -lt 10) {
        if ($curr -is [System.IO.IOException]) {
            $h = $curr.HResult
            if (($h -band -65536) -eq -2147024896) {
                $code = $h -band 65535
                if ($code -gt 0) {
                    return $code
                }
            }
        }
        $curr = $curr.InnerException
        $depth++
    }
    return -1
}

function Wait-ForHelperReady {
    param([string]$readyTokenPath, [int]$timeoutMs = 10000)
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.ElapsedMilliseconds -lt $timeoutMs) {
        if ([System.IO.File]::Exists($readyTokenPath)) {
            try {
                $content = [System.IO.File]::ReadAllText($readyTokenPath)
                if (-not [string]::IsNullOrWhiteSpace($content)) {
                    return $content.Trim()
                }
            } catch {
                # File may be in the middle of being written; continue polling
            }
        }
        [System.Threading.Thread]::Sleep(20)
    }
    throw "Timeout ($timeoutMs ms) waiting for helper ready token at: $readyTokenPath"
}

function Wait-ForOwnerQuiescent {
    param($ownerRec, [int]$timeoutMs = 10000)
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.ElapsedMilliseconds -lt $timeoutMs) {
        $obs = $ownerRec.Owner.Observe()
        if ($obs.RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Dead -and
            $obs.JobCountKnown -and
            $obs.ActiveProcesses -eq 0 -and
            $obs.IsQuiescent) {
            return $obs
        }
        [System.Threading.Thread]::Sleep(20)
    }
    $obsTimeout = $ownerRec.Owner.Observe()
    throw "Timeout ($timeoutMs ms) waiting for owner to become quiescent. State: RootState=$($obsTimeout.RootState), ActiveProcesses=$($obsTimeout.ActiveProcesses), IsQuiescent=$($obsTimeout.IsQuiescent)"
}

# --- Section 5: Synchronous Unified C# Compilation ---
$origTemp = $env:TEMP; $origTmp = $env:TMP
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $scriptDir ".."))
$workerLeaseCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\WorkerImageReadLease.cs")
$ownedChildCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\OwnedChildProcess.cs")
$sourceFileLeaseCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\SourceFileLease.cs")

if (-not [System.IO.File]::Exists($workerLeaseCsPath)) { throw "WorkerImageReadLease.cs missing at: $workerLeaseCsPath" }
if (-not [System.IO.File]::Exists($ownedChildCsPath)) { throw "OwnedChildProcess.cs missing at: $ownedChildCsPath" }
if (-not [System.IO.File]::Exists($sourceFileLeaseCsPath)) { throw "SourceFileLease.cs missing at: $sourceFileLeaseCsPath" }

$workerLeaseSource = [System.IO.File]::ReadAllText($workerLeaseCsPath, [System.Text.Encoding]::UTF8)
$ownedChildSource = [System.IO.File]::ReadAllText($ownedChildCsPath, [System.Text.Encoding]::UTF8)
$sourceFileLeaseSource = [System.IO.File]::ReadAllText($sourceFileLeaseCsPath, [System.Text.Encoding]::UTF8)

$nativeHardLinkHelperSource = @"
namespace PosDesktop.Updates.Native.Testing
{
    using System;
    using System.Runtime.InteropServices;

    public static class NativeLinkHelper
    {
        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
        [return: MarshalAs(UnmanagedType.Bool)]
        public static extern bool CreateHardLinkW(string lpFileName, string lpExistingFileName, IntPtr lpSecurityAttributes);

        [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
        public static extern IntPtr CreateFileW(
            string lpFileName,
            uint dwDesiredAccess,
            uint dwShareMode,
            IntPtr lpSecurityAttributes,
            uint dwCreationDisposition,
            uint dwFlagsAndAttributes,
            IntPtr hTemplateFile
        );

        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        public static extern bool CloseHandle(IntPtr hObject);

        public static PosDesktop.Updates.Native.WorkerImageReadLease AcquireWithNullSha(string path, long size)
        {
            return PosDesktop.Updates.Native.WorkerImageReadLease.Acquire(path, size, null);
        }
    }
}
"@

$fixtureDllSource = @"
namespace PosDesktop.Updates.Native.Testing
{
    public static class HelperDll
    {
        public static int GetWorkerToken()
        {
            return 8421;
        }
    }
}
"@

$fixtureProgramSource = @"
namespace PosDesktop.Updates.Native.Testing
{
    using System;
    using System.IO;
    using System.Reflection;
    using System.Threading;

    public static class HelperProgram
    {
        public static int Main(string[] args)
        {
            if (args == null || args.Length < 2)
            {
                return 1;
            }

            string mode = args[0];
            string readyTokenPath = args[1];

            if (string.Equals(mode, "handshake-and-gate", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 3) return 2;
                string gatePath = args[2];

                File.WriteAllText(readyTokenPath, "WORKER_READY");

                int elapsed = 0;
                while (File.Exists(gatePath) && elapsed < 30000)
                {
                    Thread.Sleep(50);
                    elapsed += 50;
                }
                return 0;
            }

            if (string.Equals(mode, "load-dll-and-gate", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 4) return 3;
                string gatePath = args[2];
                string dllPath = args[3];

                try
                {
                    Assembly loadedAssembly = Assembly.LoadFrom(dllPath);
                    Type t = loadedAssembly.GetType("PosDesktop.Updates.Native.Testing.HelperDll");
                    if (t == null) return 4;
                    MethodInfo m = t.GetMethod("GetWorkerToken");
                    if (m == null) return 5;
                    int token = (int)m.Invoke(null, null);
                    if (token != 8421) return 6;

                    File.WriteAllText(readyTokenPath, "DLL_LOADED_OK_" + token);

                    int elapsed = 0;
                    while (File.Exists(gatePath) && elapsed < 30000)
                    {
                        Thread.Sleep(50);
                        elapsed += 50;
                    }
                    return 0;
                }
                catch (Exception ex)
                {
                    File.WriteAllText(readyTokenPath, "ERROR: " + ex.Message);
                    return 7;
                }
            }

            return 99;
        }
    }
}
"@

$compiledFixtureExePath = [System.IO.Path]::Combine($labDir, "helper-program.exe")
$compiledFixtureDllPath = [System.IO.Path]::Combine($labDir, "helper-library.dll")

try {
    $script:CompilationUncertain = $true
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir
    try {
        # Unit 1: Add-Type in-memory types
        $combinedInMemory = $sourceFileLeaseSource + "`r`n" + $ownedChildSource + "`r`n" + $workerLeaseSource + "`r`n" + $nativeHardLinkHelperSource
        Add-Type -TypeDefinition $combinedInMemory
        Write-Diagnostic "Successfully compiled in-memory native types via Add-Type."

        # Unit 2: Compile helper console executable (x64)
        $codeProvider = [Microsoft.CSharp.CSharpCodeProvider]::new()
        $compilePrimaryEx = $null
        $disposeEx = $null
        try {
            $params64 = [System.CodeDom.Compiler.CompilerParameters]::new()
            $params64.GenerateExecutable = $true
            $params64.GenerateInMemory = $false
            $params64.OutputAssembly = $compiledFixtureExePath
            $params64.CompilerOptions = "/platform:x64"
            [void]$params64.ReferencedAssemblies.Add("System.dll")

            $results64 = $codeProvider.CompileAssemblyFromSource($params64, @($fixtureProgramSource))
            if ($results64.NativeCompilerReturnValue -ne 0 -or $results64.Errors.HasErrors) {
                $errMsgs = @()
                foreach ($err in $results64.Errors) { $errMsgs += $err.ToString() }
                throw [System.InvalidOperationException]::new("x64 Fixture EXE compilation failed: $($errMsgs -join '; ')")
            }

            # Unit 3: Compile helper managed library (x64)
            $dllParams64 = [System.CodeDom.Compiler.CompilerParameters]::new()
            $dllParams64.GenerateExecutable = $false
            $dllParams64.GenerateInMemory = $false
            $dllParams64.OutputAssembly = $compiledFixtureDllPath
            $dllParams64.CompilerOptions = "/platform:x64"
            [void]$dllParams64.ReferencedAssemblies.Add("System.dll")

            $dllResults64 = $codeProvider.CompileAssemblyFromSource($dllParams64, @($fixtureDllSource))
            if ($dllResults64.NativeCompilerReturnValue -ne 0 -or $dllResults64.Errors.HasErrors) {
                $dllErrMsgs = @()
                foreach ($err in $dllResults64.Errors) { $dllErrMsgs += $err.ToString() }
                throw [System.InvalidOperationException]::new("x64 Fixture DLL compilation failed: $($dllErrMsgs -join '; ')")
            }
        } catch {
            $compilePrimaryEx = $_.Exception
        } finally {
            if ($codeProvider -ne $null) {
                try {
                    $codeProvider.Dispose()
                } catch {
                    $disposeEx = $_.Exception
                    Write-Diagnostic "CodeProvider disposal failed: $($_.Exception.Message)"
                }
            }
        }

        if ($compilePrimaryEx -ne $null -and $disposeEx -ne $null) {
            throw [System.AggregateException]::new("Compilation failed and provider disposal also failed.", @($compilePrimaryEx, $disposeEx))
        }
        if ($compilePrimaryEx -ne $null) { throw $compilePrimaryEx }
        if ($disposeEx -ne $null) { throw $disposeEx }

        Write-Diagnostic "Successfully compiled helper EXE and DLL: $compiledFixtureExePath, $compiledFixtureDllPath"
        $script:CompilationUncertain = $false
    } finally {
        $env:TEMP = $origTemp; $env:TMP = $origTmp
    }

    if (-not [System.IO.File]::Exists($compiledFixtureExePath)) { throw "Compiled EXE missing at $compiledFixtureExePath" }
    if (-not [System.IO.File]::Exists($compiledFixtureDllPath)) { throw "Compiled DLL missing at $compiledFixtureDllPath" }

    # =========================================================================
    # SUITE 1: Acquire file identity, same-handle SHA-256, ReadRange/EOF, immutable DTO, after-close guards, and negatives
    # =========================================================================
    Write-Diagnostic "=== SUITE 1: Basic Acquire, Identity, Hashing, ReadRange, and Negatives ==="
    $suite1Dir = [System.IO.Path]::Combine($labDir, "suite1")
    [void][System.IO.Directory]::CreateDirectory($suite1Dir)
    $suite1File = [System.IO.Path]::Combine($suite1Dir, "sample-worker.dat")

    # Generate synthetic binary payload (65,536 bytes + 128 bytes = 65,664 bytes to span buffer boundaries)
    $suite1Payload = [byte[]]::new(65664)
    for ($i = 0; $i -lt $suite1Payload.Length; $i++) {
        $suite1Payload[$i] = [byte]($i % 251)
    }
    [System.IO.File]::WriteAllBytes($suite1File, $suite1Payload)

    $suite1ExpectedSha = Compute-Sha256OfFile $suite1File
    $suite1ExpectedSize = [long]$suite1Payload.Length

    # 1.1 Successful Acquire
    $lease1 = Safe-AcquireImageLease -path $suite1File -size $suite1ExpectedSize -sha256 $suite1ExpectedSha
    $leaseRec1 = Register-TrackedLease -Lease $lease1 -Path $suite1File

    # 1.2 Verify immutable Identity properties
    $ident1 = $lease1.Identity
    if ($ident1 -eq $null) { throw "Suite 1: Identity is null." }
    if ($ident1.VolumeSerialNumber -eq 0) { throw "Suite 1: VolumeSerialNumber is 0." }
    if ($ident1.FileIndex -eq [System.UInt64]0) { throw "Suite 1: FileIndex is 0." }
    if ($ident1.Size -ne $suite1ExpectedSize) { throw "Suite 1: Identity.Size mismatch." }
    if (-not [string]::Equals($ident1.CanonicalPath, $suite1File, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 1: CanonicalPath mismatch: expected '$suite1File', got '$($ident1.CanonicalPath)'"
    }

    # 1.3 Verify CanApply is false and IsClosed is false
    if ($lease1.CanApply -ne $false) { throw "Suite 1: CanApply must be false." }
    if ($lease1.IsClosed -ne $false) { throw "Suite 1: IsClosed must be false." }

    # 1.4 ComputeSha256 on same handle
    $actualSha1 = $lease1.ComputeSha256()
    if (-not [string]::Equals($actualSha1, $suite1ExpectedSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 1: ComputeSha256 mismatch: expected '$suite1ExpectedSha', got '$actualSha1'"
    }

    # 1.5 ReadRange tests
    # Read first 16 bytes
    $headBytes = $lease1.ReadRange(0, 16)
    if ($headBytes.Length -ne 16) { throw "Suite 1: ReadRange(0, 16) returned $($headBytes.Length) bytes." }
    for ($i = 0; $i -lt 16; $i++) {
        if ($headBytes[$i] -ne $suite1Payload[$i]) { throw "Suite 1: Head byte mismatch at index ${i}." }
    }

    # Read middle range across 64 KiB boundary
    $midBytes = $lease1.ReadRange(65530, 20)
    if ($midBytes.Length -ne 20) { throw "Suite 1: ReadRange(65530, 20) returned $($midBytes.Length) bytes." }
    for ($i = 0; $i -lt 20; $i++) {
        if ($midBytes[$i] -ne $suite1Payload[65530 + $i]) { throw "Suite 1: Mid byte mismatch at offset ${i}." }
    }

    # Read at EOF count 0
    $eofBytes = $lease1.ReadRange($suite1ExpectedSize, 0)
    if ($eofBytes.Length -ne 0) { throw "Suite 1: ReadRange at EOF count 0 returned non-empty array." }

    # 1.6 Revalidate succeeds
    $lease1.Revalidate()

    # 1.7 Checked close
    if (-not (Close-TrackedLease -leaseRec $leaseRec1)) {
        throw "Suite 1: Close-TrackedLease failed."
    }
    if (-not $lease1.IsClosed) { throw "Suite 1: Lease IsClosed is not true after closure." }

    # 1.8 After-close guards
    $disposedReadBlocked = $false
    try { [void]$lease1.ReadRange(0, 1) } catch [System.ObjectDisposedException] { $disposedReadBlocked = $true }
    if (-not $disposedReadBlocked) { throw "Suite 1: ReadRange did not throw ObjectDisposedException after closure." }

    $disposedShaBlocked = $false
    try { [void]$lease1.ComputeSha256() } catch [System.ObjectDisposedException] { $disposedShaBlocked = $true }
    if (-not $disposedShaBlocked) { throw "Suite 1: ComputeSha256 did not throw ObjectDisposedException after closure." }

    $disposedRevalBlocked = $false
    try { $lease1.Revalidate() } catch [System.ObjectDisposedException] { $disposedRevalBlocked = $true }
    if (-not $disposedRevalBlocked) { throw "Suite 1: Revalidate did not throw ObjectDisposedException after closure." }

    # 1.9 Negatives
    # Wrong expected size (smaller)
    $badSizeSmallBlocked = $false
    try { [void](Safe-AcquireImageLease -path $suite1File -size ($suite1ExpectedSize - 1) -sha256 $suite1ExpectedSha) }
    catch { $badSizeSmallBlocked = $true }
    if (-not $badSizeSmallBlocked) { throw "Suite 1: Smaller expectedSize was not rejected!" }

    # Wrong expected size (larger)
    $badSizeLargeBlocked = $false
    try { [void](Safe-AcquireImageLease -path $suite1File -size ($suite1ExpectedSize + 1) -sha256 $suite1ExpectedSha) }
    catch { $badSizeLargeBlocked = $true }
    if (-not $badSizeLargeBlocked) { throw "Suite 1: Larger expectedSize was not rejected!" }

    # Negative expected size
    $negSizeBlocked = $false
    try { [void](Safe-AcquireImageLease -path $suite1File -size -1 -sha256 $suite1ExpectedSha) }
    catch [System.ArgumentOutOfRangeException] { $negSizeBlocked = $true }
    if (-not $negSizeBlocked) { throw "Suite 1: Negative expectedSize did not throw ArgumentOutOfRangeException!" }

    # Expected size exceeding safe integer
    $overflowSizeBlocked = $false
    try { [void](Safe-AcquireImageLease -path $suite1File -size 9007199254740992 -sha256 $suite1ExpectedSha) }
    catch [System.ArgumentOutOfRangeException] { $overflowSizeBlocked = $true }
    if (-not $overflowSizeBlocked) { throw "Suite 1: expectedSize > 9007199254740991 did not throw ArgumentOutOfRangeException!" }

    # Mismatched SHA-256
    $badSha = "0000000000000000000000000000000000000000000000000000000000000000"
    $badShaBlocked = $false
    try { [void](Safe-AcquireImageLease -path $suite1File -size $suite1ExpectedSize -sha256 $badSha) }
    catch [System.InvalidOperationException] { $badShaBlocked = $true }
    if (-not $badShaBlocked) { throw "Suite 1: Wrong expectedSha256 did not throw InvalidOperationException!" }

    # Uppercase hex SHA-256
    $upperSha = $suite1ExpectedSha.ToUpperInvariant()
    $upperShaBlocked = $false
    try { [void](Safe-AcquireImageLease -path $suite1File -size $suite1ExpectedSize -sha256 $upperSha) }
    catch [System.ArgumentException] { $upperShaBlocked = $true }
    if (-not $upperShaBlocked) { throw "Suite 1: Uppercase expectedSha256 did not throw ArgumentException!" }

    # Non-hex SHA-256
    $nonHexSha = "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz"
    $nonHexBlocked = $false
    try { [void](Safe-AcquireImageLease -path $suite1File -size $suite1ExpectedSize -sha256 $nonHexSha) }
    catch [System.ArgumentException] { $nonHexBlocked = $true }
    if (-not $nonHexBlocked) { throw "Suite 1: Non-hex expectedSha256 did not throw ArgumentException!" }

    # Null SHA-256
    $nullShaBlocked = $false
    try { [void](Safe-AcquireImageLease -path $suite1File -size $suite1ExpectedSize -sha256 $null) }
    catch [System.ArgumentNullException] { $nullShaBlocked = $true }
    if (-not $nullShaBlocked) { throw "Suite 1: Null expectedSha256 did not throw ArgumentNullException!" }

    # Target path is directory
    $dirTargetBlocked = $false
    try { [void](Safe-AcquireImageLease -path $suite1Dir -size 0 -sha256 $badSha) }
    catch [System.ArgumentException] { $dirTargetBlocked = $true }
    if (-not $dirTargetBlocked) { throw "Suite 1: Target directory did not throw ArgumentException!" }

    # File does not exist
    $missingFile = [System.IO.Path]::Combine($suite1Dir, "nonexistent.dat")
    $missingBlocked = $false
    try { [void](Safe-AcquireImageLease -path $missingFile -size 10 -sha256 $badSha) }
    catch [System.IO.FileNotFoundException] { $missingBlocked = $true }
    if (-not $missingBlocked) { throw "Suite 1: Missing file did not throw FileNotFoundException!" }

    # Drive root as target
    $driveRoot = [System.IO.Path]::GetPathRoot($suite1Dir)
    $driveRootBlocked = $false
    try { [void](Safe-AcquireImageLease -path $driveRoot -size 0 -sha256 $badSha) }
    catch [System.ArgumentException] { $driveRootBlocked = $true }
    if (-not $driveRootBlocked) { throw "Suite 1: Drive root target did not throw ArgumentException!" }

    # Path with dotdot segment
    $dotDotPath = [System.IO.Path]::Combine($suite1Dir, "..", "suite1", "sample-worker.dat")
    $dotDotBlocked = $false
    try { [void](Safe-AcquireImageLease -path $dotDotPath -size $suite1ExpectedSize -sha256 $suite1ExpectedSha) }
    catch [System.ArgumentException] { $dotDotBlocked = $true }
    if (-not $dotDotBlocked) { throw "Suite 1: Dotdot path did not throw ArgumentException!" }

    Write-Diagnostic "Suite 1 passed cleanly."

    # =========================================================================
    # SUITE 2: LAUNCH real helper EXE via OwnedChildProcess WHILE WorkerImageReadLease HELD
    # =========================================================================
    Write-Diagnostic "=== SUITE 2: Launch Under Lease, DLL Loading, Live Revalidation, and Quiescence ==="
    $suite2Dir = [System.IO.Path]::Combine($labDir, "suite2")
    [void][System.IO.Directory]::CreateDirectory($suite2Dir)

    $suite2Exe = [System.IO.Path]::Combine($suite2Dir, "worker-helper.exe")
    $suite2Dll = [System.IO.Path]::Combine($suite2Dir, "worker-library.dll")
    $suite2Phar = [System.IO.Path]::Combine($suite2Dir, "synthetic-worker.phar")
    $suite2ReadyToken = [System.IO.Path]::Combine($suite2Dir, "helper-ready.token")
    $suite2GateFile = [System.IO.Path]::Combine($suite2Dir, "helper-gate.token")

    [System.IO.File]::Copy($compiledFixtureExePath, $suite2Exe, $true)
    [System.IO.File]::Copy($compiledFixtureDllPath, $suite2Dll, $true)
    [System.IO.File]::WriteAllBytes($suite2Phar, [System.Text.Encoding]::UTF8.GetBytes("SYNTHETIC_PHAR_PAYLOAD_$nonce"))

    $suite2ExeSize = [long][System.IO.FileInfo]::new($suite2Exe).Length
    $suite2ExeSha = Compute-Sha256OfFile $suite2Exe

    $suite2DllSize = [long][System.IO.FileInfo]::new($suite2Dll).Length
    $suite2DllSha = Compute-Sha256OfFile $suite2Dll

    $suite2PharSize = [long][System.IO.FileInfo]::new($suite2Phar).Length
    $suite2PharSha = Compute-Sha256OfFile $suite2Phar

    # 2.1 Acquire WorkerImageReadLease on EXE, DLL, and PHAR simultaneously
    $leaseExe = Safe-AcquireImageLease -path $suite2Exe -size $suite2ExeSize -sha256 $suite2ExeSha
    $recExe = Register-TrackedLease -Lease $leaseExe -Path $suite2Exe

    $leaseDll = Safe-AcquireImageLease -path $suite2Dll -size $suite2DllSize -sha256 $suite2DllSha
    $recDll = Register-TrackedLease -Lease $leaseDll -Path $suite2Dll

    $leasePhar = Safe-AcquireImageLease -path $suite2Phar -size $suite2PharSize -sha256 $suite2PharSha
    $recPhar = Register-TrackedLease -Lease $leasePhar -Path $suite2Phar

    # Create gate file to keep helper running until signaled
    [System.IO.File]::WriteAllText($suite2GateFile, "GATE_ACTIVE_$nonce", [System.Text.Encoding]::UTF8)

    # 2.2 Launch real helper EXE via OwnedChildProcess.Start WHILE LEASES ARE HELD
    $envMap = [System.Collections.Generic.Dictionary[string, string]]::new()
    $envMap["TEST_NONCE"] = $nonce
    $argVec = @("load-dll-and-gate", $suite2ReadyToken, $suite2GateFile, $suite2Dll)

    $workerOwner = Safe-StartOwnedProcess -exePath $suite2Exe -ArgumentVector $argVec -cwd $suite2Dir -env $envMap -tag "Suite2Worker"
    $ownerRec = Register-TrackedOwner -Owner $workerOwner -Tag "Suite2Worker" -ExePath $suite2Exe

    # Wait for ready handshake token confirming DLL loaded and helper alive
    $readyMsg = Wait-ForHelperReady -readyTokenPath $suite2ReadyToken -timeoutMs 10000
    if (-not [string]::Equals($readyMsg, "DLL_LOADED_OK_8421", [System.StringComparison]::Ordinal)) {
        throw "Suite 2: Ready handshake mismatch: expected 'DLL_LOADED_OK_8421', got '$readyMsg'"
    }

    # Verify root state is Live
    $obsLive = $workerOwner.Observe()
    if ($obsLive.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 2: Child process is not Live while running: $($obsLive.RootState)"
    }
    Write-Diagnostic "Suite 2: Worker process is LIVE (PID: $($obsLive.ProcessId)), handshake verified: $readyMsg"

    # 2.3 Revalidate and ComputeSha256 on all three held leases WHILE WORKER IS RUNNING
    $leaseExe.Revalidate()
    $liveExeSha = $leaseExe.ComputeSha256()
    if (-not [string]::Equals($liveExeSha, $suite2ExeSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: EXE SHA mismatch while running."
    }

    $leaseDll.Revalidate()
    $liveDllSha = $leaseDll.ComputeSha256()
    if (-not [string]::Equals($liveDllSha, $suite2DllSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: DLL SHA mismatch while running."
    }

    $leasePhar.Revalidate()
    $livePharSha = $leasePhar.ComputeSha256()
    if (-not [string]::Equals($livePharSha, $suite2PharSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: PHAR SHA mismatch while running."
    }
    Write-Diagnostic "Suite 2: Revalidation and same-handle SHA successfully confirmed while worker is LIVE."

    # 2.4 EXTRA control: SourceFileLease (exclusive share 0) blocks OwnedChildProcess.Start
    $suite2ControlExe = [System.IO.Path]::Combine($suite2Dir, "control-exclusive.exe")
    [System.IO.File]::Copy($compiledFixtureExePath, $suite2ControlExe, $true)
    $controlSize = [long][System.IO.FileInfo]::new($suite2ControlExe).Length
    $controlSha = Compute-Sha256OfFile $suite2ControlExe

    $sourceLease = Safe-AcquireSourceLease -path $suite2ControlExe -size $controlSize -sha256 $controlSha
    $sourceRec = Register-TrackedSourceLease -Lease $sourceLease -Path $suite2ControlExe

    $exclusiveStartBlocked = $false
    try {
        [void][PosDesktop.Updates.Native.OwnedChildProcess]::Start($suite2ControlExe, @("handshake-and-gate", "dummy", "dummy"), $suite2Dir, $envMap)
    } catch {
        $exclusiveStartBlocked = $true
        $uEx = Unwrap-Exception $_.Exception
        $winErr = Get-Win32ErrorCode $uEx
        if ($winErr -ne 32) {
            throw "Suite 2: Expected Win32 error 32 for exclusive lease launch, got ${winErr}: $($uEx.Message)"
        }
        Write-Diagnostic "Suite 2: Confirmed old SourceFileLease (share 0) blocks launch with Win32 error 32: $($uEx.Message)"
    }
    if (-not $exclusiveStartBlocked) {
        throw "Suite 2: OwnedChildProcess.Start unexpectedly succeeded on file held with SourceFileLease share 0!"
    }

    $cErr = 0
    if ($sourceLease.TryClose([ref]$cErr)) { $sourceRec.Closed = $true }

    # 2.5 Signal helper to exit by removing gate file
    [System.IO.File]::Delete($suite2GateFile)

    # 2.6 Wait for child to become Dead and quiescent
    $obsDead = Wait-ForOwnerQuiescent -ownerRec $ownerRec -timeoutMs 10000
    Write-Diagnostic "Suite 2: Worker process confirmed DEAD and quiescent: RootState=$($obsDead.RootState), ActiveProcesses=$($obsDead.ActiveProcesses), IsQuiescent=$($obsDead.IsQuiescent)"

    # 2.7 Close leases independently after worker root death
    if (-not (Close-TrackedLease -leaseRec $recExe)) { throw "Suite 2: Failed to close EXE lease." }
    if (-not (Close-TrackedLease -leaseRec $recDll)) { throw "Suite 2: Failed to close DLL lease." }
    if (-not (Close-TrackedLease -leaseRec $recPhar)) { throw "Suite 2: Failed to close PHAR lease." }

    # 2.8 Stop and close owner
    if (-not (Stop-TrackedOwner -ownerRec $ownerRec -timeoutMs 5000)) {
        throw "Suite 2: Failed to close OwnedChildProcess owner."
    }

    Write-Diagnostic "Suite 2 passed cleanly."

    # =========================================================================
    # SUITE 3: Nonmutating open probes, write/delete blocked Win32 error 32, ancestor directory protection
    # =========================================================================
    Write-Diagnostic "=== SUITE 3: Probes, Sharing Violations, and Parent Directory Protection ==="
    $suite3Dir = [System.IO.Path]::Combine($labDir, "suite3")
    $imagesDir = [System.IO.Path]::Combine($suite3Dir, "images")
    $imagesMovedDir = [System.IO.Path]::Combine($suite3Dir, "images-moved")
    [void][System.IO.Directory]::CreateDirectory($imagesDir)

    $targetExe = [System.IO.Path]::Combine($imagesDir, "protected-app.exe")
    [System.IO.File]::Copy($compiledFixtureExePath, $targetExe, $true)
    $targetSize = [long][System.IO.FileInfo]::new($targetExe).Length
    $targetSha = Compute-Sha256OfFile $targetExe

    $lease3 = Safe-AcquireImageLease -path $targetExe -size $targetSize -sha256 $targetSha
    $rec3 = Register-TrackedLease -Lease $lease3 -Path $targetExe

    # 3.1 Concurrent READ open MUST SUCCEED
    $probeReadFs = $null
    try {
        $probeReadFs = [System.IO.File]::Open($targetExe, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read)
        [void](Register-TrackedStream $probeReadFs)
        if ($probeReadFs -eq $null -or $probeReadFs.Length -ne $targetSize) {
            throw "Suite 3: Concurrent read open probe returned invalid stream."
        }
    } finally {
        if ($probeReadFs -ne $null) { $probeReadFs.Dispose() }
    }
    Write-Diagnostic "Suite 3: Concurrent read open probe succeeded as expected."

    # 3.2 Probe write open MUST FAIL with Win32 error 32
    $unexpectedWriteFs = $null
    $writeBlocked = $false
    try {
        $unexpectedWriteFs = [System.IO.FileStream]::new($targetExe, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite)
    } catch {
        $writeBlocked = $true
        $uEx = Unwrap-Exception $_.Exception
        $code = Get-Win32ErrorCode $uEx
        if ($code -ne 32) {
            throw "Suite 3: Expected Win32 error 32 on write probe, got ${code}: $($uEx.Message)"
        }
    } finally {
        if ($unexpectedWriteFs -ne $null) {
            try { $unexpectedWriteFs.Dispose() } catch { }
        }
    }
    if (-not $writeBlocked) {
        throw "Suite 3: Write probe unexpectedly succeeded on leased file!"
    }
    Write-Diagnostic "Suite 3: Write open probe blocked with Win32 error 32 as expected."

    # 3.3 Probe write + delete share open MUST FAIL with Win32 error 32
    $unexpectedDelFs = $null
    $delShareBlocked = $false
    try {
        $unexpectedDelFs = [System.IO.FileStream]::new($targetExe, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite -bor [System.IO.FileShare]::Delete)
    } catch {
        $delShareBlocked = $true
        $uEx = Unwrap-Exception $_.Exception
        $code = Get-Win32ErrorCode $uEx
        if ($code -ne 32) {
            throw "Suite 3: Expected Win32 error 32 on delete-share probe, got ${code}: $($uEx.Message)"
        }
    } finally {
        if ($unexpectedDelFs -ne $null) {
            try { $unexpectedDelFs.Dispose() } catch { }
        }
    }
    if (-not $delShareBlocked) {
        throw "Suite 3: Delete-share write probe unexpectedly succeeded on leased file!"
    }
    Write-Diagnostic "Suite 3: Delete-share write probe blocked with Win32 error 32 as expected."

    # 3.4 Probe file rename MUST FAIL
    $renamedExe = [System.IO.Path]::Combine($imagesDir, "renamed-app.exe")
    $fileRenameBlocked = $false
    try {
        [System.IO.File]::Move($targetExe, $renamedExe)
    } catch {
        $fileRenameBlocked = $true
        $uEx = Unwrap-Exception $_.Exception
        $code = Get-Win32ErrorCode $uEx
        Write-Diagnostic "Suite 3: File rename blocked with error ${code}: $($uEx.Message)"
    }
    if (-not $fileRenameBlocked) {
        try { [System.IO.File]::Move($renamedExe, $targetExe) } catch { }
        throw "Suite 3: File rename unexpectedly succeeded on leased file!"
    }

    # 3.5 Probe file delete MUST FAIL
    $fileDeleteBlocked = $false
    try {
        [System.IO.File]::Delete($targetExe)
    } catch {
        $fileDeleteBlocked = $true
        $uEx = Unwrap-Exception $_.Exception
        $code = Get-Win32ErrorCode $uEx
        Write-Diagnostic "Suite 3: File delete blocked with error ${code}: $($uEx.Message)"
    }
    if (-not $fileDeleteBlocked) {
        throw "Suite 3: File delete unexpectedly succeeded on leased file!"
    }

    # 3.6 Probe parent directory rename MUST FAIL (due to directory handle share mode)
    $parentRenameBlocked = $false
    try {
        [System.IO.Directory]::Move($imagesDir, $imagesMovedDir)
    } catch {
        $parentRenameBlocked = $true
        $uEx = Unwrap-Exception $_.Exception
        $code = Get-Win32ErrorCode $uEx
        Write-Diagnostic "Suite 3: Parent directory rename blocked with error ${code}: $($uEx.Message)"
    }
    if (-not $parentRenameBlocked) {
        try { [System.IO.Directory]::Move($imagesMovedDir, $imagesDir) } catch { }
        throw "Suite 3: Parent directory rename unexpectedly succeeded while ancestor directory handle was held!"
    }

    # 3.7 Probe parent directory write/delete access handle MUST FAIL with Win32 error 32
    $dirWriteHandleBlocked = $false
    $unexpectedDirHandle = [IntPtr]::Zero
    try {
        $unexpectedDirHandle = [PosDesktop.Updates.Native.Testing.NativeLinkHelper]::CreateFileW(
            $imagesDir,
            0x0002, # FILE_ADD_FILE (directory write access)
            0x00000001 -bor 0x00000002, # FILE_SHARE_READ | FILE_SHARE_WRITE
            [IntPtr]::Zero,
            3, # OPEN_EXISTING
            0x02000000, # FILE_FLAG_BACKUP_SEMANTICS
            [IntPtr]::Zero
        )
        if ($unexpectedDirHandle -eq [IntPtr]::Zero -or $unexpectedDirHandle -eq [IntPtr]::new(-1)) {
            $dirWriteHandleBlocked = $true
            $err = [System.Runtime.InteropServices.Marshal]::GetLastWin32Error()
            if ($err -ne 32) {
                throw "Suite 3: Expected Win32 error 32 on directory write handle probe, got ${err}."
            }
            Write-Diagnostic "Suite 3: Conflicting directory write handle blocked with Win32 error 32."
        }
    } finally {
        if ($unexpectedDirHandle -ne [IntPtr]::Zero -and $unexpectedDirHandle -ne [IntPtr]::new(-1)) {
            [void][PosDesktop.Updates.Native.Testing.NativeLinkHelper]::CloseHandle($unexpectedDirHandle)
        }
    }
    if (-not $dirWriteHandleBlocked) {
        throw "Suite 3: Conflicting directory write handle unexpectedly succeeded while ancestor directory was held!"
    }
    Write-Diagnostic "Suite 3: Parent directory rename and conflicting write handle were blocked; directory rename is the real proof of namespace locking (non-empty delete 145 is not misattributed to lease lock)."

    # 3.8 Close lease and prove rename now succeeds (before/after unlocked success proof)
    if (-not (Close-TrackedLease -leaseRec $rec3)) { throw "Suite 3: Failed to close lease." }

    [System.IO.Directory]::Move($imagesDir, $imagesMovedDir)
    [System.IO.Directory]::Move($imagesMovedDir, $imagesDir)
    Write-Diagnostic "Suite 3: After lease closure, parent directory rename confirmed operational."

    Write-Diagnostic "Suite 3 passed cleanly."

    # =========================================================================
    # SUITE 4: Pre-existing writer/deleter blocks Acquire, coexisting leases, and CanApply
    # =========================================================================
    Write-Diagnostic "=== SUITE 4: Pre-existing Writers, Multiple Leases, and Mutation Absence ==="
    $suite4Dir = [System.IO.Path]::Combine($labDir, "suite4")
    $suite4ImagesDir = [System.IO.Path]::Combine($suite4Dir, "images")
    [void][System.IO.Directory]::CreateDirectory($suite4ImagesDir)

    $writerExe = [System.IO.Path]::Combine($suite4ImagesDir, "writer-target.exe")
    [System.IO.File]::Copy($compiledFixtureExePath, $writerExe, $true)
    $writerSize = [long][System.IO.FileInfo]::new($writerExe).Length
    $writerSha = Compute-Sha256OfFile $writerExe

    # 4.1 Pre-open with write access and full sharing
    $writerProbeFs = [System.IO.FileStream]::new($writerExe, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::ReadWrite -bor [System.IO.FileShare]::Delete)
    [void](Register-TrackedStream $writerProbeFs)

    $acquireBlockedByWriter = $false
    $unexpectedWriterLease = $null
    try {
        $unexpectedWriterLease = [PosDesktop.Updates.Native.WorkerImageReadLease]::Acquire($writerExe, $writerSize, $writerSha)
    } catch {
        $acquireBlockedByWriter = $true
        $uEx = Unwrap-Exception $_.Exception
        $code = Get-Win32ErrorCode $uEx
        if ($code -ne 32) {
            throw "Suite 4: Expected Win32 error 32 for Acquire on open writer, got ${code}: $($uEx.Message)"
        }
        Write-Diagnostic "Suite 4: Acquire blocked before hash due to existing writer with Win32 error 32: $($uEx.Message)"
    } finally {
        if ($unexpectedWriterLease -ne $null) {
            try {
                $cErr = 0
                [void]$unexpectedWriterLease.TryClose([ref]$cErr)
            } catch { }
        }
        $writerProbeFs.Dispose()
    }
    if (-not $acquireBlockedByWriter) {
        throw "Suite 4: Acquire unexpectedly succeeded while file had active writer!"
    }

    # 4.2 Pre-existing directory writer handle blocks Acquire
    $hDirWriter = [PosDesktop.Updates.Native.Testing.NativeLinkHelper]::CreateFileW(
        $suite4ImagesDir,
        0x0002, # FILE_ADD_FILE (directory write access)
        0x00000001 -bor 0x00000002 -bor 0x00000004, # FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE
        [IntPtr]::Zero,
        3, # OPEN_EXISTING
        0x02000000, # FILE_FLAG_BACKUP_SEMANTICS
        [IntPtr]::Zero
    )
    if ($hDirWriter -eq [IntPtr]::Zero -or $hDirWriter -eq [IntPtr]::new(-1)) {
        throw "Suite 4: Failed to open test directory writer handle."
    }

    $acquireBlockedByDirWriter = $false
    $unexpectedDirLease = $null
    try {
        $unexpectedDirLease = [PosDesktop.Updates.Native.WorkerImageReadLease]::Acquire($writerExe, $writerSize, $writerSha)
    } catch {
        $acquireBlockedByDirWriter = $true
        $uEx = Unwrap-Exception $_.Exception
        $code = Get-Win32ErrorCode $uEx
        if ($code -ne 32) {
            throw "Suite 4: Expected Win32 error 32 for Acquire on existing directory writer, got ${code}: $($uEx.Message)"
        }
        Write-Diagnostic "Suite 4: Acquire blocked before hash due to existing ancestor directory writer with Win32 error 32: $($uEx.Message)"
    } finally {
        if ($unexpectedDirLease -ne $null) {
            try {
                $cErr = 0
                [void]$unexpectedDirLease.TryClose([ref]$cErr)
            } catch { }
        }
        [void][PosDesktop.Updates.Native.Testing.NativeLinkHelper]::CloseHandle($hDirWriter)
    }
    if (-not $acquireBlockedByDirWriter) {
        throw "Suite 4: Acquire unexpectedly succeeded while ancestor directory had active writer handle!"
    }

    # 4.3 Close writer probes and prove Acquire now succeeds
    $legitLease = Safe-AcquireImageLease -path $writerExe -size $writerSize -sha256 $writerSha
    $legitRec = Register-TrackedLease -Lease $legitLease -Path $writerExe
    if (-not (Close-TrackedLease -leaseRec $legitRec)) { throw "Suite 4: Failed to close legit lease." }

    # 4.4 Coexisting READ leases
    $multiExe = [System.IO.Path]::Combine($suite4Dir, "multi-lease.exe")
    [System.IO.File]::Copy($compiledFixtureExePath, $multiExe, $true)
    $multiSize = [long][System.IO.FileInfo]::new($multiExe).Length
    $multiSha = Compute-Sha256OfFile $multiExe

    $leaseA = Safe-AcquireImageLease -path $multiExe -size $multiSize -sha256 $multiSha
    $recA = Register-TrackedLease -Lease $leaseA -Path $multiExe

    $leaseB = Safe-AcquireImageLease -path $multiExe -size $multiSize -sha256 $multiSha
    $recB = Register-TrackedLease -Lease $leaseB -Path $multiExe

    # Both leases open; write probe blocked
    $writeBlockedMulti1 = $false
    try {
        $fs = [System.IO.FileStream]::new($multiExe, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite)
        $fs.Dispose()
    } catch {
        $writeBlockedMulti1 = $true
    }
    if (-not $writeBlockedMulti1) { throw "Suite 4: Write probe succeeded while two leases held!" }

    # Close lease A; lease B still active; write probe STILL blocked
    if (-not (Close-TrackedLease -leaseRec $recA)) { throw "Suite 4: Failed to close lease A." }
    if (-not $leaseA.IsClosed) { throw "Suite 4: Lease A is not marked closed." }
    if ($leaseB.IsClosed) { throw "Suite 4: Lease B was unexpectedly marked closed." }

    $writeBlockedMulti2 = $false
    try {
        $fs = [System.IO.FileStream]::new($multiExe, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite)
        $fs.Dispose()
    } catch {
        $writeBlockedMulti2 = $true
    }
    if (-not $writeBlockedMulti2) { throw "Suite 4: Write probe succeeded while lease B was still held!" }

    # Close lease B; write probe now succeeds
    if (-not (Close-TrackedLease -leaseRec $recB)) { throw "Suite 4: Failed to close lease B." }
    $fsAfter = [System.IO.FileStream]::new($multiExe, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite)
    $fsAfter.Dispose()
    Write-Diagnostic "Suite 4: Coexisting leases independently protect target until ALL are closed."

    # 4.4 Validate CanApply false and absence of mutating APIs
    if ($legitLease.CanApply -ne $false) { throw "Suite 4: CanApply must be false." }
    $methodNames = @([PosDesktop.Updates.Native.WorkerImageReadLease].GetMethods([System.Reflection.BindingFlags]::Public -bor [System.Reflection.BindingFlags]::Instance -bor [System.Reflection.BindingFlags]::Static) | ForEach-Object { $_.Name })
    foreach ($m in $methodNames) {
        if ($m -match 'Write|Rename|Delete|Copy|MarkForDeletion|CreateNewVerified') {
            throw "Suite 4: WorkerImageReadLease exposes unauthorized mutation method: $m"
        }
    }
    Write-Diagnostic "Suite 4: Confirmed CanApply is false and zero mutating APIs exposed."

    Write-Diagnostic "Suite 4 passed cleanly."

    # =========================================================================
    # SUITE 5: Reparse file, ancestor junction, hardlink count 2, and 8.3 alias check
    # =========================================================================
    Write-Diagnostic "=== SUITE 5: Reparse Points, Ancestor Junctions, Hardlinks, and 8.3 Aliases ==="
    $suite5Dir = [System.IO.Path]::Combine($labDir, "suite5")
    [void][System.IO.Directory]::CreateDirectory($suite5Dir)

    # 5.1 Hardlink link count 2 rejection
    $hardOrig = [System.IO.Path]::Combine($suite5Dir, "hardlink-orig.exe")
    $hardLink = [System.IO.Path]::Combine($suite5Dir, "hardlink-link.exe")
    [System.IO.File]::Copy($compiledFixtureExePath, $hardOrig, $true)
    $hardSize = [long][System.IO.FileInfo]::new($hardOrig).Length
    $hardSha = Compute-Sha256OfFile $hardOrig

    $linkCreated = [PosDesktop.Updates.Native.Testing.NativeLinkHelper]::CreateHardLinkW($hardLink, $hardOrig, [IntPtr]::Zero)
    if (-not $linkCreated) {
        throw "Suite 5: Failed to create test hardlink via CreateHardLinkW."
    }

    $hardOrigBlocked = $false
    try {
        [void][PosDesktop.Updates.Native.WorkerImageReadLease]::Acquire($hardOrig, $hardSize, $hardSha)
    } catch [System.InvalidOperationException] {
        $hardOrigBlocked = $true
        Write-Diagnostic "Suite 5: Acquire on file with link count 2 rejected as expected: $($_.Exception.Message)"
    }
    if (-not $hardOrigBlocked) { throw "Suite 5: Acquire unexpectedly succeeded on file with 2 hard links!" }

    $hardLinkBlocked = $false
    try {
        [void][PosDesktop.Updates.Native.WorkerImageReadLease]::Acquire($hardLink, $hardSize, $hardSha)
    } catch [System.InvalidOperationException] {
        $hardLinkBlocked = $true
    }
    if (-not $hardLinkBlocked) { throw "Suite 5: Acquire unexpectedly succeeded on hardlink path!" }

    # Remove hardlink and prove single link succeeds
    [System.IO.File]::Delete($hardLink)
    $singleLease = Safe-AcquireImageLease -path $hardOrig -size $hardSize -sha256 $hardSha
    $singleRec = Register-TrackedLease -Lease $singleLease -Path $hardOrig
    if (-not (Close-TrackedLease -leaseRec $singleRec)) { throw "Suite 5: Failed to close single link lease." }
    Write-Diagnostic "Suite 5: Exact single link count proof passed."

    # 5.2 Ancestor directory junction rejection
    $realTargetDir = [System.IO.Path]::Combine($suite5Dir, "real-ancestor")
    $junctionDir = [System.IO.Path]::Combine($suite5Dir, "junc-ancestor")
    [void][System.IO.Directory]::CreateDirectory($realTargetDir)

    $junctionFile = [System.IO.Path]::Combine($realTargetDir, "junction-target.exe")
    [System.IO.File]::Copy($compiledFixtureExePath, $junctionFile, $true)
    $juncSize = [long][System.IO.FileInfo]::new($junctionFile).Length
    $juncSha = Compute-Sha256OfFile $junctionFile

    # Create junction inside private lab
    $juncItem = New-Item -ItemType Junction -Path $junctionDir -Target $realTargetDir
    if ($juncItem -eq $null) { throw "Suite 5: Failed to create test junction." }

    $juncFilePath = [System.IO.Path]::Combine($junctionDir, "junction-target.exe")
    $junctionBlocked = $false
    try {
        [void][PosDesktop.Updates.Native.WorkerImageReadLease]::Acquire($juncFilePath, $juncSize, $juncSha)
    } catch [System.InvalidOperationException] {
        $junctionBlocked = $true
        Write-Diagnostic "Suite 5: Acquire on junction ancestor rejected as expected: $($_.Exception.Message)"
    }
    if (-not $junctionBlocked) { throw "Suite 5: Acquire unexpectedly succeeded through ancestor junction!" }

    # Clean up junction without traversing real directory
    [System.IO.Directory]::Delete($junctionDir)
    if (-not [System.IO.File]::Exists($junctionFile)) {
        throw "Suite 5: Target file in real directory was unexpectedly removed during junction deletion!"
    }
    Write-Diagnostic "Suite 5: Reparse point ancestor rejection passed."

    # 5.3 8.3 short-name alias test
    $shortPath = (cmd.exe /c "for %I in (`"$junctionFile`") do @echo %~sI").Trim()
    if (-not [string]::IsNullOrEmpty($shortPath) -and $shortPath.Contains("~") -and -not [string]::Equals($shortPath, $junctionFile, [System.StringComparison]::OrdinalIgnoreCase)) {
        Write-Diagnostic "Suite 5: 8.3 short path available: $shortPath"
        $shortBlocked = $false
        try {
            [void][PosDesktop.Updates.Native.WorkerImageReadLease]::Acquire($shortPath, $juncSize, $juncSha)
        } catch {
            $shortBlocked = $true
            Write-Diagnostic "Suite 5: 8.3 short path rejected as expected: $($_.Exception.Message)"
        }
        if (-not $shortBlocked) {
            throw "Suite 5: Acquire unexpectedly succeeded with 8.3 alias path!"
        }
    } else {
        Write-Diagnostic "Suite 5: 8.3 short-name generation is disabled on this volume. Runtime 8.3 alias rejection cannot be exercised without 8.3 names; no alias coverage claimed."
    }

    Write-Diagnostic "Suite 5 passed cleanly."

    # =========================================================================
    # SUITE 6: Failure cleanup, reentrancy, invalid arguments, victim witness proof
    # =========================================================================
    Write-Diagnostic "=== SUITE 6: Failure Cleanup, Invalid Arguments, and Victim Tree Witness ==="
    $suite6Dir = [System.IO.Path]::Combine($labDir, "suite6")
    [void][System.IO.Directory]::CreateDirectory($suite6Dir)

    $suite6File = [System.IO.Path]::Combine($suite6Dir, "probe-file.dat")
    [System.IO.File]::WriteAllBytes($suite6File, [System.Text.Encoding]::UTF8.GetBytes("SUITE_6_DATA_$nonce"))
    $s6Size = [long][System.IO.FileInfo]::new($suite6File).Length
    $s6Sha = Compute-Sha256OfFile $suite6File

    # 6.1 Failed Acquire cleans up all ancestor and file handles (proves no handle leak on failure)
    $failedAcquireClean = $false
    try {
        [void][PosDesktop.Updates.Native.WorkerImageReadLease]::Acquire($suite6File, $s6Size, "1111111111111111111111111111111111111111111111111111111111111111")
    } catch [System.InvalidOperationException] {
        $failedAcquireClean = $true
    }
    if (-not $failedAcquireClean) { throw "Suite 6: Acquire did not throw InvalidOperationException on SHA mismatch." }

    # Prove file can immediately be opened for write (verifying handles were closed cleanly on failure)
    $postFailWriteFs = [System.IO.FileStream]::new($suite6File, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
    $postFailWriteFs.Dispose()
    Write-Diagnostic "Suite 6: Confirmed no leaked handles after failed Acquire."

    # 6.2 Invalid ReadRange arguments
    $lease6 = Safe-AcquireImageLease -path $suite6File -size $s6Size -sha256 $s6Sha
    $rec6 = Register-TrackedLease -Lease $lease6 -Path $suite6File

    # Negative offset
    $negOffsetBlocked = $false
    try { [void]$lease6.ReadRange(-1, 5) } catch [System.ArgumentOutOfRangeException] { $negOffsetBlocked = $true }
    if (-not $negOffsetBlocked) { throw "Suite 6: Negative offset did not throw ArgumentOutOfRangeException." }

    # Negative count
    $negCountBlocked = $false
    try { [void]$lease6.ReadRange(0, -1) } catch [System.ArgumentOutOfRangeException] { $negCountBlocked = $true }
    if (-not $negCountBlocked) { throw "Suite 6: Negative count did not throw ArgumentOutOfRangeException." }

    # Count > 1 MiB
    $largeCountBlocked = $false
    try { [void]$lease6.ReadRange(0, 1048577) } catch [System.ArgumentOutOfRangeException] { $largeCountBlocked = $true }
    if (-not $largeCountBlocked) { throw "Suite 6: Count > 1 MiB did not throw ArgumentOutOfRangeException." }

    # Offset extending beyond size
    $overflowBlocked = $false
    try { [void]$lease6.ReadRange($s6Size + 1, 0) } catch [System.ArgumentOutOfRangeException] { $overflowBlocked = $true }
    if (-not $overflowBlocked) { throw "Suite 6: Offset > size did not throw ArgumentOutOfRangeException." }

    # Range extending past EOF
    $rangePastEofBlocked = $false
    try { [void]$lease6.ReadRange($s6Size - 2, 5) } catch [System.ArgumentOutOfRangeException] { $rangePastEofBlocked = $true }
    if (-not $rangePastEofBlocked) { throw "Suite 6: Range past EOF did not throw ArgumentOutOfRangeException." }

    if (-not (Close-TrackedLease -leaseRec $rec6)) { throw "Suite 6: Failed to close lease 6." }

    # 6.3 Fact-based reporting on fault injection limitations
    Write-Diagnostic "Suite 6: CloseHandle failure, cryptographic provider initialization failure, and disposal fault injection were NOT TESTED. User-mode injectable fault models are possible but outside the scope of this verification suite; compiler success does not prove exception handling paths."

    # 6.4 Protected victim integrity proof
    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "Suite 6: Protected victim witness tree confirmed completely untouched."

    $script:AllSuitesPassed = $true
    Write-Diagnostic "All 6 suites passed successfully!"

} catch {
    $script:PrimaryError = $_.Exception
    Write-Diagnostic "FATAL TEST FAILURE: $($_.Exception.Message)`r`n$($_.ScriptStackTrace)"
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
            Write-Diagnostic "Teardown safety hold: owner ($($ownerRec.Tag)) is NOT confirmed Dead and quiescent (RootState=$($obs.RootState), JobCountKnown=$($obs.JobCountKnown), ActiveProcesses=$($obs.ActiveProcesses), IsQuiescent=$($obs.IsQuiescent)). Image and source leases MUST NOT be unlocked!"
            $allOwnersProvenDeadAndQuiescent = $false
            break
        }
    }

    # 3. Only close image and source leases IF all owners are proven Dead + quiescent
    if ($allOwnersProvenDeadAndQuiescent) {
        Close-AllTrackedLeases
        Close-AllTrackedSourceLeases
    } else {
        Write-Diagnostic "Teardown safety hold active: retaining all image and source leases locked because owned child processes were not proven dead/quiescent."
    }

    # 4. Close independent streams (safe to close)
    Close-AllTrackedStreams

    # 5. Execute safe cleanup safety gate
    $targetSpecs = @(
        [PSObject]@{ Path = $labDir; MarkerName = ".img-lease-lab-marker"; Nonce = $nonce },
        [PSObject]@{ Path = $victimDir; MarkerName = ".img-lease-victim-marker"; Nonce = $nonce }
    )

    $gateResult = Invoke-SharedCleanupSafetyGate -TargetSpecs $targetSpecs

    if ($gateResult -ne 'CLEANED') {
        Write-Diagnostic "SAFETY GATE DENIAL: Teardown gate returned '$gateResult'. LAB RETAINED nonzero."
        if ($script:PrimaryError -ne $null) {
            Write-Diagnostic "Primary test error recorded prior to safety gate denial: $($script:PrimaryError.Message)"
        }
        exit 2
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
