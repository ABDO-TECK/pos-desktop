# scripts/verify-shell-native-file-io.ps1
# Win32 Native File I/O Oracle and Leased Stream-Copy Verifier
# Verifies exclusive handle leased reader, range reads, and verified stream-copy primitive.

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
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfio-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfio-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
[void][System.IO.Directory]::CreateDirectory($labDir)
[void][System.IO.Directory]::CreateDirectory($labTempDir)
[void][System.IO.Directory]::CreateDirectory($victimDir)

$labMarkerPath = [System.IO.Path]::Combine($labDir, ".snfio-lab-marker")
[System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".snfio-victim-marker")
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
$script:TrackedLeases = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedStreams = [System.Collections.Generic.List[System.IO.Stream]]::new()
$script:StreamCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:LeaseCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:CompilationUncertain = $false
$script:PrimaryError = $null
$script:AllSuitesPassed = $false

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
        # Bounded unwrap InnerException to check for SourceFileLeaseAcquisitionException
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
    param([array]$TargetSpecs = $null, [scriptblock]$RestorationCallback = $null)

    if ($script:CompilationUncertain) {
        Write-Diagnostic "Teardown uncertainty: compilation state marked uncertain."
        return 'NEEDSATTENTION'
    }
    if ($null -ne $script:PrimaryError) {
        Write-Diagnostic "Teardown uncertainty: primary test error exists ($($script:PrimaryError.Message))."
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
    foreach ($lease in $script:TrackedLeases) {
        if ($lease -eq $null -or $lease.Lease -eq $null -or -not $lease.Closed -or -not $lease.Lease.IsClosed) {
            Write-Diagnostic "Teardown uncertainty: lease ($($lease.Path)) is not closed, unverified, or null."
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

# --- Section 5: Synchronous Unified Single-Unit Compilation ---
$origTemp = $env:TEMP; $origTmp = $env:TMP
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $scriptDir ".."))
$csPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\SourceFileLease.cs")
if (-not [System.IO.File]::Exists($csPath)) { throw "SourceFileLease.cs missing at: $csPath" }

$helperSource = @"
using System;
using System.IO;

public class TestWitnessException : Exception {
    public TestWitnessException(string message) : base(message) { }
}

public class ThrowingWriteStream : Stream {
    private readonly Stream _inner;
    private readonly long _throwAfterBytes;
    private long _bytesWritten;
    private readonly PosDesktop.Updates.Native.SourceFileLease _reentrantLease;
    private bool _isDisposed;

    public readonly TestWitnessException WitnessException;
    public bool ReentrantCloseAttempted;
    public bool ReentrantCloseResult;
    public int ReentrantCloseError;
    public bool ReentrantZeroReadAttempted;
    public Exception ReentrantZeroReadException;
    public bool ReentrantReadAttempted;
    public Exception ReentrantReadException;

    public ThrowingWriteStream(Stream inner, long throwAfterBytes, PosDesktop.Updates.Native.SourceFileLease reentrantLease) {
        this._inner = inner;
        this._throwAfterBytes = throwAfterBytes;
        this._bytesWritten = 0;
        this._reentrantLease = reentrantLease;
        this._isDisposed = false;
        this.WitnessException = new TestWitnessException("ThrowingWriteStream witnessed intentional failure after " + throwAfterBytes + " bytes");
        this.ReentrantCloseAttempted = false;
        this.ReentrantCloseResult = false;
        this.ReentrantCloseError = 0;
        this.ReentrantZeroReadAttempted = false;
        this.ReentrantZeroReadException = null;
        this.ReentrantReadAttempted = false;
        this.ReentrantReadException = null;
    }

    public bool IsDisposed { get { return this._isDisposed; } }
    public override bool CanRead { get { return false; } }
    public override bool CanSeek { get { return false; } }
    public override bool CanWrite { get { return !this._isDisposed; } }
    public override long Length { get { return this._bytesWritten; } }
    public override long Position { get { return this._bytesWritten; } set { throw new NotSupportedException(); } }
    public override void Flush() { if (this._inner != null) this._inner.Flush(); }
    public override int Read(byte[] buffer, int offset, int count) { throw new NotSupportedException(); }
    public override long Seek(long offset, SeekOrigin origin) { throw new NotSupportedException(); }
    public override void SetLength(long value) { throw new NotSupportedException(); }

    protected override void Dispose(bool disposing) {
        this._isDisposed = true;
        base.Dispose(disposing);
    }

    public override void Write(byte[] buffer, int offset, int count) {
        if (this._isDisposed) {
            throw new ObjectDisposedException("ThrowingWriteStream");
        }

        if (this._reentrantLease != null && !this.ReentrantCloseAttempted) {
            this.ReentrantCloseAttempted = true;
            int err;
            this.ReentrantCloseResult = this._reentrantLease.TryClose(out err);
            this.ReentrantCloseError = err;

            this.ReentrantZeroReadAttempted = true;
            try {
                this._reentrantLease.ReadRange(0, 0);
            } catch (Exception ex) {
                this.ReentrantZeroReadException = ex;
            }

            this.ReentrantReadAttempted = true;
            try {
                this._reentrantLease.ReadRange(0, 10);
            } catch (Exception ex) {
                this.ReentrantReadException = ex;
            }
        }

        if (this._bytesWritten + count > this._throwAfterBytes) {
            int canWrite = (int)Math.Max(0, this._throwAfterBytes - this._bytesWritten);
            if (canWrite > 0 && this._inner != null) {
                this._inner.Write(buffer, offset, canWrite);
                this._bytesWritten += canWrite;
            }
            throw this.WitnessException;
        }

        if (this._inner != null) {
            this._inner.Write(buffer, offset, count);
        }
        this._bytesWritten += count;
    }
}
"@

$omegaChar = [char]0x03A9

try {
    # Compile unified unit: helperSource prepended to exact production source
    $script:CompilationUncertain = $true
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir
    $csSource = [System.IO.File]::ReadAllText($csPath, [System.Text.Encoding]::UTF8)
    $combinedSource = $helperSource + "`r`n" + $csSource
    Add-Type -TypeDefinition $combinedSource
    $script:CompilationUncertain = $false
    $env:TEMP = $origTemp; $env:TMP = $origTmp
    Write-Diagnostic "Successfully compiled unified source via Add-Type."

    # --- Section 6: Test Suite 1 - Valid Fixture > 64KiB, Acquire, Range Reads, Unicode ---
    Write-Diagnostic "Starting Suite 1: Valid fixture acquisition, exclusive locking, range reads..."
    $fixture1Len = 131329
    $fixture1Bytes = [byte[]]::new($fixture1Len)
    for ($i = 0; $i -lt $fixture1Len; $i++) {
        $fixture1Bytes[$i] = [byte]($i % 251)
    }
    $fixture1Path = [System.IO.Path]::Combine($labDir, "fixture-valid-$omegaChar.dat")
    [System.IO.File]::WriteAllBytes($fixture1Path, $fixture1Bytes)
    $controlHash1 = Compute-Sha256OfFile $fixture1Path
    Write-Diagnostic "Suite 1: Created fixture $fixture1Path (Length: $fixture1Len, SHA256: $controlHash1)"

    # Acquire lease
    $lease1 = Safe-AcquireLease -path $fixture1Path -size $fixture1Len -sha256 $controlHash1
    if ($lease1.IsClosed) { throw "Suite 1: Lease IsClosed is unexpectedly true after Acquire." }
    if ($lease1.Identity.Size -ne $fixture1Len) { throw "Suite 1: Identity size mismatch ($($lease1.Identity.Size) != $fixture1Len)." }
    $expectedCanon1 = [System.IO.Path]::GetFullPath($fixture1Path)
    if (-not [string]::Equals($lease1.Identity.CanonicalPath, $expectedCanon1, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Suite 1: Identity canonical path mismatch ($($lease1.Identity.CanonicalPath) != $expectedCanon1)."
    }
    if ($lease1.Identity.VolumeSerialNumber -eq 0) { throw "Suite 1: VolumeSerialNumber is 0." }

    # Prove exclusive sharing lock: ordinary .NET File.Open MUST fail sharing violation (error 32)
    $shareFailed = $false
    $testFs1 = $null
    try {
        $testFs1 = [System.IO.File]::Open($fixture1Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
        [void](Register-TrackedStream $testFs1)
        $testFs1.Dispose()
    } catch [System.IO.IOException] {
        $hresultLow = $_.Exception.HResult -band 0xFFFF
        if ($hresultLow -ne 32) {
            throw "Suite 1: Expected sharing violation error 32, but got HResult error $hresultLow ($($_.Exception.Message))"
        }
        $shareFailed = $true
    }
    if (-not $shareFailed) { throw "Suite 1: Ordinary File.Open unexpectedly succeeded while exclusive lease held!" }
    Write-Diagnostic "Suite 1: Verified exclusive share 0 lock blocks File.Open with error 32."

    # ReadRange 0: first 100 bytes
    $read0 = $lease1.ReadRange(0, 100)
    if ($read0.Length -ne 100) { throw "Suite 1: ReadRange(0, 100) length mismatch." }
    for ($i = 0; $i -lt 100; $i++) {
        if ($read0[$i] -ne $fixture1Bytes[$i]) { throw "Suite 1: ReadRange(0, 100) byte mismatch at offset $i." }
    }

    # ReadRange tail: last 50 bytes
    $tailOffset = $fixture1Len - 50
    $readTail = $lease1.ReadRange($tailOffset, 50)
    if ($readTail.Length -ne 50) { throw "Suite 1: ReadRange tail length mismatch." }
    for ($i = 0; $i -lt 50; $i++) {
        if ($readTail[$i] -ne $fixture1Bytes[$tailOffset + $i]) { throw "Suite 1: ReadRange tail byte mismatch at offset $i." }
    }

    # ReadRange cross 64KiB boundary (65530 to 65630)
    $readCross = $lease1.ReadRange(65530, 100)
    if ($readCross.Length -ne 100) { throw "Suite 1: ReadRange cross boundary length mismatch." }
    for ($i = 0; $i -lt 100; $i++) {
        if ($readCross[$i] -ne $fixture1Bytes[65530 + $i]) { throw "Suite 1: ReadRange cross boundary byte mismatch at offset $i." }
    }

    # ReadRange zero length
    $readZero = $lease1.ReadRange(10, 0)
    if ($readZero.Length -ne 0) { throw "Suite 1: ReadRange(10, 0) must return 0 bytes." }

    # Invalid ReadRange calls must fail without releasing lease
    $negativeOffsetFailed = $false
    try { [void]$lease1.ReadRange(-1, 10) } catch [System.ArgumentOutOfRangeException] { $negativeOffsetFailed = $true }
    if (-not $negativeOffsetFailed) { throw "Suite 1: ReadRange with negative offset did not fail." }

    $negativeCountFailed = $false
    try { [void]$lease1.ReadRange(0, -1) } catch [System.ArgumentOutOfRangeException] { $negativeCountFailed = $true }
    if (-not $negativeCountFailed) { throw "Suite 1: ReadRange with negative count did not fail." }

    $over1MiBFailed = $false
    try { [void]$lease1.ReadRange(0, 1048577) } catch [System.ArgumentOutOfRangeException] { $over1MiBFailed = $true }
    if (-not $over1MiBFailed) { throw "Suite 1: ReadRange > 1MiB did not fail." }

    $outOfBoundsFailed = $false
    try { [void]$lease1.ReadRange($fixture1Len - 10, 20) } catch [System.ArgumentOutOfRangeException] { $outOfBoundsFailed = $true }
    if (-not $outOfBoundsFailed) { throw "Suite 1: ReadRange out of bounds did not fail." }

    # Test ReadRange(Int64.MaxValue, 1) rejects bounded without overflow
    $maxValOverflowFailed = $false
    try { [void]$lease1.ReadRange([long]::MaxValue, 1) } catch [System.ArgumentOutOfRangeException] { $maxValOverflowFailed = $true }
    if (-not $maxValOverflowFailed) { throw "Suite 1: ReadRange(Int64.MaxValue, 1) did not reject." }

    if ($lease1.IsClosed) { throw "Suite 1: Lease IsClosed became true after invalid ReadRange calls." }

    # ComputeSha256 matches expected
    $computedHash1 = $lease1.ComputeSha256()
    if (-not [string]::Equals($computedHash1, $controlHash1, [System.StringComparison]::Ordinal)) {
        throw "Suite 1: ComputeSha256 mismatch: expected $controlHash1, got $computedHash1"
    }
    Write-Diagnostic "Suite 1 passed: range reads, bounds checks, and ComputeSha256 verified."

    # --- Section 7: Test Suite 2 - CopyVerifiedTo FileStream, MemoryStream, Empty File ---
    Write-Diagnostic "Starting Suite 2: CopyVerifiedTo tests..."
    $destPath2 = [System.IO.Path]::Combine($labDir, "copy-dest-$omegaChar.dat")
    $destFs2 = Register-TrackedStream ([System.IO.File]::Create($destPath2))
    $copyRes2 = $lease1.CopyVerifiedTo($destFs2)
    if ($copyRes2.BytesCopied -ne $fixture1Len) { throw "Suite 2: BytesCopied mismatch ($($copyRes2.BytesCopied) != $fixture1Len)." }
    if (-not [string]::Equals($copyRes2.Sha256, $controlHash1, [System.StringComparison]::Ordinal)) { throw "Suite 2: Copy Sha256 mismatch." }

    # Verify dest NOT disposed and can write after return
    if (-not $destFs2.CanWrite) { throw "Suite 2: Destination stream was disposed by library." }
    $destFs2.Flush()
    $destFs2.Dispose()

    $destDiskHash = Compute-Sha256OfFile $destPath2
    $destDiskLen = (Get-Item $destPath2).Length
    if ($destDiskLen -ne $fixture1Len) { throw "Suite 2: Destination on-disk length mismatch." }
    if (-not [string]::Equals($destDiskHash, $controlHash1, [System.StringComparison]::Ordinal)) { throw "Suite 2: Destination on-disk hash mismatch." }

    # MemoryStream control
    $memStream2 = Register-TrackedStream ([System.IO.MemoryStream]::new())
    $memRes2 = $lease1.CopyVerifiedTo($memStream2)
    if ($memRes2.BytesCopied -ne $fixture1Len) { throw "Suite 2: MemoryStream BytesCopied mismatch." }
    if (-not [string]::Equals($memRes2.Sha256, $controlHash1, [System.StringComparison]::Ordinal)) { throw "Suite 2: MemoryStream Sha256 mismatch." }
    if ($memStream2.Length -ne $fixture1Len) { throw "Suite 2: MemoryStream Length mismatch." }
    $memStream2.Dispose()

    # Empty source file case
    $emptyPath = [System.IO.Path]::Combine($labDir, "empty-file.dat")
    [System.IO.File]::WriteAllBytes($emptyPath, [byte[]]::new(0))
    $emptyExpectedSha = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    $emptyLease = Safe-AcquireLease -path $emptyPath -size 0 -sha256 $emptyExpectedSha
    $emptyMem = Register-TrackedStream ([System.IO.MemoryStream]::new())
    $emptyRes = $emptyLease.CopyVerifiedTo($emptyMem)
    if ($emptyRes.BytesCopied -ne 0) { throw "Suite 2: Empty file BytesCopied must be 0." }
    if (-not [string]::Equals($emptyRes.Sha256, $emptyExpectedSha, [System.StringComparison]::Ordinal)) { throw "Suite 2: Empty file SHA256 mismatch." }
    $emptyMem.Dispose()
    $cErrEmpty = 0
    if (-not $emptyLease.TryClose([ref]$cErrEmpty)) { throw "Suite 2: Failed to close empty lease: error $cErrEmpty" }
    Write-Diagnostic "Suite 2 passed: CopyVerifiedTo FileStream, MemoryStream, and empty file verified."

    # --- Section 8: Test Suite 3 - Failure Modes on SEPARATE Fixture 3, Cleanup Proof, Rejections ---
    Write-Diagnostic "Starting Suite 3: Validation failure modes on separate fixture 3, handle cleanup, rejections..."
    # Create separate fixture 3 from in-memory fixture 1 bytes (NO read path on fixture 1 while lease 1 is held)
    $fixture3Path = [System.IO.Path]::Combine($labDir, "fixture-suite3-$omegaChar.dat")
    [System.IO.File]::WriteAllBytes($fixture3Path, $fixture1Bytes)
    $controlHash3 = $controlHash1

    $wrongSha = "0000000000000000000000000000000000000000000000000000000000000000"
    $shaMismatchFailed = $false
    try {
        [void](Safe-AcquireLease -path $fixture3Path -size $fixture1Len -sha256 $wrongSha)
    } catch {
        $curr = $_.Exception
        while ($curr -ne $null) {
            if ($curr -is [System.InvalidOperationException]) { $shaMismatchFailed = $true; break }
            $curr = $curr.InnerException
        }
    }
    if (-not $shaMismatchFailed) { throw "Suite 3: Acquire unexpectedly succeeded or did not throw InvalidOperationException with wrong digest!" }

    # Prove handle cleanup after failed Acquire on fixture 3: ordinary exclusive open succeeds
    $testFs3a = Register-TrackedStream ([System.IO.File]::Open($fixture3Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None))
    $testFs3a.Dispose()

    # Wrong expected size on fixture 3
    $sizeMismatchFailed = $false
    try {
        [void](Safe-AcquireLease -path $fixture3Path -size ($fixture1Len + 100) -sha256 $controlHash3)
    } catch {
        $curr = $_.Exception
        while ($curr -ne $null) {
            if ($curr -is [System.InvalidOperationException]) { $sizeMismatchFailed = $true; break }
            $curr = $curr.InnerException
        }
    }
    if (-not $sizeMismatchFailed) { throw "Suite 3: Acquire unexpectedly succeeded or did not throw InvalidOperationException with wrong size!" }

    # Prove handle cleanup on fixture 3 again
    $testFs3b = Register-TrackedStream ([System.IO.File]::Open($fixture3Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None))
    $testFs3b.Dispose()

    # Verify source bytes unchanged on fixture 3
    $sourceHashAfter3 = Compute-Sha256OfFile $fixture3Path
    if (-not [string]::Equals($sourceHashAfter3, $controlHash3, [System.StringComparison]::Ordinal)) {
        throw "Suite 3: Source bytes of fixture 3 changed after failed acquisitions!"
    }

    # Malformed metadata and path validation rejections routed through Safe-AcquireLease
    $rejectCases = @(
        @{ Name = "Negative size"; Action = { Safe-AcquireLease -path $fixture3Path -size -1 -sha256 $controlHash3 }; Expected = [System.ArgumentOutOfRangeException] }
        @{ Name = "Short hash"; Action = { Safe-AcquireLease -path $fixture3Path -size $fixture1Len -sha256 "abc" }; Expected = [System.ArgumentException] }
        @{ Name = "Uppercase hash"; Action = { Safe-AcquireLease -path $fixture3Path -size $fixture1Len -sha256 $controlHash3.ToUpperInvariant() }; Expected = [System.ArgumentException] }
        @{ Name = "Non-hex hash"; Action = { Safe-AcquireLease -path $fixture3Path -size $fixture1Len -sha256 ("0" * 63 + "g") }; Expected = [System.ArgumentException] }
        @{ Name = "Relative path"; Action = { Safe-AcquireLease -path "relative.dat" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "Drive-relative path"; Action = { Safe-AcquireLease -path "C:relative.dat" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "UNC path"; Action = { Safe-AcquireLease -path "\\server\share\file.dat" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "ADS path"; Action = { Safe-AcquireLease -path "$fixture3Path`:stream" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "Dotdot path"; Action = { Safe-AcquireLease -path "$labDir\..\foo.dat" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "Dot segment path"; Action = { Safe-AcquireLease -path "$labDir\.\foo.dat" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "Trailing space"; Action = { Safe-AcquireLease -path "$fixture3Path " -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "8.3 alias"; Action = { Safe-AcquireLease -path "$labDir\PROGRA~1.DAT" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "Reserved DOS NUL"; Action = { Safe-AcquireLease -path "$labDir\NUL" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "Reserved DOS CON.txt"; Action = { Safe-AcquireLease -path "$labDir\con.txt" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
        @{ Name = "Drive root"; Action = { Safe-AcquireLease -path "C:\" -size 10 -sha256 $controlHash3 }; Expected = [System.ArgumentException] }
    )

    foreach ($tc in $rejectCases) {
        $failed = $false
        try {
            & $tc.Action
        } catch {
            $curr = $_.Exception
            while ($curr -ne $null) {
                if ($curr -is $tc.Expected) {
                    $failed = $true
                    break
                }
                $curr = $curr.InnerException
            }
        }
        if (-not $failed) { throw "Suite 3: $($tc.Name) unexpectedly succeeded!" }
    }

    # Absent target not created, routed through Safe-AcquireLease
    $absentPath = [System.IO.Path]::Combine($labDir, "absent-target-$nonce.dat")
    $absentFailed = $false
    try {
        [void](Safe-AcquireLease -path $absentPath -size 10 -sha256 $controlHash3)
    } catch {
        $curr = $_.Exception
        while ($curr -ne $null) {
            if ($curr -is [System.IO.IOException]) { $absentFailed = $true; break }
            $curr = $curr.InnerException
        }
    }
    if (-not $absentFailed) { throw "Suite 3: Acquire on absent target unexpectedly succeeded!" }
    if ([System.IO.File]::Exists($absentPath)) { throw "Suite 3: Absent target was created on disk!" }
    Write-Diagnostic "Suite 3 passed: failure modes on separate fixture, cleanup proof, and rejections verified."

    # --- Section 9: Test Suite 4 - ThrowingWriteStream Mid-Copy Failure, Reentrant Guard, Negative Control ---
    Write-Diagnostic "Starting Suite 4: Mid-copy failure with ThrowingWriteStream, reentrancy protection, post-close checks..."
    $destPath4 = [System.IO.Path]::Combine($labDir, "throwing-dest-$nonce.dat")
    $rawFs4 = Register-TrackedStream ([System.IO.File]::Create($destPath4))
    $throwingStream = Register-TrackedStream ([ThrowingWriteStream]::new($rawFs4, 30000, $lease1))

    $caughtWitness = $null
    try {
        [void]$lease1.CopyVerifiedTo($throwingStream)
    } catch {
        $curr = $_.Exception
        while ($curr -ne $null) {
            if ($curr -is [TestWitnessException]) {
                $caughtWitness = $curr
                break
            }
            $curr = $curr.InnerException
        }
    }
    if ($caughtWitness -eq $null) { throw "Suite 4: Expected TestWitnessException was not propagated!" }
    if (-not [object]::ReferenceEquals($caughtWitness, $throwingStream.WitnessException)) {
        throw "Suite 4: Caught exception instance was not the exact TestWitnessException instance allocated in ThrowingWriteStream!"
    }

    # Verify re-entrant behavior witnessed during active I/O
    if (-not $throwingStream.ReentrantCloseAttempted) { throw "Suite 4: Reentrant close was not attempted." }
    if ($throwingStream.ReentrantCloseResult) { throw "Suite 4: Reentrant close unexpectedly succeeded during active I/O!" }
    if ($throwingStream.ReentrantCloseError -ne 32) { throw "Suite 4: Reentrant close error was $($throwingStream.ReentrantCloseError), expected 32." }

    if (-not $throwingStream.ReentrantZeroReadAttempted) { throw "Suite 4: Reentrant zero-read was not attempted." }
    $zeroReadInvalid = $false
    $curr = $throwingStream.ReentrantZeroReadException
    while ($curr -ne $null) {
        if ($curr -is [System.InvalidOperationException]) { $zeroReadInvalid = $true; break }
        $curr = $curr.InnerException
    }
    if (-not $zeroReadInvalid) { throw "Suite 4: Reentrant zero-read did not throw InvalidOperationException." }

    if (-not $throwingStream.ReentrantReadAttempted) { throw "Suite 4: Reentrant read was not attempted." }
    $readInvalid = $false
    $curr = $throwingStream.ReentrantReadException
    while ($curr -ne $null) {
        if ($curr -is [System.InvalidOperationException]) { $readInvalid = $true; break }
        $curr = $curr.InnerException
    }
    if (-not $readInvalid) { throw "Suite 4: Reentrant read did not throw InvalidOperationException." }

    # Verify undisposed proof: ThrowingWriteStream Dispose leaves inner caller-owned; verify undisposed states
    if ($throwingStream.IsDisposed) { throw "Suite 4: ThrowingWriteStream was unexpectedly marked disposed." }
    if (-not $throwingStream.CanWrite) { throw "Suite 4: ThrowingWriteStream CanWrite is unexpectedly false." }
    if (-not $rawFs4.CanWrite) { throw "Suite 4: Raw destination FileStream was unexpectedly disposed." }

    # Exact 30,000 bytes retained and partial prefix bytes hash
    $rawFs4.Flush()
    $rawFs4.Dispose()
    $partialLen = (Get-Item $destPath4).Length
    if ($partialLen -ne 30000) { throw "Suite 4: Partial output length mismatch: expected exactly 30000, got $partialLen." }

    $actualPartialHash = Compute-Sha256OfFile $destPath4
    $shaPrefix = [System.Security.Cryptography.SHA256]::Create()
    $expectedPrefixBytes = $shaPrefix.ComputeHash($fixture1Bytes, 0, 30000)
    $shaPrefix.Dispose()
    $sbPrefix = [System.Text.StringBuilder]::new(64)
    foreach ($b in $expectedPrefixBytes) { [void]$sbPrefix.Append($b.ToString("x2")) }
    $expectedPrefixHash = $sbPrefix.ToString()
    if (-not [string]::Equals($actualPartialHash, $expectedPrefixHash, [System.StringComparison]::Ordinal)) {
        throw "Suite 4: Partial output 30000 bytes prefix hash mismatch."
    }

    # Verify SOURCE still leased and specific sharing violation (error 32)
    $shareFailed4 = $false
    $testFs4 = $null
    try {
        $testFs4 = [System.IO.File]::Open($fixture1Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
        [void](Register-TrackedStream $testFs4)
        $testFs4.Dispose()
    } catch [System.IO.IOException] {
        $hresultLow = $_.Exception.HResult -band 0xFFFF
        if ($hresultLow -ne 32) {
            throw "Suite 4: Expected sharing violation error 32, but got HResult error $hresultLow ($($_.Exception.Message))"
        }
        $shareFailed4 = $true
    }
    if (-not $shareFailed4) { throw "Suite 4: Source file exclusive lease was lost after mid-copy failure!" }

    # ComputeSha256 and ReadRange still usable through OWN HANDLE while lease is held
    $hash4 = $lease1.ComputeSha256()
    if (-not [string]::Equals($hash4, $controlHash1, [System.StringComparison]::Ordinal)) { throw "Suite 4: ComputeSha256 failed on retained lease." }
    $range4 = $lease1.ReadRange(0, 50)
    if ($range4.Length -ne 50) { throw "Suite 4: ReadRange failed on retained lease." }

    # Close lease1 via TryClose
    $cErr1 = 0
    $okClose1 = $lease1.TryClose([ref]$cErr1)
    if (-not $okClose1 -or -not $lease1.IsClosed) { throw "Suite 4: TryClose failed on lease1: error $cErr1" }

    # Source hash unchanged proof on disk AFTER TryClose
    $sourceHashAfter4 = Compute-Sha256OfFile $fixture1Path
    if (-not [string]::Equals($sourceHashAfter4, $controlHash1, [System.StringComparison]::Ordinal)) {
        throw "Suite 4: Source bytes of fixture 1 changed after lease closure!"
    }

    # Negative control ordinary exclusive open after close proves native handle was truly released
    $fsNegativeControl = Register-TrackedStream ([System.IO.File]::Open($fixture1Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None))
    $fsNegativeControl.Dispose()
    Write-Diagnostic "Suite 4: Negative control exclusive open succeeded after lease closure."

    # Verify source hash unchanged once more after negative exclusive open
    $sourceHashAfterNegative = Compute-Sha256OfFile $fixture1Path
    if (-not [string]::Equals($sourceHashAfterNegative, $controlHash1, [System.StringComparison]::Ordinal)) {
        throw "Suite 4: Source bytes changed after negative exclusive open!"
    }

    # Test post-close calls throw ObjectDisposedException, including zero-read
    $postZeroReadFailed = $false
    try { [void]$lease1.ReadRange(0, 0) } catch [System.ObjectDisposedException] { $postZeroReadFailed = $true }
    if (-not $postZeroReadFailed) { throw "Suite 4: ReadRange(0, 0) on closed lease did not throw ObjectDisposedException." }

    $postReadFailed = $false
    try { [void]$lease1.ReadRange(0, 10) } catch [System.ObjectDisposedException] { $postReadFailed = $true }
    if (-not $postReadFailed) { throw "Suite 4: ReadRange on closed lease did not throw ObjectDisposedException." }

    $postHashFailed = $false
    try { [void]$lease1.ComputeSha256() } catch [System.ObjectDisposedException] { $postHashFailed = $true }
    if (-not $postHashFailed) { throw "Suite 4: ComputeSha256 on closed lease did not throw ObjectDisposedException." }

    $dummyMem = Register-TrackedStream ([System.IO.MemoryStream]::new())
    $postCopyFailed = $false
    try { [void]$lease1.CopyVerifiedTo($dummyMem) } catch [System.ObjectDisposedException] { $postCopyFailed = $true }
    $dummyMem.Dispose()
    if (-not $postCopyFailed) { throw "Suite 4: CopyVerifiedTo on closed lease did not throw ObjectDisposedException." }

    # Second TryClose call is idempotent
    $cErrSecond = -1
    $okSecond = $lease1.TryClose([ref]$cErrSecond)
    if (-not $okSecond -or $cErrSecond -ne 0) { throw "Suite 4: Second TryClose was not idempotent (ok=$okSecond, err=$cErrSecond)." }

    $script:AllSuitesPassed = $true
} catch {
    $script:PrimaryError = $_.Exception
    Write-Diagnostic "FATAL: Test execution failed: $($_.Exception.Message)"
} finally {
    # Always restore original TEMP/TMP
    $env:TEMP = $origTemp
    $env:TMP = $origTmp

    # Step 1: Dispose all tracked streams
    Close-AllTrackedStreams

    # Step 2: TryClose every tracked lease independently, capturing errors
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

    # Step 3: Shared cleanup safety gate
    $cleanupResult = Invoke-SharedCleanupSafetyGate -TargetSpecs @(
        @{ Path = $labDir; MarkerName = ".snfio-lab-marker"; Nonce = $nonce }
        @{ Path = $victimDir; MarkerName = ".snfio-victim-marker"; Nonce = $nonce }
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
