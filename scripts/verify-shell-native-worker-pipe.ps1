# scripts/verify-shell-native-worker-pipe.ps1
# Win32 Native Worker Pipe Connection Primitive Verifier
# Verifies local-only kernel-enforced named pipe peer binding, overlapped connect lifecycle, and cleanup proofs.

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

# --- Section 3: Safe Traversal, Nonce-Marked Lab, and Immutable Victim ---
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
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snwp-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snwp-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
[void][System.IO.Directory]::CreateDirectory($labDir)
[void][System.IO.Directory]::CreateDirectory($labTempDir)
[void][System.IO.Directory]::CreateDirectory($victimDir)

$labMarkerPath = [System.IO.Path]::Combine($labDir, ".snwp-lab-marker")
[System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".snwp-victim-marker")
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
$script:TrackedEndpoints = [System.Collections.Generic.List[PSObject]]::new()
$script:TrackedInvocations = [System.Collections.Generic.List[PSObject]]::new()
$script:OwnerStopErrors = [System.Collections.Generic.List[string]]::new()
$script:EndpointCloseErrors = [System.Collections.Generic.List[string]]::new()
$script:InvocationDrainErrors = [System.Collections.Generic.List[string]]::new()
$script:CompilationUncertain = $false
$script:PrimaryError = $null

function Register-TrackedInvocation {
    param($PowerShell, [string]$Tag = "Invocation")
    $rec = [PSObject]@{
        PowerShell = $PowerShell
        AsyncHandle = $null
        Tag = $Tag
        IsCompleted = $false
        Drained = $false
        Disposed = $false
        Result = $null
        Error = $null
    }
    $script:TrackedInvocations.Add($rec)
    return $rec
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

function Close-TrackedEndpoint {
    param($epRec, [int]$timeoutMs = 5000)
    if ($epRec -eq $null -or $epRec.Endpoint -eq $null) {
        Write-Diagnostic "Close-TrackedEndpoint: missing or null endpoint record; uncertain."
        return $false
    }
    if ($epRec.Closed) {
        if (-not $epRec.Endpoint.IsClosed) {
            Write-Diagnostic "Close-TrackedEndpoint: record marked closed but Endpoint.IsClosed is false ($($epRec.Tag))."
            return $false
        }
        return $true
    }
    $err = 0
    $ok = $epRec.Endpoint.TryClose($timeoutMs, [ref]$err)
    if ($ok -and $epRec.Endpoint.IsClosed) {
        $epRec.Closed = $true
        return $true
    }
    Write-Diagnostic "TryClose failed for endpoint ($($epRec.Tag)): error $err, IsClosed: $($epRec.Endpoint.IsClosed)"
    return $false
}

function Drain-TrackedInvocation {
    param($invRec, [int]$timeoutMs = 12000)
    if ($invRec -eq $null -or $invRec.PowerShell -eq $null) {
        Write-Diagnostic "Drain-TrackedInvocation: missing or null invocation record."
        return $false
    }
    if ($invRec.Drained) {
        return $true
    }
    if ($invRec.AsyncHandle -eq $null) {
        try {
            if (-not $invRec.Disposed) {
                $invRec.PowerShell.Dispose()
                $invRec.Disposed = $true
            }
            $invRec.Drained = $true
            return $true
        } catch {
            $msg = "Exception disposing uninvoked PowerShell ($($invRec.Tag)): $($_.Exception.Message)"
            Write-Diagnostic $msg
            $script:InvocationDrainErrors.Add($msg)
            return $false
        }
    }

    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $handleCompleted = $false
    while ($sw.ElapsedMilliseconds -lt $timeoutMs) {
        if ($invRec.AsyncHandle.IsCompleted) {
            $handleCompleted = $true
            break
        }
        if ($invRec.AsyncHandle.AsyncWaitHandle.WaitOne(100)) {
            $handleCompleted = $true
            break
        }
    }

    if (-not $handleCompleted -and -not $invRec.AsyncHandle.IsCompleted) {
        $msg = "Invocation drain timed out after $($timeoutMs)ms waiting for completion ($($invRec.Tag)). Cannot safely EndInvoke/Dispose."
        Write-Diagnostic $msg
        $script:InvocationDrainErrors.Add($msg)
        return $false
    }

    $invRec.IsCompleted = $true
    try {
        if (-not $invRec.Drained) {
            $invRec.Result = $invRec.PowerShell.EndInvoke($invRec.AsyncHandle)
            $invRec.Drained = $true
        }
    } catch {
        $invRec.Error = $_.Exception
        $msg = "Exception during EndInvoke for invocation ($($invRec.Tag)): $($_.Exception.Message)"
        Write-Diagnostic $msg
        $invRec.Drained = $true
    }

    try {
        if (-not $invRec.Disposed) {
            $invRec.PowerShell.Dispose()
            $invRec.Disposed = $true
        }
    } catch {
        $msg = "Exception during PowerShell disposal ($($invRec.Tag)): $($_.Exception.Message)"
        Write-Diagnostic $msg
        $script:InvocationDrainErrors.Add($msg)
        return $false
    }

    return $true
}

function Drain-AllTrackedInvocations {
    param([int]$timeoutMs = 12000)
    foreach ($invRec in $script:TrackedInvocations) {
        if ($invRec -ne $null -and -not $invRec.Drained) {
            [void](Drain-TrackedInvocation $invRec $timeoutMs)
        }
    }
}

function Close-AllTrackedEndpoints {
    $undrained = @($script:TrackedInvocations | Where-Object { -not $_.Drained })
    if ($undrained.Count -gt 0) {
        $msg = "Close-AllTrackedEndpoints refused: $($undrained.Count) background invocation(s) not proven drained."
        Write-Diagnostic $msg
        $script:EndpointCloseErrors.Add($msg)
        return
    }
    foreach ($epRec in $script:TrackedEndpoints) {
        if ($epRec -ne $null -and $epRec.Endpoint -ne $null -and -not $epRec.Closed) {
            try {
                $err = 0
                $ok = $epRec.Endpoint.TryClose(5000, [ref]$err)
                if ($ok -and $epRec.Endpoint.IsClosed) {
                    $epRec.Closed = $true
                } else {
                    $msg = "Cleanup TryClose failed for endpoint ($($epRec.Tag)): error $err, IsClosed: $($epRec.Endpoint.IsClosed)"
                    Write-Diagnostic $msg
                    $script:EndpointCloseErrors.Add($msg)
                }
            } catch {
                $msg = "Exception during endpoint cleanup ($($epRec.Tag)): $($_.Exception.Message)"
                Write-Diagnostic $msg
                $script:EndpointCloseErrors.Add($msg)
            }
        }
    }
}

function Stop-AllTrackedOwners {
    $undrained = @($script:TrackedInvocations | Where-Object { -not $_.Drained })
    if ($undrained.Count -gt 0) {
        $msg = "Stop-AllTrackedOwners refused: $($undrained.Count) background invocation(s) not proven drained."
        Write-Diagnostic $msg
        $script:OwnerStopErrors.Add($msg)
        return
    }
    foreach ($ownerRec in $script:TrackedOwners) {
        if ($ownerRec -ne $null -and $ownerRec.Owner -ne $null -and -not $ownerRec.Closed) {
            try {
                $err = 0
                $ok = $ownerRec.Owner.TryStopAndClose(5000, [ref]$err)
                if ($ok -and $ownerRec.Owner.IsClosed) {
                    $ownerRec.Closed = $true
                } else {
                    $msg = "Cleanup TryStopAndClose failed for owner ($($ownerRec.Tag)): error $err, IsClosed: $($ownerRec.Owner.IsClosed)"
                    Write-Diagnostic $msg
                    $script:OwnerStopErrors.Add($msg)
                }
            } catch {
                $msg = "Exception during owner cleanup ($($ownerRec.Tag)): $($_.Exception.Message)"
                Write-Diagnostic $msg
                $script:OwnerStopErrors.Add($msg)
            }
        }
    }
}

function Invoke-SharedCleanupSafetyGate {
    param([array]$TargetSpecs = $null, [switch]$DryRun)

    if ($script:CompilationUncertain) {
        Write-Diagnostic "Teardown uncertainty: compilation state marked uncertain."
        return 'NEEDSATTENTION'
    }
    if ($null -ne $script:PrimaryError) {
        Write-Diagnostic "Teardown uncertainty: primary test error exists ($($script:PrimaryError.Message))."
        return 'NEEDSATTENTION'
    }
    if ($script:InvocationDrainErrors.Count -gt 0) {
        Write-Diagnostic "Teardown uncertainty: invocation drain errors recorded ($($script:InvocationDrainErrors.Count) errors)."
        return 'NEEDSATTENTION'
    }
    if ($script:OwnerStopErrors.Count -gt 0) {
        Write-Diagnostic "Teardown uncertainty: owner stop errors recorded ($($script:OwnerStopErrors.Count) errors)."
        return 'NEEDSATTENTION'
    }
    if ($script:EndpointCloseErrors.Count -gt 0) {
        Write-Diagnostic "Teardown uncertainty: endpoint close errors recorded ($($script:EndpointCloseErrors.Count) errors)."
        return 'NEEDSATTENTION'
    }

    foreach ($invRec in $script:TrackedInvocations) {
        if ($invRec -eq $null -or $invRec.PowerShell -eq $null) {
            Write-Diagnostic "Teardown uncertainty: tracked invocation record or PowerShell instance is null."
            return 'NEEDSATTENTION'
        }
        if (-not $invRec.Drained -or -not $invRec.Disposed) {
            Write-Diagnostic "Teardown uncertainty: invocation ($($invRec.Tag)) not drained or disposed (Drained: $($invRec.Drained), Disposed: $($invRec.Disposed))."
            return 'NEEDSATTENTION'
        }
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

    foreach ($epRec in $script:TrackedEndpoints) {
        if ($epRec -eq $null -or $epRec.Endpoint -eq $null) {
            Write-Diagnostic "Teardown uncertainty: tracked endpoint record or Endpoint instance is null."
            return 'NEEDSATTENTION'
        }
        if (-not $epRec.Closed -or -not $epRec.Endpoint.IsClosed) {
            Write-Diagnostic "Teardown uncertainty: endpoint ($($epRec.Tag)) not closed (IsClosed: $($epRec.Endpoint.IsClosed), State: $($epRec.Endpoint.State))."
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
            }
        }
        [System.Threading.Thread]::Sleep($intervalMs)
    }
    throw "Timed out waiting for ready handshake file after $($timeoutMs)ms: $path"
}

# --- Section 5: Synchronous Compilation of Production Code and Helper ---
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $scriptDir ".."))
$ownedChildCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\OwnedChildProcess.cs")
$ownedPipeCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\OwnedWorkerPipeEndpoint.cs")

if (-not [System.IO.File]::Exists($ownedChildCsPath)) { throw "OwnedChildProcess.cs missing at: $ownedChildCsPath" }
if (-not [System.IO.File]::Exists($ownedPipeCsPath)) { throw "OwnedWorkerPipeEndpoint.cs missing at: $ownedPipeCsPath" }

$ownedChildSource = [System.IO.File]::ReadAllText($ownedChildCsPath, [System.Text.Encoding]::UTF8)
$ownedPipeSource = [System.IO.File]::ReadAllText($ownedPipeCsPath, [System.Text.Encoding]::UTF8)

$fixtureProgramSource = @"
namespace PosDesktop.Updates.Native.Fixture
{
    using System;
    using System.Diagnostics;
    using System.IO;
    using System.IO.Pipes;
    using System.Reflection;
    using System.Text;
    using System.Threading;
    using PosDesktop.Updates.Native;

    public static class PipeTestBridge
    {
        public static OwnedWorkerPeerObservation CallConnectWithNullWorker(OwnedWorkerPipeEndpoint endpoint, int timeoutMs)
        {
            return endpoint.ConnectOwnedWorker(null, timeoutMs);
        }
    }

    public static class FixtureProgram
    {
        public static int Main(string[] args)
        {
            if (args == null || args.Length == 0)
            {
                return 1;
            }

            string mode = args[0];

            if (string.Equals(mode, "connect", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 3) return 2;
                string endpointName = args[1];
                string readyPath = args[2];

                try
                {
                    using (NamedPipeClientStream client = new NamedPipeClientStream(".", endpointName, PipeDirection.InOut, PipeOptions.None))
                    {
                        client.Connect(5000);
                        File.WriteAllText(readyPath, "READY\r\nCONNECTED\r\n", Encoding.UTF8);

                        try
                        {
                            // Block on ReadByte until server closes the pipe
                            int b = client.ReadByte();
                        }
                        catch
                        {
                        }
                    }
                    return 0;
                }
                catch (Exception ex)
                {
                    try { File.WriteAllText(readyPath, "ERROR\r\n" + ex.Message, Encoding.UTF8); } catch { }
                    return 10;
                }
            }

            if (string.Equals(mode, "idle", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 3) return 2;
                string readyPath = args[1];
                string stopGatePath = args[2];

                File.WriteAllText(readyPath, "READY\r\nIDLE\r\n", Encoding.UTF8);

                Stopwatch sw = Stopwatch.StartNew();
                while (sw.ElapsedMilliseconds < 300000)
                {
                    if (File.Exists(stopGatePath))
                    {
                        break;
                    }
                    Thread.Sleep(50);
                }
                return 0;
            }

            if (string.Equals(mode, "spawnclient", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 5) return 2;
                string parentReadyPath = args[1];
                string childReadyPath = args[2];
                string endpointName = args[3];
                string stopGatePath = args[4];

                string selfExe = Assembly.GetExecutingAssembly().Location;
                ProcessStartInfo psi = new ProcessStartInfo();
                psi.FileName = selfExe;
                psi.Arguments = "connect \"" + endpointName + "\" \"" + childReadyPath + "\"";
                psi.UseShellExecute = false;

                Process childProc = Process.Start(psi);
                if (childProc == null)
                {
                    File.WriteAllText(parentReadyPath, "ERROR\r\nChild process failed to start\r\n", Encoding.UTF8);
                    return 11;
                }

                // Wait until child signals readiness
                Stopwatch swChild = Stopwatch.StartNew();
                bool childSignaled = false;
                while (swChild.ElapsedMilliseconds < 15000)
                {
                    if (File.Exists(childReadyPath))
                    {
                        try
                        {
                            string content = File.ReadAllText(childReadyPath);
                            if (content.IndexOf("READY", StringComparison.Ordinal) != -1)
                            {
                                childSignaled = true;
                                break;
                            }
                        }
                        catch { }
                    }
                    Thread.Sleep(50);
                }

                if (!childSignaled)
                {
                    File.WriteAllText(parentReadyPath, "ERROR\r\nChild readiness timeout\r\n", Encoding.UTF8);
                    return 12;
                }

                File.WriteAllText(parentReadyPath, "READY\r\nPARENT_SPAWNED\r\n", Encoding.UTF8);

                Stopwatch sw = Stopwatch.StartNew();
                while (sw.ElapsedMilliseconds < 300000)
                {
                    if (File.Exists(stopGatePath))
                    {
                        break;
                    }
                    Thread.Sleep(50);
                }
                GC.KeepAlive(childProc);
                return 0;
            }

            if (string.Equals(mode, "quickexit", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 2) return 2;
                string readyPath = args[1];
                File.WriteAllText(readyPath, "READY\r\nQUICKEXIT\r\n", Encoding.UTF8);
                Thread.Sleep(100);
                return 0;
            }

            return 3;
        }
    }
}
"@

$fixtureExeName64 = "shell test worker pipe fixture x64.exe"
$fixtureExePath = [System.IO.Path]::Combine($labDir, $fixtureExeName64)

try {
    $script:CompilationUncertain = $true
    $origTemp = $env:TEMP; $origTmp = $env:TMP
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir

    # Unit 1: Add-Type parent in-memory types (OwnedChildProcess + OwnedWorkerPipeEndpoint + TestBridge)
    $parentCombinedSource = $ownedChildSource + "`r`n" + $ownedPipeSource + "`r`n" + @"
namespace PosDesktop.Updates.Native.Fixture
{
    public static class PipeTestBridge
    {
        public static OwnedWorkerPeerObservation CallConnectWithNullWorker(OwnedWorkerPipeEndpoint endpoint, int timeoutMs)
        {
            return endpoint.ConnectOwnedWorker(null, timeoutMs);
        }
    }
}
"@
    Add-Type -TypeDefinition $parentCombinedSource
    Write-Diagnostic "Successfully compiled in-memory types via Add-Type."

    # Unit 2: Compile console fixture executable (x64) using CodeDom CSharpCodeProvider
    $fixtureCombinedSource = $ownedChildSource + "`r`n" + $ownedPipeSource + "`r`n" + $fixtureProgramSource
    $codeProvider = [Microsoft.CSharp.CSharpCodeProvider]::new()

    $params64 = [System.CodeDom.Compiler.CompilerParameters]::new()
    $params64.GenerateExecutable = $true
    $params64.GenerateInMemory = $false
    $params64.OutputAssembly = $fixtureExePath
    $params64.CompilerOptions = "/platform:x64"
    [void]$params64.ReferencedAssemblies.Add("System.dll")
    [void]$params64.ReferencedAssemblies.Add("System.Core.dll")

    $results64 = $codeProvider.CompileAssemblyFromSource($params64, @($fixtureCombinedSource))
    if ($results64.NativeCompilerReturnValue -ne 0 -or $results64.Errors.HasErrors) {
        $errMsgs = @()
        foreach ($err in $results64.Errors) { $errMsgs += $err.ToString() }
        throw "x64 Fixture compilation failed: $($errMsgs -join '; ')"
    }
    Write-Diagnostic "Successfully compiled x64 helper fixture: $fixtureExePath"

    $script:CompilationUncertain = $false
} finally {
    $env:TEMP = $origTemp; $env:TMP = $origTmp
    if ($codeProvider -ne $null) { $codeProvider.Dispose() }
}

function Build-SparseEnvironment {
    $envDict = [System.Collections.Generic.Dictionary[string, string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    foreach ($var in @("SystemRoot", "WINDIR", "TEMP", "TMP", "SystemDrive")) {
        $val = [System.Environment]::GetEnvironmentVariable($var)
        if (-not [string]::IsNullOrEmpty($val)) { $envDict[$var] = $val }
    }
    return $envDict
}

# --- Section 6: Test Suites ---
try {
    # ------------------------------------------------------------------------
    # SUITE 1: Create & Positive OwnedRoot Peer Binding
    # ------------------------------------------------------------------------
    Write-Diagnostic "Starting Suite 1: Positive OwnedRoot Peer Binding..."
    $ep1 = Safe-CreateEndpoint -Tag "Suite1-Endpoint"
    $ep1Rec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    if ($ep1.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::Created) {
        throw "Suite 1: New endpoint must be in Created state (got $($ep1.State))."
    }
    if ([string]::IsNullOrWhiteSpace($ep1.EndpointName)) {
        throw "Suite 1: EndpointName must not be empty."
    }

    $s1Ready = [System.IO.Path]::Combine($labDir, "s1-ready.txt")
    $worker1 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("connect", $ep1.EndpointName, $s1Ready) -cwd $labDir -env (Build-SparseEnvironment) -tag "Suite1-Worker"
    $w1Rec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]

    # Wait until worker is observed LIVE
    $swLive1 = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swLive1.ElapsedMilliseconds -lt 5000) {
        if ($worker1.Observe().RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Live) { break }
        [System.Threading.Thread]::Sleep(50)
    }
    if ($worker1.Observe().RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 1: Worker did not become Live within 5000ms."
    }

    # Connect to owned worker
    $obs1 = $ep1.ConnectOwnedWorker($worker1, 15000)
    if ($obs1 -eq $null) { throw "Suite 1: ConnectOwnedWorker returned null observation." }

    if ($obs1.ProcessId -ne $worker1.ProcessId) {
        throw "Suite 1: Observation PID ($($obs1.ProcessId)) does not match worker PID ($($worker1.ProcessId))."
    }
    if ($obs1.CreationFileTimeTicks -ne $worker1.CreationFileTimeTicks) {
        throw "Suite 1: Observation ticks ($($obs1.CreationFileTimeTicks)) do not match worker ticks ($($worker1.CreationFileTimeTicks))."
    }
    if ($obs1.CanApply -ne $false) {
        throw "Suite 1: Observation CanApply must be false."
    }

    # Reflection checks: immutable fields, no property setters
    $obsType = $obs1.GetType()
    $canApplyProp = $obsType.GetProperty("CanApply")
    if ($canApplyProp.CanWrite) { throw "Suite 1: CanApply property must not have a setter." }
    $pidProp = $obsType.GetProperty("ProcessId")
    if ($pidProp.CanWrite) { throw "Suite 1: ProcessId property must not have a setter." }
    $ticksProp = $obsType.GetProperty("CreationFileTimeTicks")
    if ($ticksProp.CanWrite) { throw "Suite 1: CreationFileTimeTicks property must not have a setter." }

    if ($ep1.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::Bound) {
        throw "Suite 1: Endpoint state must be Bound (got $($ep1.State))."
    }

    # VerifyOwnedPeer repeated while worker is LIVE
    for ($i = 1; $i -le 3; $i++) {
        $recheck = $ep1.VerifyOwnedPeer()
        if ($recheck.ProcessId -ne $worker1.ProcessId) { throw "Suite 1: VerifyOwnedPeer PID mismatch on iteration $i." }
        if ($recheck.CreationFileTimeTicks -ne $worker1.CreationFileTimeTicks) { throw "Suite 1: VerifyOwnedPeer ticks mismatch on iteration $i." }
        if ($recheck.CanApply -ne $false) { throw "Suite 1: VerifyOwnedPeer CanApply must be false on iteration $i." }
    }

    # Endpoint close unblocks worker
    if (-not (Close-TrackedEndpoint $ep1Rec 5000)) {
        throw "Suite 1: Close-TrackedEndpoint failed."
    }

    # Caller separately proves root dead / job 0 and TryStopAndClose
    $swDead1 = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swDead1.ElapsedMilliseconds -lt 5000) {
        if ($worker1.Observe().RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Dead) { break }
        [System.Threading.Thread]::Sleep(50)
    }

    if (-not (Stop-TrackedOwner $w1Rec 5000)) {
        throw "Suite 1: Stop-TrackedOwner failed."
    }
    $finalObs1 = $worker1.Observe()
    if ($finalObs1.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead -or $finalObs1.ActiveProcesses -ne 0) {
        throw "Suite 1: Worker root must be Dead and ActiveProcesses must be 0 (got RootState=$($finalObs1.RootState), Active=$($finalObs1.ActiveProcesses))."
    }
    Write-Diagnostic "Suite 1: PASSED."

    # ------------------------------------------------------------------------
    # SUITE 2: Foreign Root Client Connect First
    # ------------------------------------------------------------------------
    Write-Diagnostic "Starting Suite 2: Foreign Root Client Rejection..."
    $ep2 = Safe-CreateEndpoint -Tag "Suite2-Endpoint"
    $ep2Rec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    $s2ExpectedReady = [System.IO.Path]::Combine($labDir, "s2-exp-ready.txt")
    $s2ExpectedStop = [System.IO.Path]::Combine($labDir, "s2-exp-stop.gate")
    $expectedWorker = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("idle", $s2ExpectedReady, $s2ExpectedStop) -cwd $labDir -env (Build-SparseEnvironment) -tag "Suite2-ExpectedWorker"
    $expRec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]

    $s2ForeignReady = [System.IO.Path]::Combine($labDir, "s2-foreign-ready.txt")
    $foreignWorker = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("connect", $ep2.EndpointName, $s2ForeignReady) -cwd $labDir -env (Build-SparseEnvironment) -tag "Suite2-ForeignWorker"
    $forRec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]

    [void](Wait-FileReady $s2ExpectedReady)
    [void](Wait-FileReady $s2ForeignReady)

    # Attempt to bind expectedWorker, but foreignWorker connected first
    $caughtForeign = $null
    try {
        [void]$ep2.ConnectOwnedWorker($expectedWorker, 10000)
    } catch {
        $caughtForeign = $_
    }

    if ($caughtForeign -eq $null) {
        throw "Suite 2: ConnectOwnedWorker must throw when foreign client connects."
    }
    if ($caughtForeign.Exception.Message -notmatch "does not match|Security violation|rejected") {
        throw "Suite 2: Expected security violation or mismatch exception, got: $($caughtForeign.Exception.Message)"
    }
    if ($ep2.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 2: Endpoint state must be FaultedCloseOnly after foreign client mismatch (got $($ep2.State))."
    }

    # Recheck and re-connect must be rejected
    $caughtRecheck = $null
    try { [void]$ep2.VerifyOwnedPeer() } catch { $caughtRecheck = $_ }
    if ($caughtRecheck -eq $null) { throw "Suite 2: VerifyOwnedPeer must fail on FaultedCloseOnly endpoint." }

    $caughtRebind = $null
    try { [void]$ep2.ConnectOwnedWorker($expectedWorker, 1000) } catch { $caughtRebind = $_ }
    if ($caughtRebind -eq $null) { throw "Suite 2: Re-connecting on FaultedCloseOnly endpoint must be denied." }

    [System.IO.File]::WriteAllText($s2ExpectedStop, "STOP", [System.Text.Encoding]::UTF8)
    if (-not (Stop-TrackedOwner $expRec 5000)) { throw "Suite 2: Stop expected worker failed." }
    if (-not (Stop-TrackedOwner $forRec 5000)) { throw "Suite 2: Stop foreign worker failed." }
    if (-not (Close-TrackedEndpoint $ep2Rec 5000)) { throw "Suite 2: Close endpoint failed." }
    Write-Diagnostic "Suite 2: PASSED."

    # ------------------------------------------------------------------------
    # SUITE 3: Descendant Peer Rejection (Child in same job but not root)
    # ------------------------------------------------------------------------
    Write-Diagnostic "Starting Suite 3: Descendant Peer Rejection..."
    $ep3 = Safe-CreateEndpoint -Tag "Suite3-Endpoint"
    $ep3Rec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    $s3ParentReady = [System.IO.Path]::Combine($labDir, "s3-parent-ready.txt")
    $s3ChildReady = [System.IO.Path]::Combine($labDir, "s3-child-ready.txt")
    $s3StopGate = [System.IO.Path]::Combine($labDir, "s3-stop.gate")

    $descRootWorker = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("spawnclient", $s3ParentReady, $s3ChildReady, $ep3.EndpointName, $s3StopGate) -cwd $labDir -env (Build-SparseEnvironment) -tag "Suite3-DescRootWorker"
    $descRootRec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]

    [void](Wait-FileReady $s3ParentReady)

    # Observe root worker: must be Live and ActiveProcesses >= 2 (proves child is alive in same job)
    $obsDesc = $descRootWorker.Observe()
    if ($obsDesc.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 3: Root worker is not Live."
    }
    if ($obsDesc.ActiveProcesses -lt 2) {
        throw "Suite 3: Job active processes count must be >= 2 to prove descendant existence (got $($obsDesc.ActiveProcesses))."
    }

    # Attempt to bind root worker when its child process connected to the pipe
    $caughtDesc = $null
    try {
        [void]$ep3.ConnectOwnedWorker($descRootWorker, 10000)
    } catch {
        $caughtDesc = $_
    }

    if ($caughtDesc -eq $null) {
        throw "Suite 3: ConnectOwnedWorker must reject descendant peer."
    }
    if ($caughtDesc.Exception.Message -notmatch "does not match|Security violation|rejected") {
        throw "Suite 3: Expected security violation or mismatch exception, got: $($caughtDesc.Exception.Message)"
    }
    if ($ep3.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 3: Endpoint state must be FaultedCloseOnly after descendant client rejection (got $($ep3.State))."
    }

    [System.IO.File]::WriteAllText($s3StopGate, "STOP", [System.Text.Encoding]::UTF8)
    if (-not (Stop-TrackedOwner $descRootRec 5000)) { throw "Suite 3: Stop root worker failed." }
    if (-not (Close-TrackedEndpoint $ep3Rec 5000)) { throw "Suite 3: Close endpoint failed." }
    Write-Diagnostic "Suite 3: PASSED."

    # ------------------------------------------------------------------------
    # SUITE 4: Peer After Own Root Death & Input Validations
    # ------------------------------------------------------------------------
    Write-Diagnostic "Starting Suite 4: Peer After Root Death & Input Validations..."

    # 4a: Dead worker input rejected immediately
    $s4QuickReady = [System.IO.Path]::Combine($labDir, "s4-quick-ready.txt")
    $quickWorker = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("quickexit", $s4QuickReady) -cwd $labDir -env (Build-SparseEnvironment) -tag "Suite4-QuickWorker"
    $quickRec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]

    [void](Wait-FileReady $s4QuickReady)
    $swQ = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swQ.ElapsedMilliseconds -lt 5000) {
        if ($quickWorker.Observe().RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Dead) { break }
        [System.Threading.Thread]::Sleep(50)
    }
    if ($quickWorker.Observe().RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead) {
        throw "Suite 4: Quick worker did not die within 5000ms."
    }

    $ep4a = Safe-CreateEndpoint -Tag "Suite4a-Endpoint"
    $ep4aRec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    $caughtDead = $null
    try {
        [void]$ep4a.ConnectOwnedWorker($quickWorker, 5000)
    } catch {
        $caughtDead = $_
    }
    if ($caughtDead -eq $null -or $caughtDead.Exception.Message -notmatch "not Live") {
        throw "Suite 4a: ConnectOwnedWorker must reject dead worker immediately."
    }
    [void](Stop-TrackedOwner $quickRec 5000)
    [void](Close-TrackedEndpoint $ep4aRec 5000)

    # 4b: Closed worker input rejected
    $ep4b = Safe-CreateEndpoint -Tag "Suite4b-Endpoint"
    $ep4bRec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    $caughtClosed = $null
    try {
        [void]$ep4b.ConnectOwnedWorker($quickWorker, 5000)
    } catch {
        $caughtClosed = $_
    }
    if ($caughtClosed -eq $null -or $caughtClosed.Exception.Message -notmatch "closed") {
        throw "Suite 4b: ConnectOwnedWorker must reject closed worker input."
    }
    [void](Close-TrackedEndpoint $ep4bRec 5000)

    # 4c: Invalid timeouts rejected
    $s4IdleReady = [System.IO.Path]::Combine($labDir, "s4-idle-ready.txt")
    $s4IdleStop = [System.IO.Path]::Combine($labDir, "s4-idle-stop.gate")
    $s4IdleWorker = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("idle", $s4IdleReady, $s4IdleStop) -cwd $labDir -env (Build-SparseEnvironment) -tag "Suite4-IdleWorker"
    $s4IdleRec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]
    [void](Wait-FileReady $s4IdleReady)

    $ep4c = Safe-CreateEndpoint -Tag "Suite4c-Endpoint"
    $ep4cRec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    $caughtT0 = $null
    try { [void]$ep4c.ConnectOwnedWorker($s4IdleWorker, 0) } catch { $caughtT0 = $_ }
    if ($caughtT0 -eq $null -or $caughtT0.Exception -isnot [System.ArgumentOutOfRangeException]) {
        throw "Suite 4c: Timeout 0 must throw ArgumentOutOfRangeException."
    }

    $caughtTNeg = $null
    try { [void]$ep4c.ConnectOwnedWorker($s4IdleWorker, -50) } catch { $caughtTNeg = $_ }
    if ($caughtTNeg -eq $null -or $caughtTNeg.Exception -isnot [System.ArgumentOutOfRangeException]) {
        throw "Suite 4c: Negative timeout must throw ArgumentOutOfRangeException."
    }

    $caughtTHigh = $null
    try { [void]$ep4c.ConnectOwnedWorker($s4IdleWorker, 30001) } catch { $caughtTHigh = $_ }
    if ($caughtTHigh -eq $null -or $caughtTHigh.Exception -isnot [System.ArgumentOutOfRangeException]) {
        throw "Suite 4c: Timeout > 30000 must throw ArgumentOutOfRangeException."
    }
    [void](Close-TrackedEndpoint $ep4cRec 5000)

    # 4d: Null worker rejected via C# Bridge
    $ep4d = Safe-CreateEndpoint -Tag "Suite4d-Endpoint"
    $ep4dRec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    $caughtNull = $null
    try {
        [void][PosDesktop.Updates.Native.Fixture.PipeTestBridge]::CallConnectWithNullWorker($ep4d, 5000)
    } catch {
        $caughtNull = $_
    }
    $isNullEx = $false
    $curr = if ($caughtNull -ne $null) { $caughtNull.Exception } else { $null }
    while ($curr -ne $null) {
        if ($curr -is [System.ArgumentNullException]) { $isNullEx = $true; break }
        $curr = $curr.InnerException
    }
    if (-not $isNullEx) {
        throw "Suite 4d: Passing null worker must throw ArgumentNullException."
    }
    [void](Close-TrackedEndpoint $ep4dRec 5000)

    # 4e: VerifyOwnedPeer on non-bound / closed endpoint rejected
    $ep4e = Safe-CreateEndpoint -Tag "Suite4e-Endpoint"
    $ep4eRec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    $caughtNotBound = $null
    try { [void]$ep4e.VerifyOwnedPeer() } catch { $caughtNotBound = $_ }
    if ($caughtNotBound -eq $null) { throw "Suite 4e: VerifyOwnedPeer must fail on non-bound endpoint." }

    [void](Close-TrackedEndpoint $ep4eRec 5000)

    $caughtAfterClose = $null
    try { [void]$ep4e.VerifyOwnedPeer() } catch { $caughtAfterClose = $_ }
    if ($caughtAfterClose -eq $null) { throw "Suite 4e: VerifyOwnedPeer must fail on closed endpoint." }

    [System.IO.File]::WriteAllText($s4IdleStop, "STOP", [System.Text.Encoding]::UTF8)
    [void](Stop-TrackedOwner $s4IdleRec 5000)

    # 4f: Actual positive bind -> terminate root via OWN handle (TryTerminateRootAndWait, retain root owner handle)
    #     -> ObserveRoot DEAD -> VerifyOwnedPeer reject + FaultedCloseOnly
    $ep4f = Safe-CreateEndpoint -Tag "Suite4f-Endpoint"
    $ep4fRec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    $s4fReady = [System.IO.Path]::Combine($labDir, "s4f-ready.txt")
    $worker4f = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("connect", $ep4f.EndpointName, $s4fReady) -cwd $labDir -env (Build-SparseEnvironment) -tag "Suite4f-Worker"
    $w4fRec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]

    # Wait until worker is observed LIVE
    $swLive4f = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swLive4f.ElapsedMilliseconds -lt 5000) {
        if ($worker4f.Observe().RootState -eq [PosDesktop.Updates.Native.ChildRootState]::Live) { break }
        [System.Threading.Thread]::Sleep(50)
    }
    if ($worker4f.Observe().RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 4f: Worker did not become Live within 5000ms."
    }

    # Bind worker positively
    $obs4f = $ep4f.ConnectOwnedWorker($worker4f, 15000)
    if ($obs4f -eq $null -or $ep4f.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::Bound) {
        throw "Suite 4f: Failed to positively bind worker."
    }

    # Re-verify while still Live succeeds
    $recheck4f = $ep4f.VerifyOwnedPeer()
    if ($recheck4f.ProcessId -ne $worker4f.ProcessId) {
        throw "Suite 4f: VerifyOwnedPeer failed before termination."
    }

    # Terminate root child process explicitly using its OWN process handle
    # (TryTerminateRootAndWait retains root owner handle, does not close handles or delete Job Object)
    $termErr = 0
    $termOk = $worker4f.TryTerminateRootAndWait(5000, [ref]$termErr)
    if (-not $termOk) {
        throw "Suite 4f: TryTerminateRootAndWait failed with error $termErr."
    }

    # Prove root is Dead via Observe()
    $deadObs4f = $worker4f.Observe()
    if ($deadObs4f.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead) {
        throw "Suite 4f: Root process must be Dead after TryTerminateRootAndWait (got $($deadObs4f.RootState))."
    }

    # Calling VerifyOwnedPeer on the bound endpoint now MUST reject and transition to FaultedCloseOnly
    $caughtPostDeath = $null
    try {
        [void]$ep4f.VerifyOwnedPeer()
    } catch {
        $caughtPostDeath = $_
    }

    if ($caughtPostDeath -eq $null) {
        throw "Suite 4f: VerifyOwnedPeer must reject after root death."
    }
    if ($caughtPostDeath.Exception.Message -notmatch "not Live|closed") {
        throw "Suite 4f: Expected error indicating root process not Live, got: $($caughtPostDeath.Exception.Message)"
    }
    if ($ep4f.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 4f: Endpoint state must transition to FaultedCloseOnly after root death rejection (got $($ep4f.State))."
    }

    # Clean up endpoint and worker
    [void](Close-TrackedEndpoint $ep4fRec 5000)
    [void](Stop-TrackedOwner $w4fRec 5000)
    Write-Diagnostic "Suite 4: PASSED."

    # ------------------------------------------------------------------------
    # SUITE 5: No-Client Timeout & Queued Connect Cancellation Rundown
    # ------------------------------------------------------------------------
    Write-Diagnostic "Starting Suite 5: No-Client Timeout & Cancellation Rundown..."
    $s5Ready = [System.IO.Path]::Combine($labDir, "s5-idle-ready.txt")
    $s5Stop = [System.IO.Path]::Combine($labDir, "s5-idle-stop.gate")
    $s5Worker = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("idle", $s5Ready, $s5Stop) -cwd $labDir -env (Build-SparseEnvironment) -tag "Suite5-Worker"
    $s5WorkerRec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]
    [void](Wait-FileReady $s5Ready)

    # Repeat 3 timeout cycles with fresh endpoints to exercise cancellation and races
    for ($cycle = 1; $cycle -le 3; $cycle++) {
        $ep5 = Safe-CreateEndpoint -Tag "Suite5-Ep-Cycle$cycle"
        $ep5Rec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

        $sw5 = [System.Diagnostics.Stopwatch]::StartNew()
        $caughtT = $null
        try {
            [void]$ep5.ConnectOwnedWorker($s5Worker, 300)
        } catch {
            $caughtT = $_
        }
        $elapsed5 = $sw5.ElapsedMilliseconds

        if ($caughtT -eq $null) {
            throw "Suite 5: Cycle $cycle must time out when no client connects."
        }

        $isTimeout = $false
        $curr = $caughtT.Exception
        while ($curr -ne $null) {
            if ($curr -is [System.TimeoutException]) { $isTimeout = $true; break }
            $curr = $curr.InnerException
        }
        if (-not $isTimeout) {
            throw "Suite 5: Cycle $cycle expected TimeoutException, got: $($caughtT.Exception)"
        }

        if ($ep5.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
            throw "Suite 5: Cycle $cycle endpoint state must be FaultedCloseOnly (got $($ep5.State))."
        }

        # Own root worker must remain Live and unaffected
        $s5Obs = $s5Worker.Observe()
        if ($s5Obs.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
            throw "Suite 5: Cycle $cycle own root worker must remain Live after timeout (got $($s5Obs.RootState))."
        }

        # Close endpoint and prove clean handle and buffer release
        if (-not (Close-TrackedEndpoint $ep5Rec 5000)) {
            throw "Suite 5: Cycle $cycle Close-TrackedEndpoint failed."
        }
    }

    [System.IO.File]::WriteAllText($s5Stop, "STOP", [System.Text.Encoding]::UTF8)
    if (-not (Stop-TrackedOwner $s5WorkerRec 5000)) { throw "Suite 5: Stop worker failed." }
    Write-Diagnostic "Suite 5: PASSED."

    # ------------------------------------------------------------------------
    # SUITE 6: Deterministic Concurrency, Cleanup & Negative Controls
    # ------------------------------------------------------------------------
    Write-Diagnostic "Starting Suite 6: Concurrency, Cleanup & Negative Controls..."

    # 6a: Fresh endpoint names across instances
    $ep6a = Safe-CreateEndpoint -Tag "Suite6a-Endpoint"
    $ep6aRec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]
    $ep6b = Safe-CreateEndpoint -Tag "Suite6b-Endpoint"
    $ep6bRec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    if ($ep6a.EndpointName -eq $ep6b.EndpointName) {
        throw "Suite 6: Consecutive endpoints must have distinct GUID names."
    }
    if ($ep6a.EndpointName.Length -lt 10 -or $ep6b.EndpointName.Length -lt 10) {
        throw "Suite 6: Endpoint names must be non-trivial."
    }

    [void](Close-TrackedEndpoint $ep6aRec 5000)
    [void](Close-TrackedEndpoint $ep6bRec 5000)

    # 6b: Concurrency guard / reentrancy refusal
    $ep6c = Safe-CreateEndpoint -Tag "Suite6c-Endpoint"
    $ep6cRec = $script:TrackedEndpoints[$script:TrackedEndpoints.Count - 1]

    $s6Ready = [System.IO.Path]::Combine($labDir, "s6-idle-ready.txt")
    $s6Stop = [System.IO.Path]::Combine($labDir, "s6-idle-stop.gate")
    $s6Worker = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector @("idle", $s6Ready, $s6Stop) -cwd $labDir -env (Build-SparseEnvironment) -tag "Suite6-Worker"
    $s6WorkerRec = $script:TrackedOwners[$script:TrackedOwners.Count - 1]
    [void](Wait-FileReady $s6Ready)

    # Start asynchronous connect in background runspace
    # Register invocation record IMMEDIATELY after PowerShell.Create BEFORE assertions/BeginInvoke
    $psAsync = [powershell]::Create()
    $invRec = Register-TrackedInvocation -PowerShell $psAsync -Tag "Suite6-BackgroundConnect"

    # Configure background script returning structured result
    [void]$psAsync.AddScript({
        param($ep, $w)
        $res = [PSCustomObject]@{
            Succeeded = $false
            ExceptionType = $null
            ExceptionMessage = $null
            InnerExceptionType = $null
        }
        try {
            $obs = $ep.ConnectOwnedWorker($w, 4000)
            $res.Succeeded = ($null -ne $obs)
        } catch {
            $ex = $_.Exception
            $res.ExceptionType = $ex.GetType().FullName
            $res.ExceptionMessage = $ex.Message
            if ($null -ne $ex.InnerException) {
                $res.InnerExceptionType = $ex.InnerException.GetType().FullName
            }
        }
        return $res
    }).AddArgument($ep6c).AddArgument($s6Worker)

    # Store AsyncHandle immediately when BeginInvoke returns
    $invRec.AsyncHandle = $psAsync.BeginInvoke()

    # Bounded monotonic deadline awaiting actual Connecting state (own code publishes busy+state atomically)
    $swSync = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swSync.ElapsedMilliseconds -lt 5000) {
        if ($ep6c.State -eq [PosDesktop.Updates.Native.PipeEndpointState]::Connecting) {
            break
        }
        [System.Threading.Thread]::Sleep(10)
    }
    if ($ep6c.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::Connecting) {
        throw "Suite 6: Endpoint failed to enter Connecting state within 5000ms."
    }

    # Concurrent call from main thread must be refused with busy-specific error
    $caughtConcurrent = $null
    try {
        [void]$ep6c.ConnectOwnedWorker($s6Worker, 1000)
    } catch {
        $caughtConcurrent = $_
    }

    if ($caughtConcurrent -eq $null) {
        throw "Suite 6: Concurrent ConnectOwnedWorker call must be rejected."
    }
    if ($caughtConcurrent.Exception.Message -notmatch "busy") {
        throw "Suite 6: Expected busy error on concurrent connect, got: $($caughtConcurrent.Exception.Message)"
    }

    # Drain background invocation with bounded monotonic deadline (~12000ms covers connect 4s + cleanup 5s)
    # Drain marks Drained and Disposed before further assertions
    $drainOk = Drain-TrackedInvocation $invRec 12000
    if (-not $drainOk -or -not $invRec.Drained) {
        throw "Suite 6: Background invocation failed to drain within deadline."
    }

    # Inspect background runspace error streams and structured result
    if ($psAsync.Streams.Error.Count -gt 0) {
        $streamErrMsg = ($psAsync.Streams.Error | ForEach-Object { $_.ToString() }) -join "; "
        throw "Suite 6: Background runspace had stream errors: $streamErrMsg"
    }

    if ($null -ne $invRec.Error) {
        throw "Suite 6: Background invocation threw during EndInvoke: $($invRec.Error.Message)"
    }

    $bgRes = if ($null -ne $invRec.Result -and $invRec.Result.Count -gt 0) { $invRec.Result[0] } else { $null }
    if ($null -eq $bgRes) {
        throw "Suite 6: Background invocation returned no structured result."
    }
    if ($bgRes.Succeeded) {
        throw "Suite 6: Background connect should not succeed against idle worker that never connects."
    }
    if ($bgRes.ExceptionType -notmatch "TimeoutException" -and $bgRes.ExceptionMessage -notmatch "timed out") {
        throw "Suite 6: Expected background TimeoutException, got: $($bgRes.ExceptionType): $($bgRes.ExceptionMessage)"
    }

    [System.IO.File]::WriteAllText($s6Stop, "STOP", [System.Text.Encoding]::UTF8)
    [void](Stop-TrackedOwner $s6WorkerRec 5000)
    [void](Close-TrackedEndpoint $ep6cRec 5000)

    # 6c: Protected victim tree witness check
    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "Suite 6: PASSED."

} catch {
    $script:PrimaryError = $_.Exception
    Write-Diagnostic "FATAL TEST FAILURE: $($_.Exception.Message)"
    Write-Diagnostic "Stack trace: $($_.ScriptStackTrace)"
} finally {
    Write-Diagnostic "Running teardown and resource closure..."
    Drain-AllTrackedInvocations -timeoutMs 12000
    Close-AllTrackedEndpoints
    Stop-AllTrackedOwners

    $targetSpecs = @(
        @{ Path = $labDir; MarkerName = ".snwp-lab-marker"; Nonce = $nonce },
        @{ Path = $victimDir; MarkerName = ".snwp-victim-marker"; Nonce = $nonce }
    )

    $gateResult = Invoke-SharedCleanupSafetyGate -TargetSpecs $targetSpecs
    if ($gateResult -ne 'CLEANED' -or $null -ne $script:PrimaryError) {
        Write-Diagnostic "TEARDOWN UNCERTAIN. LAB RETAINED: $labDir"
        if ($null -ne $script:PrimaryError) {
            Write-Diagnostic "Primary error: $($script:PrimaryError.Message)"
        }
        exit 1
    } else {
        Write-Diagnostic "Teardown clean. ALL 6 SUITES PASSED."
        exit 0
    }
}
