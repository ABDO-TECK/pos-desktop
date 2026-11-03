# scripts/verify-shell-native-file-lease.ps1
# Win32 Native File Lease Architecture Oracle
# Verifies exclusive READ|WRITE|DELETE file handle lease semantics on Windows.

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

# --- Section 3: Global Registries, State Tracking, and Checked Helpers ---
$script:GENERIC_READ = [uint32]2147483648
$script:EXCLUSIVE_LEASE = [uint32]3221291008
$script:DLL_SEARCH_FLAGS = [uint32]2304
$script:PROC_FLAGS = [uint32]134217732
$script:REPARSE_FLAG = [uint32]2097152

$script:TrackedProcesses = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedLeases = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedModules = [System.Collections.Generic.List[PSObject]]::new()
$script:CompilationUncertain = $false
$script:TeardownHandleStatusHook = $null
$script:UnknownHookHits = 0
$script:PrimaryError = $null
$script:AllSuitesPassed = $false

function Write-Diagnostic {
    param([string]$msg)
    [Console]::WriteLine("[$([DateTime]::UtcNow.ToString('yyyy-MM-dd HH:mm:ss.fffZ'))] $msg")
}
function Register-ProcessHandle {
    param([IntPtr]$ProcessHandle, [IntPtr]$ThreadHandle, [int]$ProcessId, [string]$Tag)
    $pClosed = ($ProcessHandle -eq [IntPtr]::Zero -or $ProcessHandle -eq [IntPtr]::new(-1))
    $tClosed = ($ThreadHandle -eq [IntPtr]::Zero -or $ThreadHandle -eq [IntPtr]::new(-1))
    $rec = [PSObject]@{
        ProcessHandle = $ProcessHandle; ThreadHandle = $ThreadHandle; ProcessId = $ProcessId; Tag = $Tag;
        State = 'RUNNING'; ProcessClosed = $pClosed; ThreadClosed = $tClosed; Closed = ($pClosed -and $tClosed)
    }
    $script:TrackedProcesses.Add($rec); return $rec
}
function Register-LeaseHandle {
    param([IntPtr]$FileHandle, [string]$Path, [string]$Tag)
    $rec = [PSObject]@{ FileHandle = $FileHandle; Path = $Path; Tag = $Tag; Closed = $false }
    $script:TrackedLeases.Add($rec); return $rec
}
function Register-ModuleHandle {
    param([IntPtr]$ModuleHandle, [string]$Path, [string]$Tag)
    $rec = [PSObject]@{ ModuleHandle = $ModuleHandle; Path = $Path; Tag = $Tag; Closed = $false }
    $script:TrackedModules.Add($rec); return $rec
}
function Close-TrackedLease {
    param($leaseRec)
    if ($leaseRec.Closed) { return $true }
    if ($leaseRec.FileHandle -eq [IntPtr]::Zero -or $leaseRec.FileHandle -eq [IntPtr]::new(-1)) { $leaseRec.Closed = $true; return $true }
    $cErr = 0; $ok = [Win32NativeFileLeaseOracle]::CloseNativeHandle($leaseRec.FileHandle, [ref]$cErr)
    if ($ok) { $leaseRec.Closed = $true; return $true }
    Write-Diagnostic "CloseHandle failed for lease $($leaseRec.Tag) (handle $($leaseRec.FileHandle)): error $cErr"; return $false
}
function Free-TrackedModule {
    param($modRec)
    if ($modRec.Closed) { return $true }
    if ($modRec.ModuleHandle -eq [IntPtr]::Zero) { $modRec.Closed = $true; return $true }
    $fErr = 0; $ok = [Win32NativeFileLeaseOracle]::FreeLibraryModule($modRec.ModuleHandle, [ref]$fErr)
    if ($ok) { $modRec.Closed = $true; return $true }
    Write-Diagnostic "FreeLibrary failed for module $($modRec.Tag) (handle $($modRec.ModuleHandle)): error $fErr"; return $false
}
function Resume-TrackedThread {
    param($procRec)
    if ($procRec.ThreadClosed -or $procRec.ThreadHandle -eq [IntPtr]::Zero) { return $false }
    $rErr = 0; $ok = [Win32NativeFileLeaseOracle]::ResumeChildThread($procRec.ThreadHandle, [ref]$rErr)
    if (-not $ok) { Write-Diagnostic "ResumeThread failed for $($procRec.Tag): error $rErr"; return $false }
    return $true
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
function Get-ProcessHandleState {
    param([IntPtr]$hProcess)
    if ($null -ne $script:TeardownHandleStatusHook -and $script:TeardownHandleStatusHook.Handle -eq $hProcess) {
        $script:UnknownHookHits++
        return $script:TeardownHandleStatusHook.ForceState
    }
    $err = 0; return [Win32NativeFileLeaseOracle]::WaitChildHandle($hProcess, 0, [ref]$err)
}
function Stop-ChildProcess {
    param($procRecord)
    if ($procRecord.Closed) { return 'DEAD' }

    # Step 1: Prove DEAD and terminate if still running, only if process handle not yet closed
    if (-not $procRecord.ProcessClosed) {
        if ($procRecord.ProcessHandle -eq [IntPtr]::Zero -or $procRecord.ProcessHandle -eq [IntPtr]::new(-1)) {
            $procRecord.ProcessClosed = $true
        } else {
            $state = Get-ProcessHandleState -hProcess $procRecord.ProcessHandle
            if ($state -eq 'RUNNING') {
                $tErr = 0; $termOk = [Win32NativeFileLeaseOracle]::TerminateChildProcess($procRecord.ProcessHandle, 1, [ref]$tErr)
                if (-not $termOk) { Write-Diagnostic "TerminateProcess returned false for $($procRecord.Tag): error $tErr" }
                $state = [Win32NativeFileLeaseOracle]::WaitChildHandle($procRecord.ProcessHandle, 5000, [ref]$tErr)
            }
            if ($state -eq 'DEAD') {
                $procRecord.State = 'DEAD'
                $cErr = 0
                if ([Win32NativeFileLeaseOracle]::CloseNativeHandle($procRecord.ProcessHandle, [ref]$cErr)) {
                    $procRecord.ProcessClosed = $true
                } else {
                    Write-Diagnostic "Failed to close process handle for $($procRecord.Tag) (handle $($procRecord.ProcessHandle)): error $cErr"
                }
            } else {
                $procRecord.State = 'UNKNOWN'
                Write-Diagnostic "Handle $($procRecord.ProcessHandle) for $($procRecord.Tag) not proven DEAD ($state); retaining."
            }
        }
    }

    # Step 2: If process is DEAD and thread handle is still open, close thread handle
    if ($procRecord.State -eq 'DEAD' -and -not $procRecord.ThreadClosed) {
        if ($procRecord.ThreadHandle -eq [IntPtr]::Zero -or $procRecord.ThreadHandle -eq [IntPtr]::new(-1)) {
            $procRecord.ThreadClosed = $true
        } else {
            $thErr = 0
            if ([Win32NativeFileLeaseOracle]::CloseNativeHandle($procRecord.ThreadHandle, [ref]$thErr)) {
                $procRecord.ThreadClosed = $true
            } else {
                Write-Diagnostic "Failed to close thread handle for $($procRecord.Tag) (handle $($procRecord.ThreadHandle)): error $thErr"
            }
        }
    }

    # Step 3: Closed is true ONLY when both native handles are verified closed
    if ($procRecord.ProcessClosed -and $procRecord.ThreadClosed) {
        $procRecord.Closed = $true
        return 'DEAD'
    } else {
        $procRecord.Closed = $false
        return 'UNCERTAIN'
    }
}
function Assert-IsMappingError {
    param([int]$errCode, [string]$desc)
    if ($errCode -ne 32 -and $errCode -ne 5) { throw "$desc failed with unexpected error: $errCode (expected 32 or 5)" }
}
function Assert-IsFileNotFoundError {
    param([int]$errCode, [string]$desc)
    if ($errCode -ne 2 -and $errCode -ne 3) { throw "$desc failed with unexpected error: $errCode (expected 2 or 3)" }
}

# --- Section 4: Safe Traversal, Nonce-Marked Lab, Immutable Victim, and Shared Gate ---
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
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfl-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snfl-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
[void][System.IO.Directory]::CreateDirectory($labDir); [void][System.IO.Directory]::CreateDirectory($labTempDir); [void][System.IO.Directory]::CreateDirectory($victimDir)
$labMarkerPath = [System.IO.Path]::Combine($labDir, ".snfl-lab-marker"); [System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".snfl-victim-marker"); [System.IO.File]::WriteAllText($victimMarkerPath, $nonce, [System.Text.Encoding]::UTF8)

# Populate victim witness tree (immutable outside lab)
$victimWitness1 = [System.IO.Path]::Combine($victimDir, "victim-witness-1.dat"); [System.IO.File]::WriteAllBytes($victimWitness1, [System.Text.Encoding]::UTF8.GetBytes("VICTIM_BYTE_1_$nonce"))
$victimWitness2 = [System.IO.Path]::Combine($victimDir, "victim-witness-2.txt"); [System.IO.File]::WriteAllText($victimWitness2, "VICTIM_TEXT_2_$nonce", [System.Text.Encoding]::UTF8)
$victimSubDir = [System.IO.Path]::Combine($victimDir, "sub-victim"); [void][System.IO.Directory]::CreateDirectory($victimSubDir)
$victimSubFile = [System.IO.Path]::Combine($victimSubDir, "sub-witness.txt"); [System.IO.File]::WriteAllText($victimSubFile, "VICTIM_SUB_$nonce", [System.Text.Encoding]::UTF8)

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
    foreach ($proc in $script:TrackedProcesses) {
        if (-not $proc.Closed) {
            $currNativeState = $proc.State
            if (-not $proc.ProcessClosed -and $proc.ProcessHandle -ne [IntPtr]::Zero -and $proc.ProcessHandle -ne [IntPtr]::new(-1)) {
                $currNativeState = Get-ProcessHandleState -hProcess $proc.ProcessHandle
                Write-Diagnostic "Teardown uncertainty: child process handle $($proc.ProcessHandle) ($($proc.Tag)) classified as native '$currNativeState' (ProcessClosed: $($proc.ProcessClosed), ThreadClosed: $($proc.ThreadClosed))."
            } else {
                Write-Diagnostic "Teardown uncertainty: child process record $($proc.Tag) is not closed (State: $($proc.State), ProcessClosed: $($proc.ProcessClosed), ThreadClosed: $($proc.ThreadClosed))."
            }
            return 'NEEDSATTENTION'
        }
        if ($proc.State -ne 'DEAD') {
            Write-Diagnostic "Teardown uncertainty: closed child process $($proc.Tag) state is '$($proc.State)' (expected DEAD)."
            return 'NEEDSATTENTION'
        }
    }
    foreach ($lease in $script:TrackedLeases) {
        if (-not $lease.Closed) { Write-Diagnostic "Teardown uncertainty: lease handle $($lease.FileHandle) ($($lease.Tag)) is not closed."; return 'NEEDSATTENTION' }
    }
    foreach ($mod in $script:TrackedModules) {
        if (-not $mod.Closed) { Write-Diagnostic "Teardown uncertainty: module handle $($mod.ModuleHandle) ($($mod.Tag)) is not freed."; return 'NEEDSATTENTION' }
    }
    if ($script:CompilationUncertain) { Write-Diagnostic "Teardown uncertainty: compilation state marked uncertain."; return 'NEEDSATTENTION' }
    if ($null -ne $script:PrimaryError) { Write-Diagnostic "Teardown uncertainty: primary test error exists ($($script:PrimaryError.Message))."; return 'NEEDSATTENTION' }
    if ($null -ne $script:VictimSnapshot -and [System.IO.Directory]::Exists($victimDir)) {
        try { Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir }
        catch { Write-Diagnostic "Teardown uncertainty: victim tree integrity check failed: $($_.Exception.Message)"; return 'NEEDSATTENTION' }
    } else { Write-Diagnostic "Teardown uncertainty: victim snapshot or directory missing."; return 'NEEDSATTENTION' }

    if ($null -ne $TargetSpecs) {
        foreach ($spec in $TargetSpecs) {
            if ([System.IO.Directory]::Exists($spec.Path)) { Invoke-DirectoryDeletion -Path $spec.Path -ExpectedMarkerName $spec.MarkerName -ExpectedNonce $spec.Nonce }
        }
    }
    if ($null -ne $RestorationCallback) { & $RestorationCallback }
    return 'CLEANED'
}

# --- Section 5 & 6 & 7: Win32 Wrapper, Fixture, and Test Suites inside Try / Finally ---
$origTemp = $env:TEMP; $origTmp = $env:TMP; $script:CompilationUncertain = $true

$nativeSource = @"
using System; using System.IO; using System.Text; using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles; using System.Security.Cryptography;

public static class Win32NativeFileLeaseOracle {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)] public struct STARTUPINFO {
        public int cb; public string lpReserved, lpDesktop, lpTitle;
        public int dwX, dwY, dwXSize, dwYSize, dwXCountChars, dwYCountChars, dwFillAttribute, dwFlags;
        public short wShowWindow, cbReserved2; public IntPtr lpReserved2, hStdInput, hStdOutput, hStdError;
    }
    [StructLayout(LayoutKind.Sequential)] public struct PROCESS_INFORMATION { public IntPtr hProcess, hThread; public int dwProcessId, dwThreadId; }
    [StructLayout(LayoutKind.Sequential)] public struct FILETIME { public uint dwLowDateTime, dwHighDateTime; }
    [StructLayout(LayoutKind.Sequential)] public struct BY_HANDLE_FILE_INFORMATION {
        public uint dwFileAttributes; public FILETIME ftCreationTime, ftLastAccessTime, ftLastWriteTime;
        public uint dwVolumeSerialNumber, nFileSizeHigh, nFileSizeLow, nNumberOfLinks, nFileIndexHigh, nFileIndexLow;
    }

    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)] public static extern IntPtr CreateFileW(string lpFileName, uint dwDesiredAccess, uint dwShareMode, IntPtr lpSecurityAttributes, uint dwCreationDisposition, uint dwFlagsAndAttributes, IntPtr hTemplateFile);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] public static extern bool CloseHandle(IntPtr hObject);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)] public static extern bool CreateProcessW(string appName, StringBuilder cmdLine, IntPtr pAttr, IntPtr tAttr, [MarshalAs(UnmanagedType.Bool)] bool inherit, uint flags, IntPtr env, string curDir, [In] ref STARTUPINFO si, out PROCESS_INFORMATION pi);
    [DllImport("kernel32.dll", SetLastError = true)] public static extern uint ResumeThread(IntPtr hThread);
    [DllImport("kernel32.dll", SetLastError = true)] public static extern uint WaitForSingleObject(IntPtr hHandle, uint dwMilliseconds);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] public static extern bool TerminateProcess(IntPtr hProcess, uint uExitCode);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)] public static extern IntPtr LoadLibraryExW(string lpLibFileName, IntPtr hFile, uint dwFlags);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)] public static extern uint GetModuleFileNameW(IntPtr hModule, [Out] StringBuilder lpFilename, uint nSize);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] public static extern bool FreeLibrary(IntPtr hLibModule);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] public static extern bool SetFileInformationByHandle(IntPtr hFile, int infoClass, IntPtr info, uint bufSize);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)] public static extern uint GetFinalPathNameByHandleW(IntPtr hFile, [Out] StringBuilder lpszFilePath, uint cchFilePath, uint dwFlags);
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] public static extern bool GetFileInformationByHandle(IntPtr hFile, out BY_HANDLE_FILE_INFORMATION lpFileInformation);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)] public static extern bool QueryFullProcessImageNameW(IntPtr hProcess, uint dwFlags, [Out] StringBuilder lpExeName, ref uint lpdwSize);
    [DllImport("kernel32.dll", SetLastError = true)] public static extern uint SetFilePointer(IntPtr hFile, int lDistanceToMove, IntPtr lpDistanceToMoveHigh, uint dwMoveMethod);

    public static IntPtr CreateFileHandle(string lpFileName, uint dwDesiredAccess, uint dwShareMode, uint dwCreationDisposition, uint dwFlagsAndAttributes, out int errorCode) {
        IntPtr h = CreateFileW(lpFileName, dwDesiredAccess, dwShareMode, IntPtr.Zero, dwCreationDisposition, dwFlagsAndAttributes, IntPtr.Zero);
        if (h == new IntPtr(-1)) { errorCode = Marshal.GetLastWin32Error(); return IntPtr.Zero; }
        errorCode = 0; return h;
    }
    public static bool CloseNativeHandle(IntPtr hObject, out int errorCode) {
        errorCode = 0; if (hObject == IntPtr.Zero || hObject == new IntPtr(-1)) return true;
        if (!CloseHandle(hObject)) { errorCode = Marshal.GetLastWin32Error(); return false; }
        return true;
    }
    public static bool CreateChildProcess(string appName, string cmdLine, uint flags, out IntPtr hProcess, out IntPtr hThread, out int processId, out int errorCode) {
        hProcess = IntPtr.Zero; hThread = IntPtr.Zero; processId = 0; errorCode = 0;
        STARTUPINFO si = new STARTUPINFO(); si.cb = Marshal.SizeOf(si); PROCESS_INFORMATION pi = new PROCESS_INFORMATION();
        StringBuilder cmdBuilder = string.IsNullOrEmpty(cmdLine) ? null : new StringBuilder(cmdLine);
        if (!CreateProcessW(appName, cmdBuilder, IntPtr.Zero, IntPtr.Zero, false, flags, IntPtr.Zero, null, ref si, out pi)) {
            errorCode = Marshal.GetLastWin32Error(); return false;
        }
        hProcess = pi.hProcess; hThread = pi.hThread; processId = pi.dwProcessId; return true;
    }
    public static bool ResumeChildThread(IntPtr hThread, out int errorCode) {
        errorCode = 0; if (ResumeThread(hThread) == unchecked((uint)-1)) { errorCode = Marshal.GetLastWin32Error(); return false; }
        return true;
    }
    public static string WaitChildHandle(IntPtr hProcess, uint ms, out int errorCode) {
        errorCode = 0; uint res = WaitForSingleObject(hProcess, ms);
        if (res == 0U) return "DEAD"; if (res == 0x102U) return "RUNNING";
        errorCode = Marshal.GetLastWin32Error(); return "UNKNOWN";
    }
    public static bool TerminateChildProcess(IntPtr hProcess, uint exitCode, out int errorCode) {
        errorCode = 0; if (!TerminateProcess(hProcess, exitCode)) { errorCode = Marshal.GetLastWin32Error(); return false; }
        return true;
    }
    public static IntPtr LoadLibraryModule(string libFileName, uint flags, out int errorCode) {
        errorCode = 0; IntPtr h = LoadLibraryExW(libFileName, IntPtr.Zero, flags);
        if (h == IntPtr.Zero) { errorCode = Marshal.GetLastWin32Error(); return IntPtr.Zero; }
        return h;
    }
    public static string GetModulePath(IntPtr hModule, out int errorCode) {
        errorCode = 0; StringBuilder sb = new StringBuilder(1024);
        if (GetModuleFileNameW(hModule, sb, (uint)sb.Capacity) == 0) { errorCode = Marshal.GetLastWin32Error(); return null; }
        return sb.ToString();
    }
    public static bool FreeLibraryModule(IntPtr hModule, out int errorCode) {
        errorCode = 0; if (hModule == IntPtr.Zero) return true;
        if (!FreeLibrary(hModule)) { errorCode = Marshal.GetLastWin32Error(); return false; }
        return true;
    }
    public static bool RenameFileHandle(IntPtr hFile, string newPath, bool replaceIfExists, out int errorCode) {
        errorCode = 0; if (hFile == IntPtr.Zero || hFile == new IntPtr(-1)) { errorCode = 6; return false; }
        if (newPath == null) { errorCode = 87; return false; }
        byte[] pathBytes = Encoding.Unicode.GetBytes(newPath);
        int headerSize = 20;
        int nameByteLength = pathBytes.Length;
        int nulTerminatorSize = 2;
        int allocSize = headerSize + nameByteLength + nulTerminatorSize;
        if (allocSize != 20 + pathBytes.Length + 2 || nameByteLength != newPath.Length * 2) {
            errorCode = 87; return false;
        }
        IntPtr buffer = Marshal.AllocHGlobal(allocSize);
        try {
            for (int i = 0; i < allocSize; i++) Marshal.WriteByte(buffer, i, 0);
            Marshal.WriteInt32(buffer, 0, replaceIfExists ? 1 : 0);
            Marshal.WriteIntPtr(buffer, 8, IntPtr.Zero);
            Marshal.WriteInt32(buffer, 16, nameByteLength);
            Marshal.Copy(pathBytes, 0, new IntPtr(buffer.ToInt64() + 20), pathBytes.Length);
            Marshal.WriteInt16(buffer, 20 + nameByteLength, (short)0);
            int readLength = Marshal.ReadInt32(buffer, 16);
            if (readLength != nameByteLength) {
                throw new InvalidOperationException(string.Format("FILE_RENAME_INFO length mismatch: expected {0}, got {1}", nameByteLength, readLength));
            }
            short trailer = Marshal.ReadInt16(buffer, 20 + nameByteLength);
            if (trailer != 0) {
                throw new InvalidOperationException("FILE_RENAME_INFO UTF-16 null trailer is non-zero");
            }
            string roundtrip = Marshal.PtrToStringUni(new IntPtr(buffer.ToInt64() + 20), nameByteLength / 2);
            string nullTerminated = Marshal.PtrToStringUni(new IntPtr(buffer.ToInt64() + 20));
            if (!string.Equals(roundtrip, newPath, StringComparison.Ordinal) || roundtrip.Length != newPath.Length || !string.Equals(nullTerminated, newPath, StringComparison.Ordinal)) {
                throw new InvalidOperationException("FILE_RENAME_INFO embedded UTF-16 roundtrip mismatch; silent trimming detected");
            }
            if (!SetFileInformationByHandle(hFile, 3, buffer, (uint)allocSize)) {
                errorCode = Marshal.GetLastWin32Error(); return false;
            }
            return true;
        } finally { Marshal.FreeHGlobal(buffer); }
    }
    public static string GetFinalHandlePath(IntPtr hFile, out int errorCode) {
        errorCode = 0; StringBuilder sb = new StringBuilder(1024);
        if (GetFinalPathNameByHandleW(hFile, sb, (uint)sb.Capacity, 0) == 0) { errorCode = Marshal.GetLastWin32Error(); return null; }
        string raw = sb.ToString();
        if (raw.StartsWith(@"\\?\UNC\", StringComparison.OrdinalIgnoreCase)) return @"\\" + raw.Substring(8);
        if (raw.StartsWith(@"\\?\", StringComparison.OrdinalIgnoreCase)) return raw.Substring(4);
        return raw;
    }
    public static bool GetHandleFileInfo(IntPtr hFile, out uint volumeSerial, out ulong fileIndex, out ulong fileSize, out uint attributes, out int errorCode) {
        volumeSerial = 0; fileIndex = 0; fileSize = 0; attributes = 0; errorCode = 0;
        BY_HANDLE_FILE_INFORMATION fi;
        if (!GetFileInformationByHandle(hFile, out fi)) { errorCode = Marshal.GetLastWin32Error(); return false; }
        volumeSerial = fi.dwVolumeSerialNumber;
        fileIndex = ((ulong)fi.nFileIndexHigh << 32) | (ulong)fi.nFileIndexLow;
        fileSize = ((ulong)fi.nFileSizeHigh << 32) | (ulong)fi.nFileSizeLow;
        attributes = fi.dwFileAttributes;
        return true;
    }
    public static string GetProcessImagePath(IntPtr hProcess, out int errorCode) {
        errorCode = 0; StringBuilder sb = new StringBuilder(1024); uint size = (uint)sb.Capacity;
        if (!QueryFullProcessImageNameW(hProcess, 0, sb, ref size)) { errorCode = Marshal.GetLastWin32Error(); return null; }
        return sb.ToString();
    }
    public static string ComputeHandleSha256(IntPtr hFile, out int errorCode) {
        errorCode = 0; uint ptrRes = SetFilePointer(hFile, 0, IntPtr.Zero, 0);
        if (ptrRes == unchecked((uint)-1)) {
            errorCode = Marshal.GetLastWin32Error(); if (errorCode != 0) return null;
        }
        try {
            SafeFileHandle sfh = new SafeFileHandle(hFile, false);
            using (FileStream fs = new FileStream(sfh, FileAccess.Read))
            using (SHA256 sha = SHA256.Create()) {
                byte[] hash = sha.ComputeHash(fs); StringBuilder sb = new StringBuilder(hash.Length * 2);
                for (int i = 0; i < hash.Length; i++) sb.Append(hash[i].ToString("x2"));
                uint resetRes = SetFilePointer(hFile, 0, IntPtr.Zero, 0);
                if (resetRes == unchecked((uint)-1)) {
                    int resetErr = Marshal.GetLastWin32Error(); if (resetErr != 0) { errorCode = resetErr; return null; }
                }
                return sb.ToString();
            }
        } catch (Exception) { errorCode = Marshal.GetLastWin32Error(); return null; }
    }
}
"@

$sleepAppSource = @"
using System; using System.Threading;
public static class SleepApp {
    public static void Main(string[] args) {
        int sleepMs = 30000;
        if (args != null && args.Length > 0) { int p; if (int.TryParse(args[0], out p) && p > 0) sleepMs = p; }
        Thread.Sleep(sleepMs);
    }
}
"@

$omegaChar = [char]0x03A9
$fixtureExeName = "shell test app $omegaChar.exe"; $fixtureExePath = [System.IO.Path]::Combine($labDir, $fixtureExeName)
$asideExeName = "shell test aside $omegaChar.exe"; $asideExePath = [System.IO.Path]::Combine($labDir, $asideExeName)
$fixtureDllName = "shell test dll $omegaChar.dll"; $fixtureDllPath = [System.IO.Path]::Combine($labDir, $fixtureDllName)

try {
    # Step A: Compile native P/Invoke wrapper under isolated scratch temp
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir
    Add-Type -TypeDefinition $nativeSource
    $script:CompilationUncertain = $false
    $env:TEMP = $origTemp; $env:TMP = $origTmp

    # Step B: Compile fixture application under isolated scratch temp
    $script:CompilationUncertain = $true
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir
    Add-Type -TypeDefinition $sleepAppSource -OutputType ConsoleApplication -OutputAssembly $fixtureExePath
    $script:CompilationUncertain = $false
    $env:TEMP = $origTemp; $env:TMP = $origTmp

    if (-not [System.IO.File]::Exists($fixtureExePath)) { throw "Fixture compilation failed: $fixtureExePath missing" }
    $controlExeHash = Compute-Sha256OfFile $fixtureExePath
    Write-Diagnostic "Compiled fixture executable: $fixtureExePath (SHA256: $controlExeHash)"

    # --- Suite 1: Baseline own EXE unleased and leased execution blocking ---
    Write-Diagnostic "--- Running Suite 1: Baseline unleased / leased EXE mapping ---"
    $openErr = 0; $hBase = [Win32NativeFileLeaseOracle]::CreateFileHandle($fixtureExePath, $script:GENERIC_READ, 1, 3, $script:REPARSE_FLAG, [ref]$openErr)
    if ($hBase -eq [IntPtr]::Zero) { throw "Failed to open baseline fixture EXE: error $openErr" }
    $baseRec = Register-LeaseHandle -FileHandle $hBase -Path $fixtureExePath -Tag "Suite1-Baseline"
    $vSerial = 0; $fIndex = 0; $fSize = 0; $fAttr = 0; $infoErr = 0
    $infoOk = [Win32NativeFileLeaseOracle]::GetHandleFileInfo($hBase, [ref]$vSerial, [ref]$fIndex, [ref]$fSize, [ref]$fAttr, [ref]$infoErr)
    if (-not $infoOk) { throw "GetFileInformationByHandle failed: error $infoErr" }
    if (-not (Close-TrackedLease $baseRec)) { throw "Failed to close baseline fixture EXE handle" }
    Write-Diagnostic "Suite 1: Baseline identity verified (Volume: $vSerial, Index: $fIndex, Size: $fSize)"

    $leaseErr = 0; $hLease = [Win32NativeFileLeaseOracle]::CreateFileHandle($fixtureExePath, $script:EXCLUSIVE_LEASE, 0, 3, $script:REPARSE_FLAG, [ref]$leaseErr)
    if ($hLease -eq [IntPtr]::Zero) { throw "Failed to acquire exclusive lease: error $leaseErr" }
    $leaseRec = Register-LeaseHandle -FileHandle $hLease -Path $fixtureExePath -Tag "Suite1-Lease"

    $hashErr = 0; $handleHash = [Win32NativeFileLeaseOracle]::ComputeHandleSha256($hLease, [ref]$hashErr)
    if ($handleHash -eq $null -or -not [string]::Equals($handleHash, $controlExeHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Hash through leased handle mismatch: expected $controlExeHash, got $handleHash (error: $hashErr)"
    }

    $hChildProc = [IntPtr]::Zero; $hChildThread = [IntPtr]::Zero; $childPid = 0; $childErr = 0
    $procOk = [Win32NativeFileLeaseOracle]::CreateChildProcess($fixtureExePath, "`"$fixtureExePath`" 30000", $script:PROC_FLAGS, [ref]$hChildProc, [ref]$hChildThread, [ref]$childPid, [ref]$childErr)
    if ($procOk) {
        [void](Register-ProcessHandle -ProcessHandle $hChildProc -ThreadHandle $hChildThread -ProcessId $childPid -Tag "Suite1-UnexpectedChild")
        throw "SECURITY INVARIANT VIOLATION: CreateProcessW succeeded while exclusive lease held!"
    }
    Assert-IsMappingError $childErr "CreateProcessW while exclusive lease held"
    Write-Diagnostic "Suite 1: CreateProcessW correctly blocked while lease held (native error: $childErr)"

    if (-not (Close-TrackedLease $leaseRec)) { throw "Failed to close lease in Suite 1" }

    $hRealProc = [IntPtr]::Zero; $hRealThread = [IntPtr]::Zero; $realPid = 0; $realErr = 0
    $realOk = [Win32NativeFileLeaseOracle]::CreateChildProcess($fixtureExePath, "`"$fixtureExePath`" 30000", $script:PROC_FLAGS, [ref]$hRealProc, [ref]$hRealThread, [ref]$realPid, [ref]$realErr)
    if (-not $realOk) { throw "CreateProcessW failed after lease release: error $realErr" }
    $realRec = Register-ProcessHandle -ProcessHandle $hRealProc -ThreadHandle $hRealThread -ProcessId $realPid -Tag "Suite1-RealChild"

    $imgErr = 0; $imgName = [Win32NativeFileLeaseOracle]::GetProcessImagePath($hRealProc, [ref]$imgErr)
    if ($imgName -eq $null -or -not [string]::Equals($imgName, $fixtureExePath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Child process image name mismatch: expected '$fixtureExePath', got '$imgName' (error: $imgErr)"
    }
    if (-not (Resume-TrackedThread $realRec)) { throw "Failed to resume child thread in Suite 1" }
    $stateRunning = Get-ProcessHandleState -hProcess $hRealProc
    if ($stateRunning -ne 'RUNNING') { throw "Child process state is not RUNNING after resume: $stateRunning" }
    $finalState = Stop-ChildProcess $realRec
    if ($finalState -ne 'DEAD' -or -not $realRec.Closed) { throw "Child process could not be proven DEAD or closed after stop: $finalState" }
    Write-Diagnostic "Suite 1 PASS: Baseline unleased / leased EXE mapping assertions passed."

    # --- Suite 2: Existing mapped image blocks exclusive lease ---
    Write-Diagnostic "--- Running Suite 2: Existing mapped image blocks lease ---"
    $hSuspProc = [IntPtr]::Zero; $hSuspThread = [IntPtr]::Zero; $suspPid = 0; $suspErr = 0
    $suspOk = [Win32NativeFileLeaseOracle]::CreateChildProcess($fixtureExePath, "`"$fixtureExePath`" 30000", $script:PROC_FLAGS, [ref]$hSuspProc, [ref]$hSuspThread, [ref]$suspPid, [ref]$suspErr)
    if (-not $suspOk) { throw "Failed to create suspended child: error $suspErr" }
    $suspRec = Register-ProcessHandle -ProcessHandle $hSuspProc -ThreadHandle $hSuspThread -ProcessId $suspPid -Tag "Suite2-SuspendedChild"

    $hAttempt1 = [Win32NativeFileLeaseOracle]::CreateFileHandle($fixtureExePath, $script:EXCLUSIVE_LEASE, 0, 3, $script:REPARSE_FLAG, [ref]$leaseErr)
    if ($hAttempt1 -ne [IntPtr]::Zero) {
        [void](Register-LeaseHandle -FileHandle $hAttempt1 -Path $fixtureExePath -Tag "Suite2-UnexpectedLease1")
        throw "SECURITY INVARIANT VIOLATION: Exclusive lease acquired on suspended image!"
    }
    Assert-IsMappingError $leaseErr "CreateFileW while image mapped (CREATE_SUSPENDED)"
    Write-Diagnostic "Suite 2: Exclusive lease blocked on suspended image (native error: $leaseErr)"

    if (-not (Resume-TrackedThread $suspRec)) { throw "Failed to resume child thread in Suite 2" }
    $stateRunning2 = Get-ProcessHandleState -hProcess $hSuspProc
    if ($stateRunning2 -ne 'RUNNING') { throw "Child process not RUNNING after resume: $stateRunning2" }

    $hAttempt2 = [Win32NativeFileLeaseOracle]::CreateFileHandle($fixtureExePath, $script:EXCLUSIVE_LEASE, 0, 3, $script:REPARSE_FLAG, [ref]$leaseErr)
    if ($hAttempt2 -ne [IntPtr]::Zero) {
        [void](Register-LeaseHandle -FileHandle $hAttempt2 -Path $fixtureExePath -Tag "Suite2-UnexpectedLease2")
        throw "SECURITY INVARIANT VIOLATION: Exclusive lease acquired on running image!"
    }
    Assert-IsMappingError $leaseErr "CreateFileW while image mapped (RUNNING)"
    Write-Diagnostic "Suite 2: Exclusive lease blocked on running image (native error: $leaseErr)"

    $stop2 = Stop-ChildProcess $suspRec
    if ($stop2 -ne 'DEAD' -or -not $suspRec.Closed) { throw "Child process could not be proven DEAD or closed: $stop2" }

    $hLease2 = [Win32NativeFileLeaseOracle]::CreateFileHandle($fixtureExePath, $script:EXCLUSIVE_LEASE, 0, 3, $script:REPARSE_FLAG, [ref]$leaseErr)
    if ($hLease2 -eq [IntPtr]::Zero) { throw "Failed to acquire exclusive lease after child termination: error $leaseErr" }
    $leaseRec2 = Register-LeaseHandle -FileHandle $hLease2 -Path $fixtureExePath -Tag "Suite2-LeaseAfterDead"

    $hHash2 = [Win32NativeFileLeaseOracle]::ComputeHandleSha256($hLease2, [ref]$hashErr)
    if (-not [string]::Equals($hHash2, $controlExeHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Hash mismatch through leased handle after termination: expected $controlExeHash, got $hHash2"
    }
    if (-not (Close-TrackedLease $leaseRec2)) { throw "Failed to close lease after termination in Suite 2" }

    $diskHash2 = Compute-Sha256OfFile $fixtureExePath
    if (-not [string]::Equals($diskHash2, $controlExeHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Disk bytes mutated in Suite 2: expected $controlExeHash, got $diskHash2"
    }
    Write-Diagnostic "Suite 2 PASS: Existing mapped image blocking assertions passed."

    # --- Suite 3: DLL mapping separate control ---
    Write-Diagnostic "--- Running Suite 3: DLL mapping separate control ---"
    $system32Dll = [System.IO.Path]::Combine($env:windir, "System32\version.dll")
    if (-not [System.IO.File]::Exists($system32Dll)) { throw "System32 version.dll not found at: $system32Dll" }
    [System.IO.File]::WriteAllBytes($fixtureDllPath, [System.IO.File]::ReadAllBytes($system32Dll))
    $dllFi = [System.IO.FileInfo]::new($fixtureDllPath)
    if ($dllFi.IsReadOnly) { $dllFi.IsReadOnly = $false }
    $controlDllHash = Compute-Sha256OfFile $fixtureDllPath
    Write-Diagnostic "Suite 3: Created writable copy of version.dll at $fixtureDllPath (SHA256: $controlDllHash)"

    # Positive control 1: exclusive lease on unmapped DLL before any load
    $hDllPreLease = [Win32NativeFileLeaseOracle]::CreateFileHandle($fixtureDllPath, $script:EXCLUSIVE_LEASE, 0, 3, $script:REPARSE_FLAG, [ref]$leaseErr)
    if ($hDllPreLease -eq [IntPtr]::Zero) { throw "Failed to acquire initial exclusive lease on unmapped DLL: error $leaseErr" }
    $dllPreLeaseRec = Register-LeaseHandle -FileHandle $hDllPreLease -Path $fixtureDllPath -Tag "Suite3-DllPreLease"

    $preHash = [Win32NativeFileLeaseOracle]::ComputeHandleSha256($hDllPreLease, [ref]$hashErr)
    if (-not [string]::Equals($preHash, $controlDllHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Hash mismatch through initial leased DLL handle: expected $controlDllHash, got $preHash"
    }
    if (-not (Close-TrackedLease $dllPreLeaseRec)) { throw "Failed to close initial DLL lease" }
    Write-Diagnostic "Suite 3: Positive control initial exclusive lease + hash verified."

    # Load DLL module
    $modErr = 0; $hMod = [Win32NativeFileLeaseOracle]::LoadLibraryModule($fixtureDllPath, $script:DLL_SEARCH_FLAGS, [ref]$modErr)
    if ($hMod -eq [IntPtr]::Zero) { throw "LoadLibraryExW failed on owned DLL copy: error $modErr" }
    $modRec = Register-ModuleHandle -ModuleHandle $hMod -Path $fixtureDllPath -Tag "Suite3-DllModule"

    $loadedModPath = [Win32NativeFileLeaseOracle]::GetModulePath($hMod, [ref]$modErr)
    if ($loadedModPath -eq $null -or -not [string]::Equals($loadedModPath, $fixtureDllPath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Loaded module path mismatch: expected '$fixtureDllPath', got '$loadedModPath' (error: $modErr)"
    }
    Write-Diagnostic "Suite 3: Module loaded and verified at exact owned path: $loadedModPath"

    # Negative control: exclusive lease blocked while DLL is mapped
    $hDllLeaseFail = [Win32NativeFileLeaseOracle]::CreateFileHandle($fixtureDllPath, $script:EXCLUSIVE_LEASE, 0, 3, $script:REPARSE_FLAG, [ref]$leaseErr)
    if ($hDllLeaseFail -ne [IntPtr]::Zero) {
        [void](Register-LeaseHandle -FileHandle $hDllLeaseFail -Path $fixtureDllPath -Tag "Suite3-UnexpectedDllLease")
        throw "SECURITY INVARIANT VIOLATION: Exclusive lease acquired on mapped DLL!"
    }
    Assert-IsMappingError $leaseErr "CreateFileW while DLL mapped"
    Write-Diagnostic "Suite 3: Exclusive lease blocked on mapped DLL (native error: $leaseErr)"

    # Free module
    if (-not (Free-TrackedModule $modRec)) { throw "Failed to free DLL module" }

    # Positive control 2: exclusive lease after free
    $hDllLease = [Win32NativeFileLeaseOracle]::CreateFileHandle($fixtureDllPath, $script:EXCLUSIVE_LEASE, 0, 3, $script:REPARSE_FLAG, [ref]$leaseErr)
    if ($hDllLease -eq [IntPtr]::Zero) { throw "Failed to acquire exclusive lease on unmapped DLL: error $leaseErr" }
    $dllLeaseRec = Register-LeaseHandle -FileHandle $hDllLease -Path $fixtureDllPath -Tag "Suite3-DllLease"

    $dllHandleHash = [Win32NativeFileLeaseOracle]::ComputeHandleSha256($hDllLease, [ref]$hashErr)
    if (-not [string]::Equals($dllHandleHash, $controlDllHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Hash mismatch through leased DLL handle: expected $controlDllHash, got $dllHandleHash"
    }

    # Negative control: LoadLibraryExW blocked while exclusive lease held
    $hModFail = [Win32NativeFileLeaseOracle]::LoadLibraryModule($fixtureDllPath, $script:DLL_SEARCH_FLAGS, [ref]$modErr)
    if ($hModFail -ne [IntPtr]::Zero) {
        [void](Register-ModuleHandle -ModuleHandle $hModFail -Path $fixtureDllPath -Tag "Suite3-UnexpectedModAfterLease")
        throw "SECURITY INVARIANT VIOLATION: LoadLibraryExW succeeded while exclusive lease held on DLL!"
    }
    Assert-IsMappingError $modErr "LoadLibraryExW while exclusive lease held on DLL"
    Write-Diagnostic "Suite 3: LoadLibraryExW blocked while lease held on DLL (native error: $modErr)"

    # Release lease
    if (-not (Close-TrackedLease $dllLeaseRec)) { throw "Failed to close leased DLL handle" }

    # Reload positive control
    $hMod2 = [Win32NativeFileLeaseOracle]::LoadLibraryModule($fixtureDllPath, $script:DLL_SEARCH_FLAGS, [ref]$modErr)
    if ($hMod2 -eq [IntPtr]::Zero) { throw "Reload of DLL failed after lease release: error $modErr" }
    $modRec2 = Register-ModuleHandle -ModuleHandle $hMod2 -Path $fixtureDllPath -Tag "Suite3-ReloadDll"
    $loadedModPath2 = [Win32NativeFileLeaseOracle]::GetModulePath($hMod2, [ref]$modErr)
    if (-not [string]::Equals($loadedModPath2, $fixtureDllPath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Reloaded module path mismatch: expected '$fixtureDllPath', got '$loadedModPath2'"
    }
    if (-not (Free-TrackedModule $modRec2)) { throw "Failed to free reloaded DLL module" }

    $diskDllHash = Compute-Sha256OfFile $fixtureDllPath
    if (-not [string]::Equals($diskDllHash, $controlDllHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "DLL bytes mutated on disk in Suite 3: expected $controlDllHash, got $diskDllHash"
    }
    Write-Diagnostic "Suite 3 PASS: DLL mapping separate control assertions passed."

    # --- Suite 4: Rename control via same handle ---
    Write-Diagnostic "--- Running Suite 4: Rename control via same handle ---"
    if ([System.IO.File]::Exists($asideExePath)) { throw "Precondition violated: aside path exists at $asideExePath" }

    $hRenameLease = [Win32NativeFileLeaseOracle]::CreateFileHandle($fixtureExePath, $script:EXCLUSIVE_LEASE, 0, 3, $script:REPARSE_FLAG, [ref]$leaseErr)
    if ($hRenameLease -eq [IntPtr]::Zero) { throw "Failed to acquire exclusive lease for rename test: error $leaseErr" }
    $renameLeaseRec = Register-LeaseHandle -FileHandle $hRenameLease -Path $fixtureExePath -Tag "Suite4-RenameLease"

    $rnErr = 0; $rnOk = [Win32NativeFileLeaseOracle]::RenameFileHandle($hRenameLease, $asideExePath, $false, [ref]$rnErr)
    if (-not $rnOk) { throw "SetFileInformationByHandle rename to aside path failed: error $rnErr" }

    $pErr = 0; $finalPathAside = [Win32NativeFileLeaseOracle]::GetFinalHandlePath($hRenameLease, [ref]$pErr)
    if ($finalPathAside -eq $null -or -not [string]::Equals($finalPathAside, $asideExePath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "GetFinalPathNameByHandle mismatch after rename: expected '$asideExePath', got '$finalPathAside' (error: $pErr)"
    }
    if (-not [System.IO.File]::Exists($asideExePath)) { throw "Renamed aside executable path does not exist on disk: $asideExePath" }
    if ([System.IO.File]::Exists($fixtureExePath)) { throw "Original executable path still exists on disk after rename: $fixtureExePath" }

    $asideHandleHash = [Win32NativeFileLeaseOracle]::ComputeHandleSha256($hRenameLease, [ref]$hashErr)
    if (-not [string]::Equals($asideHandleHash, $controlExeHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Hash mismatch through leased handle after rename: expected $controlExeHash, got $asideHandleHash"
    }

    $hAsideProc = [IntPtr]::Zero; $hAsideThread = [IntPtr]::Zero; $asidePid = 0; $asideErr = 0
    $asideProcOk = [Win32NativeFileLeaseOracle]::CreateChildProcess($asideExePath, "`"$asideExePath`" 30000", $script:PROC_FLAGS, [ref]$hAsideProc, [ref]$hAsideThread, [ref]$asidePid, [ref]$asideErr)
    if ($asideProcOk) {
        [void](Register-ProcessHandle -ProcessHandle $hAsideProc -ThreadHandle $hAsideThread -ProcessId $asidePid -Tag "Suite4-UnexpectedAsideChild")
        throw "SECURITY INVARIANT VIOLATION: CreateProcessW succeeded on leased aside executable!"
    }
    Assert-IsMappingError $asideErr "CreateProcessW on aside path while leased"
    Write-Diagnostic "Suite 4: CreateProcessW of aside path blocked while leased (native error: $asideErr)"

    if ([System.IO.File]::Exists($fixtureExePath)) { throw "Original executable path still exists on disk after rename: $fixtureExePath" }

    $hOldProc = [IntPtr]::Zero; $hOldThread = [IntPtr]::Zero; $oldPid = 0; $oldErr = 0
    $oldProcOk = [Win32NativeFileLeaseOracle]::CreateChildProcess($fixtureExePath, "`"$fixtureExePath`" 30000", $script:PROC_FLAGS, [ref]$hOldProc, [ref]$hOldThread, [ref]$oldPid, [ref]$oldErr)
    if ($oldProcOk) {
        [void](Register-ProcessHandle -ProcessHandle $hOldProc -ThreadHandle $hOldThread -ProcessId $oldPid -Tag "Suite4-UnexpectedOldChild")
        throw "SECURITY INVARIANT VIOLATION: CreateProcessW succeeded on old path after rename!"
    }
    Assert-IsFileNotFoundError $oldErr "CreateProcessW on old path after rename"
    Write-Diagnostic "Suite 4: Old path verified absent; CreateProcessW returned file not found ($oldErr)"

    $rnBackOk = [Win32NativeFileLeaseOracle]::RenameFileHandle($hRenameLease, $fixtureExePath, $false, [ref]$rnErr)
    if (-not $rnBackOk) { throw "SetFileInformationByHandle rename back to original path failed: error $rnErr" }

    $finalPathBack = [Win32NativeFileLeaseOracle]::GetFinalHandlePath($hRenameLease, [ref]$pErr)
    if (-not [string]::Equals($finalPathBack, $fixtureExePath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "GetFinalPathNameByHandle mismatch after rename back: expected '$fixtureExePath', got '$finalPathBack'"
    }

    $backHandleHash = [Win32NativeFileLeaseOracle]::ComputeHandleSha256($hRenameLease, [ref]$hashErr)
    if (-not [string]::Equals($backHandleHash, $controlExeHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Hash mismatch through leased handle after rename back: expected $controlExeHash, got $backHandleHash"
    }

    if (-not [System.IO.File]::Exists($fixtureExePath)) { throw "Original path missing after rename back: $fixtureExePath" }
    if ([System.IO.File]::Exists($asideExePath)) { throw "Aside path still exists after rename back: $asideExePath" }

    if (-not (Close-TrackedLease $renameLeaseRec)) { throw "Failed to close rename lease in Suite 4" }

    $hAfterProc = [IntPtr]::Zero; $hAfterThread = [IntPtr]::Zero; $afterPid = 0; $afterErr = 0
    $afterOk = [Win32NativeFileLeaseOracle]::CreateChildProcess($fixtureExePath, "`"$fixtureExePath`" 30000", $script:PROC_FLAGS, [ref]$hAfterProc, [ref]$hAfterThread, [ref]$afterPid, [ref]$afterErr)
    if (-not $afterOk) { throw "CreateProcessW failed after rename cycle: error $afterErr" }
    $afterRec = Register-ProcessHandle -ProcessHandle $hAfterProc -ThreadHandle $hAfterThread -ProcessId $afterPid -Tag "Suite4-AfterChild"
    if (-not (Resume-TrackedThread $afterRec)) { throw "Failed to resume child thread after rename cycle" }
    $stateAfter = Get-ProcessHandleState -hProcess $hAfterProc
    if ($stateAfter -ne 'RUNNING') { throw "Process not RUNNING after rename cycle: $stateAfter" }
    $stopAfter = Stop-ChildProcess $afterRec
    if ($stopAfter -ne 'DEAD' -or -not $afterRec.Closed) { throw "Process could not be proven DEAD or closed after rename cycle: $stopAfter" }
    Write-Diagnostic "Suite 4 PASS: Rename control via same handle assertions passed."

    # --- Suite 5: Teardown uncertainty selftest ---
    Write-Diagnostic "--- Running Suite 5: Teardown uncertainty selftest ---"
    $faultTestDir = [System.IO.Path]::Combine($labDir, "fault-selftest-$nonce"); [void][System.IO.Directory]::CreateDirectory($faultTestDir)
    $faultMarkerPath = [System.IO.Path]::Combine($faultTestDir, ".fault-marker"); $faultMarkerNonce = "FAULT_MARKER_$nonce"
    [System.IO.File]::WriteAllText($faultMarkerPath, $faultMarkerNonce, [System.Text.Encoding]::UTF8)
    $sentinelPath = [System.IO.Path]::Combine($faultTestDir, "sentinel.dat"); [System.IO.File]::WriteAllBytes($sentinelPath, [System.Text.Encoding]::UTF8.GetBytes("SENTINEL_BYTES_$nonce"))
    $controlSentinelHash = Compute-Sha256OfFile $sentinelPath

    # Add own fault log before child, with hash/byte comparison after shared gate
    $faultLogPath = [System.IO.Path]::Combine($faultTestDir, "fault-execution.log")
    $faultLogContent = "FAULT_LOG_ENTRY_INIT_$nonce"
    [System.IO.File]::WriteAllText($faultLogPath, $faultLogContent, [System.Text.Encoding]::UTF8)
    $controlFaultLogHash = Compute-Sha256OfFile $faultLogPath

    $hFaultProc = [IntPtr]::Zero; $hFaultThread = [IntPtr]::Zero; $faultPid = 0; $faultErr = 0
    $faultOk = [Win32NativeFileLeaseOracle]::CreateChildProcess($fixtureExePath, "`"$fixtureExePath`" 30000", $script:PROC_FLAGS, [ref]$hFaultProc, [ref]$hFaultThread, [ref]$faultPid, [ref]$faultErr)
    if (-not $faultOk) { throw "Failed to create child process for Suite 5: error $faultErr" }
    $faultRec = Register-ProcessHandle -ProcessHandle $hFaultProc -ThreadHandle $hFaultThread -ProcessId $faultPid -Tag "Suite5-FaultChild"

    if (-not (Resume-TrackedThread $faultRec)) { throw "Failed to resume fault child thread in Suite 5" }
    $realFaultState = Get-ProcessHandleState -hProcess $hFaultProc
    if ($realFaultState -ne 'RUNNING') { throw "Suite 5 child is not in RUNNING state: $realFaultState" }

    $script:TeardownHandleStatusHook = @{ Handle = $hFaultProc; ForceState = 'UNKNOWN' }
    $script:UnknownHookHits = 0
    $script:FaultCallbackInvoked = $false
    $faultRestorationCallback = { $script:FaultCallbackInvoked = $true }
    $faultSpec = @( @{ Path = $faultTestDir; MarkerName = ".fault-marker"; Nonce = $faultMarkerNonce } )

    $cleanupStatus = Invoke-SharedCleanupSafetyGate -TargetSpecs $faultSpec -RestorationCallback $faultRestorationCallback

    if ($cleanupStatus -ne 'NEEDSATTENTION') { throw "Teardown selftest failed: expected 'NEEDSATTENTION', got '$cleanupStatus'" }
    if ($script:UnknownHookHits -ne 1) { throw "Teardown selftest failed: expected exactly 1 hook hit, got $($script:UnknownHookHits)" }
    if (-not [System.IO.Directory]::Exists($faultTestDir)) { throw "Teardown selftest failed: fault dir was deleted despite UNKNOWN state!" }
    if (-not [string]::Equals((Compute-Sha256OfFile $sentinelPath), $controlSentinelHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Teardown selftest failed: sentinel bytes were mutated!"
    }
    if (-not [string]::Equals([System.IO.File]::ReadAllText($faultMarkerPath, [System.Text.Encoding]::UTF8), $faultMarkerNonce, [System.StringComparison]::Ordinal)) {
        throw "Teardown selftest failed: marker bytes were mutated!"
    }
    if (-not [string]::Equals([System.IO.File]::ReadAllText($faultLogPath, [System.Text.Encoding]::UTF8), $faultLogContent, [System.StringComparison]::Ordinal)) {
        throw "Teardown selftest failed: fault log bytes were mutated!"
    }
    if (-not [string]::Equals((Compute-Sha256OfFile $faultLogPath), $controlFaultLogHash, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Teardown selftest failed: fault log hash mismatch!"
    }
    if (-not $script:TrackedProcesses.Contains($faultRec)) {
        throw "Teardown selftest failed: fault record was removed from global registry!"
    }
    if ($faultRec.Closed) { throw "Teardown selftest failed: alive handle marked Closed despite UNKNOWN status!" }
    if ($faultRec.ProcessClosed) { throw "Teardown selftest failed: alive process marked ProcessClosed despite UNKNOWN status!" }
    if ($faultRec.ThreadClosed) { throw "Teardown selftest failed: alive thread marked ThreadClosed despite UNKNOWN status!" }
    if ($script:FaultCallbackInvoked) { throw "Teardown selftest failed: restoration callback invoked during uncertainty!" }

    $script:TeardownHandleStatusHook = $null
    $clearedState = Get-ProcessHandleState -hProcess $hFaultProc
    if ($clearedState -ne 'RUNNING') { throw "Child process was not RUNNING after clearing hook: $clearedState" }
    $faultStopState = Stop-ChildProcess $faultRec
    if ($faultStopState -ne 'DEAD' -or -not $faultRec.Closed) { throw "Suite 5 child process could not be proven DEAD or closed: $faultStopState" }
    Write-Diagnostic "Suite 5 PASS: Teardown uncertainty selftest passed."

    $script:AllSuitesPassed = $true
}
catch {
    $script:PrimaryError = $_.Exception
    Write-Diagnostic "PRIMARY EXECUTION FAILURE: $($_.Exception.ToString())"
}
finally {
    $env:TEMP = $origTemp; $env:TMP = $origTmp
    $script:TeardownHandleStatusHook = $null
    Write-Diagnostic "--- Entering Global Teardown ---"
    $teardownErrors = [System.Collections.Generic.List[string]]::new()

    # Step 1: Release all tracked DLL module handles independently
    foreach ($mod in $script:TrackedModules) {
        if (-not $mod.Closed) {
            try {
                if (-not (Free-TrackedModule $mod)) { $teardownErrors.Add("Failed to free module $($mod.Tag)") }
            } catch { $teardownErrors.Add("Exception freeing module $($mod.Tag): $($_.Exception.Message)") }
        }
    }

    # Step 2: Release all tracked lease handles independently
    foreach ($lease in $script:TrackedLeases) {
        if (-not $lease.Closed) {
            try {
                if (-not (Close-TrackedLease $lease)) { $teardownErrors.Add("Failed to close lease $($lease.Tag)") }
            } catch { $teardownErrors.Add("Exception closing lease $($lease.Tag): $($_.Exception.Message)") }
        }
    }

    # Step 3: Stop/wait all tracked child process handles and prove DEAD
    foreach ($proc in $script:TrackedProcesses) {
        if (-not $proc.Closed) {
            try {
                $st = Stop-ChildProcess $proc
                if ($st -ne 'DEAD' -or -not $proc.Closed) {
                    $teardownErrors.Add("Child process $($proc.Tag) (process handle $($proc.ProcessHandle), thread handle $($proc.ThreadHandle)) could not be proven DEAD or closed (state: $st, closed: $($proc.Closed), procClosed: $($proc.ProcessClosed), threadClosed: $($proc.ThreadClosed))")
                }
            } catch { $teardownErrors.Add("Exception stopping process $($proc.Tag): $($_.Exception.Message)") }
        }
    }

    # Step 4: Shared safety gate cleanup for lab and victim directories
    $globalCleanupResult = 'NEEDSATTENTION'
    $globalTargets = @(
        @{ Path = $labDir; MarkerName = ".snfl-lab-marker"; Nonce = $nonce },
        @{ Path = $victimDir; MarkerName = ".snfl-victim-marker"; Nonce = $nonce }
    )
    if ($teardownErrors.Count -eq 0 -and $null -eq $script:PrimaryError -and -not $script:CompilationUncertain) {
        try {
            $globalCleanupResult = Invoke-SharedCleanupSafetyGate -TargetSpecs $globalTargets
            if ($globalCleanupResult -eq 'CLEANED') {
                Write-Diagnostic "Global cleanup succeeded: lab and victim directories safely removed."
            } else {
                $teardownErrors.Add("Global cleanup gate returned '$globalCleanupResult'")
            }
        } catch {
            $teardownErrors.Add("Global directory cleanup error: $($_.Exception.Message)")
        }
    } else {
        Write-Diagnostic "TEARDOWN RETENTION: Retaining lab and victim directories due to uncertainty/failure."
        if ($script:CompilationUncertain) { Write-Diagnostic "  Reason: Compilation state marked uncertain." }
        if ($null -ne $script:PrimaryError) { Write-Diagnostic "  Reason: Primary test failure: $($script:PrimaryError.Message)" }
        if ($teardownErrors.Count -gt 0) { Write-Diagnostic "  Reason: Teardown errors present ($($teardownErrors.Count))." }
    }

    # Final Summary Reporting
    $allProcessesClosed = @($script:TrackedProcesses | Where-Object { -not $_.Closed }).Count -eq 0
    $allLeasesClosed = @($script:TrackedLeases | Where-Object { -not $_.Closed }).Count -eq 0
    $allModulesClosed = @($script:TrackedModules | Where-Object { -not $_.Closed }).Count -eq 0

    $canExitZero = $script:AllSuitesPassed -and
                   ($null -eq $script:PrimaryError) -and
                   ($teardownErrors.Count -eq 0) -and
                   ($globalCleanupResult -eq 'CLEANED') -and
                   $allProcessesClosed -and
                   $allLeasesClosed -and
                   $allModulesClosed -and
                   (-not $script:CompilationUncertain)

    Write-Diagnostic "============================================================"
    if ($canExitZero) {
        Write-Diagnostic "Summary: All 5 native file lease oracle suites PASSED."
        Write-Diagnostic "Verified primitive: Exclusive READ|WRITE|DELETE file handle prevents executable and DLL mapping, blocks while image mapped, and supports in-place atomic rename via own handle."
        Write-Diagnostic "Notice: Proves Win32 primitive only; does not provide full launch interlock, directory TOCTOU protection, durability, DB restore, or legacy bootstrap."
        Write-Diagnostic "============================================================"
        exit 0
    } else {
        Write-Diagnostic "Summary: Native file lease oracle FAILED."
        if (-not $script:AllSuitesPassed) { Write-Diagnostic "Failure: Not all suites passed." }
        if ($null -ne $script:PrimaryError) { Write-Diagnostic "Primary Error: $($script:PrimaryError.Message)" }
        if ($script:CompilationUncertain) { Write-Diagnostic "Failure: Compilation state is uncertain." }
        if ($globalCleanupResult -ne 'CLEANED') { Write-Diagnostic "Failure: Global cleanup gate result is '$globalCleanupResult'." }
        if (-not $allProcessesClosed) { Write-Diagnostic "Failure: Not all process handles were closed." }
        if (-not $allLeasesClosed) { Write-Diagnostic "Failure: Not all lease handles were closed." }
        if (-not $allModulesClosed) { Write-Diagnostic "Failure: Not all module handles were closed." }
        if ($teardownErrors.Count -gt 0) {
            Write-Diagnostic "Teardown Errors ($($teardownErrors.Count)):"
            foreach ($te in $teardownErrors) { Write-Diagnostic "  - $te" }
        }
        Write-Diagnostic "============================================================"
        exit 1
    }
}
