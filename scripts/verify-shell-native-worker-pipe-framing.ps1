# scripts/verify-shell-native-worker-pipe-framing.ps1
# Win32 Native Worker Pipe Bounded Framing Transport Verifier
# Verifies opaque-byte frame transport, chunked 64 KiB I/O, sequence validation,
# 64 MiB boundary transfers, timeout cancellation, EOF truncation, and busyguards.

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
$labDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snwpf-lab-$nonce")
$victimDir = [System.IO.Path]::Combine($script:CanonicalRunnerTemp, "snwpf-victim-$nonce")
$labTempDir = [System.IO.Path]::Combine($labDir, "scratch-temp")
[void][System.IO.Directory]::CreateDirectory($labDir)
[void][System.IO.Directory]::CreateDirectory($labTempDir)
[void][System.IO.Directory]::CreateDirectory($victimDir)

$labMarkerPath = [System.IO.Path]::Combine($labDir, ".snwpf-lab-marker")
[System.IO.File]::WriteAllText($labMarkerPath, $nonce, [System.Text.Encoding]::UTF8)
$victimMarkerPath = [System.IO.Path]::Combine($victimDir, ".snwpf-victim-marker")
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
    param([System.Management.Automation.PowerShell]$PowerShell, [System.IAsyncResult]$AsyncHandle, [string]$Tag = "Invocation")
    $rec = [PSObject]@{
        PowerShell = $PowerShell
        AsyncHandle = $AsyncHandle
        Tag = $Tag
        Drained = $false
        Disposed = $false
        IsCompleted = $false
        Result = $null
        Error = $null
    }
    $script:TrackedInvocations.Add($rec)
    return $rec
}

function Register-TrackedOwner {
    param([PosDesktop.Updates.Native.OwnedChildProcess]$Owner, [string]$Tag = "Owner", [string]$ExePath = "")
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
    param([PosDesktop.Updates.Native.OwnedWorkerPipeEndpoint]$Endpoint, [string]$Tag = "Endpoint")
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
        [string]$tag = "Owner"
    )
    $owner = $null
    try {
        $owner = [PosDesktop.Updates.Native.OwnedChildProcess]::Start($exePath, $ArgumentVector, $cwd, $env)
        if ($owner -eq $null) {
            throw "Start returned null owner."
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
        Write-Diagnostic "Drain-TrackedInvocation: null record or powershell instance; uncertain."
        return $false
    }
    if ($invRec.Drained) {
        if (-not $invRec.Disposed) {
            try {
                $invRec.PowerShell.Dispose()
                $invRec.Disposed = $true
            } catch {
                $msg = "Exception disposing PowerShell instance ($($invRec.Tag)): $($_.Exception.Message)"
                Write-Diagnostic $msg
                $script:InvocationDrainErrors.Add($msg)
                return $false
            }
        }
        return $true
    }

    if ($invRec.AsyncHandle -eq $null) {
        $invRec.Drained = $true
        try {
            $invRec.PowerShell.Dispose()
            $invRec.Disposed = $true
            return $true
        } catch {
            $msg = "Exception disposing PowerShell instance without async handle ($($invRec.Tag)): $($_.Exception.Message)"
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
            Write-Diagnostic "Teardown uncertainty: owner ($($ownerRec.Tag)) not closed (Closed: $($ownerRec.Closed), Owner.IsClosed: $($ownerRec.Owner.IsClosed))."
            return 'NEEDSATTENTION'
        }
    }

    foreach ($epRec in $script:TrackedEndpoints) {
        if ($epRec -eq $null -or $epRec.Endpoint -eq $null) {
            Write-Diagnostic "Teardown uncertainty: tracked endpoint record or Endpoint instance is null."
            return 'NEEDSATTENTION'
        }
        if (-not $epRec.Closed -or -not $epRec.Endpoint.IsClosed) {
            Write-Diagnostic "Teardown uncertainty: endpoint ($($epRec.Tag)) not closed (Closed: $($epRec.Closed), Endpoint.IsClosed: $($epRec.Endpoint.IsClosed))."
            return 'NEEDSATTENTION'
        }
    }

    try {
        Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    } catch {
        Write-Diagnostic "Teardown uncertainty: victim witness tree modified: $($_.Exception.Message)"
        return 'NEEDSATTENTION'
    }

    if ($DryRun) {
        return 'CLEANED'
    }

    if ($null -ne $TargetSpecs) {
        foreach ($spec in $TargetSpecs) {
            try {
                Invoke-DirectoryDeletion -Path $spec.Path -ExpectedMarkerName $spec.MarkerName -ExpectedNonce $spec.Nonce
            } catch {
                Write-Diagnostic "Teardown deletion failure for $($spec.Path): $($_.Exception.Message)"
                return 'NEEDSATTENTION'
            }
        }
    }

    return 'CLEANED'
}

function Wait-HandshakeFile {
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

# --- Section 5: Synchronous Compilation of Production Code and Helper Fixture ---
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $scriptDir ".."))
$ownedChildCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\OwnedChildProcess.cs")
$ownedPipeCsPath = [System.IO.Path]::Combine($repoRoot, "backend\Native\OwnedWorkerPipeEndpoint.cs")

if (-not [System.IO.File]::Exists($ownedChildCsPath)) { throw "OwnedChildProcess.cs missing at: $ownedChildCsPath" }
if (-not [System.IO.File]::Exists($ownedPipeCsPath)) { throw "OwnedWorkerPipeEndpoint.cs missing at: $ownedPipeCsPath" }

$ownedChildSource = [System.IO.File]::ReadAllText($ownedChildCsPath, [System.Text.Encoding]::UTF8)
$ownedPipeSource = [System.IO.File]::ReadAllText($ownedPipeCsPath, [System.Text.Encoding]::UTF8)

$fixtureProgramSource = @"
namespace PosDesktop.Updates.Native.FramingFixture
{
    using System;
    using System.Diagnostics;
    using System.IO;
    using System.IO.Pipes;
    using System.Reflection;
    using System.Text;
    using System.Threading;
    using PosDesktop.Updates.Native;

    public static class FramingFixtureProgram
    {
        private static bool ReadExact(Stream stream, byte[] buffer, int offset, int count)
        {
            int readSoFar = 0;
            while (readSoFar < count)
            {
                int r = stream.Read(buffer, offset + readSoFar, count - readSoFar);
                if (r <= 0) return false;
                readSoFar += r;
            }
            return true;
        }

        private static void WaitFile(string path, int timeoutMs)
        {
            if (string.IsNullOrEmpty(path)) return;
            Stopwatch sw = Stopwatch.StartNew();
            while (sw.ElapsedMilliseconds < timeoutMs)
            {
                if (File.Exists(path)) return;
                Thread.Sleep(20);
            }
        }

        public static int Main(string[] args)
        {
            if (args == null || args.Length < 3)
            {
                return 1;
            }

            string mode = args[0];
            string endpointName = args[1];
            string readyPath = args[2];

            try
            {
                using (NamedPipeClientStream client = new NamedPipeClientStream(".", endpointName, PipeDirection.InOut, PipeOptions.None))
                {
                    client.Connect(5000);

                    if (string.Equals(mode, "echo", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nECHO\r\n", Encoding.UTF8);

                        int count = 1;
                        if (args.Length >= 4)
                        {
                            int.TryParse(args[3], out count);
                            if (count <= 0) count = 1;
                        }

                        byte[] header = new byte[16];
                        for (int i = 0; i < count; i++)
                        {
                            if (!ReadExact(client, header, 0, 16))
                            {
                                return 10;
                            }

                            uint len = (uint)header[4] | ((uint)header[5] << 8) | ((uint)header[6] << 16) | ((uint)header[7] << 24);
                            byte[] payload = new byte[len];
                            if (!ReadExact(client, payload, 0, (int)len))
                            {
                                return 11;
                            }

                            client.Write(header, 0, 16);
                            client.Write(payload, 0, payload.Length);
                            client.Flush();
                        }

                        try { client.ReadByte(); } catch { }
                        return 0;
                    }

                    if (string.Equals(mode, "echo_large", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nECHO_LARGE\r\n", Encoding.UTF8);

                        byte[] header = new byte[16];
                        if (!ReadExact(client, header, 0, 16))
                        {
                            return 20;
                        }

                        uint totalLen = (uint)header[4] | ((uint)header[5] << 8) | ((uint)header[6] << 16) | ((uint)header[7] << 24);

                        client.Write(header, 0, 16);
                        client.Flush();

                        byte[] chunk = new byte[65536];
                        long transferred = 0;
                        while (transferred < totalLen)
                        {
                            int toRead = (int)Math.Min((long)chunk.Length, totalLen - transferred);
                            if (!ReadExact(client, chunk, 0, toRead))
                            {
                                return 21;
                            }
                            client.Write(chunk, 0, toRead);
                            transferred += toRead;
                        }
                        client.Flush();

                        try { client.ReadByte(); } catch { }
                        return 0;
                    }

                    if (string.Equals(mode, "send_bad_magic", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nBAD_MAGIC\r\n", Encoding.UTF8);

                        string magicStr = args.Length >= 4 ? args[3] : "XXXX";
                        byte[] magicBytes = Encoding.ASCII.GetBytes(magicStr.PadRight(4, 'X').Substring(0, 4));

                        byte[] header = new byte[16];
                        header[0] = magicBytes[0];
                        header[1] = magicBytes[1];
                        header[2] = magicBytes[2];
                        header[3] = magicBytes[3];
                        header[4] = 4;
                        header[8] = 1;
                        client.Write(header, 0, 16);
                        client.Write(new byte[] { 1, 2, 3, 4 }, 0, 4);
                        client.Flush();

                        try { client.ReadByte(); } catch { }
                        return 0;
                    }

                    if (string.Equals(mode, "send_bad_length", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nBAD_LENGTH\r\n", Encoding.UTF8);

                        uint badLen = 0;
                        if (args.Length >= 4)
                        {
                            uint.TryParse(args[3], out badLen);
                        }

                        byte[] header = new byte[16];
                        header[0] = (byte)'Z'; header[1] = (byte)'W'; header[2] = (byte)'P'; header[3] = (byte)'1';
                        header[4] = (byte)(badLen & 0xFF);
                        header[5] = (byte)((badLen >> 8) & 0xFF);
                        header[6] = (byte)((badLen >> 16) & 0xFF);
                        header[7] = (byte)((badLen >> 24) & 0xFF);
                        header[8] = 1;
                        client.Write(header, 0, 16);
                        client.Flush();

                        try { client.ReadByte(); } catch { }
                        return 0;
                    }

                    if (string.Equals(mode, "send_bad_seq", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nBAD_SEQ\r\n", Encoding.UTF8);

                        ulong badSeq = 2;
                        if (args.Length >= 4)
                        {
                            ulong.TryParse(args[3], out badSeq);
                        }

                        byte[] header = new byte[16];
                        header[0] = (byte)'Z'; header[1] = (byte)'W'; header[2] = (byte)'P'; header[3] = (byte)'1';
                        header[4] = 4;
                        header[8] = (byte)(badSeq & 0xFF);
                        header[9] = (byte)((badSeq >> 8) & 0xFF);
                        header[10] = (byte)((badSeq >> 16) & 0xFF);
                        header[11] = (byte)((badSeq >> 24) & 0xFF);
                        header[12] = (byte)((badSeq >> 32) & 0xFF);
                        header[13] = (byte)((badSeq >> 40) & 0xFF);
                        header[14] = (byte)((badSeq >> 48) & 0xFF);
                        header[15] = (byte)((badSeq >> 56) & 0xFF);
                        client.Write(header, 0, 16);
                        client.Write(new byte[] { 1, 2, 3, 4 }, 0, 4);
                        client.Flush();

                        try { client.ReadByte(); } catch { }
                        return 0;
                    }

                    if (string.Equals(mode, "send_repeated_seq", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nREPEATED_SEQ\r\n", Encoding.UTF8);

                        byte[] header1 = new byte[16];
                        header1[0] = (byte)'Z'; header1[1] = (byte)'W'; header1[2] = (byte)'P'; header1[3] = (byte)'1';
                        header1[4] = 4; header1[8] = 1;
                        client.Write(header1, 0, 16);
                        client.Write(new byte[] { 1, 2, 3, 4 }, 0, 4);
                        client.Flush();

                        byte[] header2 = new byte[16];
                        header2[0] = (byte)'Z'; header2[1] = (byte)'W'; header2[2] = (byte)'P'; header2[3] = (byte)'1';
                        header2[4] = 4; header2[8] = 1;
                        client.Write(header2, 0, 16);
                        client.Write(new byte[] { 5, 6, 7, 8 }, 0, 4);
                        client.Flush();

                        try { client.ReadByte(); } catch { }
                        return 0;
                    }

                    if (string.Equals(mode, "truncate_header", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nTRUNC_HEADER\r\n", Encoding.UTF8);

                        string triggerPath = args.Length >= 4 ? args[3] : null;
                        string holdPath = args.Length >= 5 ? args[4] : null;

                        if (!string.IsNullOrEmpty(triggerPath))
                        {
                            WaitFile(triggerPath, 15000);
                        }

                        byte[] halfHeader = new byte[8];
                        halfHeader[0] = (byte)'Z'; halfHeader[1] = (byte)'W'; halfHeader[2] = (byte)'P'; halfHeader[3] = (byte)'1';
                        client.Write(halfHeader, 0, 8);
                        client.Flush();
                        client.Dispose();

                        if (!string.IsNullOrEmpty(holdPath))
                        {
                            WaitFile(holdPath, 30000);
                        }
                        else
                        {
                            Thread.Sleep(15000);
                        }
                        return 0;
                    }

                    if (string.Equals(mode, "truncate_payload", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nTRUNC_PAYLOAD\r\n", Encoding.UTF8);

                        string triggerPath = args.Length >= 4 ? args[3] : null;
                        string holdPath = args.Length >= 5 ? args[4] : null;

                        if (!string.IsNullOrEmpty(triggerPath))
                        {
                            WaitFile(triggerPath, 15000);
                        }

                        byte[] header = new byte[16];
                        header[0] = (byte)'Z'; header[1] = (byte)'W'; header[2] = (byte)'P'; header[3] = (byte)'1';
                        header[4] = 0xE8; header[5] = 0x03;
                        header[8] = 1;
                        client.Write(header, 0, 16);
                        byte[] partial = new byte[200];
                        client.Write(partial, 0, 200);
                        client.Flush();
                        client.Dispose();

                        if (!string.IsNullOrEmpty(holdPath))
                        {
                            WaitFile(holdPath, 30000);
                        }
                        else
                        {
                            Thread.Sleep(15000);
                        }
                        return 0;
                    }

                    if (string.Equals(mode, "slow_stall", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nSLOW_STALL\r\n", Encoding.UTF8);

                        int sleepMs = 10000;
                        if (args.Length >= 4) int.TryParse(args[3], out sleepMs);
                        Thread.Sleep(sleepMs);
                        return 0;
                    }

                    if (string.Equals(mode, "stall_header", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nSTALL_HEADER\r\n", Encoding.UTF8);

                        byte[] half = new byte[8];
                        half[0] = (byte)'Z'; half[1] = (byte)'W'; half[2] = (byte)'P'; half[3] = (byte)'1';
                        client.Write(half, 0, 8);
                        client.Flush();

                        int sleepMs = 10000;
                        if (args.Length >= 4) int.TryParse(args[3], out sleepMs);
                        Thread.Sleep(sleepMs);
                        return 0;
                    }

                    if (string.Equals(mode, "stall_payload", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nSTALL_PAYLOAD\r\n", Encoding.UTF8);

                        byte[] header = new byte[16];
                        header[0] = (byte)'Z'; header[1] = (byte)'W'; header[2] = (byte)'P'; header[3] = (byte)'1';
                        uint pLen = 131072;
                        header[4] = (byte)(pLen & 0xFF); header[5] = (byte)((pLen >> 8) & 0xFF);
                        header[6] = (byte)((pLen >> 16) & 0xFF); header[7] = (byte)((pLen >> 24) & 0xFF);
                        header[8] = 1;
                        client.Write(header, 0, 16);
                        byte[] firstChunk = new byte[65536];
                        client.Write(firstChunk, 0, firstChunk.Length);
                        client.Flush();

                        int sleepMs = 10000;
                        if (args.Length >= 4) int.TryParse(args[3], out sleepMs);
                        Thread.Sleep(sleepMs);
                        return 0;
                    }

                    if (string.Equals(mode, "trickle_payload", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nTRICKLE_PAYLOAD\r\n", Encoding.UTF8);

                        string holdPath = args.Length >= 4 ? args[3] : null;

                        byte[] header = new byte[16];
                        header[0] = (byte)'Z'; header[1] = (byte)'W'; header[2] = (byte)'P'; header[3] = (byte)'1';
                        uint pLen = 1000;
                        header[4] = (byte)(pLen & 0xFF); header[5] = (byte)((pLen >> 8) & 0xFF);
                        header[6] = (byte)((pLen >> 16) & 0xFF); header[7] = (byte)((pLen >> 24) & 0xFF);
                        header[8] = 1;
                        client.Write(header, 0, 16);
                        client.Flush();

                        byte[] chunk = new byte[10];
                        for (int i = 0; i < 20; i++)
                        {
                            try
                            {
                                client.Write(chunk, 0, 10);
                                client.Flush();
                            }
                            catch
                            {
                                break;
                            }
                            Thread.Sleep(150);
                        }

                        if (!string.IsNullOrEmpty(holdPath))
                        {
                            WaitFile(holdPath, 30000);
                        }
                        else
                        {
                            Thread.Sleep(15000);
                        }
                        return 0;
                    }

                    if (string.Equals(mode, "die_on_read", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nDIE_ON_READ\r\n", Encoding.UTF8);

                        // Signal barrier file so test knows fixture is connected and ready
                        string barrierPath = args.Length >= 4 ? args[3] : null;
                        if (!string.IsNullOrEmpty(barrierPath))
                        {
                            File.WriteAllText(barrierPath, "CONNECTED\r\n", Encoding.UTF8);
                        }

                        Thread.Sleep(15000);
                        return 0;
                    }

                    if (string.Equals(mode, "disconnect_immediate", StringComparison.OrdinalIgnoreCase))
                    {
                        File.WriteAllText(readyPath, "READY\r\nDISC_IMM\r\n", Encoding.UTF8);

                        string holdPath = args.Length >= 4 ? args[3] : null;
                        client.Dispose();

                        if (!string.IsNullOrEmpty(holdPath))
                        {
                            WaitFile(holdPath, 30000);
                        }
                        else
                        {
                            Thread.Sleep(15000);
                        }
                        return 0;
                    }
                }
                return 0;
            }
            catch (Exception ex)
            {
                File.WriteAllText(readyPath, "ERROR\r\n" + ex.Message + "\r\n", Encoding.UTF8);
                return 99;
            }
        }
    }
}
"@

$fixtureExeName64 = "shell test worker pipe framing fixture x64.exe"
$fixtureExePath = [System.IO.Path]::Combine($labDir, $fixtureExeName64)

try {
    $script:CompilationUncertain = $true
    $origTemp = $env:TEMP; $origTmp = $env:TMP
    $env:TEMP = $labTempDir; $env:TMP = $labTempDir

    # Unit 1: Add-Type parent in-memory types (with 64 MiB test helpers)
    $helperSource = @"
namespace PosDesktop.Updates.Native.FramingFixture
{
    using System;
    using System.Security.Cryptography;

    public static class FramingTestHelpers
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

        public static string ComputeSha256(byte[] data)
        {
            using (SHA256 sha = SHA256.Create())
            {
                byte[] hash = sha.ComputeHash(data);
                return BitConverter.ToString(hash).Replace("-", "").ToLowerInvariant();
            }
        }
    }
}
"@
    $parentCombinedSource = $ownedChildSource + "`r`n" + $ownedPipeSource + "`r`n" + $helperSource
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
    Write-Diagnostic "Successfully compiled x64 framing helper fixture: $fixtureExePath"

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
    # ========================================================================
    # Suite 1: Frame Roundtrip & Echo (Small, Medium 128 KiB, Multi-Frame)
    # ========================================================================
    Write-Diagnostic "Starting Suite 1: Frame Roundtrip & Echo..."

    # 1a: Small payload roundtrip (32 bytes)
    $ep1 = Safe-CreateEndpoint -Tag "Ep1a"
    $r1a = [System.IO.Path]::Combine($labDir, "ready-1a.txt")
    $args1a = @("echo", $ep1.EndpointName, $r1a, "1")
    $w1a = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args1a -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker1a"
    $w1aRec = $script:TrackedOwners[-1]
    $ep1Rec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r1a -timeoutMs 10000)
    $peer1a = $ep1.ConnectOwnedWorker($w1a, 5000)
    if ($ep1.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::Bound) {
        throw "Suite 1a: Endpoint not in Bound state after connect."
    }

    $payload1a = [System.Text.Encoding]::UTF8.GetBytes("HELLO_WORKER_PIPE_OPAQUE_FRAME_1")
    if ($payload1a.Length -ne 32) { throw "Suite 1a: Test payload length must be 32 bytes." }

    $ep1.WriteFrame($payload1a, 5000)
    if ($ep1.NextTxSequence -ne 2) { throw "Suite 1a: Expected NextTxSequence=2, got $($ep1.NextTxSequence)" }

    $frame1a = $ep1.ReadFrame(5000)
    if ($frame1a -eq $null) { throw "Suite 1a: ReadFrame returned null." }
    if ($frame1a.Sequence -ne 1) { throw "Suite 1a: Expected frame Sequence=1, got $($frame1a.Sequence)" }
    if ($frame1a.PayloadLength -ne 32) { throw "Suite 1a: Expected PayloadLength=32, got $($frame1a.PayloadLength)" }
    if ($frame1a.CanApply -ne $false) { throw "Suite 1a: frame.CanApply must be unconditionally false." }

    $copy1a = $frame1a.CopyPayload()
    if ($copy1a.Length -ne 32) { throw "Suite 1a: CopyPayload length mismatch." }
    for ($i = 0; $i -lt 32; $i++) {
        if ($copy1a[$i] -ne $payload1a[$i]) { throw "Suite 1a: Payload byte mismatch at $i." }
    }

    # Range read tests
    $slice1a = $frame1a.ReadRange(0, 5)
    if ([System.Text.Encoding]::UTF8.GetString($slice1a) -ne "HELLO") { throw "Suite 1a: ReadRange(0, 5) mismatch." }

    $caughtRange1 = $false
    try { [void]$frame1a.ReadRange(-1, 5) } catch {
        $base = $_.Exception.GetBaseException()
        if ($base -is [ArgumentOutOfRangeException]) { $caughtRange1 = $true }
    }
    if (-not $caughtRange1) { throw "Suite 1a: ReadRange with negative offset must throw ArgumentOutOfRangeException." }

    $caughtRange2 = $false
    try { [void]$frame1a.ReadRange(0, 33) } catch {
        $base = $_.Exception.GetBaseException()
        if ($base -is [ArgumentOutOfRangeException]) { $caughtRange2 = $true }
    }
    if (-not $caughtRange2) { throw "Suite 1a: ReadRange exceeding payload must throw ArgumentOutOfRangeException." }

    $caughtRange3 = $false
    try { [void]$frame1a.ReadRange(0, 1048577) } catch {
        $base = $_.Exception.GetBaseException()
        if ($base -is [ArgumentOutOfRangeException]) { $caughtRange3 = $true }
    }
    if (-not $caughtRange3) { throw "Suite 1a: ReadRange exceeding 1 MiB cap must throw ArgumentOutOfRangeException." }

    if ($ep1.ExpectedRxSequence -ne 2) { throw "Suite 1a: Expected ExpectedRxSequence=2, got $($ep1.ExpectedRxSequence)" }
    [void](Stop-TrackedOwner $w1aRec 5000)
    [void](Close-TrackedEndpoint $ep1Rec 5000)
    Write-Diagnostic "Suite 1a: PASSED."

    # 1b: Medium payload roundtrip (128 KiB: chunked across 2x 64 KiB transfer blocks)
    $ep1b = Safe-CreateEndpoint -Tag "Ep1b"
    $r1b = [System.IO.Path]::Combine($labDir, "ready-1b.txt")
    $args1b = @("echo", $ep1b.EndpointName, $r1b, "1")
    $w1b = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args1b -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker1b"
    $w1bRec = $script:TrackedOwners[-1]
    $ep1bRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r1b -timeoutMs 10000)
    [void]$ep1b.ConnectOwnedWorker($w1b, 5000)

    $payload128k = [byte[]]::new(131072)
    for ($i = 0; $i -lt 131072; $i++) {
        $payload128k[$i] = [byte]($i -band 0xFF)
    }

    $ep1b.WriteFrame($payload128k, 10000)
    $frame1b = $ep1b.ReadFrame(10000)
    if ($frame1b.PayloadLength -ne 131072) { throw "Suite 1b: Payload length mismatch (expected 131072, got $($frame1b.PayloadLength))." }
    if ($frame1b.Sequence -ne 1) { throw "Suite 1b: Sequence mismatch." }
    $copy1b = $frame1b.CopyPayload()
    for ($i = 0; $i -lt 131072; $i += 1024) {
        if ($copy1b[$i] -ne $payload128k[$i]) { throw "Suite 1b: Chunked payload byte mismatch at $i." }
    }

    [void](Stop-TrackedOwner $w1bRec 5000)
    [void](Close-TrackedEndpoint $ep1bRec 5000)
    Write-Diagnostic "Suite 1b: PASSED."

    # 1c: Multi-frame sequence (5 frames in ping-pong succession)
    $ep1c = Safe-CreateEndpoint -Tag "Ep1c"
    $r1c = [System.IO.Path]::Combine($labDir, "ready-1c.txt")
    $args1c = @("echo", $ep1c.EndpointName, $r1c, "5")
    $w1c = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args1c -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker1c"
    $w1cRec = $script:TrackedOwners[-1]
    $ep1cRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r1c -timeoutMs 10000)
    [void]$ep1c.ConnectOwnedWorker($w1c, 5000)

    for ($seq = 1; $seq -le 5; $seq++) {
        $data = [System.Text.Encoding]::UTF8.GetBytes("FRAME_SEQ_$seq")
        $ep1c.WriteFrame($data, 5000)
        $fr = $ep1c.ReadFrame(5000)
        if ($fr.Sequence -ne [ulong]$seq) { throw "Suite 1c: Expected sequence $seq, got $($fr.Sequence)" }
        if ($ep1c.NextTxSequence -ne [ulong]($seq + 1)) { throw "Suite 1c: NextTxSequence mismatch." }
        if ($ep1c.ExpectedRxSequence -ne [ulong]($seq + 1)) { throw "Suite 1c: ExpectedRxSequence mismatch." }
    }

    if ($ep1c.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::Bound) { throw "Suite 1c: Endpoint state must remain Bound." }
    if ($ep1c.IsIoPending) { throw "Suite 1c: IsIoPending must be false." }
    if ($ep1c.IsBusy) { throw "Suite 1c: IsBusy must be false." }

    [void](Stop-TrackedOwner $w1cRec 5000)
    [void](Close-TrackedEndpoint $ep1cRec 5000)
    Write-Diagnostic "Suite 1: ALL PASSED."

    # ========================================================================
    # Suite 2: 64 MiB Boundary Transfer & Argument Limits
    # ========================================================================
    Write-Diagnostic "Starting Suite 2: 64 MiB Boundary Transfer..."

    # 2a: Full 64 MiB (67,108,864 bytes) frame roundtrip
    $ep2 = Safe-CreateEndpoint -Tag "Ep2a"
    $r2a = [System.IO.Path]::Combine($labDir, "ready-2a.txt")
    $args2a = @("echo_large", $ep2.EndpointName, $r2a)
    $w2a = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args2a -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker2a"
    $w2aRec = $script:TrackedOwners[-1]
    $ep2Rec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r2a -timeoutMs 10000)
    [void]$ep2.ConnectOwnedWorker($w2a, 5000)

    Write-Diagnostic "Generating 64 MiB test pattern via FramingTestHelpers..."
    $payload64m = [PosDesktop.Updates.Native.FramingFixture.FramingTestHelpers]::CreatePattern64MB()
    $hashOriginal = [PosDesktop.Updates.Native.FramingFixture.FramingTestHelpers]::ComputeSha256($payload64m)

    Write-Diagnostic "Writing 64 MiB frame over native pipe..."
    $sw64 = [System.Diagnostics.Stopwatch]::StartNew()
    $ep2.WriteFrame($payload64m, 30000)
    Write-Diagnostic "WriteFrame 64 MiB completed in $($sw64.ElapsedMilliseconds)ms. Reading 64 MiB echo..."

    $swEcho = [System.Diagnostics.Stopwatch]::StartNew()
    $frame64m = $ep2.ReadFrame(30000)
    Write-Diagnostic "ReadFrame 64 MiB completed in $($swEcho.ElapsedMilliseconds)ms."

    if ($frame64m.PayloadLength -ne 67108864) { throw "Suite 2a: Expected 64 MiB payload length, got $($frame64m.PayloadLength)" }
    if ($frame64m.Sequence -ne 1) { throw "Suite 2a: Expected sequence 1, got $($frame64m.Sequence)" }
    if ($frame64m.CanApply -ne $false) { throw "Suite 2a: CanApply must be false on 64 MiB frame." }

    $copy64m = $frame64m.CopyPayload()
    if (-not [PosDesktop.Updates.Native.FramingFixture.FramingTestHelpers]::VerifyPattern64MB($copy64m)) {
        throw "Suite 2a: Full 64 MiB pattern verification failed."
    }
    $hashReceived = [PosDesktop.Updates.Native.FramingFixture.FramingTestHelpers]::ComputeSha256($copy64m)
    if ($hashReceived -ne $hashOriginal) {
        throw "Suite 2a: SHA256 mismatch between sent and received 64 MiB payloads."
    }

    $headSlice = $frame64m.ReadRange(0, 4)
    if ($headSlice[0] -ne $payload64m[0] -or $headSlice[1] -ne $payload64m[1] -or $headSlice[2] -ne $payload64m[2] -or $headSlice[3] -ne $payload64m[3]) {
        throw "Suite 2a: Head slice mismatch."
    }
    $midSlice = $frame64m.ReadRange(33554432, 1)
    if ($midSlice[0] -ne $payload64m[33554432]) { throw "Suite 2a: Middle byte mismatch." }
    $tailSlice = $frame64m.ReadRange(67108860, 4)
    if ($tailSlice[0] -ne $payload64m[67108860] -or $tailSlice[1] -ne $payload64m[67108861] -or $tailSlice[2] -ne $payload64m[67108862] -or $tailSlice[3] -ne $payload64m[67108863]) {
        throw "Suite 2a: Tail slice mismatch."
    }

    # Free large managed byte arrays and collect
    $payload64m = $null
    $copy64m = $null
    $frame64m = $null
    [System.GC]::Collect()

    [void](Stop-TrackedOwner $w2aRec 5000)
    [void](Close-TrackedEndpoint $ep2Rec 5000)
    Write-Diagnostic "Suite 2a: PASSED."

    # 2b: Boundary parameter validation (null, length 0, length > 64 MiB)
    $ep2b = Safe-CreateEndpoint -Tag "Ep2b"
    $r2b = [System.IO.Path]::Combine($labDir, "ready-2b.txt")
    $args2b = @("echo", $ep2b.EndpointName, $r2b, "1")
    $w2b = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args2b -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker2b"
    $w2bRec = $script:TrackedOwners[-1]
    $ep2bRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r2b -timeoutMs 10000)
    [void]$ep2b.ConnectOwnedWorker($w2b, 5000)

    $caughtNull = $false
    try {
        $ep2b.WriteFrame($null, 5000)
    } catch {
        $base = $_.Exception.GetBaseException()
        if ($base -is [ArgumentNullException]) { $caughtNull = $true }
    }
    if (-not $caughtNull) { throw "Suite 2b: WriteFrame with null payload must throw ArgumentNullException." }

    $caughtEmpty = $false
    try {
        $ep2b.WriteFrame([byte[]]::new(0), 5000)
    } catch {
        $base = $_.Exception.GetBaseException()
        if ($base -is [ArgumentOutOfRangeException]) { $caughtEmpty = $true }
    }
    if (-not $caughtEmpty) { throw "Suite 2b: WriteFrame with 0-byte payload must throw ArgumentOutOfRangeException." }

    if ($ep2b.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::Bound) {
        throw "Suite 2b: Endpoint state must remain Bound after rejected argument validation."
    }

    [void](Stop-TrackedOwner $w2bRec 5000)
    [void](Close-TrackedEndpoint $ep2bRec 5000)
    Write-Diagnostic "Suite 2: ALL PASSED."

    # ========================================================================
    # Suite 3: Header Validation & Rejection (Magic, Zero Length, Excess Length)
    # ========================================================================
    Write-Diagnostic "Starting Suite 3: Header Validation & Rejection..."

    # 3a: Bad magic bytes in header (fixture sends XXXX instead of ZWP1)
    $ep3a = Safe-CreateEndpoint -Tag "Ep3a"
    $r3a = [System.IO.Path]::Combine($labDir, "ready-3a.txt")
    $args3a = @("send_bad_magic", $ep3a.EndpointName, $r3a)
    $w3a = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args3a -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker3a"
    $w3aRec = $script:TrackedOwners[-1]
    $ep3aRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r3a -timeoutMs 10000)
    [void]$ep3a.ConnectOwnedWorker($w3a, 5000)

    $caughtBadMagic = $null
    try {
        [void]$ep3a.ReadFrame(5000)
    } catch {
        $caughtBadMagic = $_.Exception.GetBaseException()
    }
    if ($caughtBadMagic -eq $null -or $caughtBadMagic -isnot [System.IO.InvalidDataException] -or $caughtBadMagic.Message -notmatch "magic") {
        throw "Suite 3a: Expected InvalidDataException matching 'magic', got: $(if ($caughtBadMagic) { $caughtBadMagic.ToString() } else { 'none' })"
    }
    if ($ep3a.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 3a: Endpoint must transition to FaultedCloseOnly upon bad magic header."
    }
    [void](Stop-TrackedOwner $w3aRec 5000)
    [void](Close-TrackedEndpoint $ep3aRec 5000)
    Write-Diagnostic "Suite 3a: PASSED."

    # 3a2: Bad magic bytes "ZWP2" in header
    $ep3a2 = Safe-CreateEndpoint -Tag "Ep3a2"
    $r3a2 = [System.IO.Path]::Combine($labDir, "ready-3a2.txt")
    $args3a2 = @("send_bad_magic", $ep3a2.EndpointName, $r3a2, "ZWP2")
    $w3a2 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args3a2 -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker3a2"
    $w3a2Rec = $script:TrackedOwners[-1]
    $ep3a2Rec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r3a2 -timeoutMs 10000)
    [void]$ep3a2.ConnectOwnedWorker($w3a2, 5000)

    $caughtBadMagic2 = $null
    try {
        [void]$ep3a2.ReadFrame(5000)
    } catch {
        $caughtBadMagic2 = $_.Exception.GetBaseException()
    }
    if ($caughtBadMagic2 -eq $null -or $caughtBadMagic2 -isnot [System.IO.InvalidDataException] -or $caughtBadMagic2.Message -notmatch "magic") {
        throw "Suite 3a2: Expected InvalidDataException matching 'magic', got: $(if ($caughtBadMagic2) { $caughtBadMagic2.ToString() } else { 'none' })"
    }
    if ($ep3a2.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 3a2: Endpoint must transition to FaultedCloseOnly upon bad magic ZWP2."
    }
    [void](Stop-TrackedOwner $w3a2Rec 5000)
    [void](Close-TrackedEndpoint $ep3a2Rec 5000)
    Write-Diagnostic "Suite 3a2: PASSED."

    # 3b: Bad payload length = 0 in header
    $ep3b = Safe-CreateEndpoint -Tag "Ep3b"
    $r3b = [System.IO.Path]::Combine($labDir, "ready-3b.txt")
    $args3b = @("send_bad_length", $ep3b.EndpointName, $r3b, "0")
    $w3b = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args3b -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker3b"
    $w3bRec = $script:TrackedOwners[-1]
    $ep3bRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r3b -timeoutMs 10000)
    [void]$ep3b.ConnectOwnedWorker($w3b, 5000)

    $caughtZeroLen = $null
    try {
        [void]$ep3b.ReadFrame(5000)
    } catch {
        $caughtZeroLen = $_.Exception.GetBaseException()
    }
    if ($caughtZeroLen -eq $null -or $caughtZeroLen -isnot [System.IO.InvalidDataException] -or $caughtZeroLen.Message -notmatch "payload length") {
        throw "Suite 3b: Expected InvalidDataException matching 'payload length', got: $(if ($caughtZeroLen) { $caughtZeroLen.ToString() } else { 'none' })"
    }
    if ($ep3b.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 3b: Endpoint must transition to FaultedCloseOnly upon zero payload length."
    }
    [void](Stop-TrackedOwner $w3bRec 5000)
    [void](Close-TrackedEndpoint $ep3bRec 5000)
    Write-Diagnostic "Suite 3b: PASSED."

    # 3c: Bad payload length > 64 MiB (67108865)
    $ep3c = Safe-CreateEndpoint -Tag "Ep3c"
    $r3c = [System.IO.Path]::Combine($labDir, "ready-3c.txt")
    $args3c = @("send_bad_length", $ep3c.EndpointName, $r3c, "67108865")
    $w3c = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args3c -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker3c"
    $w3cRec = $script:TrackedOwners[-1]
    $ep3cRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r3c -timeoutMs 10000)
    [void]$ep3c.ConnectOwnedWorker($w3c, 5000)

    $caughtExcessLen = $null
    try {
        [void]$ep3c.ReadFrame(5000)
    } catch {
        $caughtExcessLen = $_.Exception.GetBaseException()
    }
    if ($caughtExcessLen -eq $null -or $caughtExcessLen -isnot [System.IO.InvalidDataException] -or $caughtExcessLen.Message -notmatch "payload length") {
        throw "Suite 3c: Expected InvalidDataException matching 'payload length', got: $(if ($caughtExcessLen) { $caughtExcessLen.ToString() } else { 'none' })"
    }
    if ($ep3c.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 3c: Endpoint must transition to FaultedCloseOnly upon excessive payload length."
    }
    [void](Stop-TrackedOwner $w3cRec 5000)
    [void](Close-TrackedEndpoint $ep3cRec 5000)
    Write-Diagnostic "Suite 3c: PASSED."

    # 3c2: Bad payload length uintMaxLen (4294967295) in header
    $ep3c2 = Safe-CreateEndpoint -Tag "Ep3c2"
    $r3c2 = [System.IO.Path]::Combine($labDir, "ready-3c2.txt")
    $args3c2 = @("send_bad_length", $ep3c2.EndpointName, $r3c2, "4294967295")
    $w3c2 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args3c2 -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker3c2"
    $w3c2Rec = $script:TrackedOwners[-1]
    $ep3c2Rec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r3c2 -timeoutMs 10000)
    [void]$ep3c2.ConnectOwnedWorker($w3c2, 5000)

    $caughtMaxLen = $null
    try {
        [void]$ep3c2.ReadFrame(5000)
    } catch {
        $caughtMaxLen = $_.Exception.GetBaseException()
    }
    if ($caughtMaxLen -eq $null -or $caughtMaxLen -isnot [System.IO.InvalidDataException] -or $caughtMaxLen.Message -notmatch "payload length") {
        throw "Suite 3c2: Expected InvalidDataException matching 'payload length', got: $(if ($caughtMaxLen) { $caughtMaxLen.ToString() } else { 'none' })"
    }
    if ($ep3c2.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 3c2: Endpoint must transition to FaultedCloseOnly upon uintMaxLen payload length."
    }
    [void](Stop-TrackedOwner $w3c2Rec 5000)
    [void](Close-TrackedEndpoint $ep3c2Rec 5000)
    Write-Diagnostic "Suite 3: ALL PASSED."

    # ========================================================================
    # Suite 4: Sequence Validation & Cap (Out-of-Order, Repeated, Real 1024 Cap)
    # ========================================================================
    Write-Diagnostic "Starting Suite 4: Sequence Validation & Cap..."

    # 4a: Out-of-order sequence (expected 1, received 2)
    $ep4a = Safe-CreateEndpoint -Tag "Ep4a"
    $r4a = [System.IO.Path]::Combine($labDir, "ready-4a.txt")
    $args4a = @("send_bad_seq", $ep4a.EndpointName, $r4a, "2")
    $w4a = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args4a -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker4a"
    $w4aRec = $script:TrackedOwners[-1]
    $ep4aRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r4a -timeoutMs 10000)
    [void]$ep4a.ConnectOwnedWorker($w4a, 5000)

    $caughtSeqMismatch = $null
    try {
        [void]$ep4a.ReadFrame(5000)
    } catch {
        $caughtSeqMismatch = $_.Exception.GetBaseException()
    }
    if ($caughtSeqMismatch -eq $null -or $caughtSeqMismatch -isnot [System.IO.InvalidDataException] -or $caughtSeqMismatch.Message -notmatch "sequence") {
        throw "Suite 4a: Expected InvalidDataException matching 'sequence', got: $(if ($caughtSeqMismatch) { $caughtSeqMismatch.ToString() } else { 'none' })"
    }
    if ($ep4a.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 4a: Endpoint must transition to FaultedCloseOnly upon sequence mismatch."
    }
    [void](Stop-TrackedOwner $w4aRec 5000)
    [void](Close-TrackedEndpoint $ep4aRec 5000)
    Write-Diagnostic "Suite 4a: PASSED."

    # 4a2: Sequence 0 (expected 1, received 0)
    $ep4a2 = Safe-CreateEndpoint -Tag "Ep4a2"
    $r4a2 = [System.IO.Path]::Combine($labDir, "ready-4a2.txt")
    $args4a2 = @("send_bad_seq", $ep4a2.EndpointName, $r4a2, "0")
    $w4a2 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args4a2 -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker4a2"
    $w4a2Rec = $script:TrackedOwners[-1]
    $ep4a2Rec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r4a2 -timeoutMs 10000)
    [void]$ep4a2.ConnectOwnedWorker($w4a2, 5000)

    $caughtSeqZero = $null
    try {
        [void]$ep4a2.ReadFrame(5000)
    } catch {
        $caughtSeqZero = $_.Exception.GetBaseException()
    }
    if ($caughtSeqZero -eq $null -or $caughtSeqZero -isnot [System.IO.InvalidDataException] -or $caughtSeqZero.Message -notmatch "sequence") {
        throw "Suite 4a2: Expected InvalidDataException matching 'sequence', got: $(if ($caughtSeqZero) { $caughtSeqZero.ToString() } else { 'none' })"
    }
    if ($ep4a2.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 4a2: Endpoint must transition to FaultedCloseOnly upon sequence 0."
    }
    [void](Stop-TrackedOwner $w4a2Rec 5000)
    [void](Close-TrackedEndpoint $ep4a2Rec 5000)
    Write-Diagnostic "Suite 4a2: PASSED."

    # 4b: Repeated sequence (first 1 ok, second 1 rejected when 2 expected)
    $ep4b = Safe-CreateEndpoint -Tag "Ep4b"
    $r4b = [System.IO.Path]::Combine($labDir, "ready-4b.txt")
    $args4b = @("send_repeated_seq", $ep4b.EndpointName, $r4b)
    $w4b = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args4b -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker4b"
    $w4bRec = $script:TrackedOwners[-1]
    $ep4bRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r4b -timeoutMs 10000)
    [void]$ep4b.ConnectOwnedWorker($w4b, 5000)

    $fr4b1 = $ep4b.ReadFrame(5000)
    if ($fr4b1.Sequence -ne 1) { throw "Suite 4b: First frame sequence must be 1." }

    $caughtRepeatedSeq = $null
    try {
        [void]$ep4b.ReadFrame(5000)
    } catch {
        $caughtRepeatedSeq = $_.Exception.GetBaseException()
    }
    if ($caughtRepeatedSeq -eq $null -or $caughtRepeatedSeq -isnot [System.IO.InvalidDataException] -or $caughtRepeatedSeq.Message -notmatch "sequence") {
        throw "Suite 4b: Expected InvalidDataException on repeated sequence, got: $(if ($caughtRepeatedSeq) { $caughtRepeatedSeq.ToString() } else { 'none' })"
    }
    if ($ep4b.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 4b: Endpoint must transition to FaultedCloseOnly on repeated sequence."
    }
    [void](Stop-TrackedOwner $w4bRec 5000)
    [void](Close-TrackedEndpoint $ep4bRec 5000)
    Write-Diagnostic "Suite 4b: PASSED."

    # 4c: Real 1024-frame ping-pong sequence cap & clean session re-init
    $ep4c = Safe-CreateEndpoint -Tag "Ep4c"
    $r4c = [System.IO.Path]::Combine($labDir, "ready-4c.txt")
    $args4c = @("echo", $ep4c.EndpointName, $r4c, "1024")
    $w4c = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args4c -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker4c"
    $w4cRec = $script:TrackedOwners[-1]
    $ep4cRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r4c -timeoutMs 10000)
    [void]$ep4c.ConnectOwnedWorker($w4c, 5000)

    Write-Diagnostic "Executing 1024 real ping-pong frame roundtrips..."
    $sw1024 = [System.Diagnostics.Stopwatch]::StartNew()
    $pingData = [System.Text.Encoding]::UTF8.GetBytes("PING")
    for ($i = 1; $i -le 1024; $i++) {
        $ep4c.WriteFrame($pingData, 5000)
        $fr = $ep4c.ReadFrame(5000)
        if ($fr.Sequence -ne [ulong]$i) {
            throw "Suite 4c: Sequence mismatch at roundtrip ${i}: expected $i, got $($fr.Sequence)"
        }
    }
    $sw1024.Stop()
    Write-Diagnostic "Completed 1024 roundtrips in $($sw1024.ElapsedMilliseconds)ms. Counters: NextTxSequence=$($ep4c.NextTxSequence), ExpectedRxSequence=$($ep4c.ExpectedRxSequence)."

    if ($ep4c.NextTxSequence -ne 1025) {
        throw "Suite 4c: Expected NextTxSequence=1025 after 1024 sends, got $($ep4c.NextTxSequence)"
    }
    if ($ep4c.ExpectedRxSequence -ne 1025) {
        throw "Suite 4c: Expected ExpectedRxSequence=1025 after 1024 receives, got $($ep4c.ExpectedRxSequence)"
    }

    # Attempt 1025th write: must throw InvalidOperationException and fault endpoint
    $caughtTxCap = $null
    try {
        $ep4c.WriteFrame($pingData, 5000)
    } catch {
        $caughtTxCap = $_.Exception.GetBaseException()
    }
    if ($caughtTxCap -eq $null -or $caughtTxCap -isnot [System.InvalidOperationException] -or $caughtTxCap.Message -notmatch "1024") {
        throw "Suite 4c: Expected InvalidOperationException on 1025th write, got: $(if ($caughtTxCap) { $caughtTxCap.ToString() } else { 'none' })"
    }
    if ($ep4c.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 4c: Endpoint must transition to FaultedCloseOnly upon TX cap exceed."
    }

    [void](Stop-TrackedOwner $w4cRec 5000)
    [void](Close-TrackedEndpoint $ep4cRec 5000)

    # Verify fresh session starts clean at sequence 1
    $ep4c2 = Safe-CreateEndpoint -Tag "Ep4c2"
    $r4c2 = [System.IO.Path]::Combine($labDir, "ready-4c2.txt")
    $args4c2 = @("echo", $ep4c2.EndpointName, $r4c2, "1")
    $w4c2 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args4c2 -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker4c2"
    $w4c2Rec = $script:TrackedOwners[-1]
    $ep4c2Rec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r4c2 -timeoutMs 10000)
    [void]$ep4c2.ConnectOwnedWorker($w4c2, 5000)

    if ($ep4c2.NextTxSequence -ne 1) { throw "Suite 4c: Fresh session NextTxSequence must be 1, got $($ep4c2.NextTxSequence)" }
    if ($ep4c2.ExpectedRxSequence -ne 1) { throw "Suite 4c: Fresh session ExpectedRxSequence must be 1, got $($ep4c2.ExpectedRxSequence)" }

    $ep4c2.WriteFrame($pingData, 5000)
    $frFresh = $ep4c2.ReadFrame(5000)
    if ($frFresh.Sequence -ne 1) { throw "Suite 4c: Fresh session frame sequence must be 1, got $($frFresh.Sequence)" }
    if ($ep4c2.NextTxSequence -ne 2) { throw "Suite 4c: Fresh session NextTxSequence must be 2, got $($ep4c2.NextTxSequence)" }
    if ($ep4c2.ExpectedRxSequence -ne 2) { throw "Suite 4c: Fresh session ExpectedRxSequence must be 2, got $($ep4c2.ExpectedRxSequence)" }

    [void](Stop-TrackedOwner $w4c2Rec 5000)
    [void](Close-TrackedEndpoint $ep4c2Rec 5000)
    Write-Diagnostic "Suite 4: ALL PASSED."

    # ========================================================================
    # Suite 5: Truncation & EOF Detection (Mid-Header, Mid-Payload, Write Disconnected)
    # ========================================================================
    Write-Diagnostic "Starting Suite 5: Truncation & EOF Detection..."

    # 5a: Disconnect mid-header with root worker process remaining LIVE
    $ep5a = Safe-CreateEndpoint -Tag "Ep5a"
    $r5a = [System.IO.Path]::Combine($labDir, "ready-5a.txt")
    $trigger5a = [System.IO.Path]::Combine($labDir, "trigger-5a.txt")
    $hold5a = [System.IO.Path]::Combine($labDir, "hold-5a.txt")
    $args5a = @("truncate_header", $ep5a.EndpointName, $r5a, $trigger5a, $hold5a)
    $w5a = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args5a -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker5a"
    $w5aRec = $script:TrackedOwners[-1]
    $ep5aRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r5a -timeoutMs 10000)
    [void]$ep5a.ConnectOwnedWorker($w5a, 5000)

    # Launch background ReadFrame to establish pending overlapped read
    $psAsync5a = [System.Management.Automation.PowerShell]::Create()
    $invRec5a = Register-TrackedInvocation -PowerShell $psAsync5a -AsyncHandle $null -Tag "Suite5a-AsyncRead"
    [void]$psAsync5a.AddScript({
        param($endpoint)
        try {
            $fr = $endpoint.ReadFrame(5000)
            return [PSObject]@{ Succeeded = $true; Frame = $fr }
        } catch {
            return [PSObject]@{ Succeeded = $false; BaseException = $_.Exception.GetBaseException(); Error = $_.Exception.GetBaseException().Message }
        }
    }).AddArgument($ep5a)
    $invRec5a.AsyncHandle = $psAsync5a.BeginInvoke()

    # Poll until endpoint establishes pending I/O barrier
    $swWaitIo5a = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swWaitIo5a.ElapsedMilliseconds -lt 5000) {
        if ($ep5a.IsKnownQueued -and $ep5a.IsIoPending) { break }
        [System.Threading.Thread]::Sleep(10)
    }
    if (-not $ep5a.IsKnownQueued -or -not $ep5a.IsIoPending) {
        throw "Suite 5a: Timed out waiting for endpoint to establish pending read barrier (IsKnownQueued=$($ep5a.IsKnownQueued), IsIoPending=$($ep5a.IsIoPending))."
    }

    # Explicitly Observe Root LIVE BEFORE server read failure
    $obs5aBefore = $w5a.Observe()
    if ($obs5aBefore.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 5a: Worker root must be Live before triggering pipe disconnect."
    }

    # Signal fixture to send partial 8 bytes and dispose client pipe
    [System.IO.File]::WriteAllText($trigger5a, "TRIGGER`r`n", [System.Text.Encoding]::UTF8)

    # Drain background invocation
    $drainOk5a = Drain-TrackedInvocation $invRec5a 12000
    if (-not $drainOk5a -or -not $invRec5a.Drained) {
        throw "Suite 5a: Background ReadFrame failed to drain within deadline."
    }

    # Explicitly Observe Root LIVE AFTER server read failure
    $obs5aAfter = $w5a.Observe()
    if ($obs5aAfter.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 5a: Worker root must remain Live after server read failure (RootState=$($obs5aAfter.RootState))."
    }

    # Collect bounded result requiring EOF/pipe failure, NOT process death
    if ($invRec5a.Result -eq $null -or $invRec5a.Result.Succeeded -eq $true) {
        throw "Suite 5a: ReadFrame must throw when peer closes pipe mid-header."
    }
    $baseEx5a = $invRec5a.Result.BaseException
    if ($baseEx5a -isnot [System.IO.EndOfStreamException]) {
        throw "Suite 5a: Expected EndOfStreamException for mid-header pipe closure while root is Live, got: $(if ($baseEx5a) { $baseEx5a.ToString() } else { 'none' })"
    }
    if ($ep5a.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 5a: Endpoint must transition to FaultedCloseOnly upon mid-header disconnect."
    }
    if ($ep5a.IsIoPending) {
        throw "Suite 5a: IsIoPending must be false after rundown."
    }

    # Release fixture hold gate
    [System.IO.File]::WriteAllText($hold5a, "RELEASE`r`n", [System.Text.Encoding]::UTF8)
    [void](Stop-TrackedOwner $w5aRec 5000)
    $cErr5a = 0
    $cOk5a = $ep5a.TryClose(5000, [ref]$cErr5a)
    if (-not $cOk5a -or $cErr5a -ne 0) {
        throw "Suite 5a: TryClose failed: error $cErr5a"
    }
    $ep5aRec.Closed = $true
    Write-Diagnostic "Suite 5a: PASSED."

    # 5b: Disconnect mid-payload with root worker process remaining LIVE
    $ep5b = Safe-CreateEndpoint -Tag "Ep5b"
    $r5b = [System.IO.Path]::Combine($labDir, "ready-5b.txt")
    $trigger5b = [System.IO.Path]::Combine($labDir, "trigger-5b.txt")
    $hold5b = [System.IO.Path]::Combine($labDir, "hold-5b.txt")
    $args5b = @("truncate_payload", $ep5b.EndpointName, $r5b, $trigger5b, $hold5b)
    $w5b = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args5b -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker5b"
    $w5bRec = $script:TrackedOwners[-1]
    $ep5bRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r5b -timeoutMs 10000)
    [void]$ep5b.ConnectOwnedWorker($w5b, 5000)

    # Launch background ReadFrame to establish pending overlapped read
    $psAsync5b = [System.Management.Automation.PowerShell]::Create()
    $invRec5b = Register-TrackedInvocation -PowerShell $psAsync5b -AsyncHandle $null -Tag "Suite5b-AsyncRead"
    [void]$psAsync5b.AddScript({
        param($endpoint)
        try {
            $fr = $endpoint.ReadFrame(5000)
            return [PSObject]@{ Succeeded = $true; Frame = $fr }
        } catch {
            return [PSObject]@{ Succeeded = $false; BaseException = $_.Exception.GetBaseException(); Error = $_.Exception.GetBaseException().Message }
        }
    }).AddArgument($ep5b)
    $invRec5b.AsyncHandle = $psAsync5b.BeginInvoke()

    # Poll until endpoint establishes pending I/O barrier
    $swWaitIo5b = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swWaitIo5b.ElapsedMilliseconds -lt 5000) {
        if ($ep5b.IsKnownQueued -and $ep5b.IsIoPending) { break }
        [System.Threading.Thread]::Sleep(10)
    }
    if (-not $ep5b.IsKnownQueued -or -not $ep5b.IsIoPending) {
        throw "Suite 5b: Timed out waiting for endpoint to establish pending read barrier (IsKnownQueued=$($ep5b.IsKnownQueued), IsIoPending=$($ep5b.IsIoPending))."
    }

    # Explicitly Observe Root LIVE BEFORE server read failure
    $obs5bBefore = $w5b.Observe()
    if ($obs5bBefore.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 5b: Worker root must be Live before triggering pipe disconnect."
    }

    # Signal fixture to send header + 200 payload bytes and dispose client pipe
    [System.IO.File]::WriteAllText($trigger5b, "TRIGGER`r`n", [System.Text.Encoding]::UTF8)

    # Drain background invocation
    $drainOk5b = Drain-TrackedInvocation $invRec5b 12000
    if (-not $drainOk5b -or -not $invRec5b.Drained) {
        throw "Suite 5b: Background ReadFrame failed to drain within deadline."
    }

    # Explicitly Observe Root LIVE AFTER server read failure
    $obs5bAfter = $w5b.Observe()
    if ($obs5bAfter.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 5b: Worker root must remain Live after server read failure (RootState=$($obs5bAfter.RootState))."
    }

    # Collect bounded result requiring EOF/pipe failure, NOT process death
    if ($invRec5b.Result -eq $null -or $invRec5b.Result.Succeeded -eq $true) {
        throw "Suite 5b: ReadFrame must throw when peer closes pipe mid-payload."
    }
    $baseEx5b = $invRec5b.Result.BaseException
    if ($baseEx5b -isnot [System.IO.EndOfStreamException]) {
        throw "Suite 5b: Expected EndOfStreamException for mid-payload pipe closure while root is Live, got: $(if ($baseEx5b) { $baseEx5b.ToString() } else { 'none' })"
    }
    if ($ep5b.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 5b: Endpoint must transition to FaultedCloseOnly upon mid-payload disconnect."
    }
    if ($ep5b.IsIoPending) {
        throw "Suite 5b: IsIoPending must be false after rundown."
    }

    # Release fixture hold gate
    [System.IO.File]::WriteAllText($hold5b, "RELEASE`r`n", [System.Text.Encoding]::UTF8)
    [void](Stop-TrackedOwner $w5bRec 5000)
    $cErr5b = 0
    $cOk5b = $ep5b.TryClose(5000, [ref]$cErr5b)
    if (-not $cOk5b -or $cErr5b -ne 0) {
        throw "Suite 5b: TryClose failed: error $cErr5b"
    }
    $ep5bRec.Closed = $true
    Write-Diagnostic "Suite 5b: PASSED."

    # 5c: Write to disconnected client with root worker process remaining LIVE
    $ep5c = Safe-CreateEndpoint -Tag "Ep5c"
    $r5c = [System.IO.Path]::Combine($labDir, "ready-5c.txt")
    $hold5c = [System.IO.Path]::Combine($labDir, "hold-5c.txt")
    $args5c = @("disconnect_immediate", $ep5c.EndpointName, $r5c, $hold5c)
    $w5c = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args5c -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker5c"
    $w5cRec = $script:TrackedOwners[-1]
    $ep5cRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r5c -timeoutMs 10000)
    [void]$ep5c.ConnectOwnedWorker($w5c, 5000)

    [System.Threading.Thread]::Sleep(100) # allow client to dispose pipe and enter hold loop

    # Explicitly Observe Root LIVE BEFORE server write failure
    $obs5cBefore = $w5c.Observe()
    if ($obs5cBefore.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 5c: Worker root must be Live before write-after-disconnect attempt."
    }

    $caughtWriteDisc = $null
    try {
        $ep5c.WriteFrame([System.Text.Encoding]::UTF8.GetBytes("DATA_AFTER_DISCONNECT"), 5000)
    } catch {
        $caughtWriteDisc = $_.Exception.GetBaseException()
    }

    # Explicitly Observe Root LIVE AFTER server write failure
    $obs5cAfter = $w5c.Observe()
    if ($obs5cAfter.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 5c: Worker root must remain Live after server write failure (RootState=$($obs5cAfter.RootState))."
    }

    if ($caughtWriteDisc -eq $null) {
        throw "Suite 5c: WriteFrame must throw when writing to disconnected client."
    }
    if ($caughtWriteDisc -isnot [System.IO.IOException] -and $caughtWriteDisc -isnot [System.ComponentModel.Win32Exception]) {
        throw "Suite 5c: Expected IOException or Win32Exception on broken pipe, got: $($caughtWriteDisc.ToString())"
    }
    if ($ep5c.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 5c: Endpoint must transition to FaultedCloseOnly when write fails on broken pipe."
    }

    # Release fixture hold gate
    [System.IO.File]::WriteAllText($hold5c, "RELEASE`r`n", [System.Text.Encoding]::UTF8)
    [void](Stop-TrackedOwner $w5cRec 5000)
    $cErr5c = 0
    $cOk5c = $ep5c.TryClose(5000, [ref]$cErr5c)
    if (-not $cOk5c -or $cErr5c -ne 0) {
        throw "Suite 5c: TryClose failed: error $cErr5c"
    }
    $ep5cRec.Closed = $true
    Write-Diagnostic "Suite 5: ALL PASSED."

    # ========================================================================
    # Suite 6: Timeout Cancellation & Rundown (Read Timeout, Deadline Monotonicity)
    # ========================================================================
    Write-Diagnostic "Starting Suite 6: Timeout Cancellation & Rundown..."

    # 6a: ReadFrame timeout when worker does not send data
    $ep6a = Safe-CreateEndpoint -Tag "Ep6a"
    $r6a = [System.IO.Path]::Combine($labDir, "ready-6a.txt")
    $args6a = @("slow_stall", $ep6a.EndpointName, $r6a, "10000")
    $w6a = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args6a -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker6a"
    $w6aRec = $script:TrackedOwners[-1]
    $ep6aRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r6a -timeoutMs 10000)
    [void]$ep6a.ConnectOwnedWorker($w6a, 5000)

    $swTimeout = [System.Diagnostics.Stopwatch]::StartNew()
    $caughtTimeout = $null
    try {
        [void]$ep6a.ReadFrame(1000)
    } catch {
        $caughtTimeout = $_.Exception.GetBaseException()
    }
    $swTimeout.Stop()

    if ($caughtTimeout -eq $null -or $caughtTimeout.Message -notmatch "timed out") {
        throw "Suite 6a: Expected TimeoutException, got: $(if ($caughtTimeout) { $caughtTimeout.ToString() } else { 'none' })"
    }
    if ($swTimeout.ElapsedMilliseconds -lt 900 -or $swTimeout.ElapsedMilliseconds -gt 3500) {
        throw "Suite 6a: Timeout took unexpected duration: $($swTimeout.ElapsedMilliseconds)ms (expected ~1000ms)."
    }
    if ($ep6a.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 6a: Endpoint must transition to FaultedCloseOnly upon timeout."
    }
    if ($ep6a.IsIoPending) {
        throw "Suite 6a: IsIoPending must be false (CancelIoEx must have drained the pending read)."
    }

    [void](Stop-TrackedOwner $w6aRec 5000)
    $cErr = 0
    $cOk = $ep6a.TryClose(5000, [ref]$cErr)
    if (-not $cOk -or $cErr -ne 0) {
        throw "Suite 6a: TryClose failed after timeout rundown: error $cErr"
    }
    $ep6aRec.Closed = $true
    Write-Diagnostic "Suite 6a: PASSED."

    # 6b: Timeout parameter bounds validation (1..30000 ms)
    $ep6b = Safe-CreateEndpoint -Tag "Ep6b"
    $r6b = [System.IO.Path]::Combine($labDir, "ready-6b.txt")
    $args6b = @("echo", $ep6b.EndpointName, $r6b, "1")
    $w6b = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args6b -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker6b"
    $w6bRec = $script:TrackedOwners[-1]
    $ep6bRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r6b -timeoutMs 10000)
    [void]$ep6b.ConnectOwnedWorker($w6b, 5000)

    $caughtT1 = $false
    try { [void]$ep6b.ReadFrame(0) } catch {
        if ($_.Exception.GetBaseException() -is [ArgumentOutOfRangeException]) { $caughtT1 = $true }
    }
    if (-not $caughtT1) { throw "Suite 6b: ReadFrame(0) must throw ArgumentOutOfRangeException." }

    $caughtT2 = $false
    try { [void]$ep6b.ReadFrame(30001) } catch {
        if ($_.Exception.GetBaseException() -is [ArgumentOutOfRangeException]) { $caughtT2 = $true }
    }
    if (-not $caughtT2) { throw "Suite 6b: ReadFrame(30001) must throw ArgumentOutOfRangeException." }

    $caughtT3 = $false
    try { $ep6b.WriteFrame([System.Text.Encoding]::UTF8.GetBytes("X"), 0) } catch {
        if ($_.Exception.GetBaseException() -is [ArgumentOutOfRangeException]) { $caughtT3 = $true }
    }
    if (-not $caughtT3) { throw "Suite 6b: WriteFrame with timeout 0 must throw ArgumentOutOfRangeException." }

    $caughtT4 = $false
    try { $ep6b.WriteFrame([System.Text.Encoding]::UTF8.GetBytes("X"), 30001) } catch {
        if ($_.Exception.GetBaseException() -is [ArgumentOutOfRangeException]) { $caughtT4 = $true }
    }
    if (-not $caughtT4) { throw "Suite 6b: WriteFrame with timeout 30001 must throw ArgumentOutOfRangeException." }

    if ($ep6b.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::Bound) {
        throw "Suite 6b: Endpoint state must remain Bound after timeout parameter rejections."
    }

    [void](Stop-TrackedOwner $w6bRec 5000)
    [void](Close-TrackedEndpoint $ep6bRec 5000)
    Write-Diagnostic "Suite 6b: PASSED."

    # 6c: ReadFrame timeout during stalled header chunk (verifies CancelIoEx rundown on mid-header stall)
    $ep6c = Safe-CreateEndpoint -Tag "Ep6c"
    $r6c = [System.IO.Path]::Combine($labDir, "ready-6c.txt")
    $args6c = @("stall_header", $ep6c.EndpointName, $r6c, "10000")
    $w6c = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args6c -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker6c"
    $w6cRec = $script:TrackedOwners[-1]
    $ep6cRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r6c -timeoutMs 10000)
    [void]$ep6c.ConnectOwnedWorker($w6c, 5000)

    $swTimeout6c = [System.Diagnostics.Stopwatch]::StartNew()
    $caughtTimeout6c = $null
    try {
        [void]$ep6c.ReadFrame(1000)
    } catch {
        $caughtTimeout6c = $_.Exception.GetBaseException()
    }
    $swTimeout6c.Stop()

    if ($caughtTimeout6c -eq $null -or $caughtTimeout6c.Message -notmatch "timed out") {
        throw "Suite 6c: Expected TimeoutException on stalled header, got: $(if ($caughtTimeout6c) { $caughtTimeout6c.ToString() } else { 'none' })"
    }
    if ($swTimeout6c.ElapsedMilliseconds -lt 900 -or $swTimeout6c.ElapsedMilliseconds -gt 3500) {
        throw "Suite 6c: Timeout took unexpected duration: $($swTimeout6c.ElapsedMilliseconds)ms (expected ~1000ms)."
    }
    if ($ep6c.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 6c: Endpoint must transition to FaultedCloseOnly upon header stall timeout."
    }
    if ($ep6c.IsIoPending) {
        throw "Suite 6c: IsIoPending must be false (CancelIoEx must have drained the pending header read)."
    }

    [void](Stop-TrackedOwner $w6cRec 5000)
    $cErr6c = 0
    $cOk6c = $ep6c.TryClose(5000, [ref]$cErr6c)
    if (-not $cOk6c -or $cErr6c -ne 0) {
        throw "Suite 6c: TryClose failed after header timeout rundown: error $cErr6c"
    }
    $ep6cRec.Closed = $true
    Write-Diagnostic "Suite 6c: PASSED."

    # 6d: ReadFrame timeout during stalled payload chunk (verifies monotonic stopwatch deadline across chunks)
    $ep6d = Safe-CreateEndpoint -Tag "Ep6d"
    $r6d = [System.IO.Path]::Combine($labDir, "ready-6d.txt")
    $args6d = @("stall_payload", $ep6d.EndpointName, $r6d, "10000")
    $w6d = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args6d -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker6d"
    $w6dRec = $script:TrackedOwners[-1]
    $ep6dRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r6d -timeoutMs 10000)
    [void]$ep6d.ConnectOwnedWorker($w6d, 5000)

    $swTimeout6d = [System.Diagnostics.Stopwatch]::StartNew()
    $caughtTimeout6d = $null
    try {
        [void]$ep6d.ReadFrame(1500)
    } catch {
        $caughtTimeout6d = $_.Exception.GetBaseException()
    }
    $swTimeout6d.Stop()

    if ($caughtTimeout6d -eq $null -or $caughtTimeout6d.Message -notmatch "timed out") {
        throw "Suite 6d: Expected TimeoutException on stalled payload, got: $(if ($caughtTimeout6d) { $caughtTimeout6d.ToString() } else { 'none' })"
    }
    if ($swTimeout6d.ElapsedMilliseconds -lt 1350 -or $swTimeout6d.ElapsedMilliseconds -gt 4000) {
        throw "Suite 6d: Timeout took unexpected duration: $($swTimeout6d.ElapsedMilliseconds)ms (expected ~1500ms)."
    }
    if ($ep6d.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 6d: Endpoint must transition to FaultedCloseOnly upon payload stall timeout."
    }
    if ($ep6d.IsIoPending) {
        throw "Suite 6d: IsIoPending must be false (CancelIoEx must have drained the pending payload read)."
    }

    [void](Stop-TrackedOwner $w6dRec 5000)
    $cErr6d = 0
    $cOk6d = $ep6d.TryClose(5000, [ref]$cErr6d)
    if (-not $cOk6d -or $cErr6d -ne 0) {
        throw "Suite 6d: TryClose failed after payload timeout rundown: error $cErr6d"
    }
    $ep6dRec.Closed = $true
    Write-Diagnostic "Suite 6d: PASSED."

    # 6e: Progressing deadline monotonicity across chunks (10 bytes every 150ms across 800ms frame budget)
    $ep6e = Safe-CreateEndpoint -Tag "Ep6e"
    $r6e = [System.IO.Path]::Combine($labDir, "ready-6e.txt")
    $hold6e = [System.IO.Path]::Combine($labDir, "hold-6e.txt")
    $args6e = @("trickle_payload", $ep6e.EndpointName, $r6e, $hold6e)
    $w6e = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args6e -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker6e"
    $w6eRec = $script:TrackedOwners[-1]
    $ep6eRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r6e -timeoutMs 10000)
    [void]$ep6e.ConnectOwnedWorker($w6e, 5000)

    $swTimeout6e = [System.Diagnostics.Stopwatch]::StartNew()
    $caughtTimeout6e = $null
    try {
        [void]$ep6e.ReadFrame(800)
    } catch {
        $caughtTimeout6e = $_.Exception.GetBaseException()
    }
    $swTimeout6e.Stop()

    if ($caughtTimeout6e -eq $null -or $caughtTimeout6e.Message -notmatch "timed out") {
        throw "Suite 6e: Expected TimeoutException on progressing deadline exceed, got: $(if ($caughtTimeout6e) { $caughtTimeout6e.ToString() } else { 'none' })"
    }
    # Verify timeout occurred near the single 800ms budget (with 5000ms cleanup rundown allowance)
    if ($swTimeout6e.ElapsedMilliseconds -lt 750 -or $swTimeout6e.ElapsedMilliseconds -gt 3500) {
        throw "Suite 6e: Progressing deadline timeout took unexpected duration: $($swTimeout6e.ElapsedMilliseconds)ms (expected ~800ms)."
    }

    # Root worker MUST REMAIN LIVE after the catch
    $obs6e = $w6e.Observe()
    if ($obs6e.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Live) {
        throw "Suite 6e: Root worker must remain Live after timeout catch (RootState=$($obs6e.RootState))."
    }

    if ($ep6e.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 6e: Endpoint must transition to FaultedCloseOnly upon progressing deadline timeout."
    }
    if ($ep6e.NextTxSequence -ne 1) {
        throw "Suite 6e: NextTxSequence must remain 1 (no frame advance)."
    }
    if ($ep6e.ExpectedRxSequence -ne 1) {
        throw "Suite 6e: ExpectedRxSequence must remain 1 (no frame advance)."
    }
    if ($ep6e.IsIoPending) {
        throw "Suite 6e: IsIoPending must be false (CancelIoEx must have drained the pending read)."
    }

    [System.IO.File]::WriteAllText($hold6e, "RELEASE`r`n", [System.Text.Encoding]::UTF8)
    [void](Stop-TrackedOwner $w6eRec 5000)
    $cErr6e = 0
    $cOk6e = $ep6e.TryClose(5000, [ref]$cErr6e)
    if (-not $cOk6e -or $cErr6e -ne 0) {
        throw "Suite 6e: TryClose failed after progressing timeout rundown: error $cErr6e"
    }
    $ep6eRec.Closed = $true
    Write-Diagnostic "Suite 6: ALL PASSED."

    # ========================================================================
    # Suite 7: Worker Death During Pending I/O (Read Barrier & Write Backpressure)
    # ========================================================================
    Write-Diagnostic "Starting Suite 7: Worker Death During Pending I/O..."

    # 7a: Worker death during pending READ barrier
    $ep7a = Safe-CreateEndpoint -Tag "Ep7a"
    $r7a = [System.IO.Path]::Combine($labDir, "ready-7a.txt")
    $barrier7a = [System.IO.Path]::Combine($labDir, "barrier-7a.txt")
    $args7a = @("die_on_read", $ep7a.EndpointName, $r7a, $barrier7a)
    $w7a = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args7a -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker7a"
    $w7aRec = $script:TrackedOwners[-1]
    $ep7aRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r7a -timeoutMs 10000)
    [void]$ep7a.ConnectOwnedWorker($w7a, 5000)
    [void](Wait-HandshakeFile -path $barrier7a -timeoutMs 10000)

    # Launch background ReadFrame to establish pending overlapped I/O
    $psAsync7a = [System.Management.Automation.PowerShell]::Create()
    $invRec7a = Register-TrackedInvocation -PowerShell $psAsync7a -AsyncHandle $null -Tag "Suite7a-AsyncRead"
    [void]$psAsync7a.AddScript({
        param($endpoint)
        try {
            [void]$endpoint.ReadFrame(10000)
            return [PSObject]@{ Succeeded = $true }
        } catch {
            return [PSObject]@{ Succeeded = $false; Error = $_.Exception.GetBaseException().Message }
        }
    }).AddArgument($ep7a)
    $invRec7a.AsyncHandle = $psAsync7a.BeginInvoke()

    # Poll until endpoint establishes pending I/O barrier
    $swWaitIo7a = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swWaitIo7a.ElapsedMilliseconds -lt 5000) {
        if ($ep7a.IsKnownQueued -and $ep7a.IsIoPending) { break }
        [System.Threading.Thread]::Sleep(10)
    }
    if (-not $ep7a.IsKnownQueued -or -not $ep7a.IsIoPending) {
        throw "Suite 7a: Timed out waiting for endpoint to establish pending overlapped read (IsKnownQueued=$($ep7a.IsKnownQueued), IsIoPending=$($ep7a.IsIoPending))."
    }
    Write-Diagnostic "Suite 7a: Confirmed pending read queued (IsKnownQueued=true, IsIoPending=true). Now terminating worker process..."

    # Terminate the worker process
    $tErr7a = 0
    $termOk7a = $w7a.TryTerminateRootAndWait(5000, [ref]$tErr7a)
    Write-Diagnostic "Suite 7a: TryTerminateRootAndWait returned $termOk7a (error $tErr7a)."

    $obs7a = $w7a.Observe()
    if ($obs7a.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead) {
        throw "Suite 7a: Worker root must be Dead after TryTerminateRootAndWait (RootState=$($obs7a.RootState))."
    }

    # Drain background invocation and inspect result
    $drainOk7a = Drain-TrackedInvocation $invRec7a 12000
    if (-not $drainOk7a -or -not $invRec7a.Drained) {
        throw "Suite 7a: Background ReadFrame failed to settle within deadline."
    }

    if ($invRec7a.Result -eq $null -or $invRec7a.Result.Succeeded -eq $true) {
        throw "Suite 7a: ReadFrame must fail when worker process is terminated during pending I/O."
    }
    Write-Diagnostic "Suite 7a: ReadFrame failed as expected with: $($invRec7a.Result.Error)"

    if ($ep7a.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 7a: Endpoint must transition to FaultedCloseOnly upon worker termination during I/O."
    }
    if ($ep7a.IsIoPending) {
        throw "Suite 7a: IsIoPending must be false after rundown."
    }

    [void](Stop-TrackedOwner $w7aRec 5000)
    $cErr7a = 0
    $cOk7a = $ep7a.TryClose(5000, [ref]$cErr7a)
    if (-not $cOk7a -or $cErr7a -ne 0) {
        throw "Suite 7a: TryClose failed: error $cErr7a"
    }
    $ep7aRec.Closed = $true
    Write-Diagnostic "Suite 7a: PASSED."

    # 7b: Deterministic WRITE backpressure-root death
    $ep7b = Safe-CreateEndpoint -Tag "Ep7b"
    $r7b = [System.IO.Path]::Combine($labDir, "ready-7b.txt")
    $args7b = @("slow_stall", $ep7b.EndpointName, $r7b, "25000")
    $w7b = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args7b -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker7b"
    $w7bRec = $script:TrackedOwners[-1]
    $ep7bRec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r7b -timeoutMs 10000)
    [void]$ep7b.ConnectOwnedWorker($w7b, 5000)

    # Prepare 64 MiB pattern to guarantee filling the kernel pipe buffer and hitting backpressure
    $payload7b = [byte[]]::new(67108864)
    for ($k = 0; $k -lt 64; $k++) { $payload7b[$k] = [byte]($k % 256) }

    # Launch background WriteFrame to hit backpressure
    $psAsync7b = [System.Management.Automation.PowerShell]::Create()
    $invRec7b = Register-TrackedInvocation -PowerShell $psAsync7b -AsyncHandle $null -Tag "Suite7b-AsyncWrite"
    [void]$psAsync7b.AddScript({
        param($endpoint, $payload)
        try {
            $endpoint.WriteFrame($payload, 20000)
            return [PSObject]@{ Succeeded = $true }
        } catch {
            return [PSObject]@{ Succeeded = $false; BaseException = $_.Exception.GetBaseException(); Error = $_.Exception.GetBaseException().Message }
        }
    }).AddArgument($ep7b).AddArgument($payload7b)
    $invRec7b.AsyncHandle = $psAsync7b.BeginInvoke()

    # Await actual IsKnownQueued && IsIoPending (997) barrier
    $swWaitIo7b = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swWaitIo7b.ElapsedMilliseconds -lt 5000) {
        if ($ep7b.IsKnownQueued -and $ep7b.IsIoPending) { break }
        [System.Threading.Thread]::Sleep(10)
    }
    if (-not $ep7b.IsKnownQueued -or -not $ep7b.IsIoPending) {
        throw "Suite 7b: Timed out waiting for endpoint to establish write backpressure barrier (IsKnownQueued=$($ep7b.IsKnownQueued), IsIoPending=$($ep7b.IsIoPending))."
    }
    Write-Diagnostic "Suite 7b: Confirmed write backpressure queued (IsKnownQueued=true, IsIoPending=true). Now terminating worker process..."

    # Invoke owner process TryTerminateRootAndWait then observe dead (owner retained)
    $tErr7b = 0
    $termOk7b = $w7b.TryTerminateRootAndWait(5000, [ref]$tErr7b)
    Write-Diagnostic "Suite 7b: TryTerminateRootAndWait returned $termOk7b (error $tErr7b)."

    $obs7b = $w7b.Observe()
    if ($obs7b.RootState -ne [PosDesktop.Updates.Native.ChildRootState]::Dead) {
        throw "Suite 7b: Worker root must be Dead after TryTerminateRootAndWait (RootState=$($obs7b.RootState))."
    }

    # Collect bounded background expected error (no success)
    $drainOk7b = Drain-TrackedInvocation $invRec7b 12000
    if (-not $drainOk7b -or -not $invRec7b.Drained) {
        throw "Suite 7b: Background WriteFrame failed to settle within deadline."
    }

    if ($invRec7b.Result -eq $null -or $invRec7b.Result.Succeeded -eq $true) {
        throw "Suite 7b: WriteFrame must fail when worker process dies under backpressure."
    }
    Write-Diagnostic "Suite 7b: WriteFrame failed as expected with: $($invRec7b.Result.Error)"

    # Assert FaultedCloseOnly / pending completed
    if ($ep7b.State -ne [PosDesktop.Updates.Native.PipeEndpointState]::FaultedCloseOnly) {
        throw "Suite 7b: Endpoint must transition to FaultedCloseOnly upon worker termination during write backpressure."
    }
    if ($ep7b.IsIoPending) {
        throw "Suite 7b: IsIoPending must be false after rundown."
    }

    # Clean up payload reference
    $payload7b = $null
    [System.GC]::Collect()

    # Checked close endpoint + owner
    [void](Stop-TrackedOwner $w7bRec 5000)
    $cErr7b = 0
    $cOk7b = $ep7b.TryClose(5000, [ref]$cErr7b)
    if (-not $cOk7b -or $cErr7b -ne 0) {
        throw "Suite 7b: TryClose failed: error $cErr7b"
    }
    $ep7bRec.Closed = $true
    Write-Diagnostic "Suite 7b: PASSED."
    Write-Diagnostic "Suite 7: ALL PASSED."

    # ========================================================================
    # Suite 8: Concurrency & Busyguard
    # ========================================================================
    Write-Diagnostic "Starting Suite 8: Concurrency & Busyguard..."

    $ep8 = Safe-CreateEndpoint -Tag "Ep8"
    $r8 = [System.IO.Path]::Combine($labDir, "ready-8.txt")
    $args8 = @("slow_stall", $ep8.EndpointName, $r8, "10000")
    $w8 = Safe-StartOwnedProcess -exePath $fixtureExePath -ArgumentVector $args8 -cwd $labDir -env (Build-SparseEnvironment) -tag "Worker8"
    $w8Rec = $script:TrackedOwners[-1]
    $ep8Rec = $script:TrackedEndpoints[-1]

    [void](Wait-HandshakeFile -path $r8 -timeoutMs 10000)
    [void]$ep8.ConnectOwnedWorker($w8, 5000)

    # Launch background ReadFrame to hold endpoint busy - register immediately on Create
    $psAsync = [System.Management.Automation.PowerShell]::Create()
    $invRec = Register-TrackedInvocation -PowerShell $psAsync -AsyncHandle $null -Tag "Suite8-AsyncRead"
    [void]$psAsync.AddScript({
        param($endpoint)
        try {
            $fr = $endpoint.ReadFrame(5000)
            return [PSObject]@{ Succeeded = $true }
        } catch {
            return [PSObject]@{ Succeeded = $false; Error = $_.Exception.GetBaseException().Message }
        }
    }).AddArgument($ep8)

    $asyncHandle = $psAsync.BeginInvoke()
    $invRec.AsyncHandle = $asyncHandle

    # Wait until endpoint is busy
    $swBusy = [System.Diagnostics.Stopwatch]::StartNew()
    while ($swBusy.ElapsedMilliseconds -lt 3000) {
        if ($ep8.IsBusy) { break }
        [System.Threading.Thread]::Sleep(10)
    }
    if (-not $ep8.IsBusy) {
        throw "Suite 8: Endpoint failed to enter busy state within 3000ms."
    }

    # Concurrent operations from foreground must be rejected with busy error
    $caughtWriteBusy = $null
    try {
        $ep8.WriteFrame([System.Text.Encoding]::UTF8.GetBytes("CONCURRENT"), 1000)
    } catch {
        $caughtWriteBusy = $_.Exception.GetBaseException()
    }
    if ($caughtWriteBusy -eq $null -or $caughtWriteBusy.Message -notmatch "busy") {
        throw "Suite 8: Expected busy rejection on concurrent WriteFrame, got: $(if ($caughtWriteBusy) { $caughtWriteBusy.ToString() } else { 'none' })"
    }

    $caughtReadBusy = $null
    try {
        [void]$ep8.ReadFrame(1000)
    } catch {
        $caughtReadBusy = $_.Exception.GetBaseException()
    }
    if ($caughtReadBusy -eq $null -or $caughtReadBusy.Message -notmatch "busy") {
        throw "Suite 8: Expected busy rejection on concurrent ReadFrame, got: $(if ($caughtReadBusy) { $caughtReadBusy.ToString() } else { 'none' })"
    }

    $caughtVerifyBusy = $null
    try {
        [void]$ep8.VerifyOwnedPeer()
    } catch {
        $caughtVerifyBusy = $_.Exception.GetBaseException()
    }
    if ($caughtVerifyBusy -eq $null -or $caughtVerifyBusy.Message -notmatch "busy") {
        throw "Suite 8: Expected busy rejection on concurrent VerifyOwnedPeer, got: $(if ($caughtVerifyBusy) { $caughtVerifyBusy.ToString() } else { 'none' })"
    }

    # Drain background invocation
    $drainOk = Drain-TrackedInvocation $invRec 12000
    if (-not $drainOk -or -not $invRec.Drained) {
        throw "Suite 8: Background invocation failed to drain within deadline."
    }

    if ($ep8.IsBusy) {
        throw "Suite 8: Endpoint IsBusy must be false after background invocation finished."
    }

    [void](Stop-TrackedOwner $w8Rec 5000)
    [void](Close-TrackedEndpoint $ep8Rec 5000)
    Write-Diagnostic "Suite 8: ALL PASSED."

    # Victim witness tree check
    Assert-VictimTreeUnchanged -ExpectedSnapshot $script:VictimSnapshot -VictimPath $victimDir
    Write-Diagnostic "Protected victim tree witness check PASSED."

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
        @{ Path = $labDir; MarkerName = ".snwpf-lab-marker"; Nonce = $nonce },
        @{ Path = $victimDir; MarkerName = ".snwpf-victim-marker"; Nonce = $nonce }
    )

    $gateResult = Invoke-SharedCleanupSafetyGate -TargetSpecs $targetSpecs
    if ($gateResult -ne 'CLEANED' -or $null -ne $script:PrimaryError) {
        Write-Diagnostic "TEARDOWN UNCERTAIN. LAB RETAINED: $labDir"
        if ($null -ne $script:PrimaryError) {
            Write-Diagnostic "Primary error: $($script:PrimaryError.Message)"
        }
        exit 1
    } else {
        Write-Diagnostic "Teardown clean. ALL 8 SUITES PASSED."
        exit 0
    }
}
