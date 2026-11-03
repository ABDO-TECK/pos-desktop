# scripts/verify-shell-native-file-disposition.ps1
# Win32 Native Exclusive Leased-Handle Deletion Disposition Primitive Verifier
# Verifies exclusive handle-based deletion disposition, retention, and namespace absence semantics.

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
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfd-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfd-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
$sourceFilesDir = [System.IO.Path]::Combine($labDir, "sources")
$backupFilesDir = [System.IO.Path]::Combine($labDir, "backups")

[void][System.IO.Directory]::CreateDirectory($labDir)
[void][System.IO.Directory]::CreateDirectory($labTempDir)
[void][System.IO.Directory]::CreateDirectory($sourceFilesDir)
[void][System.IO.Directory]::CreateDirectory($backupFilesDir)
[void][System.IO.Directory]::CreateDirectory($victimDir)

$labMarkerPath = [System.IO.Path]::Combine($labDir, ".snfd-lab-marker")
[System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".snfd-victim-marker")
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
        $targetPath = $ExpectedPath
        $depth = 0
        while ($curr -ne $null -and $depth -lt 10) {
            if ($curr -is [PosDesktop.Updates.Native.SourceFileLeaseCreationException]) {
                $retained = $curr.RetainedLease
                if (-not [string]::IsNullOrEmpty($curr.StagePath)) {
                    $targetPath = $curr.StagePath
                }
                break
            }
            if ($curr -is [PosDesktop.Updates.Native.SourceFileLeaseAcquisitionException]) {
                $retained = $curr.RetainedLease
                break
            }
            if ($curr -is [PosDesktop.Updates.Native.SourceFileLeaseRenameException]) {
                $retained = $curr.RetainedLease
                break
            }
            if ($curr -is [PosDesktop.Updates.Native.SourceFileLeaseDispositionException]) {
                $retained = $curr.RetainedLease
                if (-not [string]::IsNullOrEmpty($curr.ObservedCanonicalPath)) {
                    $targetPath = $curr.ObservedCanonicalPath
                }
                break
            }
            $curr = $curr.InnerException
            $depth++
        }
        if ($retained -ne $null) {
            [void](Register-TrackedLease -Lease $retained -Path $targetPath)
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
        if ($curr -is [System.UnauthorizedAccessException]) {
            $h = $curr.HResult
            if ($h -eq -2147024891 -or $h -eq [int]0x80070005) {
                return 5
            }
            if (($h -band -65536) -eq -2147024896 -and ($h -band 65535) -eq 5) {
                return 5
            }
        }
        $curr = $curr.InnerException
        $depth++
    }
    return -1
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

$testHelpersSource = @"
namespace PosDesktop.Updates.Native.Testing
{
    using System;
    using System.IO;
    using PosDesktop.Updates.Native;

    public sealed class ReentrantDispositionCallbackStream : Stream
    {
        private readonly MemoryStream _inner;
        private SourceFileLease _targetLease;
        private bool _attemptedMark;
        private Exception _markException;
        private bool _attemptedTryClose;
        private bool _tryCloseResult;
        private int _tryCloseError;

        public ReentrantDispositionCallbackStream()
        {
            this._inner = new MemoryStream();
            this._attemptedMark = false;
            this._markException = null;
            this._attemptedTryClose = false;
            this._tryCloseResult = false;
            this._tryCloseError = 0;
        }

        public void SetTargetLease(SourceFileLease lease)
        {
            this._targetLease = lease;
        }

        public bool AttemptedMark { get { return this._attemptedMark; } }
        public Exception MarkException { get { return this._markException; } }
        public bool AttemptedTryClose { get { return this._attemptedTryClose; } }
        public bool TryCloseResult { get { return this._tryCloseResult; } }
        public int TryCloseError { get { return this._tryCloseError; } }

        public override bool CanRead { get { return this._inner.CanRead; } }
        public override bool CanSeek { get { return this._inner.CanSeek; } }
        public override bool CanWrite { get { return this._inner.CanWrite; } }
        public override long Length { get { return this._inner.Length; } }
        public override long Position
        {
            get { return this._inner.Position; }
            set { this._inner.Position = value; }
        }

        public override void Flush() { this._inner.Flush(); }
        public override long Seek(long offset, SeekOrigin origin) { return this._inner.Seek(offset, origin); }
        public override void SetLength(long value) { this._inner.SetLength(value); }
        public override int Read(byte[] buffer, int offset, int count) { return this._inner.Read(buffer, offset, count); }

        public override void Write(byte[] buffer, int offset, int count)
        {
            if (this._targetLease != null && !this._attemptedMark)
            {
                this._attemptedMark = true;
                try
                {
                    this._targetLease.MarkForDeletion();
                }
                catch (Exception ex)
                {
                    this._markException = ex;
                }

                this._attemptedTryClose = true;
                int err;
                this._tryCloseResult = this._targetLease.TryClose(out err);
                this._tryCloseError = err;
            }

            this._inner.Write(buffer, offset, count);
        }

        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                this._inner.Dispose();
            }
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

$fixtureExeName = "shell-test-disposition-fixture-x64.exe"
$fixtureExePath = [System.IO.Path]::Combine($labDir, $fixtureExeName)

try {
    $script:CompilationUncertain = $true
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir
    try {
        # Unit 1: Add-Type parent in-memory types
        $parentCombinedSource = $sourceFileLeaseSource + "`r`n" + $ownedChildSource + "`r`n" + $testHelpersSource
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

    # Decoder unit controls:
    # 1. Generic IOException must decode to -1
    $genericIoEx = [System.IO.IOException]::new("control")
    $genericCode = Get-Win32ErrorCode $genericIoEx
    if ($genericCode -ne -1) {
        throw "Decoder unit control failed: generic IOException decoded to ${genericCode} instead of -1."
    }

    # 2. UnauthorizedAccessException (0x80070005) must decode to 5 (explicitly UNIT decoder only, NOT kernel proof)
    $uaEx = [System.UnauthorizedAccessException]::new("decoder control")
    $uaCode = Get-Win32ErrorCode $uaEx
    if ($uaCode -ne 5) {
        throw "Decoder unit control failed: UnauthorizedAccessException decoded to ${uaCode} instead of 5."
    }

    # =========================================================================
    # Suite 1: Regular Data File Disposition + Verified Backup + Proof of Absence
    # =========================================================================
    Write-Diagnostic "=== Running Suite 1: Regular Data File Disposition + Absence Proof ==="

    $s1SourcePath = [System.IO.Path]::Combine($sourceFilesDir, "suite1-regular.dat")
    $s1Bytes = [byte[]]::new(65536)
    $rng1 = [System.Security.Cryptography.RNGCryptoServiceProvider]::new()
    $rng1.GetBytes($s1Bytes)
    $rng1.Dispose()
    [System.IO.File]::WriteAllBytes($s1SourcePath, $s1Bytes)
    $s1Len = [long]$s1Bytes.Length
    $s1Sha = Compute-Sha256OfFile $s1SourcePath
    Write-Diagnostic "Suite 1: Source file created ($s1Len bytes, SHA: $s1Sha)."

    # Acquire lease on source data file
    $lease1 = Invoke-TrackedCreation -ExpectedPath $s1SourcePath -Action {
        return [PosDesktop.Updates.Native.SourceFileLease]::Acquire($s1SourcePath, $s1Len, $s1Sha)
    }
    $lease1Rec = Register-TrackedLease -Lease $lease1 -Path $s1SourcePath

    $orig1Id = $lease1.Identity
    $orig1Vol = $orig1Id.VolumeSerialNumber
    $orig1Idx = $orig1Id.FileIndex
    $orig1Canon = $orig1Id.CanonicalPath
    Write-Diagnostic "Suite 1: Acquired lease (Vol: $orig1Vol, Idx: $orig1Idx, Path: $orig1Canon)."

    # Independently create VERIFIED backup OUTSIDE source lab subdir via CopyVerifiedTo
    $s1BackupPath = [System.IO.Path]::Combine($backupFilesDir, "suite1-backup.dat")
    $s1BackupFs = [System.IO.FileStream]::new($s1BackupPath, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
    [void](Register-TrackedStream $s1BackupFs)

    $copyRes1 = $lease1.CopyVerifiedTo($s1BackupFs)
    if ($copyRes1.BytesCopied -ne $s1Len) {
        throw "Suite 1: CopyVerifiedTo bytes copied mismatch: expected $s1Len, got $($copyRes1.BytesCopied)"
    }
    if (-not [string]::Equals($copyRes1.Sha256, $s1Sha, [System.StringComparison]::Ordinal)) {
        throw "Suite 1: CopyVerifiedTo SHA-256 mismatch: expected $s1Sha, got $($copyRes1.Sha256)"
    }
    $s1BackupFs.Flush($true)
    $s1BackupFs.Dispose()

    # Re-read expected full SHA after closed
    $postCloseBackupLen = [System.IO.FileInfo]::new($s1BackupPath).Length
    if ($postCloseBackupLen -ne $s1Len) {
        throw "Suite 1: Re-read backup length mismatch: expected $s1Len, got $postCloseBackupLen"
    }
    $postCloseBackupSha = Compute-Sha256OfFile $s1BackupPath
    if (-not [string]::Equals($postCloseBackupSha, $s1Sha, [System.StringComparison]::Ordinal)) {
        throw "Suite 1: Re-read backup SHA mismatch: expected $s1Sha, got $postCloseBackupSha"
    }
    Write-Diagnostic "Suite 1: Verified external backup established ($postCloseBackupLen bytes, SHA matches)."

    # Mark for deletion
    $lease1.MarkForDeletion()
    Write-Diagnostic "Suite 1: MarkForDeletion completed."

    # Assert status flags and identity preservation
    if (-not $lease1.IsDeletionPending) {
        throw "Suite 1: IsDeletionPending must be true after MarkForDeletion."
    }
    if ($lease1.IsMutationUncertain) {
        throw "Suite 1: IsMutationUncertain must be false after successful MarkForDeletion."
    }
    if ($lease1.IsClosed) {
        throw "Suite 1: IsClosed must be false while handle stays open."
    }
    $pending1Id = $lease1.Identity
    if ($pending1Id.VolumeSerialNumber -ne $orig1Vol) {
        throw "Suite 1: VolumeSerialNumber changed after MarkForDeletion."
    }
    if ($pending1Id.FileIndex -ne $orig1Idx) {
        throw "Suite 1: FileIndex changed after MarkForDeletion."
    }
    if ($pending1Id.Size -ne $s1Len) {
        throw "Suite 1: Size changed after MarkForDeletion."
    }
    if (-not [string]::Equals($pending1Id.CanonicalPath, $orig1Canon, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 1: CanonicalPath changed after MarkForDeletion."
    }

    # ALL ReadRange(0,0) / Hash / Copy / Rename / MarkAgain BLOCK
    $s1ReadBlocked = $false
    try { [void]$lease1.ReadRange(0, 0) } catch [System.InvalidOperationException] { $s1ReadBlocked = $true }
    if (-not $s1ReadBlocked) { throw "Suite 1: ReadRange(0, 0) was not blocked on pending lease!" }

    $s1HashBlocked = $false
    try { [void]$lease1.ComputeSha256() } catch [System.InvalidOperationException] { $s1HashBlocked = $true }
    if (-not $s1HashBlocked) { throw "Suite 1: ComputeSha256 was not blocked on pending lease!" }

    $s1CopyBlocked = $false
    $dummyDest1 = [System.IO.MemoryStream]::new()
    [void](Register-TrackedStream $dummyDest1)
    try { [void]$lease1.CopyVerifiedTo($dummyDest1) } catch [System.InvalidOperationException] { $s1CopyBlocked = $true }
    if (-not $s1CopyBlocked) { throw "Suite 1: CopyVerifiedTo was not blocked on pending lease!" }

    $s1RenameBlocked = $false
    try { [void]$lease1.RenameWithinDirectory("aside-suite1.dat") } catch [System.InvalidOperationException] { $s1RenameBlocked = $true }
    if (-not $s1RenameBlocked) { throw "Suite 1: RenameWithinDirectory was not blocked on pending lease!" }

    $s1MarkAgainBlocked = $false
    try { $lease1.MarkForDeletion() } catch [System.InvalidOperationException] { $s1MarkAgainBlocked = $true }
    if (-not $s1MarkAgainBlocked) { throw "Suite 1: Second MarkForDeletion was not blocked on pending lease!" }

    Write-Diagnostic "Suite 1: Confirmed all public read/hash/copy/rename/mark-again calls blocked."

    # Actual File.Open source while pending MUST be blocked with known 32, 5, or 303
    $s1PendingProbeFs = $null
    $s1PendingCode = -1
    try {
        $s1PendingProbeFs = [System.IO.File]::Open($s1SourcePath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
        [void](Register-TrackedStream $s1PendingProbeFs)
        throw "Suite 1: File.Open unexpectedly succeeded while deletion was pending!"
    } catch {
        if ($s1PendingProbeFs -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        $s1PendingCode = Get-Win32ErrorCode $uEx
        if ($s1PendingCode -ne 32 -and $s1PendingCode -ne 5 -and $s1PendingCode -ne 303) {
            throw "Suite 1: File.Open while pending threw unexpected native error ${s1PendingCode}: $($uEx.Message)"
        }
    }
    Write-Diagnostic "Suite 1: Confirmed File.Open while pending blocked with error ${s1PendingCode}."

    # Caller explicit TryClose 0 / IsClosed true
    $cErr1 = 0
    $close1Ok = $lease1.TryClose([ref]$cErr1)
    if (-not $close1Ok -or $cErr1 -ne 0 -or -not $lease1.IsClosed) {
        throw "Suite 1: TryClose failed on pending lease: ok=$close1Ok, err=$cErr1, IsClosed=$($lease1.IsClosed)"
    }
    $lease1Rec.Closed = $true
    Write-Diagnostic "Suite 1: Lease closed cleanly (error 0, IsClosed true)."

    # Double close 0 does not reNative close
    $cErr1b = 0
    $close1bOk = $lease1.TryClose([ref]$cErr1b)
    if (-not $close1bOk -or $cErr1b -ne 0 -or -not $lease1.IsClosed) {
        throw "Suite 1: Second TryClose failed on closed lease: ok=$close1bOk, err=$cErr1b"
    }
    if (-not $lease1.IsDeletionPending) {
        throw "Suite 1: IsDeletionPending must remain true as last-known status after closure."
    }

    # Independently actual File.Open OPEN_EXISTING now exact typed Native 2 proves name GONE — not File.Exists false alone
    $s1GoneProbeFs = $null
    $s1GoneCode = -1
    try {
        $s1GoneProbeFs = [System.IO.File]::Open($s1SourcePath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read)
        [void](Register-TrackedStream $s1GoneProbeFs)
        throw "Suite 1: File.Open unexpectedly succeeded after lease closed!"
    } catch {
        if ($s1GoneProbeFs -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        $s1GoneCode = Get-Win32ErrorCode $uEx
        if ($s1GoneCode -ne 2) {
            throw "Suite 1: File.Open after close expected exact native error 2 (ERROR_FILE_NOT_FOUND), got ${s1GoneCode}: $($uEx.Message)"
        }
    }
    Write-Diagnostic "Suite 1: Confirmed source name gone with exact native Win32 error 2."

    # Backup bytes/size/hash UNCHANGED
    $bLenCheck = [System.IO.FileInfo]::new($s1BackupPath).Length
    if ($bLenCheck -ne $s1Len) {
        throw "Suite 1: Backup length changed after source deletion: expected $s1Len, got $bLenCheck"
    }
    $bShaCheck = Compute-Sha256OfFile $s1BackupPath
    if (-not [string]::Equals($bShaCheck, $s1Sha, [System.StringComparison]::Ordinal)) {
        throw "Suite 1: Backup SHA changed after source deletion: expected $s1Sha, got $bShaCheck"
    }

    # Separate Victim UNCHANGED
    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "Suite 1 PASSED: Leased regular file marked for deletion, absence proven with error 2, backup intact."

    # =========================================================================
    # Suite 2: Leased Executable PE Disposition + Launch Interlock + Backup Relaunch
    # =========================================================================
    Write-Diagnostic "=== Running Suite 2: Leased PE Disposition + Launch Interlock ==="

    $s2ExePath = [System.IO.Path]::Combine($sourceFilesDir, "suite2-target-fixture.exe")
    [System.IO.File]::Copy($fixtureExePath, $s2ExePath, $false)
    $s2Len = [System.IO.FileInfo]::new($s2ExePath).Length
    $s2Sha = Compute-Sha256OfFile $s2ExePath
    Write-Diagnostic "Suite 2: Fixture EXE created ($s2Len bytes, SHA: $s2Sha)."

    # Acquire lease on compiled executable
    $lease2 = Invoke-TrackedCreation -ExpectedPath $s2ExePath -Action {
        return [PosDesktop.Updates.Native.SourceFileLease]::Acquire($s2ExePath, $s2Len, $s2Sha)
    }
    $lease2Rec = Register-TrackedLease -Lease $lease2 -Path $s2ExePath

    # Create known backup OUTSIDE source lab subdir via CopyVerifiedTo
    $s2BackupExePath = [System.IO.Path]::Combine($backupFilesDir, "suite2-fixture-backup.exe")
    $s2BackupFs = [System.IO.FileStream]::new($s2BackupExePath, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
    [void](Register-TrackedStream $s2BackupFs)
    $copyRes2 = $lease2.CopyVerifiedTo($s2BackupFs)
    if ($copyRes2.BytesCopied -ne $s2Len -or -not [string]::Equals($copyRes2.Sha256, $s2Sha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: Fixture backup copy verification mismatch."
    }
    $s2BackupFs.Flush($true)
    $s2BackupFs.Dispose()
    Write-Diagnostic "Suite 2: Executable backup established."

    # ACTUAL OwnChild.Start while leased BEFORE mark MUST throw Win32 error 32
    $childEnv = [System.Collections.Generic.Dictionary[string, string]]::new()
    $childEnv["SystemRoot"] = $env:SystemRoot
    $childEnv["WINDIR"] = $env:WINDIR
    $childEnv["TEMP"] = $labTempDir
    $childEnv["TMP"] = $labTempDir

    $s2PreLaunchBlocked = $false
    try {
        $unexpectedOwner1 = Safe-StartOwnedProcess -exePath $s2ExePath -ArgumentVector @("sleep", "30000") -cwd $labDir -env $childEnv -tag "suite2-pre-launch"
        throw "Suite 2: OwnedChildProcess.Start unexpectedly succeeded on leased EXE!"
    } catch {
        $uEx = Unwrap-Exception $_.Exception
        $preCode = Get-Win32ErrorCode $uEx
        if ($preCode -eq 32) {
            $s2PreLaunchBlocked = $true
        } else {
            throw "Suite 2: OwnedChildProcess.Start on leased EXE expected error 32, got ${preCode}: $($uEx.Message)"
        }
    }
    if (-not $s2PreLaunchBlocked) { throw "Suite 2: Error 32 not observed on leased EXE launch attempt." }
    Write-Diagnostic "Suite 2: Confirmed process launch blocked with error 32 on leased EXE."

    # MarkForDeletion ownh, still OPEN
    $lease2.MarkForDeletion()
    if (-not $lease2.IsDeletionPending -or $lease2.IsClosed -or $lease2.IsMutationUncertain) {
        throw "Suite 2: Lease flags mismatch after MarkForDeletion."
    }
    Write-Diagnostic "Suite 2: Executable marked for deletion, handle remains open."

    # Actual direct file open known blocked 32/5/303 while pending
    $s2PendingProbeFs = $null
    $s2PendingCode = -1
    try {
        $s2PendingProbeFs = [System.IO.File]::Open($s2ExePath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
        [void](Register-TrackedStream $s2PendingProbeFs)
        throw "Suite 2: File.Open unexpectedly succeeded while deletion was pending!"
    } catch {
        if ($s2PendingProbeFs -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        $s2PendingCode = Get-Win32ErrorCode $uEx
        if ($s2PendingCode -ne 32 -and $s2PendingCode -ne 5 -and $s2PendingCode -ne 303) {
            throw "Suite 2: File.Open while pending threw unexpected native error ${s2PendingCode}: $($uEx.Message)"
        }
    }
    Write-Diagnostic "Suite 2: Confirmed File.Open while pending blocked with error ${s2PendingCode}."

    # Close OWNh
    $cErr2 = 0
    $close2Ok = $lease2.TryClose([ref]$cErr2)
    if (-not $close2Ok -or $cErr2 -ne 0 -or -not $lease2.IsClosed) {
        throw "Suite 2: TryClose failed: ok=$close2Ok, err=$cErr2"
    }
    $lease2Rec.Closed = $true
    Write-Diagnostic "Suite 2: Executable lease closed cleanly."

    # After OWNh close: actual Open 2 + OwnChild.Start missing FileNotFoundException
    $s2GoneProbeFs = $null
    $s2GoneCode = -1
    try {
        $s2GoneProbeFs = [System.IO.File]::Open($s2ExePath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read)
        [void](Register-TrackedStream $s2GoneProbeFs)
        throw "Suite 2: File.Open unexpectedly succeeded after lease closed!"
    } catch {
        if ($s2GoneProbeFs -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        $s2GoneCode = Get-Win32ErrorCode $uEx
        if ($s2GoneCode -ne 2) {
            throw "Suite 2: File.Open after close expected error 2, got ${s2GoneCode}: $($uEx.Message)"
        }
    }

    $s2FnfSeen = $false
    try {
        $unexpectedOwner2 = Safe-StartOwnedProcess -exePath $s2ExePath -ArgumentVector @("sleep", "30000") -cwd $labDir -env $childEnv -tag "suite2-post-close-launch"
        throw "Suite 2: OwnedChildProcess.Start unexpectedly succeeded after EXE was deleted!"
    } catch {
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.IO.FileNotFoundException]) {
            $s2FnfSeen = $true
        } else {
            $code = Get-Win32ErrorCode $uEx
            if ($code -eq 2) {
                $s2FnfSeen = $true
            } else {
                throw "Suite 2: OwnedChildProcess.Start did not throw FileNotFoundException/2. Got: $($uEx.GetType().FullName): $($uEx.Message)"
            }
        }
    }
    if (-not $s2FnfSeen) { throw "Suite 2: FileNotFoundException not observed on deleted EXE." }
    Write-Diagnostic "Suite 2: Confirmed absence proven via File.Open (error 2) and OwnedChildProcess.Start (FileNotFoundException)."

    # Backup EXE full hash/payload same
    $bExeLen = [System.IO.FileInfo]::new($s2BackupExePath).Length
    if ($bExeLen -ne $s2Len) { throw "Suite 2: Backup EXE length mismatch." }
    $bExeSha = Compute-Sha256OfFile $s2BackupExePath
    if (-not [string]::Equals($bExeSha, $s2Sha, [System.StringComparison]::Ordinal)) { throw "Suite 2: Backup EXE SHA mismatch." }

    # Copying backup into NEW owned fake path after source gone
    $s2NewExePath = [System.IO.Path]::Combine($labDir, "suite2-restored-launchable.exe")
    [System.IO.File]::Copy($s2BackupExePath, $s2NewExePath, $false)
    Write-Diagnostic "Suite 2: Copied backup into new path: $s2NewExePath"

    # Actual OwnChild.Start LIVE / job known >= 1
    $restoredOwner = Safe-StartOwnedProcess -exePath $s2NewExePath -ArgumentVector @("sleep", "30000") -cwd $labDir -env $childEnv -tag "suite2-restored-launch"
    $obsStart = $restoredOwner.Observe()
    if ($obsStart.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 2: Restored process RootState expected Live, got $($obsStart.RootState)"
    }
    if (-not $obsStart.JobCountKnown) {
        throw "Suite 2: Restored process JobCountKnown expected true, got false"
    }
    if ($obsStart.ActiveProcesses -lt 1) {
        throw "Suite 2: Restored process ActiveProcesses expected >= 1, got $($obsStart.ActiveProcesses)"
    }
    if ($restoredOwner.IsClosed) {
        throw "Suite 2: Restored process IsClosed expected false after Start"
    }
    Write-Diagnostic "Suite 2: Restored process LIVE proven via owned handle observation (RootState=Live, JobCountKnown=true, ActiveProcesses=$($obsStart.ActiveProcesses), IsClosed=false)."

    # Bounded owned stop DEAD + known job 0 + closed
    $stopErr = 0
    $stopOk = $restoredOwner.TryStopAndClose(5000, [ref]$stopErr)
    if (-not $stopOk -or $stopErr -ne 0 -or -not $restoredOwner.IsClosed) {
        throw "Suite 2: Failed to stop restored process: ok=$stopOk, err=$stopErr, IsClosed=$($restoredOwner.IsClosed)"
    }
    $obsDead = $restoredOwner.Observe()
    if ($obsDead.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead) {
        throw "Suite 2: Restored process RootState expected Dead after stop, got $($obsDead.RootState)"
    }
    if (-not $obsDead.JobCountKnown) {
        throw "Suite 2: Restored process JobCountKnown expected true after stop, got false"
    }
    if ($obsDead.ActiveProcesses -ne 0) {
        throw "Suite 2: Restored process ActiveProcesses expected 0 after stop, got $($obsDead.ActiveProcesses)"
    }
    if (-not $obsDead.IsQuiescent) {
        throw "Suite 2: Restored process IsQuiescent expected true after stop, got false"
    }
    if ($obsDead.LastError -ne 0) {
        throw "Suite 2: Restored process LastError expected 0 after stop, got $($obsDead.LastError)"
    }

    # Mark trackedOwner closed ONLY after ALL proofs pass
    foreach ($oRec in $script:TrackedOwners) {
        if ([object]::ReferenceEquals($oRec.Owner, $restoredOwner)) {
            $oRec.Closed = $true
        }
    }
    Write-Diagnostic "Suite 2: Restored process DEAD proven via owned handle observation (RootState=Dead, JobCountKnown=true, ActiveProcesses=0, IsQuiescent=true, LastError=0, IsClosed=true)."

    # Separate Victim UNCHANGED
    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "Suite 2 PASSED: Executable disposition, launch interlock, absence proof, and backup relaunch verified."

    # =========================================================================
    # Suite 3: Staged File Target Variant + Failed Create Retainer Rejection
    # =========================================================================
    Write-Diagnostic "=== Running Suite 3: Staged Target Disposition + Failed Retainer Protection ==="

    # Part A: Created target variant via CreateNewVerified validated true
    $targetPayload3 = [System.Text.Encoding]::UTF8.GetBytes("STAGE_TARGET_VALID_PAYLOAD_$nonce")
    $targetLen3 = [long]$targetPayload3.Length

    $shaObj3 = [System.Security.Cryptography.SHA256]::Create()
    $hashBytes3 = $shaObj3.ComputeHash($targetPayload3)
    $shaObj3.Dispose()
    $sb3 = [System.Text.StringBuilder]::new($hashBytes3.Length * 2)
    foreach ($b in $hashBytes3) { [void]$sb3.Append($b.ToString("x2")) }
    $targetSha3 = $sb3.ToString()

    $targetStream3 = [System.IO.MemoryStream]::new($targetPayload3, $false)
    [void](Register-TrackedStream $targetStream3)

    $s3StageTargetPath = [System.IO.Path]::Combine($sourceFilesDir, "suite3-stage-target.dat")
    $stageLease3 = Invoke-TrackedCreation -ExpectedPath $s3StageTargetPath -Action {
        return [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($s3StageTargetPath, $targetLen3, $targetSha3, $targetStream3)
    }
    $stageLease3Rec = Register-TrackedLease -Lease $stageLease3 -Path $s3StageTargetPath

    if ($stageLease3.IsClosed -or $stageLease3.IsMutationUncertain) {
        throw "Suite 3: Staged lease must be open and validated."
    }
    if (-not $targetStream3.CanRead) {
        throw "Suite 3: Source caller stream must remain open after CreateNewVerified."
    }
    Write-Diagnostic "Suite 3: Created staging file; caller stream kept open."

    # Mark target pending + close
    $stageLease3.MarkForDeletion()
    if (-not $stageLease3.IsDeletionPending -or $stageLease3.IsClosed -or $stageLease3.IsMutationUncertain) {
        throw "Suite 3: Staged lease flags mismatch after MarkForDeletion."
    }

    $cErr3 = 0
    $close3Ok = $stageLease3.TryClose([ref]$cErr3)
    if (-not $close3Ok -or $cErr3 -ne 0 -or -not $stageLease3.IsClosed) {
        throw "Suite 3: Staged lease TryClose failed: ok=$close3Ok, err=$cErr3"
    }
    $stageLease3Rec.Closed = $true

    # Actual missing 2, input unchanged
    $stageGoneProbeFs = $null
    $stageGoneCode = -1
    try {
        $stageGoneProbeFs = [System.IO.File]::Open($s3StageTargetPath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read)
        [void](Register-TrackedStream $stageGoneProbeFs)
        throw "Suite 3: Staged target file unexpectedly existed after lease close!"
    } catch {
        if ($stageGoneProbeFs -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        $stageGoneCode = Get-Win32ErrorCode $uEx
        if ($stageGoneCode -ne 2) {
            throw "Suite 3: Staged file after close expected native error 2, got ${stageGoneCode}: $($uEx.Message)"
        }
    }
    if (-not $targetStream3.CanRead) {
        throw "Suite 3: Source caller stream was unexpectedly closed."
    }
    Write-Diagnostic "Suite 3: Confirmed staged file gone with error 2; input stream unchanged."

    # Part B: Failed staging stream size/hash retainer OPEN/unvalidated/uncertain
    $failedStagePath3 = [System.IO.Path]::Combine($sourceFilesDir, "suite3-failed-stage.dat")
    $partialData3 = [System.Text.Encoding]::UTF8.GetBytes("FAILED_STAGE_PARTIAL_BYTES_$nonce")
    $wrongSha3 = "0" * 64
    $failStream3 = [System.IO.MemoryStream]::new($partialData3, $false)
    [void](Register-TrackedStream $failStream3)

    $unexpectedLease3 = $null
    $creationEx3 = $null
    try {
        $unexpectedLease3 = Invoke-TrackedCreation -ExpectedPath $failedStagePath3 -Action {
            return [PosDesktop.Updates.Native.SourceFileLease]::CreateNewVerified($failedStagePath3, [long]$partialData3.Length, $wrongSha3, $failStream3)
        }
        if ($unexpectedLease3 -ne $null) {
            throw "Suite 3: CreateNewVerified unexpectedly succeeded with mismatched SHA!"
        }
        throw "Suite 3: CreateNewVerified returned without throwing exception!"
    } catch {
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx.Message -like "Suite 3: CreateNewVerified unexpectedly succeeded*" -or $uEx.Message -like "Suite 3: CreateNewVerified returned without*") {
            throw
        }
        if ($uEx -is [PosDesktop.Updates.Native.SourceFileLeaseCreationException]) {
            $creationEx3 = $uEx
        } else {
            throw "Suite 3: Expected SourceFileLeaseCreationException, got: $($uEx.GetType().FullName): $($uEx.Message)"
        }
    }

    # Register retainer BEFORE assert!
    $retainedLease3 = $creationEx3.RetainedLease
    if ($retainedLease3 -eq $null) {
        throw "Suite 3: RetainedLease is null on SourceFileLeaseCreationException."
    }
    $retainedRec3 = Register-TrackedLease -Lease $retainedLease3 -Path $failedStagePath3

    if ($retainedLease3.IsClosed) { throw "Suite 3: Retained lease must be OPEN." }
    if (-not $retainedLease3.IsMutationUncertain) { throw "Suite 3: Retained lease must have IsMutationUncertain = true." }

    # MarkForDeletion MUST reject WITHOUT deleting/mutating
    $markRejected3 = $false
    try {
        $retainedLease3.MarkForDeletion()
        throw "Suite 3: MarkForDeletion unexpectedly succeeded on unvalidated retained lease!"
    } catch {
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.InvalidOperationException]) {
            $markRejected3 = $true
        } else {
            throw "Suite 3: MarkForDeletion on unvalidated lease threw unexpected exception: $($uEx.GetType().FullName): $($uEx.Message)"
        }
    }
    if (-not $markRejected3) { throw "Suite 3: MarkForDeletion was not rejected on unvalidated lease." }
    if ($retainedLease3.IsDeletionPending) { throw "Suite 3: IsDeletionPending must remain false on rejected mark." }

    # Still actual sharing 32 / old stage exists
    $probeSharingFs = $null
    $stageSharingCode = -1
    try {
        $probeSharingFs = [System.IO.File]::Open($failedStagePath3, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read)
        [void](Register-TrackedStream $probeSharingFs)
        throw "Suite 3: File.Open unexpectedly succeeded on held retained lease path!"
    } catch {
        if ($probeSharingFs -ne $null) { throw }
        $uEx = Unwrap-Exception $_.Exception
        $stageSharingCode = Get-Win32ErrorCode $uEx
        if ($stageSharingCode -ne 32) {
            throw "Suite 3: Retained file probe expected sharing violation 32, got ${stageSharingCode}: $($uEx.Message)"
        }
    }
    Write-Diagnostic "Suite 3: Confirmed sharing violation 32 still held on retained partial stage path."

    # Then explicit close only
    $cErr3b = 0
    $close3bOk = $retainedLease3.TryClose([ref]$cErr3b)
    if (-not $close3bOk -or $cErr3b -ne 0 -or -not $retainedLease3.IsClosed) {
        throw "Suite 3: TryClose failed on retained lease: ok=$close3bOk, err=$cErr3b"
    }
    $retainedRec3.Closed = $true

    # Actual partial bytes PRESERVED (not deleted!)
    if (-not [System.IO.File]::Exists($failedStagePath3)) {
        throw "Suite 3: Partial staging file was unexpectedly deleted!"
    }
    $diskLen3 = [System.IO.FileInfo]::new($failedStagePath3).Length
    if ($diskLen3 -ne $partialData3.Length) {
        throw "Suite 3: Partial staging file length on disk mismatch: expected $($partialData3.Length), got $diskLen3"
    }
    $diskBytes3 = [System.IO.File]::ReadAllBytes($failedStagePath3)
    for ($i = 0; $i -lt $partialData3.Length; $i++) {
        if ($diskBytes3[$i] -ne $partialData3[$i]) {
            throw "Suite 3: Partial staging file byte mismatch at offset ${i}."
        }
    }
    Write-Diagnostic "Suite 3: Confirmed partial bytes preserved on disk after explicit close."

    # Separate Victim UNCHANGED
    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "Suite 3 PASSED: Staged variant disposition and failed retainer preservation verified."

    # =========================================================================
    # Suite 4: Reentrancy Interlock & Closed Guard Verification
    # =========================================================================
    Write-Diagnostic "=== Running Suite 4: Reentrancy & Closed Guard Protection ==="

    $s4SourcePath = [System.IO.Path]::Combine($sourceFilesDir, "suite4-reentrancy.dat")
    $s4Bytes = [byte[]]::new(131072) # 128 KiB to ensure multiple 64 KiB chunks
    $rng4 = [System.Security.Cryptography.RNGCryptoServiceProvider]::new()
    $rng4.GetBytes($s4Bytes)
    $rng4.Dispose()
    [System.IO.File]::WriteAllBytes($s4SourcePath, $s4Bytes)
    $s4Len = [long]$s4Bytes.Length
    $s4Sha = Compute-Sha256OfFile $s4SourcePath
    Write-Diagnostic "Suite 4: Reentrancy test file created ($s4Len bytes, SHA: $s4Sha)."

    # Acquire lease on source file
    $lease4 = Invoke-TrackedCreation -ExpectedPath $s4SourcePath -Action {
        return [PosDesktop.Updates.Native.SourceFileLease]::Acquire($s4SourcePath, $s4Len, $s4Sha)
    }
    $lease4Rec = Register-TrackedLease -Lease $lease4 -Path $s4SourcePath

    # Reentrancy: custom MemoryStream.Write callback during Source CopyVerifiedTo calls MarkForDeletion
    $callbackStream4 = [PosDesktop.Updates.Native.Testing.ReentrantDispositionCallbackStream]::new()
    $callbackStream4.SetTargetLease($lease4)
    [void](Register-TrackedStream $callbackStream4)

    $copyRes4 = $lease4.CopyVerifiedTo($callbackStream4)
    if ($copyRes4.BytesCopied -ne $s4Len) {
        throw "Suite 4: CopyVerifiedTo bytes copied mismatch: expected $s4Len, got $($copyRes4.BytesCopied)"
    }
    if (-not [string]::Equals($copyRes4.Sha256, $s4Sha, [System.StringComparison]::Ordinal)) {
        throw "Suite 4: CopyVerifiedTo SHA mismatch: expected $s4Sha, got $($copyRes4.Sha256)"
    }

    if (-not $callbackStream4.AttemptedMark) {
        throw "Suite 4: Callback stream did not attempt reentrant MarkForDeletion."
    }
    if ($callbackStream4.MarkException -eq $null -or -not ($callbackStream4.MarkException -is [System.InvalidOperationException])) {
        throw "Suite 4: Reentrant MarkForDeletion did not throw InvalidOperationException. Got: $($callbackStream4.MarkException)"
    }
    if (-not $callbackStream4.AttemptedTryClose) {
        throw "Suite 4: Callback stream did not attempt reentrant TryClose."
    }
    if ($callbackStream4.TryCloseResult -ne $false -or $callbackStream4.TryCloseError -ne 32) {
        throw "Suite 4: Reentrant TryClose expected false with error 32, got ok=$($callbackStream4.TryCloseResult), err=$($callbackStream4.TryCloseError)"
    }
    Write-Diagnostic "Suite 4: Confirmed reentrant MarkForDeletion blocked with InvalidOperationException and TryClose blocked with 32."

    # Pending false / uncertain false / source ID/path/hash same + copy full pin
    if ($lease4.IsDeletionPending) {
        throw "Suite 4: IsDeletionPending must be false after rejected reentrant mark."
    }
    if ($lease4.IsMutationUncertain) {
        throw "Suite 4: IsMutationUncertain must be false after rejected reentrant mark."
    }
    if ($lease4.IsClosed) {
        throw "Suite 4: IsClosed must be false."
    }
    $postReId = $lease4.Identity
    if ($postReId.Size -ne $s4Len) { throw "Suite 4: Size mismatch." }
    if (-not [string]::Equals($postReId.CanonicalPath, [System.IO.Path]::GetFullPath($s4SourcePath), [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 4: CanonicalPath mismatch."
    }
    $postReSha = $lease4.ComputeSha256()
    if (-not [string]::Equals($postReSha, $s4Sha, [System.StringComparison]::Ordinal)) {
        throw "Suite 4: ComputeSha256 digest mismatch."
    }

    # Clean explicit TryClose
    $cErr4 = 0
    $close4Ok = $lease4.TryClose([ref]$cErr4)
    if (-not $close4Ok -or $cErr4 -ne 0 -or -not $lease4.IsClosed) {
        throw "Suite 4: TryClose failed on lease: ok=$close4Ok, err=$cErr4"
    }
    $lease4Rec.Closed = $true
    Write-Diagnostic "Suite 4: Lease closed cleanly."

    # Closed lease Mark reject ObjectDisposed before Native / no namespace changes
    $disposedRejected4 = $false
    try {
        $lease4.MarkForDeletion()
        throw "Suite 4: MarkForDeletion unexpectedly succeeded on closed lease!"
    } catch {
        $uEx = Unwrap-Exception $_.Exception
        if ($uEx -is [System.ObjectDisposedException]) {
            $disposedRejected4 = $true
        } else {
            throw "Suite 4: MarkForDeletion on closed lease threw unexpected exception: $($uEx.GetType().FullName): $($uEx.Message)"
        }
    }
    if (-not $disposedRejected4) { throw "Suite 4: ObjectDisposedException not thrown on closed lease." }

    # Prove no namespace change occurred: file still exists on disk
    if (-not [System.IO.File]::Exists($s4SourcePath)) {
        throw "Suite 4: File was deleted after rejected mark on closed lease!"
    }
    $s4DiskLen = [System.IO.FileInfo]::new($s4SourcePath).Length
    if ($s4DiskLen -ne $s4Len) { throw "Suite 4: File length changed on disk." }
    $s4DiskSha = Compute-Sha256OfFile $s4SourcePath
    if (-not [string]::Equals($s4DiskSha, $s4Sha, [System.StringComparison]::Ordinal)) {
        throw "Suite 4: File SHA changed on disk."
    }
    Write-Diagnostic "Suite 4: Confirmed closed lease throws ObjectDisposedException without namespace changes."

    # Separate Victim UNCHANGED
    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir

    # Truthful runtime coverage notice
    Write-Diagnostic "Suite 4 Notice: Kernel Disposition FALSE / postverify UNKNOWN states are not fault injected; no powerloss/DB/coordinator/bootstrap proof."
    Write-Diagnostic "Suite 4 Notice: Main fixtures delete only through actual shared safe Gate after ALL known resources closed, no mismatch, and no primary errors."
    Write-Diagnostic "Suite 4 PASSED: Reentrancy protection and closed-lease guards verified."

    $script:AllSuitesPassed = $true
    Write-Diagnostic "=== ALL 4 TEST SUITES PASSED SUCCESSFULLY ==="
} catch {
    $script:PrimaryError = $_.Exception
    Write-Diagnostic "FATAL ERROR during test execution: $($script:PrimaryError.Message)"
} finally {
    Close-AllTrackedStreams
    Stop-AllTrackedOwners
    Close-AllTrackedLeases

    $gateResult = Invoke-SharedCleanupSafetyGate -TargetSpecs @(
        @{ Path = $labDir; MarkerName = ".snfd-lab-marker"; Nonce = $nonce }
        @{ Path = $victimDir; MarkerName = ".snfd-victim-marker"; Nonce = $nonce }
    )
    Write-Diagnostic "Cleanup gate result: $gateResult"

    if ($script:PrimaryError -ne $null -or -not $script:AllSuitesPassed -or $gateResult -ne 'CLEANED') {
        [Console]::Error.WriteLine("Verification failed. PrimaryError=$($script:PrimaryError -ne $null), AllPassed=$($script:AllSuitesPassed), Gate=$gateResult")
        exit 1
    }
}

exit 0
