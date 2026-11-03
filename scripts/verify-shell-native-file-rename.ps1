# scripts/verify-shell-native-file-rename.ps1
# Win32 Native Same-Directory Handle Rename Primitive Verifier
# Verifies atomic in-place file rename strictly through an owned Win32 handle.

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
            try {
                $sha.Dispose()
            } catch {
                $shaEx = $_.Exception
                $msg = "SHA disposal failed for '$filePath': $($_.Exception.Message)"
                Write-Diagnostic $msg
                $script:StreamCloseErrors.Add($msg)
            }
        }
        $fsEx = $null
        if ($fs -ne $null) {
            try {
                $fs.Dispose()
            } catch {
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
                if ($cleanupErrors.Count -eq 1) {
                    throw $cleanupErrors[0]
                } else {
                    throw [System.AggregateException]::new("Disposal failure during Compute-Sha256OfFile", $cleanupErrors.ToArray())
                }
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
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfr-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfr-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
[void][System.IO.Directory]::CreateDirectory($labDir)
[void][System.IO.Directory]::CreateDirectory($labTempDir)
[void][System.IO.Directory]::CreateDirectory($victimDir)

$labMarkerPath = [System.IO.Path]::Combine($labDir, ".snfr-lab-marker")
[System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".snfr-victim-marker")
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

# --- Section 4: Process and Lease Helpers & Safety Gate ---

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

$reentrantStreamSource = @"
namespace PosDesktop.Updates.Native.Testing
{
    using System;
    using System.IO;
    using PosDesktop.Updates.Native;

    public static class SourceFileLeaseTestBridge
    {
        public static SourceFileIdentity RenameLiteralNull(SourceFileLease lease)
        {
            return lease.RenameWithinDirectory((string)null);
        }
    }

    public sealed class ReentrantCallbackStream : MemoryStream
    {
        private readonly SourceFileLease _lease;
        public Exception RenameException { get; private set; }
        public bool RenameAttempted { get; private set; }
        public bool TryCloseResult { get; private set; }
        public int TryCloseError { get; private set; }
        public Exception ReadException { get; private set; }
        public bool ReadAttempted { get; private set; }

        public ReentrantCallbackStream(SourceFileLease lease)
        {
            this._lease = lease;
        }

        public override void Write(byte[] buffer, int offset, int count)
        {
            if (!this.RenameAttempted && this._lease != null)
            {
                this.RenameAttempted = true;
                try
                {
                    this._lease.RenameWithinDirectory("reentrant-attempt.dat");
                }
                catch (Exception ex)
                {
                    this.RenameException = ex;
                }

                int err;
                this.TryCloseResult = this._lease.TryClose(out err);
                this.TryCloseError = err;

                this.ReadAttempted = true;
                try
                {
                    this._lease.ReadRange(0, Math.Min(10, (int)this._lease.Identity.Size));
                }
                catch (Exception ex)
                {
                    this.ReadException = ex;
                }
            }
            base.Write(buffer, offset, count);
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

$omegaChar = [char]0x03A9
$fixtureExeName = "shell test owned fixture x64 $omegaChar.exe"
$fixtureExePath = [System.IO.Path]::Combine($labDir, $fixtureExeName)

try {
    # Synchronous unified compilations
    $script:CompilationUncertain = $true
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir
    try {
        # Unit 1: Add-Type parent in-memory types (SourceFileLease + OwnedChildProcess + ReentrantCallbackStream)
        $parentCombinedSource = $sourceFileLeaseSource + "`r`n" + $ownedChildSource + "`r`n" + $reentrantStreamSource
        Add-Type -TypeDefinition $parentCombinedSource
        Write-Diagnostic "Successfully compiled parent in-memory types via Add-Type."

        # Unit 2: Compile console fixture executable (x64) using CodeDom CSharpCodeProvider with provider disposed and no parent assembly mapping
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
        if ($compilePrimaryEx -ne $null) {
            throw $compilePrimaryEx
        }
        if ($disposeEx -ne $null) {
            throw $disposeEx
        }

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

    # Common environment map for process launches
    $envMap = [System.Collections.Generic.Dictionary[string, string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    $envMap["SystemRoot"] = $env:SystemRoot
    $envMap["WINDIR"] = $env:WINDIR
    $envMap["TEMP"] = $labTempDir
    $envMap["TMP"] = $labTempDir

    # =========================================================================
    # --- SUITE 1: EXE Rename + Aside Launch Block 32 + Restore Launch ---
    # =========================================================================
    Write-Diagnostic "--- Running Suite 1: EXE Rename + Aside Launch Block 32 + Restore Launch ---"

    $s1Lease = Safe-AcquireLease -path $fixtureExePath -size $fixtureLen -sha256 $fixtureSha
    $s1Rec = $script:TrackedLeases[$script:TrackedLeases.Count - 1]

    $origIdentity = $s1Lease.Identity
    $origCanonical = $origIdentity.CanonicalPath
    $origVol = $origIdentity.VolumeSerialNumber
    $origIdx = $origIdentity.FileIndex

    if (-not [string]::Equals($origCanonical, $fixtureExePath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 1: Initial canonical path mismatch. Expected: $fixtureExePath, Got: $origCanonical"
    }

    # Atomic rename to aside leaf with spaces and unicode Omega
    $asideLeaf = "shell test aside renamed $omegaChar.exe"
    $asideExePath = [System.IO.Path]::Combine($labDir, $asideLeaf)

    Write-Diagnostic "Suite 1: Renaming leased EXE to '$asideLeaf'..."
    $asideIdentity = $s1Lease.RenameWithinDirectory($asideLeaf)

    # Verify returned identity metadata
    if (-not [string]::Equals($asideIdentity.CanonicalPath, $asideExePath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 1: Aside canonical path mismatch. Expected: $asideExePath, Got: $($asideIdentity.CanonicalPath)"
    }
    if ($asideIdentity.Size -ne $fixtureLen) {
        throw "Suite 1: Aside size mismatch. Expected: $fixtureLen, Got: $($asideIdentity.Size)"
    }
    if ($asideIdentity.VolumeSerialNumber -ne $origVol) {
        throw "Suite 1: Aside volume serial mismatch. Expected: $origVol, Got: $($asideIdentity.VolumeSerialNumber)"
    }
    if ($asideIdentity.FileIndex -ne $origIdx) {
        throw "Suite 1: Aside file index mismatch. Expected: $origIdx, Got: $($asideIdentity.FileIndex)"
    }
    if ($s1Lease.IsMutationUncertain) {
        throw "Suite 1: Lease IsMutationUncertain is true after successful rename."
    }

    # Verify filesystem state
    if ([System.IO.File]::Exists($fixtureExePath)) {
        throw "Suite 1: Original file path still exists after rename: $fixtureExePath"
    }
    if (-not [System.IO.File]::Exists($asideExePath)) {
        throw "Suite 1: Aside file path does not exist after rename: $asideExePath"
    }

    # Verify I/O through handle on renamed file
    $asideComputedSha = $s1Lease.ComputeSha256()
    if (-not [string]::Equals($asideComputedSha, $fixtureSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 1: ComputeSha256 on renamed file mismatch: $asideComputedSha vs $fixtureSha"
    }
    $mzBytes = $s1Lease.ReadRange(0, 2)
    if ($mzBytes.Length -ne 2 -or $mzBytes[0] -ne 0x4D -or $mzBytes[1] -ne 0x5A) {
        throw "Suite 1: MZ header check failed on renamed file."
    }

    $memStream = Register-TrackedStream ([System.IO.MemoryStream]::new())
    $copyRes = $s1Lease.CopyVerifiedTo($memStream)
    if ($copyRes.BytesCopied -ne $fixtureLen -or -not [string]::Equals($copyRes.Sha256, $fixtureSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 1: CopyVerifiedTo on renamed file failed."
    }

    # Test process launch from leased aside path: MUST FAIL with Win32 error 32 (share mode 0 held)
    Write-Diagnostic "Suite 1: Attempting process launch on leased aside path (expecting Win32 error 32)..."
    $launchBlocked = $false
    $launchBlockedNativeCode = -1
    $unexpectedOwner = $null
    try {
        $unexpectedOwner = Safe-StartOwnedProcess -exePath $asideExePath -ArgumentVector @("sleep", "2000") -cwd $labDir -env $envMap -tag "Suite1-AsideBlocked"
    } catch {
        $curr = $_.Exception
        while ($curr -ne $null) {
            if ($curr -is [System.ComponentModel.Win32Exception]) {
                $launchBlockedNativeCode = $curr.NativeErrorCode
                if ($launchBlockedNativeCode -eq 32) {
                    $launchBlocked = $true
                    break
                }
            }
            $curr = $curr.InnerException
        }
        if (-not $launchBlocked) {
            throw "Suite 1: Process launch failed but expected Win32 error 32. Got NativeCode ${launchBlockedNativeCode}: $($_.Exception.Message)"
        }
    }
    if ($unexpectedOwner -ne $null) {
        throw "Suite 1: Process launch from leased aside path unexpectedly SUCCEEDED!"
    }
    Write-Diagnostic "Suite 1: Confirmed process launch blocked with Win32 error 32 (sharing violation) on leased aside path."

    # Test process launch on original path: MUST FAIL with FileNotFoundException (Win32 error 2)
    Write-Diagnostic "Suite 1: Attempting process launch on original path (expecting file not found)..."
    $origBlocked = $false
    try {
        $null = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("sleep", "2000") -cwd $labDir -env $envMap -tag "Suite1-OrigNotFound"
    } catch {
        $curr = $_.Exception
        while ($curr -ne $null) {
            if ($curr -is [System.IO.FileNotFoundException]) {
                $origBlocked = $true
                break
            }
            $curr = $curr.InnerException
        }
    }
    if (-not $origBlocked) {
        throw "Suite 1: Process launch on non-existent original path did not throw FileNotFoundException."
    }
    Write-Diagnostic "Suite 1: Confirmed original path launch blocked (file not found)."

    # Restore fixture to original name
    Write-Diagnostic "Suite 1: Restoring fixture to original leaf name..."
    $restoredIdentity = $s1Lease.RenameWithinDirectory($fixtureExeName)
    if (-not [string]::Equals($restoredIdentity.CanonicalPath, $fixtureExePath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 1: Restored canonical path mismatch. Expected: $fixtureExePath, Got: $($restoredIdentity.CanonicalPath)"
    }
    if ($restoredIdentity.FileIndex -ne $origIdx -or $restoredIdentity.VolumeSerialNumber -ne $origVol) {
        throw "Suite 1: Restored identity index or volume mismatch."
    }
    if ($s1Lease.IsMutationUncertain) {
        throw "Suite 1: Lease IsMutationUncertain is true after restore."
    }

    # Verify filesystem post-restore
    if ([System.IO.File]::Exists($asideExePath)) {
        throw "Suite 1: Aside path still exists after restore: $asideExePath"
    }
    if (-not [System.IO.File]::Exists($fixtureExePath)) {
        throw "Suite 1: Restored path does not exist: $fixtureExePath"
    }

    # Cleanly close lease before process launch
    if (-not (Close-TrackedLease $s1Rec)) {
        throw "Suite 1: Failed to close fixture lease."
    }
    Write-Diagnostic "Suite 1: Fixture lease closed cleanly."

    # Launch restored fixture process now that lease is closed
    Write-Diagnostic "Suite 1: Launching restored fixture process..."
    $restoredOwner = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("sleep", "5000") -cwd $labDir -env $envMap -tag "Suite1-RestoredRunning"
    $restoredRec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]

    $obs = $restoredOwner.Observe()
    if ($obs.ActiveProcesses -lt 1) {
        throw "Suite 1: Restored process active processes < 1 (ActiveProcesses: $($obs.ActiveProcesses))."
    }
    Write-Diagnostic "Suite 1: Restored process running (PID: $($restoredOwner.ProcessId), ActiveProcesses: $($obs.ActiveProcesses))."

    # Stop restored process cleanly
    if (-not (Stop-TrackedOwner -ownerRec $restoredRec -timeoutMs 5000)) {
        throw "Suite 1: Failed to stop restored fixture process."
    }
    Write-Diagnostic "Suite 1: Restored process stopped and owner closed cleanly."

    # Re-verify fixture file integrity post-launch
    $postLaunchLen = [System.IO.FileInfo]::new($fixtureExePath).Length
    $postLaunchSha = Compute-Sha256OfFile $fixtureExePath
    if ($postLaunchLen -ne $fixtureLen -or -not [string]::Equals($postLaunchSha, $fixtureSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 1: Fixture file integrity changed after process run."
    }
    Write-Diagnostic "Suite 1 PASSED: EXE rename, aside launch block 32, restore, and post-restore launch verified."

    # =========================================================================
    # --- SUITE 2: Non-Replace Collision ---
    # =========================================================================
    Write-Diagnostic "--- Running Suite 2: Non-Replace Collision ---"

    $colSrcPath = [System.IO.Path]::Combine($labDir, "collision-src-$nonce.dat")
    $colSrcBytes = [System.Text.Encoding]::UTF8.GetBytes("COLLISION_SOURCE_DATA_$nonce")
    [System.IO.File]::WriteAllBytes($colSrcPath, $colSrcBytes)
    $colSrcLen = [int64]$colSrcBytes.Length
    $colSrcSha = Compute-Sha256OfFile $colSrcPath

    $colDestLeaf = "collision-dest-$nonce.dat"
    $colDestPath = [System.IO.Path]::Combine($labDir, $colDestLeaf)
    $colDestBytes = [System.Text.Encoding]::UTF8.GetBytes("COLLISION_DEST_EXISTING_DATA_$nonce")
    [System.IO.File]::WriteAllBytes($colDestPath, $colDestBytes)
    $colDestLen = [int64]$colDestBytes.Length
    $colDestSha = Compute-Sha256OfFile $colDestPath

    $colLease = Safe-AcquireLease -path $colSrcPath -size $colSrcLen -sha256 $colSrcSha
    $colRec = $script:TrackedLeases[$script:TrackedLeases.Count - 1]

    $colSrcId = $colLease.Identity
    $colSrcVol = $colSrcId.VolumeSerialNumber
    $colSrcIdx = $colSrcId.FileIndex
    $colSrcSize = $colSrcId.Size
    $colSrcCanon = $colSrcId.CanonicalPath

    # Attempt rename to existing destination leaf (non-replace collision)
    Write-Diagnostic "Suite 2: Attempting rename to existing file leaf '$colDestLeaf' (must fail)..."
    $colFailed = $false
    $colNativeErr = -1
    try {
        $null = $colLease.RenameWithinDirectory($colDestLeaf)
    } catch {
        $curr = $_.Exception
        $depth = 0
        while ($curr -ne $null -and $depth -lt 5) {
            if ($curr -is [System.IO.IOException] -and $curr.InnerException -is [System.ComponentModel.Win32Exception]) {
                $errCode = ([System.ComponentModel.Win32Exception]$curr.InnerException).NativeErrorCode
                if ($errCode -eq 5 -or $errCode -eq 80 -or $errCode -eq 183) {
                    $colNativeErr = $errCode
                    $colFailed = $true
                    break
                } else {
                    throw "Suite 2: Collision threw Win32Exception with unexpected native code $errCode (allowed: 5, 80, 183): $($curr.Message)"
                }
            }
            $curr = $curr.InnerException
            $depth++
        }
        if (-not $colFailed) {
            throw "Suite 2: Collision failed but did not contain typed IOException with inner Win32Exception (allowed codes: 5, 80, 183): $($_.Exception.Message)"
        }
    }
    if (-not $colFailed) {
        throw "Suite 2: Rename to existing file leaf unexpectedly SUCCEEDED!"
    }
    Write-Diagnostic "Suite 2: Confirmed collision rejected with native Win32 error $colNativeErr."

    # Verify source lease state after failed collision
    if ($colLease.IsMutationUncertain) {
        throw "Suite 2: Source lease IsMutationUncertain is true after rejected collision."
    }
    if ($colLease.Identity.VolumeSerialNumber -ne $colSrcVol) {
        throw "Suite 2: Source lease VolumeSerialNumber changed after rejected collision."
    }
    if ($colLease.Identity.FileIndex -ne $colSrcIdx) {
        throw "Suite 2: Source lease FileIndex changed after rejected collision."
    }
    if ($colLease.Identity.Size -ne $colSrcSize) {
        throw "Suite 2: Source lease Size changed after rejected collision."
    }
    if (-not [string]::Equals($colLease.Identity.CanonicalPath, $colSrcCanon, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 2: Source lease CanonicalPath changed after rejected collision."
    }
    $colSrcVerifySha = $colLease.ComputeSha256()
    if (-not [string]::Equals($colSrcVerifySha, $colSrcSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: Source lease SHA-256 changed after rejected collision."
    }
    if (-not [System.IO.File]::Exists($colSrcPath)) {
        throw "Suite 2: Source file missing on disk after rejected collision."
    }
    if ([System.IO.FileInfo]::new($colSrcPath).Length -ne $colSrcLen) {
        throw "Suite 2: Source file length on disk changed after rejected collision."
    }
    if (-not [string]::Equals(($colLease.ComputeSha256()), $colSrcSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: Source file hash on disk changed after rejected collision."
    }

    # Verify existing destination file completely unmodified
    if (-not [System.IO.File]::Exists($colDestPath)) {
        throw "Suite 2: Destination file missing on disk after collision attempt."
    }
    $colDestVerifyLen = [System.IO.FileInfo]::new($colDestPath).Length
    $colDestVerifySha = Compute-Sha256OfFile $colDestPath
    $colDestVerifyBytes = [System.IO.File]::ReadAllBytes($colDestPath)
    if ($colDestVerifyLen -ne $colDestLen -or -not [string]::Equals($colDestVerifySha, $colDestSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: Destination file content was modified by collision attempt."
    }
    if ($colDestVerifyBytes.Length -ne $colDestBytes.Length) {
        throw "Suite 2: Destination file byte count mismatch after collision attempt."
    }
    for ($i = 0; $i -lt $colDestBytes.Length; $i++) {
        if ($colDestVerifyBytes[$i] -ne $colDestBytes[$i]) {
            throw "Suite 2: Destination file byte mismatch at index $i after collision attempt."
        }
    }
    Write-Diagnostic "Suite 2: Confirmed existing destination file remained completely intact."

    # Test collision with existing directory leaf
    $colDirLeaf = "collision-subdir-$nonce"
    $colDirPath = [System.IO.Path]::Combine($labDir, $colDirLeaf)
    [void][System.IO.Directory]::CreateDirectory($colDirPath)

    # Fresh nonce witness file inside existing directory created BEFORE attempt (never recreated to mask)
    $colDirWitnessLeaf = "dir-witness-$nonce.dat"
    $colDirWitnessPath = [System.IO.Path]::Combine($colDirPath, $colDirWitnessLeaf)
    $colDirWitnessBytes = [System.Text.Encoding]::UTF8.GetBytes("DIR_WITNESS_DATA_$nonce")
    [System.IO.File]::WriteAllBytes($colDirWitnessPath, $colDirWitnessBytes)
    $colDirWitnessLen = [int64]$colDirWitnessBytes.Length
    $colDirWitnessSha = Compute-Sha256OfFile $colDirWitnessPath

    Write-Diagnostic "Suite 2: Attempting rename to existing directory leaf '$colDirLeaf' (must fail)..."
    $dirColFailed = $false
    $dirColNativeErr = -1
    try {
        $null = $colLease.RenameWithinDirectory($colDirLeaf)
    } catch {
        $curr = $_.Exception
        $depth = 0
        while ($curr -ne $null -and $depth -lt 5) {
            if ($curr -is [System.IO.IOException] -and $curr.InnerException -is [System.ComponentModel.Win32Exception]) {
                $errCode = ([System.ComponentModel.Win32Exception]$curr.InnerException).NativeErrorCode
                if ($errCode -eq 5 -or $errCode -eq 80 -or $errCode -eq 183) {
                    $dirColNativeErr = $errCode
                    $dirColFailed = $true
                    break
                } else {
                    throw "Suite 2: Directory collision threw Win32Exception with unexpected native code $errCode (allowed: 5, 80, 183): $($curr.Message)"
                }
            }
            $curr = $curr.InnerException
            $depth++
        }
        if (-not $dirColFailed) {
            throw "Suite 2: Directory collision failed but did not contain typed IOException with inner Win32Exception (allowed codes: 5, 80, 183): $($_.Exception.Message)"
        }
    }
    if (-not $dirColFailed) {
        throw "Suite 2: Rename to existing directory leaf unexpectedly SUCCEEDED!"
    }
    Write-Diagnostic "Suite 2: Confirmed directory collision rejected with native Win32 error $dirColNativeErr."

    # Prove source ID, volume, size, path, hash unchanged and IsMutationUncertain false
    if ($colLease.IsMutationUncertain) {
        throw "Suite 2: Source lease IsMutationUncertain is true after directory collision."
    }
    if ($colLease.Identity.VolumeSerialNumber -ne $colSrcVol) {
        throw "Suite 2: Source lease VolumeSerialNumber changed after directory collision."
    }
    if ($colLease.Identity.FileIndex -ne $colSrcIdx) {
        throw "Suite 2: Source lease FileIndex changed after directory collision."
    }
    if ($colLease.Identity.Size -ne $colSrcSize) {
        throw "Suite 2: Source lease Size changed after directory collision."
    }
    if (-not [string]::Equals($colLease.Identity.CanonicalPath, $colSrcCanon, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 2: Source lease CanonicalPath changed after directory collision."
    }
    $dirColSrcVerifySha = $colLease.ComputeSha256()
    if (-not [string]::Equals($dirColSrcVerifySha, $colSrcSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: Source lease SHA-256 changed after directory collision."
    }
    if (-not [System.IO.File]::Exists($colSrcPath)) {
        throw "Suite 2: Source file missing on disk after directory collision."
    }
    if ([System.IO.FileInfo]::new($colSrcPath).Length -ne $colSrcLen) {
        throw "Suite 2: Source file length on disk changed after directory collision."
    }
    if (-not [string]::Equals(($colLease.ComputeSha256()), $colSrcSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: Source file hash on disk changed after directory collision."
    }

    # Prove directory and existing fresh nonce witness file bytes, length, hash UNCHANGED (not Exists alone)
    if (-not [System.IO.Directory]::Exists($colDirPath)) {
        throw "Suite 2: Existing directory missing after collision attempt."
    }
    if (-not [System.IO.File]::Exists($colDirWitnessPath)) {
        throw "Suite 2: Existing directory witness file missing after collision attempt."
    }
    $dirWitnessVerifyLen = [System.IO.FileInfo]::new($colDirWitnessPath).Length
    if ($dirWitnessVerifyLen -ne $colDirWitnessLen) {
        throw "Suite 2: Directory witness file length changed after collision attempt: expected $colDirWitnessLen, got $dirWitnessVerifyLen."
    }
    $dirWitnessVerifySha = Compute-Sha256OfFile $colDirWitnessPath
    if (-not [string]::Equals($dirWitnessVerifySha, $colDirWitnessSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 2: Directory witness file hash changed after collision attempt."
    }
    $dirWitnessVerifyBytes = [System.IO.File]::ReadAllBytes($colDirWitnessPath)
    if ($dirWitnessVerifyBytes.Length -ne $colDirWitnessBytes.Length) {
        throw "Suite 2: Directory witness file byte count mismatch after collision attempt."
    }
    for ($i = 0; $i -lt $colDirWitnessBytes.Length; $i++) {
        if ($dirWitnessVerifyBytes[$i] -ne $colDirWitnessBytes[$i]) {
            throw "Suite 2: Directory witness file byte mismatch at index $i after collision attempt."
        }
    }
    Write-Diagnostic "Suite 2: Confirmed directory collision rejected and directory witness file bytes, length, and hash unchanged."

    # Valid rename to unused leaf name and restore back
    $validLeaf = "collision-renamed-ok-$nonce.dat"
    $validDestPath = [System.IO.Path]::Combine($labDir, $validLeaf)
    $validIdentity = $colLease.RenameWithinDirectory($validLeaf)
    if (-not [string]::Equals($validIdentity.CanonicalPath, $validDestPath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 2: Valid rename CanonicalPath mismatch."
    }
    $null = $colLease.RenameWithinDirectory("collision-src-$nonce.dat")

    if (-not (Close-TrackedLease $colRec)) {
        throw "Suite 2: Failed to close collision lease."
    }
    Write-Diagnostic "Suite 2 PASSED: Non-replace collision semantics and untouched destination verified."

    # =========================================================================
    # --- SUITE 3: Invalid Leaf Rejection & Boundary Checks ---
    # =========================================================================
    Write-Diagnostic "--- Running Suite 3: Invalid Leaf Rejection & Boundary Checks ---"

    $invSrcPath = [System.IO.Path]::Combine($labDir, "invalid-leaf-source-$nonce.dat")
    $invSrcBytes = [System.Text.Encoding]::UTF8.GetBytes("INVALID_LEAF_SOURCE_DATA_$nonce")
    [System.IO.File]::WriteAllBytes($invSrcPath, $invSrcBytes)
    $invSrcLen = [int64]$invSrcBytes.Length
    $invSrcSha = Compute-Sha256OfFile $invSrcPath

    $invLease = Safe-AcquireLease -path $invSrcPath -size $invSrcLen -sha256 $invSrcSha
    $invRec = $script:TrackedLeases[$script:TrackedLeases.Count - 1]

    $invalidTestCases = @(
        @{ Label = "Null leaf"; Leaf = $null; ExpectedType = "ArgumentNullException"; HelperName = "RenameLiteralNull" },
        @{ Label = "Empty string"; Leaf = ""; ExpectedType = "ArgumentException" },
        @{ Label = "Whitespace only"; Leaf = "   "; ExpectedType = "ArgumentException" },
        @{ Label = "Single dot"; Leaf = "."; ExpectedType = "ArgumentException" },
        @{ Label = "Double dot"; Leaf = ".."; ExpectedType = "ArgumentException" },
        @{ Label = "Trailing dot"; Leaf = "bad."; ExpectedType = "ArgumentException" },
        @{ Label = "Trailing space"; Leaf = "bad "; ExpectedType = "ArgumentException" },
        @{ Label = "8.3 short name alias"; Leaf = "PROGRA~1.TXT"; ExpectedType = "ArgumentException" },
        @{ Label = "Forward slash"; Leaf = "sub/file.dat"; ExpectedType = "ArgumentException" },
        @{ Label = "Backslash"; Leaf = "sub\file.dat"; ExpectedType = "ArgumentException" },
        @{ Label = "Parent traversal"; Leaf = "..\file.dat"; ExpectedType = "ArgumentException" },
        @{ Label = "Colon / ADS"; Leaf = "file:stream"; ExpectedType = "ArgumentException" },
        @{ Label = "Wildcard asterisk"; Leaf = "file*star.dat"; ExpectedType = "ArgumentException" },
        @{ Label = "Wildcard question"; Leaf = "file?quest.dat"; ExpectedType = "ArgumentException" },
        @{ Label = "Less than"; Leaf = "file<lt.dat"; ExpectedType = "ArgumentException" },
        @{ Label = "Greater than"; Leaf = "file>gt.dat"; ExpectedType = "ArgumentException" },
        @{ Label = "Pipe"; Leaf = "file|pipe.dat"; ExpectedType = "ArgumentException" },
        @{ Label = "Quote"; Leaf = '"quoted"'; ExpectedType = "ArgumentException" },
        @{ Label = "Control NUL"; Leaf = "bad" + ([char]0) + ".txt"; ExpectedType = "ArgumentException" },
        @{ Label = "Control SOH"; Leaf = "bad" + ([char]1) + ".txt"; ExpectedType = "ArgumentException" },
        @{ Label = "Control 0x1F"; Leaf = "bad" + ([char]0x1F) + ".txt"; ExpectedType = "ArgumentException" },
        @{ Label = "Control DEL (0x7F)"; Leaf = "bad" + ([char]0x7F) + ".txt"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device CON"; Leaf = "CON"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device PRN"; Leaf = "prn"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device AUX"; Leaf = "AUX"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device NUL"; Leaf = "nul"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device CONIN$"; Leaf = "CONIN$"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device CONOUT$"; Leaf = "conout$"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device COM1"; Leaf = "COM1"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device COM9"; Leaf = "com9"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device LPT1"; Leaf = "LPT1"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device LPT9"; Leaf = "lpt9"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device CON.txt"; Leaf = "CON.txt"; ExpectedType = "ArgumentException" },
        @{ Label = "DOS device PRN.log"; Leaf = "prn.log"; ExpectedType = "ArgumentException" },
        @{ Label = "Superscript COM1"; Leaf = "COM" + ([char]0x00B9); ExpectedType = "ArgumentException" },
        @{ Label = "Superscript LPT2"; Leaf = "lpt" + ([char]0x00B2); ExpectedType = "ArgumentException" },
        @{ Label = "Unpaired high surrogate"; Leaf = ([string]([char]0xD800)) + "file.txt"; ExpectedType = "ArgumentException" },
        @{ Label = "Unpaired low surrogate"; Leaf = ([string]([char]0xDC00)) + "file.txt"; ExpectedType = "ArgumentException" },
        @{ Label = "Identical source leaf"; Leaf = "invalid-leaf-source-$nonce.dat"; ExpectedType = "ArgumentException" },
        @{ Label = "Case-only alias"; Leaf = ("invalid-leaf-source-$nonce.dat").ToUpperInvariant(); ExpectedType = "ArgumentException" },
        @{ Label = "Exceeds 255 chars"; Leaf = [string]::new('a', 256); ExpectedType = "ArgumentException" }
    )

    foreach ($tc in $invalidTestCases) {
        $threwExpected = $false
        try {
            if ($tc.ContainsKey('HelperName') -and $tc['HelperName'] -eq "RenameLiteralNull") {
                $null = [PosDesktop.Updates.Native.Testing.SourceFileLeaseTestBridge]::RenameLiteralNull($invLease)
            } else {
                $null = $invLease.RenameWithinDirectory($tc.Leaf)
            }
        } catch {
            $curr = $_.Exception
            $depth = 0
            while ($curr -ne $null -and $depth -lt 5) {
                if ($tc.ExpectedType -eq "ArgumentNullException" -and $curr -is [System.ArgumentNullException]) {
                    $threwExpected = $true
                    break
                }
                if ($tc.ExpectedType -eq "ArgumentException" -and $curr -is [System.ArgumentException]) {
                    $threwExpected = $true
                    break
                }
                $curr = $curr.InnerException
                $depth++
            }
        }
        if (-not $threwExpected) {
            throw "Suite 3: Test case '$($tc.Label)' failed to throw expected $($tc.ExpectedType)."
        }
        if ($invLease.IsMutationUncertain) {
            throw "Suite 3: Lease entered uncertain mutation state on invalid leaf rejection: '$($tc.Label)'."
        }
    }
    Write-Diagnostic "Suite 3: Verified $($invalidTestCases.Count) invalid destination leaf test cases rejected correctly."

    # Verify source lease intact after all rejected attempts
    if (-not [string]::Equals($invLease.Identity.CanonicalPath, $invSrcPath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 3: Source canonical path changed after invalid leaf tests."
    }
    $invVerifySha = $invLease.ComputeSha256()
    if (-not [string]::Equals($invVerifySha, $invSrcSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 3: Source SHA-256 changed after invalid leaf tests."
    }

    # Test closed lease rejection
    if (-not (Close-TrackedLease $invRec)) {
        throw "Suite 3: Failed to close lease."
    }
    $closedThrewDisposed = $false
    try {
        $null = $invLease.RenameWithinDirectory("any-valid-leaf.dat")
    } catch {
        $curr = $_.Exception
        while ($curr -ne $null) {
            if ($curr -is [System.ObjectDisposedException]) {
                $closedThrewDisposed = $true
                break
            }
            $curr = $curr.InnerException
        }
    }
    if (-not $closedThrewDisposed) {
        throw "Suite 3: RenameWithinDirectory on closed lease did not throw ObjectDisposedException."
    }
    Write-Diagnostic "Suite 3: Confirmed closed lease throws ObjectDisposedException."
    Write-Diagnostic "Suite 3 PASSED: All invalid leaf names and closed guards rejected cleanly."

    # =========================================================================
    # --- SUITE 4: Reentrancy During CopyVerifiedTo & Coverage Diagnostics ---
    # =========================================================================
    Write-Diagnostic "--- Running Suite 4: Reentrancy During CopyVerifiedTo & Coverage Diagnostics ---"

    $reSrcPath = [System.IO.Path]::Combine($labDir, "reentrant-source-$nonce.dat")
    $chunkCount = 2
    $chunkSize = 65536
    $totalReBytes = $chunkCount * $chunkSize
    $reBytes = [byte[]]::new($totalReBytes)
    for ($i = 0; $i -lt $totalReBytes; $i++) {
        $reBytes[$i] = [byte](($i * 31 + 17) % 251)
    }
    [System.IO.File]::WriteAllBytes($reSrcPath, $reBytes)
    $reSha = Compute-Sha256OfFile $reSrcPath
    Write-Diagnostic "Suite 4: Reentrant test file created ($totalReBytes bytes, SHA: $reSha)."

    $reLease = Safe-AcquireLease -path $reSrcPath -size $totalReBytes -sha256 $reSha
    $reRec = $script:TrackedLeases[$script:TrackedLeases.Count - 1]

    $callbackStream = Register-TrackedStream ([PosDesktop.Updates.Native.Testing.ReentrantCallbackStream]::new($reLease))

    Write-Diagnostic "Suite 4: Calling CopyVerifiedTo with reentrant callback stream..."
    $reCopyResult = $reLease.CopyVerifiedTo($callbackStream)

    if ($reCopyResult.BytesCopied -ne $totalReBytes) {
        throw "Suite 4: CopyVerifiedTo bytes copied mismatch: expected $totalReBytes, got $($reCopyResult.BytesCopied)"
    }
    if (-not [string]::Equals($reCopyResult.Sha256, $reSha, [System.StringComparison]::Ordinal)) {
        throw "Suite 4: CopyVerifiedTo SHA-256 mismatch: expected $reSha, got $($reCopyResult.Sha256)"
    }

    # Verify callback assertions
    if (-not $callbackStream.RenameAttempted) {
        throw "Suite 4: Callback stream did not attempt reentrant rename."
    }
    if ($callbackStream.RenameException -eq $null -or -not ($callbackStream.RenameException -is [System.InvalidOperationException])) {
        throw "Suite 4: Reentrant rename did not throw InvalidOperationException. Got: $($callbackStream.RenameException)"
    }
    if ($callbackStream.RenameException.Message.IndexOf("Reentrant or concurrent I/O detected", [System.StringComparison]::OrdinalIgnoreCase) -eq -1) {
        throw "Suite 4: Reentrant rename exception message mismatch: $($callbackStream.RenameException.Message)"
    }

    if ($callbackStream.TryCloseResult -ne $false) {
        throw "Suite 4: Reentrant TryClose unexpectedly returned true."
    }
    if ($callbackStream.TryCloseError -ne 32) {
        throw "Suite 4: Reentrant TryClose error code mismatch: expected 32, got $($callbackStream.TryCloseError)"
    }

    if (-not $callbackStream.ReadAttempted) {
        throw "Suite 4: Callback stream did not attempt reentrant ReadRange."
    }
    if ($callbackStream.ReadException -eq $null -or -not ($callbackStream.ReadException -is [System.InvalidOperationException])) {
        throw "Suite 4: Reentrant ReadRange did not throw InvalidOperationException. Got: $($callbackStream.ReadException)"
    }
    if ($callbackStream.ReadException.Message.IndexOf("Reentrant or concurrent I/O detected", [System.StringComparison]::OrdinalIgnoreCase) -eq -1) {
        throw "Suite 4: Reentrant ReadRange exception message mismatch: $($callbackStream.ReadException.Message)"
    }
    Write-Diagnostic "Suite 4: Reentrant operations during CopyVerifiedTo confirmed blocked with InvalidOperationException and error 32."

    # Verify post-copy lease state
    if ($reLease.IsClosed) {
        throw "Suite 4: Lease IsClosed is true after CopyVerifiedTo."
    }
    if ($reLease.IsMutationUncertain) {
        throw "Suite 4: Lease IsMutationUncertain is true after CopyVerifiedTo."
    }
    if (-not [string]::Equals($reLease.Identity.CanonicalPath, $reSrcPath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 4: Lease CanonicalPath mismatch after CopyVerifiedTo."
    }

    if (-not (Close-TrackedLease $reRec)) {
        throw "Suite 4: Failed to close reentrant test lease."
    }
    Write-Diagnostic "Suite 4: Lease closed cleanly."

    # Coverage diagnostic notices
    Write-Diagnostic "Suite 4 Notice: Win32 SetFileInformationByHandle with FileRenameInfo is an atomic same-directory handle primitive; cross-volume moves are not supported by design."
    Write-Diagnostic "Suite 4 Notice: No kernel fault/post-rename verification failure injected; uncertain mutation branch reviewed, not runtime-proven."
    Write-Diagnostic "Suite 4 PASSED: Reentrant lock protection across nested copy/rename/read/close verified."

    $script:AllSuitesPassed = $true
    Write-Diagnostic "=== ALL 4 VERIFICATION SUITES COMPLETED SUCCESSFULLY ==="

} catch {
    $script:PrimaryError = $_.Exception
    Write-Diagnostic "FATAL ERROR during test execution: $($_.Exception.ToString())"
} finally {
    Write-Diagnostic "--- Beginning Section 10 Teardown and Final Safety Gate ---"
    $env:TEMP = $origTemp; $env:TMP = $origTmp

    # Step 1: Close all tracked streams independently
    Close-AllTrackedStreams

    # Step 2: Stop and close all tracked process owners independently
    Stop-AllTrackedOwners

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
        @{ Path = $labDir; MarkerName = ".snfr-lab-marker"; Nonce = $nonce }
        @{ Path = $victimDir; MarkerName = ".snfr-victim-marker"; Nonce = $nonce }
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

Write-Diagnostic "All 4 test suites passed successfully and cleanup gate verified."
exit 0
