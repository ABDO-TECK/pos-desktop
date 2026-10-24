# scripts/verify-delta-handoff-windows.ps1
# Real Windows filesystem acceptance test under isolated temporary directory
# Tests real exclusive PHAR lock, real READONLY source/target/stale TMP,
# and permission denial probe contracts.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Guard: Never run on developer workstation
if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) {
    throw 'Windows delta handoff real filesystem lab test is restricted to an ephemeral GitHub Actions runner'
}

Write-Host "=========================================================="
Write-Host " Running Windows Delta Handoff Real Filesystem Test Lab"
Write-Host "=========================================================="

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$handoffModulePath = Join-Path $repoRoot "electron\services\delta-update-handoff.js"

if (-not (Test-Path -LiteralPath $handoffModulePath)) {
    Write-Error "Could not find delta-update-handoff.js at: $handoffModulePath"
    exit 1
}

# 1. Create a unique lab directory under TEMP with spaces in the path
$tempRoot = [System.IO.Path]::GetFullPath($env:TEMP).TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
$labGuid = [guid]::NewGuid().ToString('N')
$labName = "pos delta windows lab $labGuid"
$lab = [System.IO.Path]::GetFullPath((Join-Path $env:TEMP $labName))

# Verify lab path is strictly inside TEMP using separator boundary
if (-not ($lab + [System.IO.Path]::DirectorySeparatorChar).StartsWith($tempRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    Write-Error "Lab path escapes TEMP boundary: $lab"
    exit 1
}

New-Item -ItemType Directory -Path $lab -Force | Out-Null

# Verify lab directory is not a reparse point or junction
$labItem = Get-Item -LiteralPath $lab
if ($labItem.Attributes.HasFlag([System.IO.FileAttributes]::ReparsePoint)) {
    Write-Error "Lab path is an unsafe reparse point: $lab"
    exit 1
}

Write-Host "[INIT] Isolated test lab created at: $lab"

$pharLock = $null
$aclDenyApplied = $false
$protectedDir = $null
$originalAcl = $null
$originalSddl = $null
$currentSid = $null
$origRepoHandoffPath = $env:REPO_HANDOFF_PATH

function AssertOwnedLabPath([string]$targetPath) {
    if ([string]::IsNullOrWhiteSpace($targetPath)) {
        Write-Error "AssertOwnedLabPath: Path is null or empty"
        exit 1
    }
    $fullTarget = [System.IO.Path]::GetFullPath($targetPath)
    $fullLab = [System.IO.Path]::GetFullPath($lab)
    if (-not ($fullTarget + [System.IO.Path]::DirectorySeparatorChar).StartsWith($fullLab + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase) -and ($fullTarget -ne $fullLab)) {
        Write-Error "AssertOwnedLabPath security boundary violation: '$fullTarget' is outside lab '$fullLab'"
        exit 1
    }
    # Verify no reparse point in the target or any ancestor up to lab
    $curr = $fullTarget
    while ($curr.Length -ge $fullLab.Length) {
        if (Test-Path -LiteralPath $curr) {
            $item = Get-Item -LiteralPath $curr -Force
            if ($item.Attributes.HasFlag([System.IO.FileAttributes]::ReparsePoint)) {
                Write-Error "AssertOwnedLabPath rejected reparse point / junction at '$curr'"
                exit 1
            }
        }
        $parent = [System.IO.Path]::GetDirectoryName($curr)
        if ($parent -eq $curr -or [string]::IsNullOrEmpty($parent)) { break }
        $curr = $parent
    }
}

function Normalize-SddlDescriptorDaclAi([string]$sddl) {
    if ([string]::IsNullOrWhiteSpace($sddl)) { return $sddl }
    # Normalize ONLY the non-permission SE_DACL_AUTO_INHERITED ('AI') descriptor control flag
    # located in the DACL control header (between D: and the first '(' or 'S:').
    # All ACE contents, identity SIDs, rights, inheritance flags within ACEs, DACL protection ('P'),
    # and owner/group remain strictly unnormalized and enforced.
    if ($sddl -match '^(.*?D:)([^()S]*?)(\(.*|S:.*|$)$') {
        $prefix = $Matches[1]
        $flags = $Matches[2] -replace 'AI', ''
        $rest = $Matches[3]
        return "$prefix$flags$rest"
    }
    return $sddl
}

function Format-CanonicalAceRule($rule) {
    $sid = $null
    try {
        $sid = $rule.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
    } catch {
        $sid = $rule.IdentityReference.Value
    }
    $type = $rule.AccessControlType.ToString()
    $rights = [int]$rule.FileSystemRights
    $inherit = $rule.InheritanceFlags.ToString()
    $prop = $rule.PropagationFlags.ToString()
    $isInherited = $rule.IsInherited
    return "$type|$sid|$rights|$inherit|$prop|$isInherited"
}

function Assert-RestoredAclMatchesOriginal($origAcl, $restAcl, [string]$targetDesc = "restored path") {
    if ($origAcl -eq $null -or $restAcl -eq $null) {
        throw "Assert-RestoredAclMatchesOriginal: Original or restored ACL object is null"
    }

    # 1. Assert deny ACE removed: Ensure no unexpected Deny ACEs remain on restored path
    $denyAces = @($restAcl.Access | Where-Object { $_.AccessControlType -eq [System.Security.AccessControl.AccessControlType]::Deny })
    $origDenyAces = @($origAcl.Access | Where-Object { $_.AccessControlType -eq [System.Security.AccessControl.AccessControlType]::Deny })
    if ($denyAces.Count -gt $origDenyAces.Count) {
        throw "Assert-RestoredAclMatchesOriginal: Deny ACE was not removed from $targetDesc (found $($denyAces.Count) deny ACEs, expected $($origDenyAces.Count))"
    }

    # 2. Compare Owner SID / Identity
    $origOwner = $null
    $restOwner = $null
    try { $origOwner = $origAcl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value } catch {}
    try { $restOwner = $restAcl.GetOwner([System.Security.Principal.SecurityIdentifier]).Value } catch {}
    if (-not $origOwner) { $origOwner = $origAcl.Owner }
    if (-not $restOwner) { $restOwner = $restAcl.Owner }
    if ($origOwner -ne $restOwner) {
        throw "Assert-RestoredAclMatchesOriginal: Owner mismatch on $targetDesc (expected '$origOwner', got '$restOwner')"
    }

    # 3. Compare Primary Group SID / Identity
    $origGroup = $null
    $restGroup = $null
    try { $origGroup = $origAcl.GetGroup([System.Security.Principal.SecurityIdentifier]).Value } catch {}
    try { $restGroup = $restAcl.GetGroup([System.Security.Principal.SecurityIdentifier]).Value } catch {}
    if (-not $origGroup) { $origGroup = $origAcl.Group }
    if (-not $restGroup) { $restGroup = $restAcl.Group }
    if ($origGroup -ne $restGroup) {
        throw "Assert-RestoredAclMatchesOriginal: Group mismatch on $targetDesc (expected '$origGroup', got '$restGroup')"
    }

    # 4. Compare DACL protection flag (AreAccessRulesProtected)
    if ($origAcl.AreAccessRulesProtected -ne $restAcl.AreAccessRulesProtected) {
        throw "Assert-RestoredAclMatchesOriginal: DACL protection mismatch on $targetDesc (expected $($origAcl.AreAccessRulesProtected), got $($restAcl.AreAccessRulesProtected))"
    }

    # 5. Canonical sorted comparison of all ACE rules (type, SID, rights, inheritance, propagation, inherited status)
    $origAces = @($origAcl.Access | ForEach-Object { Format-CanonicalAceRule $_ }) | Sort-Object
    $restAces = @($restAcl.Access | ForEach-Object { Format-CanonicalAceRule $_ }) | Sort-Object

    if ($origAces.Count -ne $restAces.Count) {
        throw "Assert-RestoredAclMatchesOriginal: ACE count mismatch on $targetDesc (expected $($origAces.Count), got $($restAces.Count))"
    }
    for ($i = 0; $i -lt $origAces.Count; $i++) {
        if ($origAces[$i] -ne $restAces[$i]) {
            throw "Assert-RestoredAclMatchesOriginal: ACE semantic mismatch on $targetDesc at index $i (expected '$($origAces[$i])', got '$($restAces[$i])')"
        }
    }

    # 6. Normalized SDDL equivalence (comparing SDDL with ONLY non-permission SE_DACL_AUTO_INHERITED normalized)
    $origSddlStr = if ($origAcl.Sddl) { $origAcl.Sddl } else { $originalSddl }
    $restSddlStr = $restAcl.Sddl
    $normOrigSddl = Normalize-SddlDescriptorDaclAi $origSddlStr
    $normRestSddl = Normalize-SddlDescriptorDaclAi $restSddlStr
    if ($normOrigSddl -ne $normRestSddl) {
        throw "Assert-RestoredAclMatchesOriginal: Normalized SDDL mismatch on $targetDesc (expected '$normOrigSddl', got '$normRestSddl')"
    }
}

try {
    # 2. Write the Node test runner script into the isolated lab
    $runnerScript = Join-Path $lab "runner.mjs"
    $runnerContent = @"
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const handoff = require(process.env.REPO_HANDOFF_PATH);

const args = process.argv.slice(2);
const modeArg = args.find(a => a.startsWith('--mode='));
const resultArg = args.find(a => a.startsWith('--result='));
const mode = modeArg ? modeArg.split('=')[1] : null;
const resultFile = resultArg ? resultArg.split('=')[1] : null;

function saveResult(data) {
  if (resultFile) {
    fs.mkdirSync(path.dirname(resultFile), { recursive: true });
    fs.writeFileSync(resultFile, JSON.stringify(data, null, 2), 'utf8');
  }
}

try {
  if (mode === 'test-lock') {
    const sourcePath = args.find(a => a.startsWith('--source=')).split('=')[1];
    const targetPath = args.find(a => a.startsWith('--target=')).split('=')[1];
    try {
      await handoff.replaceFile(sourcePath, targetPath, { retries: 2, retryDelayMs: 20 });
      saveResult({ ok: true, replaced: true });
    } catch (err) {
      saveResult({
        ok: false,
        error: err.message,
        code: err.code,
        lockedTargetPreserved: true,
      });
    }
  } else if (mode === 'test-readonly') {
    const sourcePath = args.find(a => a.startsWith('--source=')).split('=')[1];
    const targetPath = args.find(a => a.startsWith('--target=')).split('=')[1];
    await handoff.replaceFile(sourcePath, targetPath);
    const content = fs.readFileSync(targetPath, 'utf8');
    saveResult({ ok: true, content });
  } else if (mode === 'test-probe-plan') {
    const storageDir = args.find(a => a.startsWith('--storage=')).split('=')[1];
    const version = args.find(a => a.startsWith('--version=')).split('=')[1];
    const expectedRoot = args.find(a => a.startsWith('--root=')).split('=')[1];
    const preflight = handoff.preflightPendingDelta(storageDir, version, expectedRoot);
    saveResult(preflight);
  } else {
    saveResult({ ok: false, error: 'Unknown mode: ' + mode });
  }
} catch (error) {
  saveResult({ ok: false, error: error.message, stack: error.stack });
}
"@
    [System.IO.File]::WriteAllText($runnerScript, $runnerContent, (New-Object System.Text.UTF8Encoding($false)))
    $env:REPO_HANDOFF_PATH = $handoffModulePath

    # =========================================================================
    # TEST 1: Real Exclusive PHAR Lock via owned FileStream
    # =========================================================================
    Write-Host "`n[TEST 1] Testing real exclusive PHAR lock handling..."
    $installedBackend = Join-Path $lab "installed\backend"
    New-Item -ItemType Directory -Path $installedBackend -Force | Out-Null
    $pharPath = Join-Path $installedBackend "backend.phar"
    $originalPharContent = "original immutable phar bytes 0.0.1"
    [System.IO.File]::WriteAllText($pharPath, $originalPharContent)

    $stagedBackend = Join-Path $lab "staged\backend"
    New-Item -ItemType Directory -Path $stagedBackend -Force | Out-Null
    $stagedPharPath = Join-Path $stagedBackend "backend.phar"
    [System.IO.File]::WriteAllText($stagedPharPath, "new phar bytes 0.0.10-beta.8")

    # Open exclusive lock on backend.phar with FileShare.None
    $pharLock = [System.IO.File]::Open($pharPath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    Write-Host "  -> Real Windows FileStream lock acquired on: $pharPath"

    $result1File = Join-Path $lab "result1.json"
    & node $runnerScript --mode=test-lock "--source=$stagedPharPath" "--target=$pharPath" "--result=$result1File"

    # Close the lock so we can read the file
    $pharLock.Close()
    $pharLock = $null

    if (-not (Test-Path -LiteralPath $result1File)) {
        Write-Error "Test 1 failed: Result file not generated by runner"
        exit 1
    }

    $res1 = Get-Content -Encoding UTF8 -LiteralPath $result1File | ConvertFrom-Json
    if ($res1.ok -eq $false -and ($res1.code -eq "EBUSY" -or $res1.code -eq "EPERM")) {
        Write-Host "  -> Correctly refused replacement under exclusive lock (code: $($res1.code))"
    } else {
        Write-Error "Test 1 failed: Expected lock failure (EBUSY/EPERM) but got: $($res1 | ConvertTo-Json)"
        exit 1
    }

    # Verify original file content was preserved intact
    $currentContent = [System.IO.File]::ReadAllText($pharPath)
    if ($currentContent -ne $originalPharContent) {
        Write-Error "Test 1 failed: Original locked file content was corrupted! Content: $currentContent"
        exit 1
    }
    Write-Host "[PASS] Test 1: Real exclusive PHAR lock cleanly aborted without in-place corruption."

    # =========================================================================
    # TEST 2: Real READONLY attributes on source, target, and stale TMP
    # =========================================================================
    Write-Host "`n[TEST 2] Testing real READONLY attributes and stale TMP collision resistance..."
    $roDir = Join-Path $lab "readonly_test"
    New-Item -ItemType Directory -Path $roDir -Force | Out-Null

    $roTarget = Join-Path $roDir "target.txt"
    [System.IO.File]::WriteAllText($roTarget, "initial target content")
    Set-ItemProperty -Path $roTarget -Name IsReadOnly -Value $true
    Write-Host "  -> Created ReadOnly target: $roTarget"

    $roSource = Join-Path $roDir "source.txt"
    [System.IO.File]::WriteAllText($roSource, "new replaced content 0.0.10-beta.8")
    Set-ItemProperty -Path $roSource -Name IsReadOnly -Value $true
    Write-Host "  -> Created ReadOnly source: $roSource"

    # Pre-seed a stale ReadOnly temp file mimicking previous crash
    $staleTmp = Join-Path $roDir ".target.txt.delta-$PID.tmp"
    [System.IO.File]::WriteAllText($staleTmp, "stale crash residue")
    Set-ItemProperty -Path $staleTmp -Name IsReadOnly -Value $true
    Write-Host "  -> Created pre-existing ReadOnly stale tmp file: $staleTmp"

    $result2File = Join-Path $lab "result2.json"
    & node $runnerScript --mode=test-readonly "--source=$roSource" "--target=$roTarget" "--result=$result2File"

    if (-not (Test-Path -LiteralPath $result2File)) {
        Write-Error "Test 2 failed: Result file not generated by runner"
        exit 1
    }

    $res2 = Get-Content -Encoding UTF8 -LiteralPath $result2File | ConvertFrom-Json
    if ($res2.ok -eq $true -and $res2.content -eq "new replaced content 0.0.10-beta.8") {
        Write-Host "  -> ReplaceFile succeeded through ReadOnly source/target attributes"
    } else {
        Write-Error "Test 2 failed: ReadOnly replace failed: $($res2 | ConvertTo-Json)"
        exit 1
    }

    # Verify stale temp file was NOT collided with or corrupted
    if (Test-Path -LiteralPath $staleTmp) {
        $staleContent = [System.IO.File]::ReadAllText($staleTmp)
        if ($staleContent -ne "stale crash residue") {
            Write-Error "Test 2 failed: Stale tmp was corrupted by replaceFile! Content: $staleContent"
            exit 1
        }
        # Clean up stale tmp so lab can be removed
        AssertOwnedLabPath $staleTmp
        Set-ItemProperty -Path $staleTmp -Name IsReadOnly -Value $false
        Remove-Item -Path $staleTmp -Force
    }

    # Verify target has the new content
    $replacedTargetContent = [System.IO.File]::ReadAllText($roTarget)
    if ($replacedTargetContent -ne "new replaced content 0.0.10-beta.8") {
        Write-Error "Test 2 failed: Replaced target content mismatch: $replacedTargetContent"
        exit 1
    }
    Write-Host "[PASS] Test 2: Real READONLY attributes and stale TMP collision resistance passed."

    # =========================================================================
    # TEST 3: Parent Directory ACL Deny Probe
    # =========================================================================
    Write-Host "`n[TEST 3] Testing parent directory permission denial probe..."
    $protectedParent = Join-Path $lab "protected_install"
    $protectedDir = Join-Path $protectedParent "resources\app.asar.unpacked"
    New-Item -ItemType Directory -Path $protectedDir -Force | Out-Null

    $testPlanStorage = Join-Path $lab "runtime_storage"
    $testPlanStaging = Join-Path $testPlanStorage "update_staging\0.0.10-beta.8"
    $testPlanSnapshot = Join-Path $testPlanStorage "update-backups\patch_0.0.1_to_beta.8"
    $testSnapshotFiles = Join-Path $testPlanSnapshot "files"
    New-Item -ItemType Directory -Path $testPlanStaging -Force | Out-Null
    New-Item -ItemType Directory -Path $testSnapshotFiles -Force | Out-Null

    # Create a minimal handoff plan targeting the protected child dir
    $dummyTarget = Join-Path $protectedDir "version.json"
    [System.IO.File]::WriteAllText($dummyTarget, '{"version":"0.0.1"}')
    $dummyStaged = Join-Path $testPlanStaging "version.json"
    [System.IO.File]::WriteAllText($dummyStaged, '{"version":"0.0.10-beta.8"}')
    $dummyBackup = Join-Path $testSnapshotFiles "version.json"
    [System.IO.File]::WriteAllText($dummyBackup, '{"version":"0.0.1"}')

    $targetSha = (Get-FileHash -LiteralPath $dummyTarget -Algorithm SHA256).Hash.ToLower()
    $stagedSha = (Get-FileHash -LiteralPath $dummyStaged -Algorithm SHA256).Hash.ToLower()

    # Record target state before probe
    $beforeHash = $targetSha
    $beforeAttrs = (Get-Item -LiteralPath $dummyTarget).Attributes
    $beforeContent = [System.IO.File]::ReadAllText($dummyTarget)

    $metadataObj = @{
        purpose = "delta_install_safety"
        files = @(
            @{
                path = "version.json"
                sha256 = $targetSha
                size = (Get-Item -LiteralPath $dummyTarget).Length
            }
        )
        new_files = @()
        deleted_files = @()
        version_json_backup = '{"version":"0.0.1"}'
    }
    $metadataJson = $metadataObj | ConvertTo-Json -Depth 5
    [System.IO.File]::WriteAllText((Join-Path $testPlanSnapshot "metadata.json"), $metadataJson, (New-Object System.Text.UTF8Encoding($false)))

    $planObj = @{
        version = "0.0.10-beta.8"
        root_dir = $protectedDir
        storage_dir = $testPlanStorage
        staging_dir = $testPlanStaging
        snapshot_path = $testPlanSnapshot
        manifest = @{
            version = "0.0.10-beta.8"
            from_version = "0.0.1"
            files = @(
                @{
                    path = "version.json"
                    sha256 = $stagedSha
                    action = "replace"
                }
            )
            deleted_files = @()
            baseline_files = @{
                "version.json" = $targetSha
            }
        }
    }
    $planJson = $planObj | ConvertTo-Json -Depth 5
    [System.IO.File]::WriteAllText((Join-Path $testPlanStaging "desktop-handoff.json"), $planJson, (New-Object System.Text.UTF8Encoding($false)))

    # Step A: Run writable preflight first and require ok: true before applying deny
    $resultPreDeny = Join-Path $lab "result_pre_deny.json"
    & node $runnerScript --mode=test-probe-plan "--storage=$testPlanStorage" "--version=0.0.10-beta.8" "--root=$protectedDir" "--result=$resultPreDeny"
    if (-not (Test-Path -LiteralPath $resultPreDeny)) {
        Write-Error "Test 3 failed: Writable preflight result file not generated"
        exit 1
    }
    $resPre = Get-Content -Encoding UTF8 -LiteralPath $resultPreDeny | ConvertFrom-Json
    if ($resPre.ok -ne $true) {
        Write-Error "Test 3 failed: Expected ok: true for writable preflight before deny, got: $($resPre | ConvertTo-Json)"
        exit 1
    }
    Write-Host "  -> Writable preflight verified ok: true before applying deny"

    # Step B: Capture original ACL via Get-Acl before applying deny rule
    AssertOwnedLabPath $protectedDir
    $originalAcl = Get-Acl -LiteralPath $protectedDir
    $originalSddl = $originalAcl.Sddl

    # Step C: Apply Deny ACL using current identity SID
    $currentSid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    Write-Host "  -> Current Windows Identity SID: $currentSid"
    $icaclsOut = icacls $protectedDir /deny "*$($currentSid):(WD,AD)" 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Test 3 failed: Confinement or security boundary prevented WRITE_DAC (exit code $($LASTEXITCODE) - output: $icaclsOut). Test 3 runs real ACL probe with current identity SID and fails non-zero if confinement prevents WRITE_DAC."
        exit 1
    }

    # Step D: Verify deny using Get-Acl and exit code (without localized English string matching)
    $appliedAcl = Get-Acl -LiteralPath $protectedDir
    $denyFound = $false
    foreach ($rule in $appliedAcl.Access) {
        if ($rule.AccessControlType -eq [System.Security.AccessControl.AccessControlType]::Deny) {
            $denyFound = $true
            break
        }
    }
    if (-not $denyFound) {
        Write-Error "Test 3 failed: Deny rule was not verified via Get-Acl"
        exit 1
    }
    $aclDenyApplied = $true
    Write-Host "  -> Verified write deny ACL applied on lab directory via Get-Acl: $protectedDir"

    $result3File = Join-Path $lab "result3.json"
    & node $runnerScript --mode=test-probe-plan "--storage=$testPlanStorage" "--version=0.0.10-beta.8" "--root=$protectedDir" "--result=$result3File"

    if (-not (Test-Path -LiteralPath $result3File)) {
        Write-Error "Test 3 failed: Result file not generated by runner"
        exit 1
    }

    $res3 = Get-Content -Encoding UTF8 -LiteralPath $result3File | ConvertFrom-Json
    if ($res3.ok -eq $false -and $res3.reason_code -eq "install_permission_required") {
        Write-Host "  -> Preflight correctly detected permission denial with reason_code: $($res3.reason_code)"
        Write-Host "  -> Actionable Arabic message verified: $($res3.message_ar)"

        # Assert actionable Arabic message is non-empty and contains administrator guidance
        $arabicAdminKeyword = [regex]::Unescape('\u0635\u0644\u0627\u062d\u064a\u0627\u062a \u0627\u0644\u0645\u0633\u0624\u0648\u0644')
        if ([string]::IsNullOrWhiteSpace($res3.message_ar) -or ($res3.message_ar -notmatch $arabicAdminKeyword)) {
            Write-Error "Test 3 failed: Arabic guidance missing or does not mention administrator permissions"
            exit 1
        }

        # Assert before/after hash, attributes, and content match exactly (no mutation)
        $afterHash = (Get-FileHash -LiteralPath $dummyTarget -Algorithm SHA256).Hash.ToLower()
        $afterAttrs = (Get-Item -LiteralPath $dummyTarget).Attributes
        $afterContent = [System.IO.File]::ReadAllText($dummyTarget)

        if ($beforeHash -ne $afterHash -or $beforeContent -ne $afterContent -or $beforeAttrs -ne $afterAttrs) {
            Write-Error "Test 3 failed: Target file was modified during permission probe!"
            exit 1
        }

        # Assert no leftover probe files (.probe-*)
        $leftoverProbes = @(Get-ChildItem -LiteralPath $protectedDir -Filter "*probe*" -Force)
        if ($leftoverProbes.Count -gt 0) {
            Write-Error "Test 3 failed: Leftover probe files found in protected directory: $($leftoverProbes.Name -join ', ')"
            exit 1
        }

        Write-Host "[PASS] Test 3: Protected parent ACL probe returned install_permission_required with untouched artifacts."
    } else {
        Write-Error "Test 3 failed: Expected install_permission_required but got: $($res3 | ConvertTo-Json)"
        exit 1
    }

    # =========================================================================
    # Summary & Diagnostic Limitation Report
    # =========================================================================
    Write-Host "`n=========================================================="
    Write-Host " Diagnostic Limitation Summary"
    Write-Host "=========================================================="
    Write-Host "1. Real exclusive lock contention (EPERM/EBUSY) aborted cleanly without fallback in-place corruption."
    Write-Host "2. Fresh per-operation unique tmp paths prevented stale PID temporary collisions."
    Write-Host "3. ReadOnly attributes on owned temporary files cleared safely; target atomicity preserved."
    Write-Host "4. IMPORTANT DELIVERY LIMITATION: An Electron shell installed in C:\Program Files by standard users"
    Write-Host "   cannot be elevated via delta update alone without administrator credentials."
    Write-Host "   Existing 0.0.1 shell requires a full installer Setup or one-time signed administrative recovery tool."
    Write-Host "=========================================================="
    Write-Host " ALL WINDOWS DELTA HANDOFF REAL FILESYSTEM TESTS PASSED!"
    Write-Host "=========================================================="
}
finally {
    $cleanupFailed = $false
    # Guaranteed cleanup
    if ($pharLock -ne $null) {
        try { $pharLock.Close() } catch {}
        $pharLock = $null
    }
    if ($aclDenyApplied -and (Test-Path -LiteralPath $protectedDir)) {
        try {
            AssertOwnedLabPath $protectedDir
            if ($originalAcl -ne $null) {
                Set-Acl -LiteralPath $protectedDir -AclObject $originalAcl
                $restoredAcl = Get-Acl -LiteralPath $protectedDir
                Assert-RestoredAclMatchesOriginal -origAcl $originalAcl -restAcl $restoredAcl -targetDesc $protectedDir
                Write-Host "  -> Successfully restored exact original ACL and verified security semantics and SDDL: $protectedDir"

                # Assert directory is writable after restore via direct file probe
                $cleanupProbe = Join-Path $protectedDir ".cleanup-verify-probe-$([guid]::NewGuid().ToString('N'))"
                [System.IO.File]::WriteAllText($cleanupProbe, "cleanup probe verification")
                Remove-Item -LiteralPath $cleanupProbe -Force

                # Run writable preflight probe after restore if runner and plan storage are intact
                if ((Test-Path -LiteralPath $runnerScript) -and (Test-Path -LiteralPath $testPlanStorage)) {
                    $resultPostRestore = Join-Path $lab "result_post_restore.json"
                    & node $runnerScript --mode=test-probe-plan "--storage=$testPlanStorage" "--version=0.0.10-beta.8" "--root=$protectedDir" "--result=$resultPostRestore"
                    if (-not (Test-Path -LiteralPath $resultPostRestore)) {
                        Write-Error "Cleanup failed: Writable preflight result file not generated after restore"
                        $cleanupFailed = $true
                    } else {
                        $resPost = Get-Content -Encoding UTF8 -LiteralPath $resultPostRestore | ConvertFrom-Json
                        if ($resPost.ok -ne $true) {
                            Write-Error "Cleanup failed: Writable preflight probe failed after ACL restore: $($resPost | ConvertTo-Json)"
                            $cleanupFailed = $true
                        } else {
                            Write-Host "  -> Verified writable preflight probe ok: true after ACL restore"
                        }
                    }
                }

                # Assert no leftover probe files exist in protectedDir
                $leftoverProbes = @(Get-ChildItem -LiteralPath $protectedDir -Filter "*probe*" -Force)
                if ($leftoverProbes.Count -gt 0) {
                    Write-Error "Cleanup failed: Leftover probe files found in protected directory after restore: $($leftoverProbes.Name -join ', ')"
                    $cleanupFailed = $true
                }
            } elseif ($currentSid) {
                icacls $protectedDir /remove:d "*$($currentSid)" *>$null
                $restoredAcl = Get-Acl -LiteralPath $protectedDir
                $denyAces = @($restoredAcl.Access | Where-Object { $_.AccessControlType -eq [System.Security.AccessControl.AccessControlType]::Deny })
                if ($denyAces.Count -gt 0) {
                    Write-Error "Cleanup failed: Deny ACE still present after icacls /remove:d"
                    $cleanupFailed = $true
                }
            }
        } catch {
            Write-Error "Cleanup failed: Could not restore ACL on protectedDir: $($_.Exception.Message)"
            $cleanupFailed = $true
        }
    }
    if ($origRepoHandoffPath -ne $null) {
        $env:REPO_HANDOFF_PATH = $origRepoHandoffPath
    } else {
        Remove-Item Env:\REPO_HANDOFF_PATH -ErrorAction SilentlyContinue
    }
    if (Test-Path -LiteralPath $lab) {
        try {
            AssertOwnedLabPath $lab
            attrib -r /s (Join-Path $lab "*") *>$null
            Remove-Item -Path $lab -Recurse -Force
            Write-Host "[CLEANUP] Removed test lab: $lab"
        } catch {
            Write-Error "Cleanup failed: Could not fully remove lab directory: $($_.Exception.Message)"
            $cleanupFailed = $true
        }
    }
    if ($cleanupFailed) {
        exit 1
    }
}

exit 0
