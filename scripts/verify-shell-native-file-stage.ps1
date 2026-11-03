# scripts/verify-shell-native-file-stage.ps1
# Win32 Native Verified CREATE_NEW Target Staging Slice Verifier
# Verifies exclusive new-file staging, copy verification, and retention semantics.

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

# --- Global Registries and State Tracking (Must exist before first victim snapshot / stream creation, never reset) ---
$script:TrackedOwners = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedLeases = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedStreams = [System.Collections.Generic.List[System.IO.Stream]]::new()
$script:OwnerStopErrors = [System.Collections.Generic.List[string]]::new()
$script:StreamCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:LeaseCloseErrors = [System.Collections.Generic.List[string]]::new()
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
        $p = "unknown-lease-path"
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
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfs-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfs-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
[void][System.IO.Directory]::CreateDirectory($labDir)
[void][System.IO.Directory]::CreateDirectory($labTempDir)
[void][System.IO.Directory]::CreateDirectory($victimDir)

$labMarkerPath = [System.IO.Path]::Combine($labDir, ".snfs-lab-marker")
[System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".snfs-victim-marker")
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

function Invoke-SharedCleanupSafetyGate {
    param([array]$TargetSpecs = $null, [scriptblock]$RestorationCallback = $null)

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

# --- Section 4: Safe Process and Lease Invocation Wrappers ---
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

function Invoke-TrackedCreation {
    param(
        [scriptblock]$Action,
        [string]$ExpectedPath = $null
    )
    if ($Action -eq $null) { throw "Action scriptblock is required for Invoke-TrackedCreation." }
    $result = $null
    try {
        $result = & $Action
        if ($result -ne $null -and ($result -is [PosDesktop.Updates.Native.SourceFileLease])) {
            [void](Register-TrackedLease -Lease $result -Path $ExpectedPath)
        }
        return $result
    } catch {
        $curr = $_.Exception
        $retained = $null
        $stagePath = $ExpectedPath
        $depth = 0
        while ($curr -ne $null -and $depth -lt 10) {
            if ($curr -is [PosDesktop.Updates.Native.SourceFileLeaseCreationException]) {
                $retained = $curr.RetainedLease
                if (-not [string]::IsNullOrEmpty($curr.StagePath)) {
                    $stagePath = $curr.StagePath
                }
                break
            }
            $curr = $curr.InnerException
            $depth++
        }
        if ($retained -ne $null) {
            [void](Register-TrackedLease -Lease $retained -Path $stagePath)
        }
        throw
    }
}

function Safe-CreateNewVerifiedLease {
    param([string]$path, [long]$size, [string]$sha256, [System.IO.Stream]$SourceStream)
    $lease = Invoke-TrackedCreation -ExpectedPath $path -Action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($path, $size, $sha256, $SourceStream)
    }
    if ($lease -eq $null) {
        throw "CreateNewVerified returned null lease for path: $path"
    }
    return $lease
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

function Assert-RetainedCreationLease {
    param(
        $creationEx,
        [string]$expectedStagePath,
        [type]$expectedInnerType,
        [long]$expectedPartialBytesOnDisk = -1,
        [byte[]]$expectedPrefixBytes = $null
    )
    if ($creationEx -eq $null) { throw "Expected SourceFileLeaseCreationException, got null." }
    $retained = $creationEx.RetainedLease
    if ($retained -eq $null) { throw "RetainedLease is null on SourceFileLeaseCreationException." }

    # Ensure retainer is registered (Register-TrackedLease dedupes by object reference)
    $leaseRec = Register-TrackedLease -Lease $retained -Path $expectedStagePath

    if (-not [string]::Equals($creationEx.StagePath, $expectedStagePath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "StagePath mismatch: expected '$expectedStagePath', got '$($creationEx.StagePath)'"
    }

    $inner = $creationEx.InnerException
    if ($inner -eq $null) { throw "InnerException is null on SourceFileLeaseCreationException." }
    if ($null -ne $expectedInnerType -and -not $expectedInnerType.IsAssignableFrom($inner.GetType())) {
        throw "InnerException type mismatch: expected $($expectedInnerType.FullName), got $($inner.GetType().FullName): $($inner.Message)"
    }

    # Verify lease is retained OPEN, mutation uncertain, acquisition not validated
    if ($retained.IsClosed) { throw "Retained lease IsClosed must be false." }
    if (-not $retained.IsMutationUncertain) { throw "Retained lease IsMutationUncertain must be true." }

    # Zero Read/Hash/Copy/Rename ALL BLOCKED
    $readBlocked = $false
    try { [void]$retained.ReadRange(0, 0) } catch [System.InvalidOperationException] { $readBlocked = $true }
    if (-not $readBlocked) { throw "ReadRange was not blocked on retained lease!" }

    $hashBlocked = $false
    try { [void]$retained.ComputeSha256() } catch [System.InvalidOperationException] { $hashBlocked = $true }
    if (-not $hashBlocked) { throw "ComputeSha256 was not blocked on retained lease!" }

    $copyBlocked = $false
    $dummyDest = [System.IO.MemoryStream]::new()
    [void](Register-TrackedStream $dummyDest)
    try { [void]$retained.CopyVerifiedTo($dummyDest) } catch [System.InvalidOperationException] { $copyBlocked = $true }
    if (-not $copyBlocked) { throw "CopyVerifiedTo was not blocked on retained lease!" }

    $renameBlocked = $false
    try { [void]$retained.RenameWithinDirectory("dummy-rename.dat") } catch [System.InvalidOperationException] { $renameBlocked = $true }
    if (-not $renameBlocked) { throw "RenameWithinDirectory was not blocked on retained lease!" }

    # Stage path retained on disk (not deleted!)
    if (-not [System.IO.File]::Exists($expectedStagePath)) {
        throw "Staging file was unexpectedly deleted or does not exist at: $expectedStagePath"
    }

    # Prove open native lease: File.OpenRead MUST fail with actual native Win32 sharing violation 32
    $sharingViolationSeen = $false
    $unexpectedProbeFs = $null
    try {
        $unexpectedProbeFs = [System.IO.File]::Open($expectedStagePath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read)
        [void](Register-TrackedStream $unexpectedProbeFs)
        throw "File open probe unexpectedly succeeded on held retained lease path: $expectedStagePath"
    } catch {
        if ($unexpectedProbeFs -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.IO.IOException]) {
            $errCode = Get-Win32ErrorCode $uEx
            if ($errCode -eq 32) {
                $sharingViolationSeen = $true
            } else {
                throw "File open probe on retained stage path threw IOException with unexpected Win32 error ${errCode}: $($uEx.Message)"
            }
        } else {
            throw "File open probe on retained stage path threw unexpected exception: $($uEx.GetType().FullName)"
        }
    }
    if (-not $sharingViolationSeen) {
        throw "Retained staging file did not hold exclusive lock (Win32 error 32 not observed) on: $expectedStagePath"
    }

    # Clean explicit TryClose 0
    $cErr = 0
    $closeOk = $retained.TryClose([ref]$cErr)
    if (-not $closeOk -or $cErr -ne 0 -or -not $retained.IsClosed) {
        throw "TryClose failed on retained lease: ok=$closeOk, err=$cErr, IsClosed=$($retained.IsClosed)"
    }
    $leaseRec.Closed = $true

    # Prove file bytes partial retained on disk after handle close
    if ($expectedPartialBytesOnDisk -ge 0) {
        $actualLen = [System.IO.FileInfo]::new($expectedStagePath).Length
        if ($actualLen -ne $expectedPartialBytesOnDisk) {
            throw "Retained file length on disk mismatch: expected $expectedPartialBytesOnDisk, got $actualLen"
        }
    }

    # Prove exact byte prefix on disk after handle close
    if ($null -ne $expectedPrefixBytes) {
        $diskBytes = [System.IO.File]::ReadAllBytes($expectedStagePath)
        if ($diskBytes.Length -ne $expectedPrefixBytes.Length) {
            throw "Retained file byte count on disk mismatch: expected $($expectedPrefixBytes.Length), got $($diskBytes.Length)"
        }
        for ($i = 0; $i -lt $expectedPrefixBytes.Length; $i++) {
            if ($diskBytes[$i] -ne $expectedPrefixBytes[$i]) {
                throw "Retained file byte mismatch at offset ${i}: expected $($expectedPrefixBytes[$i]), got $($diskBytes[$i])"
            }
        }
    }
}

# --- Section 5: Synchronous Unified Single-Unit Compilation ---
$origTemp = $env:TEMP; $origTmp = $env:TMP
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $scriptDir ".."))
$ownedChildCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\OwnedChildProcess.cs")
$sourceFileLeaseCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\SourceFileLease.cs")

if (-not [System.IO.File]::Exists($ownedChildCsPath)) { throw "OwnedChildProcess.cs missing at: $ownedChildCsPath" }
if (-not [System.IO.File]::Exists($sourceFileLeaseCsPath)) { throw "SourceFileLease.cs missing at: $sourceFileLeaseCsPath" }

$ownedChildSource = [System.IO.File]::ReadAllText($ownedChildCsPath, [System.Text.Encoding]::UTF8)
$sourceFileLeaseSource = [System.IO.File]::ReadAllText($sourceFileLeaseCsPath, [System.Text.Encoding]::UTF8)

$streamHelpersSource = @"
namespace PosDesktop.Updates.Native.Testing
{
    using System;
    using System.IO;
    using PosDesktop.Updates.Native;

    public static class SourceFileLeaseTestBridge
    {
        public static SourceFileLease CreateNewVerifiedNullPath(long expectedSize, string sha256, Stream input)
        {
            return SourceFileLease.CreateNewVerified((string)null, expectedSize, sha256, input);
        }

        public static SourceFileLease CreateNewVerifiedNullSha(string path, long expectedSize, Stream input)
        {
            return SourceFileLease.CreateNewVerified(path, expectedSize, (string)null, input);
        }

        public static SourceFileLease CreateNewVerifiedNullStream(string path, long expectedSize, string sha256)
        {
            return SourceFileLease.CreateNewVerified(path, expectedSize, sha256, (Stream)null);
        }
    }

    public sealed class CustomTestStreamException : Exception
    {
        public CustomTestStreamException(string message) : base(message) { }
    }

    public sealed class NonSeekableShortReadStream : Stream
    {
        private readonly byte[] _data;
        private readonly int _maxChunkSize;
        private int _position;
        private int _readCallCount;
        private int _seekAttemptCount;
        private int _lengthAccessCount;
        private int _disposeAttemptCount;
        private bool _isDisposed;

        public NonSeekableShortReadStream(byte[] data, int maxChunkSize)
        {
            if (data == null) throw new ArgumentNullException("data");
            this._data = (byte[])data.Clone();
            this._maxChunkSize = maxChunkSize > 0 ? maxChunkSize : 1024;
            this._position = 0;
            this._readCallCount = 0;
            this._seekAttemptCount = 0;
            this._lengthAccessCount = 0;
            this._disposeAttemptCount = 0;
            this._isDisposed = false;
        }

        public int ReadCallCount { get { return this._readCallCount; } }
        public int SeekAttemptCount { get { return this._seekAttemptCount; } }
        public int LengthAccessCount { get { return this._lengthAccessCount; } }
        public int DisposeAttemptCount { get { return this._disposeAttemptCount; } }
        public bool IsStreamDisposed { get { return this._isDisposed; } }

        public override bool CanRead { get { return !this._isDisposed; } }
        public override bool CanSeek { get { return false; } }
        public override bool CanWrite { get { return false; } }

        public override long Length
        {
            get
            {
                this._lengthAccessCount++;
                throw new NotSupportedException("Length is not supported on non-seekable stream.");
            }
        }

        public override long Position
        {
            get
            {
                this._seekAttemptCount++;
                throw new NotSupportedException("Position is not supported on non-seekable stream.");
            }
            set
            {
                this._seekAttemptCount++;
                throw new NotSupportedException("Position is not supported on non-seekable stream.");
            }
        }

        public override long Seek(long offset, SeekOrigin origin)
        {
            this._seekAttemptCount++;
            throw new NotSupportedException("Seek is not supported on non-seekable stream.");
        }

        public override void SetLength(long value)
        {
            throw new NotSupportedException("SetLength is not supported.");
        }

        public override void Write(byte[] buffer, int offset, int count)
        {
            throw new NotSupportedException("Write is not supported.");
        }

        public override void Flush() { }

        public override int Read(byte[] buffer, int offset, int count)
        {
            if (buffer == null) throw new ArgumentNullException("buffer");
            if (offset < 0 || count < 0 || offset + count > buffer.Length) throw new ArgumentOutOfRangeException();
            this._readCallCount++;

            if (this._position >= this._data.Length)
            {
                return 0; // EOF
            }

            int toRead = Math.Min(count, Math.Min(this._maxChunkSize, this._data.Length - this._position));
            Array.Copy(this._data, this._position, buffer, offset, toRead);
            this._position += toRead;
            return toRead;
        }

        protected override void Dispose(bool disposing)
        {
            this._disposeAttemptCount++;
            this._isDisposed = true;
            base.Dispose(disposing);
        }
    }

    public enum FaultMode
    {
        None = 0,
        ThrowMidRead = 1,
        NegativeReadCount = 2,
        ExceedsRequestedReadCount = 3,
        Unreadable = 4
    }

    public sealed class FaultingReadStream : Stream
    {
        private readonly byte[] _data;
        private readonly FaultMode _mode;
        private readonly int _failOnCallNumber;
        private readonly int _chunkSize;
        private int _callCount;
        private int _position;
        private bool _isDisposed;

        public FaultingReadStream(byte[] data, FaultMode mode, int failOnCallNumber = 1, int chunkSize = 65536)
        {
            this._data = data != null ? (byte[])data.Clone() : new byte[0];
            this._mode = mode;
            this._failOnCallNumber = failOnCallNumber;
            this._chunkSize = chunkSize > 0 ? chunkSize : 65536;
            this._callCount = 0;
            this._position = 0;
            this._isDisposed = false;
        }

        public int ReadCallCount { get { return this._callCount; } }
        public bool IsStreamDisposed { get { return this._isDisposed; } }

        public override bool CanRead { get { return !this._isDisposed && this._mode != FaultMode.Unreadable; } }
        public override bool CanSeek { get { return false; } }
        public override bool CanWrite { get { return false; } }
        public override long Length { get { throw new NotSupportedException(); } }
        public override long Position { get { throw new NotSupportedException(); } set { throw new NotSupportedException(); } }
        public override long Seek(long offset, SeekOrigin origin) { throw new NotSupportedException(); }
        public override void SetLength(long value) { throw new NotSupportedException(); }
        public override void Write(byte[] buffer, int offset, int count) { throw new NotSupportedException(); }
        public override void Flush() { }

        public override int Read(byte[] buffer, int offset, int count)
        {
            if (buffer == null) throw new ArgumentNullException("buffer");
            if (offset < 0 || count < 0 || offset + count > buffer.Length) throw new ArgumentOutOfRangeException();
            this._callCount++;

            if (this._callCount >= this._failOnCallNumber)
            {
                switch (this._mode)
                {
                    case FaultMode.ThrowMidRead:
                        throw new CustomTestStreamException("Simulated mid-stream failure at call " + this._callCount);
                    case FaultMode.NegativeReadCount:
                        return -1;
                    case FaultMode.ExceedsRequestedReadCount:
                        return count + 10;
                    case FaultMode.Unreadable:
                        throw new InvalidOperationException("Stream is unreadable.");
                }
            }

            if (this._position >= this._data.Length)
            {
                return 0; // EOF
            }

            int toRead = Math.Min(count, Math.Min(this._chunkSize, this._data.Length - this._position));
            Array.Copy(this._data, this._position, buffer, offset, toRead);
            this._position += toRead;
            return toRead;
        }

        protected override void Dispose(bool disposing)
        {
            this._isDisposed = true;
            base.Dispose(disposing);
        }
    }
}
"@

$fixtureProgramSource = @"
namespace PosDesktop.Updates.Native.Testing
{
    using System;
    using System.Threading;

    public static class FixtureProgram
    {
        public static int Main(string[] args)
        {
            if (args.Length > 0 && string.Equals(args[0], "sleep", StringComparison.OrdinalIgnoreCase))
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
            return 0;
        }
    }
}
"@

$fixtureExeName = "shell-test-staging-fixture-x64.exe"
$fixtureExePath = [System.IO.Path]::Combine($labDir, $fixtureExeName)

try {
    $script:CompilationUncertain = $true
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir
    try {
        # Unit 1: Add-Type parent in-memory types
        $parentCombinedSource = $sourceFileLeaseSource + "`r`n" + $ownedChildSource + "`r`n" + $streamHelpersSource
        Add-Type -TypeDefinition $parentCombinedSource
        Write-Diagnostic "Successfully compiled parent in-memory types via Add-Type."

        # Unit 2: Compile console fixture executable (x64) using CodeDom CSharpCodeProvider
        $codeProvider = [Microsoft.CSharp.CSharpCodeProvider]::new()
        $compilePrimaryEx = $null
        $disposeEx = $null
        try {
            $params64 = [System.CodeDom.Compiler.CompilerParameters]::new()
            $params64.GenerateExecutable = $true
            $params64.GenerateInMemory = $false
            $params64.OutputAssembly = $fixtureExePath
            $params64.CompilerOptions = "/platform:x64"
            [void]$params64.ReferencedAssemblies.Add("System.dll")

            $results64 = $codeProvider.CompileAssemblyFromSource($params64, @($fixtureProgramSource))
            if ($results64.NativeCompilerReturnValue -ne 0 -or $results64.Errors.HasErrors) {
                $errMsgs = @()
                foreach ($err in $results64.Errors) { $errMsgs += $err.ToString() }
                throw [System.InvalidOperationException]::new("x64 Fixture compilation failed: $($errMsgs -join '; ')")
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

        Write-Diagnostic "Successfully compiled x64 console fixture: $fixtureExePath"
        $script:CompilationUncertain = $false
    } finally {
        $env:TEMP = $origTemp; $env:TMP = $origTmp
    }

    if (-not [System.IO.File]::Exists($fixtureExePath)) {
        throw "Fixture compilation failed: x64 binary missing at $fixtureExePath"
    }

    $fixtureSha = Compute-Sha256OfFile $fixtureExePath
    $fixtureLen = [System.IO.FileInfo]::new($fixtureExePath).Length
    Write-Diagnostic "Fixture ready: $fixtureLen bytes, SHA: $fixtureSha."

    # Decoder unit control 1: generic IOException (COR_E_IO 0x80131620) must decode to -1 (not low 16 bits)
    $genericIoEx = [System.IO.IOException]::new("control")
    $genericCode = Get-Win32ErrorCode $genericIoEx
    if ($genericCode -ne -1) {
        throw "Get-Win32ErrorCode on generic IOException returned $genericCode instead of -1!"
    }

    # Decoder unit control 2: actual nonexistent file path in existing parent labDir must throw typed IOException with Win32 code 2
    $nonexistentControlPath = [System.IO.Path]::Combine($labDir, "control-nonexistent-$nonce.dat")
    if ([System.IO.File]::Exists($nonexistentControlPath)) {
        throw "Unexpected file exists at nonexistent control path: $nonexistentControlPath"
    }
    $fnfCodeSeen = $false
    $unexpectedControlStream = $null
    try {
        $unexpectedControlStream = [System.IO.File]::OpenRead($nonexistentControlPath)
        [void](Register-TrackedStream $unexpectedControlStream)
        throw "File.OpenRead on nonexistent control path unexpectedly succeeded!"
    } catch {
        if ($unexpectedControlStream -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.IO.IOException]) {
            $fnfCode = Get-Win32ErrorCode $uEx
            if ($fnfCode -eq 2) {
                $fnfCodeSeen = $true
            } else {
                throw "File.OpenRead on nonexistent path returned unexpected Win32 code ${fnfCode}: $($uEx.Message)"
            }
        } else {
            throw "File.OpenRead on nonexistent path threw unexpected exception type: $($uEx.GetType().FullName)"
        }
    }
    if (-not $fnfCodeSeen) {
        throw "File.OpenRead on nonexistent control path did not observe Win32 error 2!"
    }

    $envMap = [System.Collections.Generic.Dictionary[string, string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    $envMap["SystemRoot"] = $env:SystemRoot
    $envMap["WINDIR"] = $env:WINDIR
    $envMap["TEMP"] = $labTempDir
    $envMap["TMP"] = $labTempDir

    # =========================================================================
    # --- SUITE 1: Fresh Staging Payload Success with Nonseekable Short Read ---
    # =========================================================================
    Write-Diagnostic "--- Running Suite 1: Fresh Staging Payload Success with Nonseekable Short Read ---"

    $suite1PayloadLen = 143417 # multi 64k (65536 * 2 + 12345)
    $suite1Payload = [byte[]]::new($suite1PayloadLen)
    $rng = [System.Random]::new(12345)
    $rng.NextBytes($suite1Payload)

    $shaInstance = [System.Security.Cryptography.SHA256]::Create()
    $suite1HashBytes = $shaInstance.ComputeHash($suite1Payload)
    $shaInstance.Dispose()
    $sb = [System.Text.StringBuilder]::new($suite1HashBytes.Length * 2)
    foreach ($b in $suite1HashBytes) { [void]$sb.Append($b.ToString("x2")) }
    $suite1ExpectedSha = $sb.ToString()

    $suite1Stream = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new($suite1Payload, 4093)
    [void](Register-TrackedStream $suite1Stream)

    $suite1StagePath = [System.IO.Path]::Combine($labDir, "suite1-staged.dat")
    $lease1 = Safe-CreateNewVerifiedLease -path $suite1StagePath -size $suite1PayloadLen -sha256 $suite1ExpectedSha -SourceStream $suite1Stream

    # Check caller stream state
    if ($suite1Stream.IsStreamDisposed) { throw "Caller input stream was disposed during CreateNewVerified!" }
    if (-not $suite1Stream.CanRead) { throw "Caller input stream CanRead is false!" }
    if ($suite1Stream.SeekAttemptCount -ne 0) { throw "Caller input stream was seeked ($($suite1Stream.SeekAttemptCount) times)!" }
    if ($suite1Stream.LengthAccessCount -ne 0) { throw "Caller input stream Length was accessed ($($suite1Stream.LengthAccessCount) times)!" }
    if ($suite1Stream.DisposeAttemptCount -ne 0) { throw "Caller input stream Dispose was called ($($suite1Stream.DisposeAttemptCount) times)!" }
    if ($suite1Stream.ReadCallCount -le 1) { throw "Expected ReadCallCount > 1, got $($suite1Stream.ReadCallCount)!" }
    # 143417 / 4093 = 35 full chunks + 1 partial chunk (152 bytes) + 1 EOF probe = 37 calls
    if ($suite1Stream.ReadCallCount -lt 37) { throw "Expected at least 37 read calls including EOF probe, got $($suite1Stream.ReadCallCount)!" }

    # Check returned lease properties
    if ($lease1.IsClosed) { throw "Returned lease IsClosed must be false." }
    if ($lease1.IsMutationUncertain) { throw "Returned lease IsMutationUncertain must be false." }
    if ($lease1.Identity -eq $null) { throw "Returned lease Identity is null." }
    if ($lease1.Identity.Size -ne $suite1PayloadLen) { throw "Identity.Size mismatch: expected $suite1PayloadLen, got $($lease1.Identity.Size)" }
    $expectedCanonical1 = [System.IO.Path]::GetFullPath($suite1StagePath)
    if (-not [string]::Equals($lease1.Identity.CanonicalPath, $expectedCanonical1, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Identity.CanonicalPath mismatch: expected '$expectedCanonical1', got '$($lease1.Identity.CanonicalPath)'"
    }
    if ($lease1.Identity.VolumeSerialNumber -eq 0) { throw "Identity.VolumeSerialNumber is 0." }
    if ($lease1.Identity.FileIndex -eq 0) { throw "Identity.FileIndex is 0." }

    $computedSha1 = $lease1.ComputeSha256()
    if (-not [string]::Equals($computedSha1, $suite1ExpectedSha, [System.StringComparison]::Ordinal)) {
        throw "ComputeSha256 mismatch: expected '$suite1ExpectedSha', got '$computedSha1'"
    }

    # Verify ReadRange through owned handle
    $headRange = $lease1.ReadRange(0, 100)
    if ($headRange.Length -ne 100) { throw "ReadRange head length mismatch" }
    for ($i = 0; $i -lt 100; $i++) {
        if ($headRange[$i] -ne $suite1Payload[$i]) { throw "ReadRange head byte mismatch at offset $i" }
    }
    $tailRange = $lease1.ReadRange($suite1PayloadLen - 100, 100)
    if ($tailRange.Length -ne 100) { throw "ReadRange tail length mismatch" }
    for ($i = 0; $i -lt 100; $i++) {
        if ($tailRange[$i] -ne $suite1Payload[$suite1PayloadLen - 100 + $i]) { throw "ReadRange tail byte mismatch at offset $i" }
    }
    $zeroRange = $lease1.ReadRange(0, 0)
    if ($zeroRange.Length -ne 0) { throw "ReadRange count 0 returned non-empty array" }

    # Verify CopyVerifiedTo
    $copyDest1 = [System.IO.MemoryStream]::new()
    [void](Register-TrackedStream $copyDest1)
    $copyResult1 = $lease1.CopyVerifiedTo($copyDest1)
    if ($copyResult1.BytesCopied -ne $suite1PayloadLen) { throw "CopyVerifiedTo BytesCopied mismatch" }
    if (-not [string]::Equals($copyResult1.Sha256, $suite1ExpectedSha, [System.StringComparison]::Ordinal)) { throw "CopyVerifiedTo Sha256 mismatch" }

    # Verify File.OpenRead on stage MUST fail with actual sharing violation 32
    $sharingViolationSeen = $false
    $unexpectedBlockedStream = $null
    try {
        $unexpectedBlockedStream = [System.IO.File]::OpenRead($suite1StagePath)
        [void](Register-TrackedStream $unexpectedBlockedStream)
        throw "File.OpenRead on staged file unexpectedly succeeded!"
    } catch {
        if ($unexpectedBlockedStream -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.IO.IOException]) {
            $errCode = Get-Win32ErrorCode $uEx
            if ($errCode -eq 32) {
                $sharingViolationSeen = $true
            } else {
                throw "File.OpenRead on staged file threw IOException with unexpected Win32 error ${errCode}: $($uEx.Message)"
            }
        } else {
            throw "File.OpenRead on staged file threw unexpected exception type: $($uEx.GetType().FullName)"
        }
    }
    if (-not $sharingViolationSeen) { throw "File.OpenRead on staged file did not fail with sharing violation 32!" }

    # Rename within directory while held
    $suite1FinalLeaf = "suite1-final.dat"
    $suite1FinalPath = [System.IO.Path]::Combine($labDir, $suite1FinalLeaf)
    $renamedId1 = $lease1.RenameWithinDirectory($suite1FinalLeaf)

    if ($renamedId1.VolumeSerialNumber -ne $lease1.Identity.VolumeSerialNumber) { throw "Renamed volume serial number changed" }
    if ($renamedId1.FileIndex -ne $lease1.Identity.FileIndex) { throw "Renamed file index changed" }
    if ($renamedId1.Size -ne $suite1PayloadLen) { throw "Renamed size changed" }
    $expectedFinalCanonical1 = [System.IO.Path]::GetFullPath($suite1FinalPath)
    if (-not [string]::Equals($renamedId1.CanonicalPath, $expectedFinalCanonical1, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Renamed canonical path mismatch: expected '$expectedFinalCanonical1', got '$($renamedId1.CanonicalPath)'"
    }

    if ([System.IO.File]::Exists($suite1StagePath)) { throw "Old stage path still exists after rename!" }
    if (-not [System.IO.File]::Exists($suite1FinalPath)) { throw "Final path does not exist after rename!" }

    # File.OpenRead on final path STILL fails with sharing violation 32 while held
    $finalSharingViolationSeen = $false
    $unexpectedBlockedFinalStream = $null
    try {
        $unexpectedBlockedFinalStream = [System.IO.File]::OpenRead($suite1FinalPath)
        [void](Register-TrackedStream $unexpectedBlockedFinalStream)
        throw "File.OpenRead on renamed final held path unexpectedly succeeded!"
    } catch {
        if ($unexpectedBlockedFinalStream -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.IO.IOException]) {
            $errCode = Get-Win32ErrorCode $uEx
            if ($errCode -eq 32) {
                $finalSharingViolationSeen = $true
            } else {
                throw "File.OpenRead on final path threw IOException with unexpected Win32 error ${errCode}: $($uEx.Message)"
            }
        } else {
            throw "File.OpenRead on final path threw unexpected exception type: $($uEx.GetType().FullName)"
        }
    }
    if (-not $finalSharingViolationSeen) { throw "File.OpenRead on renamed final path did not fail with sharing violation 32!" }

    # Close lease cleanly
    $lease1Rec = $null
    foreach ($rec in $script:TrackedLeases) { if ($rec.Lease -eq $lease1) { $lease1Rec = $rec; break } }
    if (-not (Close-TrackedLease -leaseRec $lease1Rec)) { throw "Failed to cleanly close lease1!" }

    # Verify actual path read bytes and hash match on disk after closure
    $diskSha1 = Compute-Sha256OfFile $suite1FinalPath
    if (-not [string]::Equals($diskSha1, $suite1ExpectedSha, [System.StringComparison]::Ordinal)) {
        throw "On-disk file SHA mismatch after close: expected '$suite1ExpectedSha', got '$diskSha1'"
    }
    $diskBytes1 = [System.IO.File]::ReadAllBytes($suite1FinalPath)
    if ($diskBytes1.Length -ne $suite1PayloadLen) { throw "On-disk file length mismatch after close" }

    # Verify caller input stream never seeked / disposed
    if ($suite1Stream.SeekAttemptCount -ne 0) { throw "Caller stream was seeked after close!" }
    if ($suite1Stream.LengthAccessCount -ne 0) { throw "Caller stream Length accessed after close!" }
    if ($suite1Stream.DisposeAttemptCount -ne 0) { throw "Caller stream Dispose called after close!" }
    if ($suite1Stream.IsStreamDisposed) { throw "Caller stream was marked disposed after close!" }

    Write-Diagnostic "Suite 1 passed successfully."

    # =========================================================================
    # --- SUITE 2: Compiled PE Bytes via CreateNewVerified + OwnedChild Process Control ---
    # =========================================================================
    Write-Diagnostic "--- Running Suite 2: Compiled PE Bytes via CreateNewVerified + OwnedChild Process Control ---"

    # Clone fixture bytes BEFORE target lease
    $fixtureBytes = [System.IO.File]::ReadAllBytes($fixtureExePath)
    $fixtureClonedLen = [long]$fixtureBytes.Length
    $fixtureClonedSha = $fixtureSha

    $suite2Stream = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new($fixtureBytes, 4093)
    [void](Register-TrackedStream $suite2Stream)

    $suite2StageExePath = [System.IO.Path]::Combine($labDir, "suite2-staged.exe")
    $exeLease = Safe-CreateNewVerifiedLease -path $suite2StageExePath -size $fixtureClonedLen -sha256 $fixtureClonedSha -SourceStream $suite2Stream

    # Attempt OwnedChildProcess.Start on staged EXE: MUST fail with Win32 Native error 32
    $startBlockedAsExpected = $false
    $unexpectedOwner = $null
    try {
        $unexpectedOwner = [PosDesktop.Updates.Native.OwnedChildProcess]::Start($suite2StageExePath, @("sleep", "10000"), $labDir, $envMap)
        [void](Register-TrackedOwner -Owner $unexpectedOwner -Tag "Suite2-Staged-Unexpected" -ExePath $suite2StageExePath)
    } catch {
        $uEx = Unwrap-Exception $_.Exception
        $win32Err = Get-Win32ErrorCode $uEx
        if ($win32Err -eq 32) {
            $startBlockedAsExpected = $true
        } else {
            throw "Expected Win32 error 32 starting staged exe, got ${win32Err}: $($uEx.Message)"
        }
    }
    if (-not $startBlockedAsExpected) { throw "OwnedChildProcess.Start unexpectedly succeeded on staged exe held exclusively!" }

    # Rename to final EXE within directory while held
    $suite2FinalExeLeaf = "suite2-final.exe"
    $suite2FinalExePath = [System.IO.Path]::Combine($labDir, $suite2FinalExeLeaf)
    $renamedExeId = $exeLease.RenameWithinDirectory($suite2FinalExeLeaf)

    # Attempt OwnedChildProcess.Start on renamed final EXE: MUST STILL fail with Win32 Native error 32
    $finalStartBlockedAsExpected = $false
    $unexpectedFinalOwner = $null
    try {
        $unexpectedFinalOwner = [PosDesktop.Updates.Native.OwnedChildProcess]::Start($suite2FinalExePath, @("sleep", "10000"), $labDir, $envMap)
        [void](Register-TrackedOwner -Owner $unexpectedFinalOwner -Tag "Suite2-FinalHeld-Unexpected" -ExePath $suite2FinalExePath)
    } catch {
        $uEx = Unwrap-Exception $_.Exception
        $win32Err = Get-Win32ErrorCode $uEx
        if ($win32Err -eq 32) {
            $finalStartBlockedAsExpected = $true
        } else {
            throw "Expected Win32 error 32 starting renamed held exe, got ${win32Err}: $($uEx.Message)"
        }
    }
    if (-not $finalStartBlockedAsExpected) { throw "OwnedChildProcess.Start unexpectedly succeeded on renamed held exe!" }

    # Close lease cleanly
    $exeLeaseRec = $null
    foreach ($rec in $script:TrackedLeases) { if ($rec.Lease -eq $exeLease) { $exeLeaseRec = $rec; break } }
    if (-not (Close-TrackedLease -leaseRec $exeLeaseRec)) { throw "Failed to cleanly close exe lease!" }

    # Now launch live OwnedChildProcess on final EXE: MUST succeed!
    $liveOwner = Safe-StartOwnedProcess -exePath $suite2FinalExePath -ArgumentVector @("sleep", "30000") -cwd $labDir -env $envMap -tag "Suite2-FinalLive"
    $obsLive = $liveOwner.Observe()
    if ($obsLive.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Expected live owner RootState to be Live, got $($obsLive.RootState)"
    }
    if (-not $obsLive.JobCountKnown) { throw "Expected JobCountKnown to be true for live owner" }
    if ($obsLive.ActiveProcesses -lt 1) { throw "Expected at least 1 active process in live job object, got $($obsLive.ActiveProcesses)" }

    # Bounded stop of owned child process
    $liveOwnerRec = $null
    foreach ($rec in $script:TrackedOwners) { if ($rec.Owner -eq $liveOwner) { $liveOwnerRec = $rec; break } }
    if (-not (Stop-TrackedOwner -ownerRec $liveOwnerRec -timeoutMs 5000)) { throw "Failed to boundedly stop live owner!" }

    $obsDead = $liveOwner.Observe()
    if ($obsDead.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead) {
        throw "Expected stopped owner RootState to be Dead, got $($obsDead.RootState)"
    }
    if (-not $obsDead.JobCountKnown) { throw "Expected JobCountKnown to be true after stop" }
    if ($obsDead.ActiveProcesses -ne 0) { throw "Expected 0 active processes after stop, got $($obsDead.ActiveProcesses)" }
    if (-not $liveOwner.IsClosed) { throw "Expected liveOwner.IsClosed to be true after stop" }

    Write-Diagnostic "Suite 2 passed successfully."

    # =========================================================================
    # --- SUITE 3: Existing Destination Nonce Bytes Collision Baseline ---
    # =========================================================================
    Write-Diagnostic "--- Running Suite 3: Existing Destination Nonce Bytes Collision Baseline ---"

    # Case 3a: Destination file collision
    $suite3ExistingFilePath = [System.IO.Path]::Combine($labDir, "suite3-existing-file.dat")
    $suite3WitnessBytes = [System.Text.Encoding]::UTF8.GetBytes("SUITE3-EXISTING-WITNESS-$nonce")
    [System.IO.File]::WriteAllBytes($suite3ExistingFilePath, $suite3WitnessBytes)
    $suite3WitnessSha = Compute-Sha256OfFile $suite3ExistingFilePath
    $suite3WitnessLen = [System.IO.FileInfo]::new($suite3ExistingFilePath).Length

    $suite3NewPayload = [System.Text.Encoding]::UTF8.GetBytes("NEW-STAGE-PAYLOAD-$nonce")
    $shaInstance3 = [System.Security.Cryptography.SHA256]::Create()
    $suite3NewShaBytes = $shaInstance3.ComputeHash($suite3NewPayload)
    $shaInstance3.Dispose()
    $sb3 = [System.Text.StringBuilder]::new($suite3NewShaBytes.Length * 2)
    foreach ($b in $suite3NewShaBytes) { [void]$sb3.Append($b.ToString("x2")) }
    $suite3NewSha = $sb3.ToString()

    $suite3StreamA = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new($suite3NewPayload, 1024)
    [void](Register-TrackedStream $suite3StreamA)

    $unexpectedLease3a = $null
    $collisionASeen = $false
    try {
        $unexpectedLease3a = Safe-CreateNewVerifiedLease -path $suite3ExistingFilePath -size ([long]$suite3NewPayload.Length) -sha256 $suite3NewSha -SourceStream $suite3StreamA
        throw "CreateNewVerified on existing file unexpectedly succeeded!"
    } catch {
        if ($unexpectedLease3a -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.IO.IOException]) {
            $errCode = Get-Win32ErrorCode $uEx
            # 80 = ERROR_FILE_EXISTS, 183 = ERROR_ALREADY_EXISTS
            if ($errCode -eq 80 -or $errCode -eq 183) {
                $collisionASeen = $true
            } else {
                throw "CreateNewVerified on existing file threw IOException with unexpected Win32 error ${errCode}: $($uEx.Message)"
            }
        } else {
            throw "CreateNewVerified on existing file threw unexpected exception type: $($uEx.GetType().FullName)"
        }
    }
    if (-not $collisionASeen) { throw "CreateNewVerified did not fail with CREATE_NEW collision 80/183 on existing file!" }
    if ($suite3StreamA.ReadCallCount -ne 0) { throw "Source stream was read during collision failure ($($suite3StreamA.ReadCallCount) calls)!" }
    if ([System.IO.FileInfo]::new($suite3ExistingFilePath).Length -ne $suite3WitnessLen) { throw "Existing file length was modified during collision!" }
    if (-not [string]::Equals((Compute-Sha256OfFile $suite3ExistingFilePath), $suite3WitnessSha, [System.StringComparison]::Ordinal)) {
        throw "Existing file SHA was modified during collision!"
    }

    # Case 3b: Destination directory collision
    $suite3ExistingDirPath = [System.IO.Path]::Combine($labDir, "suite3-existing-dir")
    [void][System.IO.Directory]::CreateDirectory($suite3ExistingDirPath)
    $suite3DirWitnessFile = [System.IO.Path]::Combine($suite3ExistingDirPath, "dir-witness.txt")
    [System.IO.File]::WriteAllText($suite3DirWitnessFile, "DIR-WITNESS-$nonce", [System.Text.Encoding]::UTF8)
    $suite3DirWitnessSha = Compute-Sha256OfFile $suite3DirWitnessFile

    $suite3StreamB = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new($suite3NewPayload, 1024)
    [void](Register-TrackedStream $suite3StreamB)

    $unexpectedLease3b = $null
    $collisionBSeen = $false
    try {
        $unexpectedLease3b = Safe-CreateNewVerifiedLease -path $suite3ExistingDirPath -size ([long]$suite3NewPayload.Length) -sha256 $suite3NewSha -SourceStream $suite3StreamB
        throw "CreateNewVerified on existing dir unexpectedly succeeded!"
    } catch {
        if ($unexpectedLease3b -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.IO.IOException]) {
            $errCode = Get-Win32ErrorCode $uEx
            # 80 = ERROR_FILE_EXISTS, 183 = ERROR_ALREADY_EXISTS, 5 = ERROR_ACCESS_DENIED (directory target)
            if ($errCode -eq 80 -or $errCode -eq 183 -or $errCode -eq 5) {
                $collisionBSeen = $true
            } else {
                throw "CreateNewVerified on existing dir threw IOException with unexpected Win32 error ${errCode}: $($uEx.Message)"
            }
        } else {
            throw "CreateNewVerified on existing dir threw unexpected exception type: $($uEx.GetType().FullName)"
        }
    }
    if (-not $collisionBSeen) { throw "CreateNewVerified did not fail with collision 80/183/5 on existing directory!" }
    if ($suite3StreamB.ReadCallCount -ne 0) { throw "Source stream was read during directory collision failure!" }
    if (-not [System.IO.Directory]::Exists($suite3ExistingDirPath)) { throw "Existing directory was removed during collision!" }
    if (-not [string]::Equals((Compute-Sha256OfFile $suite3DirWitnessFile), $suite3DirWitnessSha, [System.StringComparison]::Ordinal)) {
        throw "Directory witness file SHA was modified during collision!"
    }

    # Case 3c: Held existing target file
    $suite3HeldFilePath = [System.IO.Path]::Combine($labDir, "suite3-held-file.dat")
    [System.IO.File]::WriteAllBytes($suite3HeldFilePath, [System.Text.Encoding]::UTF8.GetBytes("HELD-TARGET-$nonce"))
    $heldFs = [System.IO.File]::Open($suite3HeldFilePath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    [void](Register-TrackedStream $heldFs)

    $suite3StreamC = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new($suite3NewPayload, 1024)
    [void](Register-TrackedStream $suite3StreamC)

    $unexpectedLease3c = $null
    $collisionCSeen = $false
    try {
        $unexpectedLease3c = Safe-CreateNewVerifiedLease -path $suite3HeldFilePath -size ([long]$suite3NewPayload.Length) -sha256 $suite3NewSha -SourceStream $suite3StreamC
        throw "CreateNewVerified on held file unexpectedly succeeded!"
    } catch {
        if ($unexpectedLease3c -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.IO.IOException]) {
            $errCode = Get-Win32ErrorCode $uEx
            # 32 = ERROR_SHARING_VIOLATION, 80 = ERROR_FILE_EXISTS, 183 = ERROR_ALREADY_EXISTS
            if ($errCode -eq 32 -or $errCode -eq 80 -or $errCode -eq 183) {
                $collisionCSeen = $true
            } else {
                throw "CreateNewVerified on held file threw unexpected error code ${errCode}: $($uEx.Message)"
            }
        } else {
            throw "CreateNewVerified on held file threw unexpected exception: $($uEx.GetType().FullName)"
        }
    } finally {
        $heldFs.Dispose()
    }
    if (-not $collisionCSeen) { throw "CreateNewVerified on held file did not fail with expected 32/80/183!" }

    Write-Diagnostic "Suite 3 passed successfully."

    # =========================================================================
    # --- SUITE 4: Post-Create Failures (Retained Lease & Uncertainty Checks) ---
    # =========================================================================
    Write-Diagnostic "--- Running Suite 4: Post-Create Failures ---"

    # Case 4a: expectedSize > actual EOF
    $suite4PathA = [System.IO.Path]::Combine($labDir, "suite4-eof-early.dat")
    $payload4a = [byte[]]::new(500)
    (New-Object System.Random 101).NextBytes($payload4a)
    $stream4a = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new($payload4a, 128)
    [void](Register-TrackedStream $stream4a)

    $unexpectedLease4a = $null
    $creationExA = $null
    try {
        $unexpectedLease4a = Invoke-TrackedCreation -ExpectedPath $suite4PathA -Action {
            [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified(
                $suite4PathA,
                1000, # expected 1000, but stream only has 500
                "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
                $stream4a
            )
        }
        throw "CreateNewVerified unexpectedly succeeded for early EOF!"
    } catch {
        if ($unexpectedLease4a -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [PosDesktop.Updates.Native.SourceFileLeaseCreationException]) {
            $creationExA = $uEx
        } else {
            throw "Expected SourceFileLeaseCreationException, got $($uEx.GetType().FullName): $($uEx.Message)"
        }
    }
    Assert-RetainedCreationLease -creationEx $creationExA -expectedStagePath $suite4PathA -expectedInnerType ([System.IO.EndOfStreamException]) -expectedPartialBytesOnDisk 500 -expectedPrefixBytes $payload4a
    if ($stream4a.IsStreamDisposed) { throw "Stream 4a was disposed!" }

    # Case 4b: smaller than input with pin of PREFIX (trailing byte probe reject)
    $suite4PathB = [System.IO.Path]::Combine($labDir, "suite4-trailing-bytes.dat")
    $payload4b = [byte[]]::new(600)
    (New-Object System.Random 102).NextBytes($payload4b)
    $prefixBytes4b = [byte[]]::new(500)
    [System.Array]::Copy($payload4b, 0, $prefixBytes4b, 0, 500)
    # Compute SHA of first 500 bytes (pin of prefix)
    $shaInst4b = [System.Security.Cryptography.SHA256]::Create()
    $shaBytes4b = $shaInst4b.ComputeHash($prefixBytes4b)
    $shaInst4b.Dispose()
    $sb4b = [System.Text.StringBuilder]::new($shaBytes4b.Length * 2)
    foreach ($b in $shaBytes4b) { [void]$sb4b.Append($b.ToString("x2")) }
    $prefixSha4b = $sb4b.ToString()

    $stream4b = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new($payload4b, 128)
    [void](Register-TrackedStream $stream4b)

    $unexpectedLease4b = $null
    $creationExB = $null
    try {
        $unexpectedLease4b = Invoke-TrackedCreation -ExpectedPath $suite4PathB -Action {
            [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified(
                $suite4PathB,
                500, # expected 500, but stream has 600
                $prefixSha4b,
                $stream4b
            )
        }
        throw "CreateNewVerified unexpectedly succeeded for trailing bytes!"
    } catch {
        if ($unexpectedLease4b -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [PosDesktop.Updates.Native.SourceFileLeaseCreationException]) {
            $creationExB = $uEx
        } else {
            throw "Expected SourceFileLeaseCreationException for trailing bytes, got $($uEx.GetType().FullName): $($uEx.Message)"
        }
    }
    Assert-RetainedCreationLease -creationEx $creationExB -expectedStagePath $suite4PathB -expectedInnerType ([System.InvalidOperationException]) -expectedPartialBytesOnDisk 500 -expectedPrefixBytes $prefixBytes4b
    if ($stream4b.IsStreamDisposed) { throw "Stream 4b was disposed!" }

    # Case 4c: valid format WRONG SHA
    $suite4PathC = [System.IO.Path]::Combine($labDir, "suite4-wrong-sha.dat")
    $payload4c = [byte[]]::new(500)
    (New-Object System.Random 103).NextBytes($payload4c)
    $wrongSha4c = "0" * 64 # valid 64 lowercase hex format, but wrong content digest

    $stream4c = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new($payload4c, 128)
    [void](Register-TrackedStream $stream4c)

    $unexpectedLease4c = $null
    $creationExC = $null
    try {
        $unexpectedLease4c = Invoke-TrackedCreation -ExpectedPath $suite4PathC -Action {
            [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified(
                $suite4PathC,
                500,
                $wrongSha4c,
                $stream4c
            )
        }
        throw "CreateNewVerified unexpectedly succeeded for wrong SHA!"
    } catch {
        if ($unexpectedLease4c -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [PosDesktop.Updates.Native.SourceFileLeaseCreationException]) {
            $creationExC = $uEx
        } else {
            throw "Expected SourceFileLeaseCreationException for wrong SHA, got $($uEx.GetType().FullName): $($uEx.Message)"
        }
    }
    Assert-RetainedCreationLease -creationEx $creationExC -expectedStagePath $suite4PathC -expectedInnerType ([System.InvalidOperationException]) -expectedPartialBytesOnDisk 500 -expectedPrefixBytes $payload4c
    if ($stream4c.IsStreamDisposed) { throw "Stream 4c was disposed!" }

    # Case 4d: stream throw MID read after at least 1 successful chunk
    $suite4PathD = [System.IO.Path]::Combine($labDir, "suite4-mid-stream-throw.dat")
    $payload4d = [byte[]]::new(131072) # 2 x 64KiB
    (New-Object System.Random 104).NextBytes($payload4d)
    $prefixBytes4d = [byte[]]::new(65536)
    [System.Array]::Copy($payload4d, 0, $prefixBytes4d, 0, 65536)
    $shaInst4d = [System.Security.Cryptography.SHA256]::Create()
    $shaBytes4d = $shaInst4d.ComputeHash($payload4d)
    $shaInst4d.Dispose()
    $sb4d = [System.Text.StringBuilder]::new($shaBytes4d.Length * 2)
    foreach ($b in $shaBytes4d) { [void]$sb4d.Append($b.ToString("x2")) }
    $fullSha4d = $sb4d.ToString()

    # FaultingReadStream: chunk size 65536, fail on call 2 (mid-stream throw)
    $stream4d = [PosDesktop.Updates.Native.Testing.FaultingReadStream]::new(
        $payload4d,
        [PosDesktop.Updates.Native.Testing.FaultMode]::ThrowMidRead,
        2,
        65536
    )
    [void](Register-TrackedStream $stream4d)

    $unexpectedLease4d = $null
    $creationExD = $null
    try {
        $unexpectedLease4d = Invoke-TrackedCreation -ExpectedPath $suite4PathD -Action {
            [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified(
                $suite4PathD,
                131072,
                $fullSha4d,
                $stream4d
            )
        }
        throw "CreateNewVerified unexpectedly succeeded for mid-stream throw!"
    } catch {
        if ($unexpectedLease4d -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [PosDesktop.Updates.Native.SourceFileLeaseCreationException]) {
            $creationExD = $uEx
        } else {
            throw "Expected SourceFileLeaseCreationException for mid-stream throw, got $($uEx.GetType().FullName): $($uEx.Message)"
        }
    }
    Assert-RetainedCreationLease -creationEx $creationExD -expectedStagePath $suite4PathD -expectedInnerType ([PosDesktop.Updates.Native.Testing.CustomTestStreamException]) -expectedPartialBytesOnDisk 65536 -expectedPrefixBytes $prefixBytes4d
    if ($stream4d.IsStreamDisposed) { throw "Stream 4d was disposed!" }

    # Case 4e: invalid Read count negative (-1)
    $suite4PathE = [System.IO.Path]::Combine($labDir, "suite4-invalid-read-negative.dat")
    $stream4e = [PosDesktop.Updates.Native.Testing.FaultingReadStream]::new(
        $payload4c,
        [PosDesktop.Updates.Native.Testing.FaultMode]::NegativeReadCount,
        1,
        128
    )
    [void](Register-TrackedStream $stream4e)

    $unexpectedLease4e = $null
    $creationExE = $null
    try {
        $unexpectedLease4e = Invoke-TrackedCreation -ExpectedPath $suite4PathE -Action {
            [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified(
                $suite4PathE,
                500,
                $wrongSha4c,
                $stream4e
            )
        }
        throw "CreateNewVerified unexpectedly succeeded for negative read count!"
    } catch {
        if ($unexpectedLease4e -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [PosDesktop.Updates.Native.SourceFileLeaseCreationException]) {
            $creationExE = $uEx
        } else {
            throw "Expected SourceFileLeaseCreationException for negative read count, got $($uEx.GetType().FullName): $($uEx.Message)"
        }
    }
    $prefixBytes4e = [byte[]]::new(0)
    Assert-RetainedCreationLease -creationEx $creationExE -expectedStagePath $suite4PathE -expectedInnerType ([System.InvalidOperationException]) -expectedPartialBytesOnDisk 0 -expectedPrefixBytes $prefixBytes4e

    # Case 4f: invalid Read count > requested
    $suite4PathF = [System.IO.Path]::Combine($labDir, "suite4-invalid-read-toolarge.dat")
    $stream4f = [PosDesktop.Updates.Native.Testing.FaultingReadStream]::new(
        $payload4c,
        [PosDesktop.Updates.Native.Testing.FaultMode]::ExceedsRequestedReadCount,
        1,
        128
    )
    [void](Register-TrackedStream $stream4f)

    $unexpectedLease4f = $null
    $creationExF = $null
    try {
        $unexpectedLease4f = Invoke-TrackedCreation -ExpectedPath $suite4PathF -Action {
            [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified(
                $suite4PathF,
                500,
                $wrongSha4c,
                $stream4f
            )
        }
        throw "CreateNewVerified unexpectedly succeeded for too-large read count!"
    } catch {
        if ($unexpectedLease4f -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [PosDesktop.Updates.Native.SourceFileLeaseCreationException]) {
            $creationExF = $uEx
        } else {
            throw "Expected SourceFileLeaseCreationException for too-large read count, got $($uEx.GetType().FullName): $($uEx.Message)"
        }
    }
    $prefixBytes4f = [byte[]]::new(0)
    Assert-RetainedCreationLease -creationEx $creationExF -expectedStagePath $suite4PathF -expectedInnerType ([System.InvalidOperationException]) -expectedPartialBytesOnDisk 0 -expectedPrefixBytes $prefixBytes4f

    Write-Diagnostic "Suite 4 passed successfully."

    # =========================================================================
    # --- SUITE 5: Empty File Success & Pre-Creation Validations ---
    # =========================================================================
    Write-Diagnostic "--- Running Suite 5: Empty File Success & Pre-Creation Validations ---"

    # Part 5a: Empty file correct SHA+EOF success
    $emptySha = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    $emptyStream = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new([byte[]]::new(0), 1024)
    [void](Register-TrackedStream $emptyStream)

    $suite5EmptyPath = [System.IO.Path]::Combine($labDir, "suite5-empty.dat")
    $emptyLease = Safe-CreateNewVerifiedLease -path $suite5EmptyPath -size 0 -sha256 $emptySha -SourceStream $emptyStream

    if ($emptyLease.IsClosed) { throw "Empty lease IsClosed must be false." }
    if ($emptyLease.IsMutationUncertain) { throw "Empty lease IsMutationUncertain must be false." }
    if ($emptyLease.Identity.Size -ne 0) { throw "Empty lease Identity.Size must be 0." }
    $computedEmptySha = $emptyLease.ComputeSha256()
    if (-not [string]::Equals($computedEmptySha, $emptySha, [System.StringComparison]::Ordinal)) {
        throw "Empty lease ComputeSha256 mismatch: expected '$emptySha', got '$computedEmptySha'"
    }

    $emptyLeaseRec = $null
    foreach ($rec in $script:TrackedLeases) { if ($rec.Lease -eq $emptyLease) { $emptyLeaseRec = $rec; break } }
    if (-not (Close-TrackedLease -leaseRec $emptyLeaseRec)) { throw "Failed to close empty lease!" }
    if ([System.IO.FileInfo]::new($suite5EmptyPath).Length -ne 0) { throw "On-disk empty file length is non-zero!" }

    # Part 5b: Pre-creation validations (Must fail BEFORE file creation; no new file on disk, no input read)
    function Assert-PreCreationFailure {
        param(
            [string]$name,
            [scriptblock]$action,
            [string]$targetPath,
            [type]$expectedType
        )
        $failedAsExpected = $false
        $unexpectedResult = $null
        try {
            $unexpectedResult = Invoke-TrackedCreation -ExpectedPath $targetPath -Action $action
            throw "Pre-creation test '$name' succeeded unexpectedly!"
        } catch {
            if ($unexpectedResult -ne $null) { throw }
            $uEx = Unwrap-Exception $_.Exception
            if ($expectedType.IsAssignableFrom($uEx.GetType())) {
                $failedAsExpected = $true
            } else {
                throw "Pre-creation test '$name' threw $($uEx.GetType().FullName) instead of $($expectedType.FullName): $($uEx.Message)"
            }
        }
        if (-not $failedAsExpected) { throw "Pre-creation test '$name' succeeded unexpectedly!" }
        if ([string]::IsNullOrEmpty($targetPath) -or -not [System.IO.Path]::IsPathRooted($targetPath)) { return }
        if ([System.IO.File]::Exists($targetPath)) {
            throw "Pre-creation test '$name' created a file on disk at: $targetPath"
        }
    }

    $validPayload5 = [System.Text.Encoding]::UTF8.GetBytes("PREVALIDATION-PAYLOAD")
    $validStream5 = [PosDesktop.Updates.Native.Testing.NonSeekableShortReadStream]::new($validPayload5, 1024)
    [void](Register-TrackedStream $validStream5)
    $validTarget5 = [System.IO.Path]::Combine($labDir, "suite5-preval-target.dat")

    # 1. Negative size (-1)
    Assert-PreCreationFailure -name "Negative size" -targetPath $validTarget5 -expectedType ([System.ArgumentOutOfRangeException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($validTarget5, -1, $emptySha, $validStream5)
    }

    # 2. Uppercase SHA-256
    Assert-PreCreationFailure -name "Uppercase SHA" -targetPath $validTarget5 -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($validTarget5, 0, $emptySha.ToUpperInvariant(), $validStream5)
    }

    # 3. Short SHA-256
    Assert-PreCreationFailure -name "Short SHA" -targetPath $validTarget5 -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($validTarget5, 0, "abc", $validStream5)
    }

    # 4. Non-hex SHA-256
    Assert-PreCreationFailure -name "Non-hex SHA" -targetPath $validTarget5 -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($validTarget5, 0, ("0" * 63 + "g"), $validStream5)
    }

    # 5. Stream unreadable (CanRead = false)
    $unreadableStream = [PosDesktop.Updates.Native.Testing.FaultingReadStream]::new($validPayload5, [PosDesktop.Updates.Native.Testing.FaultMode]::Unreadable)
    [void](Register-TrackedStream $unreadableStream)
    Assert-PreCreationFailure -name "Unreadable stream" -targetPath $validTarget5 -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($validTarget5, [long]$validPayload5.Length, $emptySha, $unreadableStream)
    }

    # 6. Stream null (PowerShell null parameter to Stream)
    Assert-PreCreationFailure -name "Null stream" -targetPath $validTarget5 -expectedType ([System.ArgumentNullException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($validTarget5, 0, $emptySha, $null)
    }

    # 7. Relative path
    Assert-PreCreationFailure -name "Relative path" -targetPath "" -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified("relative-target.dat", 0, $emptySha, $validStream5)
    }

    # 8. UNC path
    Assert-PreCreationFailure -name "UNC path" -targetPath "" -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified("\\server\share\file.dat", 0, $emptySha, $validStream5)
    }

    # 9. ADS path
    Assert-PreCreationFailure -name "ADS path" -targetPath "" -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified("${validTarget5}:stream", 0, $emptySha, $validStream5)
    }

    # 10. Dotdot path
    Assert-PreCreationFailure -name "Dotdot path" -targetPath "" -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified("$labDir\..\escape.dat", 0, $emptySha, $validStream5)
    }

    # 11. 8.3 short-name path
    Assert-PreCreationFailure -name "8.3 alias path" -targetPath "" -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified("$labDir\PROGRA~1.DAT", 0, $emptySha, $validStream5)
    }

    # 12. Reserved DOS device
    Assert-PreCreationFailure -name "Reserved DOS device" -targetPath "" -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified("$labDir\NUL", 0, $emptySha, $validStream5)
    }

    # 13. Drive root
    Assert-PreCreationFailure -name "Drive root" -targetPath "" -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified("C:\", 0, $emptySha, $validStream5)
    }

    # 14. Non-existent ancestor directory
    $nonExistentPath = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "nonexistent-$nonce\sub\target.dat")
    Assert-PreCreationFailure -name "Non-existent ancestor" -targetPath $nonExistentPath -expectedType ([System.IO.DirectoryNotFoundException]) -action {
        [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($nonExistentPath, 0, $emptySha, $validStream5)
    }

    # 15. Literal null path via C# test bridge
    Assert-PreCreationFailure -name "Bridge null path" -targetPath "" -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.Testing.SourceFileLeaseTestBridge]::CreateNewVerifiedNullPath(0, $emptySha, $validStream5)
    }

    # 16. Literal null SHA via C# test bridge
    Assert-PreCreationFailure -name "Bridge null SHA" -targetPath $validTarget5 -expectedType ([System.ArgumentException]) -action {
        [PosDesktop.Updates.Native.Testing.SourceFileLeaseTestBridge]::CreateNewVerifiedNullSha($validTarget5, 0, $validStream5)
    }

    # 17. Literal null stream via C# test bridge
    Assert-PreCreationFailure -name "Bridge null stream" -targetPath $validTarget5 -expectedType ([System.ArgumentNullException]) -action {
        [PosDesktop.Updates.Native.Testing.SourceFileLeaseTestBridge]::CreateNewVerifiedNullStream($validTarget5, 0, $emptySha)
    }

    if ($validStream5.ReadCallCount -ne 0) {
        throw "Pre-validation stream was read ($($validStream5.ReadCallCount) calls) during pre-creation failure tests!"
    }

    Write-Diagnostic "Suite 5 passed successfully."

    # Truthful runtime coverage notice:
    # This verification suite proves:
    # 1. Exclusive CREATE_NEW staging and share-0 locks across live processes (Suites 1, 2, 3).
    # 2. Complete nonseekable stream staging, trailing byte rejection, and full re-read SHA-256 validation (Suites 1, 4, 5).
    # 3. Post-create failure isolation and retained close-only lease semantics across stream errors (Suite 4).
    # 4. Strict pre-creation parameter, canonical path, and ancestor directory validation (Suite 5).
    # Runtime Coverage Boundary Notice:
    # The following native kernel failure states cannot be reliably injected without kernel-level fault drivers or out-of-disk simulation:
    # - Native Win32 WriteFile failure (disk full / physical I/O error) during streaming copy.
    # - Native FlushFileBuffers failure or unbuffered power-loss during flush.
    # - Post-flush kernel I/O failure before final handle validation.
    # - In-flight rename collision or lock conflicts under adversarial rename races.
    # Production updater callers MUST combine this primitive with journaled state, nonce directories, and process execution barriers.
    Write-Diagnostic "Runtime coverage boundary notice recorded: un-fault-injected native WriteFile/FlushFileBuffers/kernel post-flush/rename states documented."

    $script:AllSuitesPassed = $true
    Write-Diagnostic "=== ALL 5 TEST SUITES PASSED SUCCESSFULLY ==="
} catch {
    $script:PrimaryError = $_.Exception
    Write-Diagnostic "FATAL ERROR during test execution: $($script:PrimaryError.Message)"
} finally {
    Close-AllTrackedStreams
    Stop-AllTrackedOwners
    Close-AllTrackedLeases

    $gateResult = Invoke-SharedCleanupSafetyGate -TargetSpecs @(
        @{ Path = $labDir; MarkerName = ".snfs-lab-marker"; Nonce = $nonce }
        @{ Path = $victimDir; MarkerName = ".snfs-victim-marker"; Nonce = $nonce }
    )
    Write-Diagnostic "Cleanup gate result: $gateResult"

    if ($script:PrimaryError -ne $null -or -not $script:AllSuitesPassed -or $gateResult -ne 'CLEANED') {
        [Console]::Error.WriteLine("Verification failed. PrimaryError=$($script:PrimaryError -ne $null), AllPassed=$($script:AllSuitesPassed), Gate=$gateResult")
        exit 1
    }
}

exit 0
