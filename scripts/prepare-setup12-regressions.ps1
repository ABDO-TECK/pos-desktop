[CmdletBinding()]
param(
    [ValidateSet('prepare', 'sign', 'acceptance', 'cleanup')]
    [string]$Phase = 'prepare'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) {
    throw 'Ephemeral Windows GitHub Actions runner required.'
}

$lab = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP 'setup12-regression-lab'))
$evidence = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP 'setup12-regression-evidence'))
$assets = [IO.Path]::GetFullPath((Join-Path $lab 'assets'))
$candidateSource = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP 'candidate-source'))
$ownershipReceiptPath = [IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP 'setup12-ownership-receipt.json'))
$appDir = [IO.Path]::GetFullPath((Join-Path $lab 'app'))

function Assert-SafeDirectory {
    param([string]$Directory)
    $full = [IO.Path]::GetFullPath($Directory)
    if ($full.TrimEnd('\') -eq [IO.Path]::GetPathRoot($full).TrimEnd('\')) {
        throw "Refusing root directory: $full"
    }
    $runnerTemp = [IO.Path]::GetFullPath($env:RUNNER_TEMP)
    if (-not $full.StartsWith($runnerTemp.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -and $full -ne $runnerTemp) {
        throw "Directory outside RUNNER_TEMP: $full"
    }
    $ancestor = $full
    while ($ancestor) {
        if (Test-Path -LiteralPath $ancestor) {
            if ((Get-Item -LiteralPath $ancestor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw "Reparse point rejected: $ancestor"
            }
        }
        $ancestor = [IO.Path]::GetDirectoryName($ancestor)
    }
    return $full
}

function Assert-SafeReceiptPath {
    param([string]$FilePath)
    $full = [IO.Path]::GetFullPath($FilePath)
    if ($full.TrimEnd('\') -eq [IO.Path]::GetPathRoot($full).TrimEnd('\')) {
        throw "Refusing root path: $full"
    }
    $runnerTemp = [IO.Path]::GetFullPath($env:RUNNER_TEMP)
    if (-not $full.StartsWith($runnerTemp.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -and $full -ne $runnerTemp) {
        throw "Path outside RUNNER_TEMP: $full"
    }
    $cursor = $full
    while ($cursor) {
        if (Test-Path -LiteralPath $cursor) {
            if ((Get-Item -LiteralPath $cursor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw "Reparse point rejected: $cursor"
            }
        }
        $cursor = [IO.Path]::GetDirectoryName($cursor)
    }
    return $full
}

function Find-Tool {
    param([string]$Name, [string]$RelativeFallback)
    if ($RelativeFallback -and (Test-Path -LiteralPath $RelativeFallback)) {
        return (Resolve-Path -LiteralPath $RelativeFallback).Path
    }
    $cmd = Get-Command $Name -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }
    foreach ($cand in @(
        (Join-Path $env:ProgramFiles "7-Zip/$Name"),
        (Join-Path ${env:ProgramFiles(x86)} "7-Zip/$Name")
    )) {
        if ($cand -and (Test-Path -LiteralPath $cand)) {
            return (Resolve-Path -LiteralPath $cand).Path
        }
    }
    if ($RelativeFallback) {
        throw "Tool $Name not found in PATH or at fallback $RelativeFallback"
    }
    throw "Tool $Name not found in PATH"
}

$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
. (Join-Path $repo 'build/uninstall-data.ps1')

$canonicalTargets = @(Get-PosCleanupTargets -Roaming $env:APPDATA -Local $env:LOCALAPPDATA -Shared $env:PROGRAMDATA)
$exactRegistryKeys = @(
    'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\com.abdoteck.pos',
    'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\com.abdoteck.pos',
    'HKCU:\Software\com.abdoteck.pos',
    'HKLM:\Software\com.abdoteck.pos'
)

$knownFirewallRules = @(
    'POS System - SSL Port 8443',
    'POS System - Embedded PHP',
    'POS System - Main Executable',
    'POS System - Web Port 8080'
)

function Assert-PosFirewallRulesAbsent {
    param([string[]]$Names)
    foreach ($name in $Names) {
        $found = $false
        try {
            $rules = @(Get-NetFirewallRule -DisplayName $name -ErrorAction Stop)
            if ($rules.Count -gt 0) {
                $found = $true
            }
        } catch {
            $msg = $_.Exception.Message
            if ($msg -like '*No MSFT_NetFirewallRule objects found*' -or `
                $msg -like '*No matching*') {
                $found = $false
            } elseif ($msg -like '*Access is denied*') {
                # Non-elevated fallback: query netsh advfirewall (fails closed if netsh cannot determine absence)
                $netshOut = & netsh.exe advfirewall firewall show rule name="$name" 2>&1
                if ($netshOut -match 'Rule Name:\s+' -or $netshOut -match '----------------------------------------------------------------------') {
                    $found = $true
                } elseif ($netshOut -match 'No rules match the specified criteria') {
                    $found = $false
                } else {
                    throw "Failed to enumerate firewall rules (fail-closed check for '$name'): $netshOut"
                }
            } else {
                throw "Failed to enumerate firewall rules (fail-closed check for '$name'): $msg"
            }
        }
        if ($found) {
            throw "Preexisting firewall rule blocks isolated regression test: $name"
        }
    }
}

function Get-PosDesktopUninstallRegistrations {
    $roots = @(
        'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall',
        'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall',
        'HKCU:\Software\Wow6432Node\Microsoft\Windows\CurrentVersion\Uninstall',
        'HKLM:\Software\Wow6432Node\Microsoft\Windows\CurrentVersion\Uninstall'
    )
    $found = @()
    foreach ($r in $roots) {
        if (Test-Path -LiteralPath $r) {
            Get-ChildItem -LiteralPath $r -ErrorAction SilentlyContinue | ForEach-Object {
                $subKey = $_
                $dispName = $subKey.GetValue('DisplayName')
                $installLoc = $subKey.GetValue('InstallLocation')
                $unString = $subKey.GetValue('UninstallString')
                $keyName = $subKey.PSChildName
                if (($dispName -and $dispName -like '*POS Desktop*') -or `
                    $keyName -eq 'com.abdoteck.pos' -or `
                    ($installLoc -and $installLoc -like '*POS Desktop*')) {
                    $found += [ordered]@{
                        KeyPath = $subKey.PSPath
                        PSPath = $subKey.PSPath
                        DisplayName = $dispName
                        InstallLocation = $installLoc
                        UninstallString = $unString
                    }
                }
            }
        }
    }
    return $found
}

function Get-ValidatedOwnershipReceipt {
    param([switch]$AllowMissing)
    if (-not (Test-Path -LiteralPath $ownershipReceiptPath)) {
        if ($AllowMissing) { return $null }
        throw "Ownership receipt missing: $ownershipReceiptPath"
    }
    Assert-SafeReceiptPath $ownershipReceiptPath | Out-Null
    $raw = Get-Content -LiteralPath $ownershipReceiptPath -Raw -ErrorAction Stop
    $receipt = $raw | ConvertFrom-Json
    if (-not $receipt) { throw "Ownership receipt could not be parsed as JSON." }

    if ([string]::IsNullOrWhiteSpace($env:GITHUB_RUN_ID) -or $receipt.github_run_id -ne $env:GITHUB_RUN_ID) {
        throw "Ownership receipt github_run_id mismatch ($($receipt.github_run_id) vs $($env:GITHUB_RUN_ID))"
    }
    if ([string]::IsNullOrWhiteSpace($env:GITHUB_RUN_ATTEMPT) -or $receipt.github_run_attempt -ne $env:GITHUB_RUN_ATTEMPT) {
        throw "Ownership receipt github_run_attempt mismatch ($($receipt.github_run_attempt) vs $($env:GITHUB_RUN_ATTEMPT))"
    }
    if ([string]::IsNullOrWhiteSpace($receipt.run_token) -or $receipt.run_token -notmatch '^[0-9a-f]{32}$') {
        throw "Ownership receipt run_token is invalid: '$($receipt.run_token)'"
    }
    if ($receipt.fresh_prechecks_passed -ne $true) {
        throw "Ownership receipt fresh_prechecks_passed is not true"
    }
    if ($receipt.qz_precheck_absent -ne $true) {
        throw "Ownership receipt qz_precheck_absent is not true"
    }
    if ($receipt.shortcuts_precheck_absent -ne $true) {
        throw "Ownership receipt shortcuts_precheck_absent is not true"
    }
    if ($receipt.firewall_precheck_absent -ne $true) {
        throw "Ownership receipt firewall_precheck_absent is not true"
    }

    # Validate created_at_utc: parse valid UTC timestamp, verify not future, within 24 hours
    if ([string]::IsNullOrWhiteSpace($receipt.created_at_utc)) {
        throw "Ownership receipt created_at_utc is missing or empty"
    }
    $parsedCreatedUtc = [DateTime]::MinValue
    if (-not [DateTime]::TryParse($receipt.created_at_utc, [System.Globalization.CultureInfo]::InvariantCulture, [System.Globalization.DateTimeStyles]::AdjustToUniversal -bor [System.Globalization.DateTimeStyles]::AssumeUniversal, [ref]$parsedCreatedUtc)) {
        throw "Ownership receipt created_at_utc is not a valid ISO timestamp: '$($receipt.created_at_utc)'"
    }
    $parsedCreatedUtc = $parsedCreatedUtc.ToUniversalTime()
    $nowUtc = (Get-Date).ToUniversalTime()
    if ($parsedCreatedUtc -gt $nowUtc.AddMinutes(1)) {
        throw "Ownership receipt created_at_utc is in the future ($($receipt.created_at_utc) vs $nowUtc)"
    }
    if ($parsedCreatedUtc -lt $nowUtc.AddDays(-1)) {
        throw "Ownership receipt created_at_utc is too old ($($receipt.created_at_utc) vs $nowUtc)"
    }
    $receipt | Add-Member -NotePropertyName 'parsed_created_at_utc' -NotePropertyValue $parsedCreatedUtc -Force

    $freshQzRoot = [IO.Path]::GetFullPath((Join-Path $env:PROGRAMDATA 'qz'))
    if ($receipt.qz_root -ne $freshQzRoot) {
        throw "Ownership receipt qz_root mismatch: '$($receipt.qz_root)' vs '$freshQzRoot'"
    }
    if ($receipt.application -ne $appDir -or $receipt.lab -ne $lab -or $receipt.evidence -ne $evidence -or $receipt.candidate_source -ne $candidateSource) {
        throw "Ownership receipt paths mismatch standard run directories"
    }

    $expectedDesktopShortcut = Join-Path ([Environment]::GetFolderPath('Desktop')) 'POS Desktop.lnk'
    $expectedProgramsShortcut = Join-Path ([Environment]::GetFolderPath('Programs')) 'POS Desktop.lnk'
    if ($receipt.desktop_shortcut -ne $expectedDesktopShortcut -or $receipt.programs_shortcut -ne $expectedProgramsShortcut) {
        throw "Ownership receipt shortcut paths mismatch: '$($receipt.desktop_shortcut)' vs '$expectedDesktopShortcut' or '$($receipt.programs_shortcut)' vs '$expectedProgramsShortcut'"
    }

    $freshTargets = @(Get-PosCleanupTargets -Roaming $env:APPDATA -Local $env:LOCALAPPDATA -Shared $env:PROGRAMDATA)
    $receiptTargets = @($receipt.pos_targets)
    if ($receiptTargets.Count -ne 8 -or $freshTargets.Count -ne 8) {
        throw "Ownership receipt POS targets count mismatch (expected 8, got $($receiptTargets.Count))"
    }
    for ($i = 0; $i -lt 8; $i++) {
        if ($receiptTargets[$i] -ne $freshTargets[$i]) {
            throw "Ownership receipt POS target at [$i] mismatch: '$($receiptTargets[$i])' vs '$($freshTargets[$i])'"
        }
    }

    $receiptRegKeys = @($receipt.registry_keys)
    if ($receiptRegKeys.Count -ne 4 -or $exactRegistryKeys.Count -ne 4) {
        throw "Ownership receipt registry keys count mismatch (expected 4, got $($receiptRegKeys.Count))"
    }
    for ($i = 0; $i -lt 4; $i++) {
        if ($receiptRegKeys[$i] -ne $exactRegistryKeys[$i]) {
            throw "Ownership receipt registry key at [$i] mismatch: '$($receiptRegKeys[$i])' vs '$($exactRegistryKeys[$i])'"
        }
    }

    return $receipt
}

$seven = Find-Tool '7z.exe' (Join-Path $repo 'node_modules/electron-winstaller/vendor/7z.exe')
$php = Find-Tool 'php.exe' $env:PHP_BINARY
$node = Find-Tool 'node.exe' $null
$git = Find-Tool 'git.exe' $null
$ghExe = Find-Tool 'gh.exe' $null

if ($Phase -eq 'prepare') {
    Write-Host '=== Setup 0.0.12 Regression: Prepare Phase ==='

    if ($env:UPDATE_PRIVATE_KEY) {
        throw 'Security violation: UPDATE_PRIVATE_KEY must not exist during prepare phase.'
    }

    # 1. Ephemeral runner isolation: REFUSE ALL preexisting state before ANY mutation
    if (Test-Path -LiteralPath $ownershipReceiptPath) {
        throw "Preexisting ownership receipt blocks isolated regression: $ownershipReceiptPath"
    }
    if (Test-Path -LiteralPath $candidateSource) {
        throw "Preexisting candidate source directory blocks isolated regression: $candidateSource"
    }
    if (Test-Path -LiteralPath $lab) {
        throw "Preexisting lab directory blocks isolated regression: $lab"
    }
    if (Test-Path -LiteralPath $evidence) {
        throw "Preexisting evidence directory blocks isolated regression: $evidence"
    }

    foreach ($existing in $canonicalTargets) {
        if (Test-Path -LiteralPath $existing) {
            throw "Preexisting POS target blocks isolated regression test: $existing"
        }
    }

    foreach ($regPath in $exactRegistryKeys) {
        if (Test-Path -LiteralPath $regPath) {
            throw "Preexisting registry key blocks isolated regression test: $regPath"
        }
    }

    $preRegistrations = @(Get-PosDesktopUninstallRegistrations)
    if ($preRegistrations.Count -gt 0) {
        throw "Preexisting POS Desktop uninstall registration blocks isolated regression test: $(($preRegistrations | ForEach-Object { $_.KeyPath }) -join '; ')"
    }

    $qzCanonicalRoot = [IO.Path]::GetFullPath((Join-Path $env:PROGRAMDATA 'qz'))
    if (Test-Path -LiteralPath $qzCanonicalRoot) {
        throw "Preexisting shared QZ directory blocks isolated regression test: $qzCanonicalRoot"
    }
    foreach ($programFiles in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
        if ($programFiles -and (Test-Path -LiteralPath (Join-Path $programFiles 'QZ Tray/qz-tray.exe'))) {
            throw "Preexisting QZ Tray installation blocks isolated regression test: $programFiles\QZ Tray"
        }
    }

    $desktopShortcut = Join-Path ([Environment]::GetFolderPath('Desktop')) 'POS Desktop.lnk'
    $programsShortcut = Join-Path ([Environment]::GetFolderPath('Programs')) 'POS Desktop.lnk'
    $programsSubfolderShortcut = Join-Path (Join-Path ([Environment]::GetFolderPath('Programs')) 'POS Desktop') 'POS Desktop.lnk'

    $preexistingShortcuts = @()
    if (Test-Path -LiteralPath $desktopShortcut) { $preexistingShortcuts += $desktopShortcut }
    if (Test-Path -LiteralPath $programsShortcut) { $preexistingShortcuts += $programsShortcut }
    if (Test-Path -LiteralPath $programsSubfolderShortcut) { $preexistingShortcuts += $programsSubfolderShortcut }
    if ($preexistingShortcuts.Count -gt 0) {
        throw "Preexisting POS Desktop shortcut blocks isolated regression test: $($preexistingShortcuts -join '; ')"
    }

    Assert-PosFirewallRulesAbsent -Names $knownFirewallRules

    Assert-SafeDirectory $lab | Out-Null
    Assert-SafeDirectory $evidence | Out-Null
    Assert-SafeDirectory $assets | Out-Null

    New-Item -ItemType Directory -Path $lab -Force | Out-Null
    New-Item -ItemType Directory -Path $evidence -Force | Out-Null
    New-Item -ItemType Directory -Path $assets -Force | Out-Null

    # Generate cryptographic random run token (lower 32 hex) and establish Early Ownership Receipt
    $runToken = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(16)).ToLowerInvariant()
    $ownershipReceipt = [ordered]@{
        github_run_id = $env:GITHUB_RUN_ID
        github_run_attempt = $env:GITHUB_RUN_ATTEMPT
        run_token = $runToken
        fresh_prechecks_passed = $true
        created_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        application = $appDir
        lab = $lab
        evidence = $evidence
        candidate_source = $candidateSource
        pos_targets = @($canonicalTargets)
        registry_keys = @($exactRegistryKeys)
        qz_root = $qzCanonicalRoot
        qz_precheck_absent = $true
        shortcuts_precheck_absent = $true
        firewall_precheck_absent = $true
        desktop_shortcut = $desktopShortcut
        programs_shortcut = $programsShortcut
    }
    $tmpReceipt = $ownershipReceiptPath + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
    $ownershipReceipt | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $tmpReceipt -Encoding utf8
    Move-Item -LiteralPath $tmpReceipt -Destination $ownershipReceiptPath -Force

    # 2. Download published Setup 0.0.12 release assets
    Write-Host 'Downloading published Setup 0.0.12 release assets from ABDO-TECK/pos-desktop v0.0.12...'
    $setup12Exe = Join-Path $lab 'POS-Desktop-Setup-0.0.12.exe'
    $setup12Inv = Join-Path $lab 'setup12-inventory.json'

    & $ghExe release download v0.0.12 --repo ABDO-TECK/pos-desktop --pattern 'POS-Desktop-Setup-0.0.12.exe' --dir $lab --clobber
    if ($LASTEXITCODE -ne 0) { throw 'Failed to download POS-Desktop-Setup-0.0.12.exe' }

    if (-not (Test-Path -LiteralPath $setup12Exe)) {
        throw "Downloaded Setup 12 executable missing: $setup12Exe"
    }
    $actualSetup12ExeSize = (Get-Item -LiteralPath $setup12Exe).Length
    if ($actualSetup12ExeSize -ne 322556752) {
        throw "Setup 12 installer size mismatch! Expected 322556752, got $actualSetup12ExeSize"
    }
    $setup12ExeHash = (Get-FileHash -LiteralPath $setup12Exe -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedSetup12ExeHash = 'cdfa1d797e726280d48d7f7c64e9ccbe5717ba64831b455cf59de47fab4c6ef8'
    if ($setup12ExeHash -ne $expectedSetup12ExeHash) {
        throw "Setup 12 installer hash mismatch! Expected $expectedSetup12ExeHash, got $setup12ExeHash"
    }

    & $ghExe release download v0.0.12 --repo ABDO-TECK/pos-desktop --pattern 'deployment-inventory.json' --dir $lab --clobber
    if ($LASTEXITCODE -ne 0) { throw 'Failed to download Setup 0.0.12 deployment-inventory.json' }
    Move-Item -LiteralPath (Join-Path $lab 'deployment-inventory.json') -Destination $setup12Inv -Force

    $actualSetup12InvSize = (Get-Item -LiteralPath $setup12Inv).Length
    if ($actualSetup12InvSize -ne 11202) {
        throw "Setup 12 inventory size mismatch! Expected 11202, got $actualSetup12InvSize"
    }
    $setup12InvHash = (Get-FileHash -LiteralPath $setup12Inv -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedSetup12InvHash = 'b1265fe0e0cab3d2a91d482a44a9ba5aa4759bb971bb640a9241331bbb060132'
    if ($setup12InvHash -ne $expectedSetup12InvHash) {
        throw "Setup 12 inventory hash mismatch! Expected $expectedSetup12InvHash, got $setup12InvHash"
    }

    # 3. Download published Delta 0.0.12 release assets
    Write-Host 'Downloading published Delta 0.0.12 release assets from ABDO-TECK/pos-desktop-updates v0.0.12...'
    $delta12Dir = Join-Path $lab 'delta12-download'
    New-Item -ItemType Directory -Path $delta12Dir -Force | Out-Null
    & $ghExe release download v0.0.12 --repo ABDO-TECK/pos-desktop-updates --pattern 'deployment-inventory.json' --dir $delta12Dir --clobber
    if ($LASTEXITCODE -ne 0) { throw 'Failed to download Delta 0.0.12 deployment-inventory.json' }

    $delta12Inv = Join-Path $lab 'delta12-inventory.json'
    Move-Item -LiteralPath (Join-Path $delta12Dir 'deployment-inventory.json') -Destination $delta12Inv -Force

    $actualDelta12InvSize = (Get-Item -LiteralPath $delta12Inv).Length
    if ($actualDelta12InvSize -ne 11203) {
        throw "Delta 12 inventory size mismatch! Expected 11203, got $actualDelta12InvSize"
    }
    $delta12InvHash = (Get-FileHash -LiteralPath $delta12Inv -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedDelta12InvHash = '219a04064436abdeb3fb58087964ef23d908e7c49b3e62eb4ca1314ca3a6d817'
    if ($delta12InvHash -ne $expectedDelta12InvHash) {
        throw "Delta 12 inventory hash mismatch! Expected $expectedDelta12InvHash, got $delta12InvHash"
    }

    # 4. Download published Beta 0.0.12-beta.1 release assets
    Write-Host 'Downloading published Beta 0.0.12-beta.1 release assets from ABDO-TECK/pos-desktop-updates v0.0.12-beta.1...'
    $beta12Dir = Join-Path $lab 'beta12-download'
    New-Item -ItemType Directory -Path $beta12Dir -Force | Out-Null

    & $ghExe release download v0.0.12-beta.1 --repo ABDO-TECK/pos-desktop-updates --pattern 'transition-0.0.11-to-beta-0.0.12-beta.1-full.zip' --dir $beta12Dir --clobber
    if ($LASTEXITCODE -ne 0) { throw 'Failed to download Beta 12.1 full transition package' }
    $beta12Zip = Join-Path $beta12Dir 'transition-0.0.11-to-beta-0.0.12-beta.1-full.zip'
    $actualBeta12ZipSize = (Get-Item -LiteralPath $beta12Zip).Length
    if ($actualBeta12ZipSize -ne 47546932) {
        throw "Beta 12.1 full zip size mismatch! Expected 47546932, got $actualBeta12ZipSize"
    }
    $beta12ZipHash = (Get-FileHash -LiteralPath $beta12Zip -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedBeta12ZipHash = 'cb17fb94a476a3e5070e2d0f2c3db4fbc40f5bc6bc193b697fe3af7f74965cf2'
    if ($beta12ZipHash -ne $expectedBeta12ZipHash) {
        throw "Beta 12.1 zip hash mismatch! Expected $expectedBeta12ZipHash, got $beta12ZipHash"
    }

    & $ghExe release download v0.0.12-beta.1 --repo ABDO-TECK/pos-desktop-updates --pattern 'transition-0.0.11-to-beta-0.0.12-beta.1-full.json' --dir $beta12Dir --clobber
    if ($LASTEXITCODE -ne 0) { throw 'Failed to download Beta 12.1 full transition descriptor' }
    $beta12Desc = Join-Path $beta12Dir 'transition-0.0.11-to-beta-0.0.12-beta.1-full.json'
    $actualBeta12DescSize = (Get-Item -LiteralPath $beta12Desc).Length
    if ($actualBeta12DescSize -ne 38591) {
        throw "Beta 12.1 descriptor size mismatch! Expected 38591, got $actualBeta12DescSize"
    }
    $beta12DescHash = (Get-FileHash -LiteralPath $beta12Desc -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedBeta12DescHash = '73d8177e5605b7762e3fc6b2333752e78bbb81881b67cde3f7bd2088c34712b0'
    if ($beta12DescHash -ne $expectedBeta12DescHash) {
        throw "Beta 12.1 descriptor hash mismatch! Expected $expectedBeta12DescHash, got $beta12DescHash"
    }

    & $ghExe release download v0.0.12-beta.1 --repo ABDO-TECK/pos-desktop-updates --pattern 'transition-0.0.11-to-beta-0.0.12-beta.1-full.sig' --dir $beta12Dir --clobber
    if ($LASTEXITCODE -ne 0) { throw 'Failed to download Beta 12.1 full transition signature' }
    $beta12DescSig = Join-Path $beta12Dir 'transition-0.0.11-to-beta-0.0.12-beta.1-full.sig'
    $actualBeta12DescSigSize = (Get-Item -LiteralPath $beta12DescSig).Length
    if ($actualBeta12DescSigSize -ne 344) {
        throw "Beta 12.1 descriptor signature size mismatch! Expected 344, got $actualBeta12DescSigSize"
    }
    $beta12DescSigHash = (Get-FileHash -LiteralPath $beta12DescSig -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedBeta12DescSigHash = '3861b74953c58ef7fa217774e456e04e3b8540d8026cd13330511195692860bc'
    if ($beta12DescSigHash -ne $expectedBeta12DescSigHash) {
        throw "Beta 12.1 descriptor signature hash mismatch! Expected $expectedBeta12DescSigHash, got $beta12DescSigHash"
    }

    $beta12Index = Join-Path $beta12Dir 'channel-transitions.json'
    & $ghExe release download v0.0.12-beta.1 --repo ABDO-TECK/pos-desktop-updates --pattern 'channel-transitions.json' --dir $beta12Dir --clobber
    if ($LASTEXITCODE -ne 0) { throw 'Failed to download Beta 12.1 channel-transitions.json' }
    $actualBeta12IndexSize = (Get-Item -LiteralPath $beta12Index).Length
    if ($actualBeta12IndexSize -ne 2182) {
        throw "Beta 12.1 index size mismatch! Expected 2182, got $actualBeta12IndexSize"
    }
    $beta12IndexHash = (Get-FileHash -LiteralPath $beta12Index -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedBeta12IndexHash = '4109ad3cdfb06201219a8939015cc1c723863e27f200d2257e408c7b8cd98f27'
    if ($beta12IndexHash -ne $expectedBeta12IndexHash) {
        throw "Beta 12.1 index hash mismatch! Expected $expectedBeta12IndexHash, got $beta12IndexHash"
    }

    $beta12IndexSig = Join-Path $beta12Dir 'channel-transitions.sig'
    & $ghExe release download v0.0.12-beta.1 --repo ABDO-TECK/pos-desktop-updates --pattern 'channel-transitions.sig' --dir $beta12Dir --clobber
    if ($LASTEXITCODE -ne 0) { throw 'Failed to download Beta 12.1 channel-transitions.sig' }
    $actualBeta12IndexSigSize = (Get-Item -LiteralPath $beta12IndexSig).Length
    if ($actualBeta12IndexSigSize -ne 344) {
        throw "Beta 12.1 index signature size mismatch! Expected 344, got $actualBeta12IndexSigSize"
    }
    $beta12IndexSigHash = (Get-FileHash -LiteralPath $beta12IndexSig -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedBeta12IndexSigHash = '599742f4c95f842944b8705458b5f5bc7dfea4cf5d23110b04464014764b77c6'
    if ($beta12IndexSigHash -ne $expectedBeta12IndexSigHash) {
        throw "Beta 12.1 index signature hash mismatch! Expected $expectedBeta12IndexSigHash, got $beta12IndexSigHash"
    }

    $beta12Inv = Join-Path $beta12Dir 'deployment-inventory.json'
    & $ghExe release download v0.0.12-beta.1 --repo ABDO-TECK/pos-desktop-updates --pattern 'deployment-inventory.json' --dir $beta12Dir --clobber
    if ($LASTEXITCODE -ne 0) { throw 'Failed to download Beta 12.1 deployment-inventory.json' }
    $actualBeta12InvSize = (Get-Item -LiteralPath $beta12Inv).Length
    if ($actualBeta12InvSize -ne 11209) {
        throw "Beta 12.1 inventory size mismatch! Expected 11209, got $actualBeta12InvSize"
    }
    $beta12InvHash = (Get-FileHash -LiteralPath $beta12Inv -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedBeta12InvHash = '104cc1b55d9ebae69aac8d56bbb024afa178e4208fd73a41a1d4d89d504b1fb3'
    if ($beta12InvHash -ne $expectedBeta12InvHash) {
        throw "Beta 12.1 inventory hash mismatch! Expected $expectedBeta12InvHash, got $beta12InvHash"
    }
    Copy-Item -LiteralPath $beta12Inv -Destination (Join-Path $lab 'beta12-inventory.json') -Force

    # Verify RSA signature and manifest binding via PHP helper action
    $pubKey = Join-Path $repo 'backend/certs/update_public_key.pem'
    & $php scripts/fixtures/setup12-regressions.php `
        --action=verify-beta-signatures `
        "--beta12-index=$beta12Index" `
        "--beta12-index-sig=$beta12IndexSig" `
        "--beta12-desc=$beta12Desc" `
        "--beta12-desc-sig=$beta12DescSig" `
        "--beta12-zip=$beta12Zip" `
        "--public-key=$pubKey"
    if ($LASTEXITCODE -ne 0) { throw 'Beta 12.1 RSA signature and manifest verification failed.' }

    # 5. Silently install genuine immutable Setup 0.0.12 into $appDir
    if (Test-Path -LiteralPath $appDir) {
        throw "Existing application directory blocks fresh Setup 0.0.12 installation: $appDir"
    }
    Assert-SafeDirectory $appDir | Out-Null

    Write-Host "Installing published Setup 0.0.12 silently to $appDir..."
    $process = Start-Process -FilePath $setup12Exe -ArgumentList @('/S', '/currentuser', "/D=$appDir") -WindowStyle Hidden -PassThru
    if (-not $process.WaitForExit(180000)) {
        throw 'Silent Setup 0.0.12 installation timed out.'
    }
    if ($process.ExitCode -ne 0) {
        throw "Silent Setup 0.0.12 installation failed with exit code: $($process.ExitCode)"
    }

    $installedExe = Join-Path $appDir 'POS Desktop.exe'
    if (-not (Test-Path -LiteralPath $installedExe)) {
        throw "Installed Setup 0.0.12 executable missing: $installedExe"
    }
    $installedAsar = Join-Path $appDir 'resources/app.asar'
    if (-not (Test-Path -LiteralPath $installedAsar)) {
        throw "Installed Setup 0.0.12 app.asar missing: $installedAsar"
    }
    $installedMysql = Join-Path $appDir 'resources/portable/mysql'
    if (-not (Test-Path -LiteralPath $installedMysql)) {
        throw "Installed Setup 0.0.12 portable MySQL missing: $installedMysql"
    }
    $installedPhp = Join-Path $appDir 'resources/portable/php'
    if (-not (Test-Path -LiteralPath $installedPhp)) {
        throw "Installed Setup 0.0.12 portable PHP missing: $installedPhp"
    }
    $installedUninstaller = Join-Path $appDir 'Uninstall POS Desktop.exe'
    if (-not (Test-Path -LiteralPath $installedUninstaller)) {
        throw "Installed Setup 0.0.12 uninstaller missing: $installedUninstaller"
    }
    $freshRegistrations = @(Get-PosDesktopUninstallRegistrations | Where-Object {
        ($_.InstallLocation -and [IO.Path]::GetFullPath($_.InstallLocation).TrimEnd('\') -eq $appDir.TrimEnd('\')) -or `
        ($_.UninstallString -and $_.UninstallString.IndexOf($appDir, [StringComparison]::OrdinalIgnoreCase) -ge 0)
    })
    if ($freshRegistrations.Count -ne 1) {
        throw "Failed to locate unique fresh POS Desktop uninstall registration bound to $appDir (found $($freshRegistrations.Count))"
    }
    $freshRegistrationKey = $freshRegistrations[0].PSPath
    Write-Host "Captured fresh POS Desktop uninstall registration: $freshRegistrationKey"
    Assert-PosTreeSafe $appDir

    # 6. Reconstruct Beta 12.1 app files into $lab/beta12-app
    Write-Host 'Reconstructing Beta 12.1 target application files...'
    $beta12App = Join-Path $lab 'beta12-app'
    & $php scripts/fixtures/setup12-regressions.php `
        --action=reconstruct-beta12 `
        "--beta12-zip=$beta12Zip" `
        "--beta12-inventory=$beta12Inv" `
        "--output=$beta12App"
    if ($LASTEXITCODE -ne 0) { throw 'Beta 12.1 reconstruction failed.' }

    # 7. Candidate 0.0.13 assembly in isolated RUNNER_TEMP
    Write-Host 'Assembling Candidate 0.0.13 in isolated source copy...'
    Assert-SafeDirectory $candidateSource | Out-Null
    New-Item -ItemType Directory -Path $candidateSource -Force | Out-Null

    # Copy Git-tracked files only, excluding sensitive keys/certs/env
    $trackedFiles = & $git -C $repo ls-files
    if ($LASTEXITCODE -ne 0) { throw 'git ls-files failed' }
    foreach ($file in $trackedFiles) {
        if ($file -match '(\.env|\.key|\.pem$|\.p12$)') {
            if ($file -ne 'backend/certs/cacert.pem' -and $file -ne 'backend/certs/update_public_key.pem') {
                continue
            }
        }
        $dest = Join-Path $candidateSource $file
        $parent = Split-Path $dest -Parent
        if (-not (Test-Path -LiteralPath $parent)) {
            New-Item -ItemType Directory -Path $parent -Force | Out-Null
        }
        Copy-Item -LiteralPath (Join-Path $repo $file) -Destination $dest -Force
    }

    # Verify tracked database migrations, seeders, and pos_schema.sql exist
    $migrationFiles = @(Get-ChildItem -LiteralPath (Join-Path $candidateSource 'database/migrations') -Filter '*.sql' -Recurse -File)
    $seederFiles = @(Get-ChildItem -LiteralPath (Join-Path $candidateSource 'database/seeders') -Filter '*.sql' -Recurse -File)
    if ($migrationFiles.Count -ne 66) {
        throw "database/migrations missing or incomplete in candidate source (expected 66, found $($migrationFiles.Count))"
    }
    if ($seederFiles.Count -ne 3) {
        throw "database/seeders missing or incomplete in candidate source (expected 3, found $($seederFiles.Count))"
    }
    if (-not (Test-Path -LiteralPath (Join-Path $candidateSource 'database/pos_schema.sql'))) {
        throw "database/pos_schema.sql missing in candidate source"
    }

    # Create dependency junctions
    New-Item -ItemType Junction -Path (Join-Path $candidateSource 'node_modules') -Target (Join-Path $repo 'node_modules') | Out-Null
    New-Item -ItemType Junction -Path (Join-Path $candidateSource 'frontend/node_modules') -Target (Join-Path $repo 'frontend/node_modules') | Out-Null
    New-Item -ItemType Junction -Path (Join-Path $candidateSource 'backend/vendor') -Target (Join-Path $repo 'backend/vendor') | Out-Null
    New-Item -ItemType Junction -Path (Join-Path $candidateSource 'portable') -Target (Join-Path $repo 'portable') | Out-Null

    # Normalize versions to 0.0.13 in candidate-source only (preserving lock dependencies and version metadata)
    $cPkgPath = Join-Path $candidateSource 'package.json'
    $cPkg = Get-Content -LiteralPath $cPkgPath -Raw | ConvertFrom-Json
    $cPkg.version = '0.0.13'
    $cPkg | ConvertTo-Json -Depth 32 | Set-Content -LiteralPath $cPkgPath

    $cLockPath = Join-Path $candidateSource 'package-lock.json'
    $cLock = Get-Content -LiteralPath $cLockPath -Raw | ConvertFrom-Json -AsHashtable
    if (-not ($cLock -and ($cLock['packages'] -is [System.Collections.IDictionary]) -and ($cLock['packages'][''] -is [System.Collections.IDictionary]))) {
        throw "Candidate package-lock.json missing required root package entry in packages['']."
    }
    $cLock['version'] = '0.0.13'
    $cLock['packages']['']['version'] = '0.0.13'
    $cLock | ConvertTo-Json -Depth 64 | Set-Content -LiteralPath $cLockPath

    $cFEndPkgPath = Join-Path $candidateSource 'frontend/package.json'
    $cFEndPkg = Get-Content -LiteralPath $cFEndPkgPath -Raw | ConvertFrom-Json
    $cFEndPkg.version = '0.0.13'
    $cFEndPkg | ConvertTo-Json -Depth 32 | Set-Content -LiteralPath $cFEndPkgPath

    $cFEndLockPath = Join-Path $candidateSource 'frontend/package-lock.json'
    $cFEndLock = Get-Content -LiteralPath $cFEndLockPath -Raw | ConvertFrom-Json -AsHashtable
    if (-not ($cFEndLock -and ($cFEndLock['packages'] -is [System.Collections.IDictionary]) -and ($cFEndLock['packages'][''] -is [System.Collections.IDictionary]))) {
        throw "Candidate frontend/package-lock.json missing required root package entry in packages['']."
    }
    $cFEndLock['version'] = '0.0.13'
    $cFEndLock['packages']['']['version'] = '0.0.13'
    $cFEndLock | ConvertTo-Json -Depth 64 | Set-Content -LiteralPath $cFEndLockPath

    # Clone repository version.json preserving all engine metadata, updating only version fields
    $cVerPath = Join-Path $candidateSource 'version.json'
    $cVer = Get-Content -LiteralPath (Join-Path $repo 'version.json') -Raw | ConvertFrom-Json
    $cVer.version = '0.0.13'
    $cVer.application_version = '0.0.13'
    $cVer.installer_version = '0.0.13'
    $cVer.channel = 'stable'
    $cVer.update_channel = 'stable'
    $cVer.release_type = 'setup'
    $cVer | ConvertTo-Json -Depth 32 | Set-Content -LiteralPath $cVerPath

    Write-Host 'Building Candidate 0.0.13 artifacts...'
    $gitDir = (& $git -C $repo rev-parse --absolute-git-dir).Trim()
    $oldGitDir = $env:GIT_DIR
    $oldGitWorkTree = $env:GIT_WORK_TREE
    $env:GIT_DIR = $gitDir
    $env:GIT_WORK_TREE = $candidateSource

    # Write gitdir file in candidate-source so subprocess git calls resolve safely
    $cGitFile = Join-Path $candidateSource '.git'
    Set-Content -LiteralPath $cGitFile -Value "gitdir: $gitDir"

    try {
        Push-Location $candidateSource
        try {
            & npm.cmd run frontend:build
            if ($LASTEXITCODE -ne 0) { throw 'npm run frontend:build failed.' }

            & npm.cmd run build:phar
            if ($LASTEXITCODE -ne 0) { throw 'npm run build:phar failed.' }

            $candidatePhar = Join-Path $candidateSource 'backend/backend.phar'
            if (-not (Test-Path -LiteralPath $candidatePhar)) {
                throw "Candidate backend.phar missing after build: $candidatePhar"
            }

            # Verify PHAR layout (SQL SET, hash/sizes, restore CLI) inside PHAR
            & $php (Join-Path $repo 'scripts/fixtures/setup12-regressions.php') `
                --action=verify-phar-layout `
                "--phar=$candidatePhar" `
                "--repo-root=$repo"
            if ($LASTEXITCODE -ne 0) { throw 'Candidate PHAR layout verification failed.' }

            & npm.cmd run electron:build -- --x64
            if ($LASTEXITCODE -ne 0) { throw 'npm run electron:build failed.' }
        }
        finally {
            Pop-Location
        }

        # Output directory configured in package.json is 'dist-electron'
        $candidateExeSrc = Join-Path $candidateSource 'dist-electron/POS-Desktop-Setup-0.0.13.exe'
        if (-not (Test-Path -LiteralPath $candidateExeSrc)) {
            throw "Candidate installer missing after build: $candidateExeSrc"
        }
        $candidateInstaller = Join-Path $lab 'POS-Desktop-Setup-0.0.13.exe'
        Copy-Item -LiteralPath $candidateExeSrc -Destination $candidateInstaller -Force

        # Extract Candidate app payload to unpack resources
        Write-Host 'Extracting candidate application files...'
        $candidateExtract = Join-Path $lab 'candidate-extract'
        & $seven e $candidateInstaller '$PLUGINSDIR\app-64.7z' "-o$candidateExtract" -y | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Failed to extract app-64.7z from candidate installer.' }

        $candidateUnpacked = Join-Path $lab 'candidate-unpacked'
        & $seven x (Join-Path $candidateExtract 'app-64.7z') "-o$candidateUnpacked" -y | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Failed to unpack candidate installer files.' }

        $candidateShellRoot = $candidateUnpacked
        $candidateExe = Join-Path $candidateShellRoot 'POS Desktop.exe'
        $candidateAsar = Join-Path $candidateShellRoot 'resources/app.asar'
        if (-not (Test-Path -LiteralPath $candidateExe) -or -not (Test-Path -LiteralPath $candidateAsar)) {
            throw "Candidate shell files missing in: $candidateShellRoot"
        }
        $candidateExeHash = (Get-FileHash -LiteralPath $candidateExe -Algorithm SHA256).Hash.ToLowerInvariant()
        $candidateAsarHash = (Get-FileHash -LiteralPath $candidateAsar -Algorithm SHA256).Hash.ToLowerInvariant()

        $candidateUnpackedApp = Join-Path $candidateUnpacked 'resources/app.asar.unpacked'
        if (-not (Test-Path -LiteralPath $candidateUnpackedApp)) {
            throw "Candidate unpacked resources missing: $candidateUnpackedApp"
        }

        # 8. Prepare candidate variants (Setup 13 inventory vs Delta 13 inventory)
        Write-Host 'Preparing candidate Setup 13 and Delta 13 variant inventories...'
        $candidateSetupInv = Join-Path $lab 'candidate13-setup-inventory.json'
        $candidateDeltaApp = Join-Path $lab 'candidate13-delta-app'
        $candidateDeltaInv = Join-Path $lab 'candidate13-delta-inventory.json'
        & $php (Join-Path $repo 'scripts/fixtures/setup12-regressions.php') `
            --action=prepare-candidate-variants `
            "--candidate-unpacked=$candidateUnpackedApp" `
            "--repo-root=$repo" `
            "--candidate-source=$candidateSource" `
            "--candidate-setup-inventory=$candidateSetupInv" `
            "--candidate-delta-app=$candidateDeltaApp" `
            "--candidate-delta-inventory=$candidateDeltaInv"
        if ($LASTEXITCODE -ne 0) { throw 'Candidate variant preparation failed.' }
    }
    finally {
        $env:GIT_DIR = $oldGitDir
        $env:GIT_WORK_TREE = $oldGitWorkTree
    }

    # Write late owned lab marker before contract phase
    $ownedLabMarker = Join-Path $lab 'owned-lab.json'
    $ownedData = [ordered]@{
        github_run_id = $env:GITHUB_RUN_ID
        github_run_attempt = $env:GITHUB_RUN_ATTEMPT
        run_token = $runToken
        early_receipt_path = $ownershipReceiptPath
        ownership_receipt_path = $ownershipReceiptPath
        application = $appDir
        uninstall_registry_key = $freshRegistrationKey
        candidate_installer = $candidateInstaller
        candidate_shell_root = $candidateShellRoot
        candidate_exe_sha256 = $candidateExeHash
        candidate_asar_sha256 = $candidateAsarHash
        candidate_unpacked_app = $candidateUnpackedApp
        candidate_delta_app = $candidateDeltaApp
        beta12_app = $beta12App
        lab = $lab
        evidence = $evidence
        assets = $assets
        storage = [IO.Path]::GetFullPath((Join-Path $env:PROGRAMDATA 'POS System'))
        created_at = (Get-Date).ToUniversalTime().ToString('o')
    }
    $tmpOwned = $ownedLabMarker + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
    $ownedData | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $tmpOwned -Encoding utf8
    Move-Item -LiteralPath $tmpOwned -Destination $ownedLabMarker -Force

    # 9. Execute initial contract phase using Setup 12
    Write-Host 'Executing Setup 12 contract phase to onboard, seed sentinel, and capture SQL compatibility contract...'
    & $node scripts/verify-setup12-regressions.mjs contract "$appDir" "$lab" "$evidence"
    if ($LASTEXITCODE -ne 0) { throw 'Setup 12 contract phase failed.' }

    $contractPath = Join-Path $lab 'data-contract.json'
    if (-not (Test-Path -LiteralPath $contractPath)) {
        throw "Contract phase completed without generating $contractPath"
    }

    Write-Host 'Prepare phase completed successfully.'
}

if ($Phase -eq 'sign') {
    Write-Host '=== Setup 0.0.12 Regression: Sign Phase ==='

    if (-not $env:UPDATE_PRIVATE_KEY -or [string]::IsNullOrWhiteSpace($env:UPDATE_PRIVATE_KEY)) {
        throw 'UPDATE_PRIVATE_KEY secret is required during sign phase.'
    }

    $receipt = Get-ValidatedOwnershipReceipt
    Assert-SafeDirectory $lab | Out-Null
    Assert-SafeDirectory $assets | Out-Null

    $ownedLabMarker = Join-Path $lab 'owned-lab.json'
    if (-not (Test-Path -LiteralPath $ownedLabMarker)) {
        throw "Owned lab marker missing ($ownedLabMarker); refusing to sign."
    }
    Assert-SafeReceiptPath $ownedLabMarker | Out-Null
    $owned = Get-Content -LiteralPath $ownedLabMarker -Raw | ConvertFrom-Json
    if ($owned.run_token -ne $receipt.run_token) {
        throw "Owned lab marker run_token mismatch ($($owned.run_token) vs $($receipt.run_token))"
    }

    $contractPath = Join-Path $lab 'data-contract.json'
    if (-not (Test-Path -LiteralPath $contractPath)) {
        throw "Data contract missing ($contractPath); refusing to sign."
    }

    try {
        Write-Host 'Building and signing bidirectional channel transition packages...'
        # Transitions are bound to candidate 13 extracted shell root ($owned.candidate_shell_root)
        & $php scripts/fixtures/setup12-regressions.php `
            --action=build-transitions `
            "--assets-dir=$assets" `
            "--output-root=$assets" `
            "--candidate-app=$($owned.candidate_unpacked_app)" `
            "--candidate-setup-inventory=$(Join-Path $lab 'candidate13-setup-inventory.json')" `
            "--candidate-delta-app=$($owned.candidate_delta_app)" `
            "--candidate-delta-inventory=$(Join-Path $lab 'candidate13-delta-inventory.json')" `
            "--beta12-app=$($owned.beta12_app)" `
            "--beta12-inventory=$(Join-Path $lab 'beta12-inventory.json')" `
            "--contract=$contractPath" `
            "--shell-root=$($owned.candidate_shell_root)"
        if ($LASTEXITCODE -ne 0) { throw 'Building and signing transitions failed.' }

        # Verify signed outputs nested in pos-desktop and pos-desktop-updates (3 pairs delta/full)
        $posDesktopDir = Join-Path $assets 'pos-desktop'
        $posDesktopUpdatesDir = Join-Path $assets 'pos-desktop-updates'

        foreach ($req in @(
            'channel-transitions.json',
            'channel-transitions.sig',
            'transition-0.0.13-to-beta-0.0.12-beta.1-delta.zip',
            'transition-0.0.13-to-beta-0.0.12-beta.1-full.zip'
        )) {
            $p = Join-Path $posDesktopDir $req
            if (-not (Test-Path -LiteralPath $p)) {
                throw "Expected signed transition artifact missing in pos-desktop: $req"
            }
        }

        foreach ($req in @(
            'channel-transitions.json',
            'channel-transitions.sig',
            'transition-0.0.13-to-beta-0.0.12-beta.1-delta.zip',
            'transition-0.0.13-to-beta-0.0.12-beta.1-full.zip',
            'transition-0.0.12-beta.1-to-stable-0.0.13-delta.zip',
            'transition-0.0.12-beta.1-to-stable-0.0.13-full.zip'
        )) {
            $p = Join-Path $posDesktopUpdatesDir $req
            if (-not (Test-Path -LiteralPath $p)) {
                throw "Expected signed transition artifact missing in pos-desktop-updates: $req"
            }
        }
    }
    finally {
        # Secret hygiene: purge private key immediately
        Remove-Item Env:UPDATE_PRIVATE_KEY -ErrorAction SilentlyContinue
        [Environment]::SetEnvironmentVariable('UPDATE_PRIVATE_KEY', $null, 'Process')
        Write-Host 'Environment variable UPDATE_PRIVATE_KEY purged.'
    }

    Write-Host 'Sign phase completed and private key purged.'
}

if ($Phase -eq 'acceptance') {
    Write-Host '=== Setup 0.0.12 Regression: Acceptance Phase ==='

    if ($env:UPDATE_PRIVATE_KEY) {
        throw 'Security violation: UPDATE_PRIVATE_KEY must not exist during acceptance phase.'
    }

    $receipt = Get-ValidatedOwnershipReceipt
    Assert-SafeDirectory $lab | Out-Null
    Assert-SafeDirectory $assets | Out-Null
    Assert-SafeDirectory $evidence | Out-Null

    $ownedLabMarker = Join-Path $lab 'owned-lab.json'
    if (-not (Test-Path -LiteralPath $ownedLabMarker)) {
        throw "Owned lab marker missing ($ownedLabMarker); refusing acceptance execution."
    }
    Assert-SafeReceiptPath $ownedLabMarker | Out-Null
    $owned = Get-Content -LiteralPath $ownedLabMarker -Raw | ConvertFrom-Json
    if ($owned.run_token -ne $receipt.run_token) {
        throw "Owned lab marker run_token mismatch ($($owned.run_token) vs $($receipt.run_token))"
    }

    $appDir = $owned.application

    & $node scripts/verify-setup12-regressions.mjs acceptance "$appDir" "$lab" "$evidence" "$assets"
    if ($LASTEXITCODE -ne 0) { throw 'Acceptance execution failed.' }

    $candidateResults = Join-Path $lab 'candidate-results.json'
    if (-not (Test-Path -LiteralPath $candidateResults)) {
        throw "Acceptance execution finished without generating $candidateResults"
    }
    $cRes = Get-Content -LiteralPath $candidateResults -Raw | ConvertFrom-Json
    if ($cRes.run_token -ne $receipt.run_token) {
        throw "candidate-results.json run_token mismatch ($($cRes.run_token) vs $($receipt.run_token))"
    }

    $evidenceCandidateResults = Join-Path $evidence 'candidate-results.json'
    Copy-Item -LiteralPath $candidateResults -Destination $evidenceCandidateResults -Force
    Write-Host 'Setup 0.0.12 regression acceptance verification passed. Results saved to candidate-results.json.'
}

if ($Phase -eq 'cleanup') {
    Write-Host '=== Setup 0.0.12 Regression: Cleanup Phase ==='

    # Guarded Ownership Validation: Cleanup MUST NOOP unless early fresh-run marker exists and is valid
    $receipt = $null
    try {
        $receipt = Get-ValidatedOwnershipReceipt -AllowMissing
    }
    catch {
        Write-Host "Ownership receipt is invalid ($($_.Exception.Message)); cleanup is a safe NOOP."
        return
    }

    if (-not $receipt) {
        Write-Host "No validated early ownership receipt found at $ownershipReceiptPath; cleanup is a safe NOOP."
        return
    }

    $cleanupErrors = @()

    $ownedLabMarker = Join-Path $receipt.lab 'owned-lab.json'
    $owned = $null
    if (Test-Path -LiteralPath $ownedLabMarker) {
        try {
            Assert-SafeReceiptPath $ownedLabMarker | Out-Null
            $owned = Get-Content -LiteralPath $ownedLabMarker -Raw | ConvertFrom-Json
            if ($owned.run_token -ne $receipt.run_token) {
                throw "Owned lab marker run_token mismatch in cleanup: $($owned.run_token) vs $($receipt.run_token)"
            }
        } catch {
            $cleanupErrors += "Failed validating owned-lab.json: $($_.Exception.Message)"
        }
    }

    # GLOBAL PREFLIGHT: Validate ALL 8 owned roots + app + lab + source + QZ + registry bindings
    # BEFORE ANY cleanup mutation (trust, unlinks, uninstaller, deletions)
    $expectedJunctions = @{
        (Join-Path $receipt.candidate_source 'node_modules') = (Join-Path $repo 'node_modules')
        (Join-Path $receipt.candidate_source 'frontend/node_modules') = (Join-Path $repo 'frontend/node_modules')
        (Join-Path $receipt.candidate_source 'backend/vendor') = (Join-Path $repo 'backend/vendor')
        (Join-Path $receipt.candidate_source 'portable') = (Join-Path $repo 'portable')
    }

    try {
        Assert-SafeDirectory $receipt.lab | Out-Null
        Assert-SafeDirectory $receipt.evidence | Out-Null
        Assert-SafeDirectory $receipt.candidate_source | Out-Null
        Assert-SafeDirectory $receipt.application | Out-Null

        # Classify ALL reparse points in candidate source: ONLY the 4 known junctions are permitted
        if (Test-Path -LiteralPath $receipt.candidate_source) {
            Get-ChildItem -LiteralPath $receipt.candidate_source -Recurse -Force | ForEach-Object {
                if ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) {
                    $itemPath = [IO.Path]::GetFullPath($_.FullName)
                    if (-not $expectedJunctions.ContainsKey($itemPath)) {
                        throw "Unknown reparse point in candidate source rejected during preflight: $itemPath"
                    }
                    $targetProp = $_.Target
                    $resolvedTarget = if ($targetProp -is [array]) { $targetProp[0] } else { [string]$targetProp }
                    $expectedTarget = $expectedJunctions[$itemPath]
                    if ([IO.Path]::GetFullPath($resolvedTarget) -ne [IO.Path]::GetFullPath($expectedTarget)) {
                        throw "Junction target mismatch for $itemPath ($resolvedTarget vs $expectedTarget)"
                    }
                }
            }
        }

        foreach ($target in $receipt.pos_targets) {
            Assert-PosTreeSafe $target
        }

        if (Test-Path -LiteralPath $receipt.qz_root) {
            Assert-PosTreeSafe $receipt.qz_root
        }

        if (Test-Path -LiteralPath $receipt.application) {
            Assert-PosTreeSafe $receipt.application
        }

        if (Test-Path -LiteralPath $receipt.lab) {
            Assert-PosTreeSafe $receipt.lab
        }
    } catch {
        throw "Global cleanup preflight validation failed: $($_.Exception.Message)"
    }

    # Now proceed with teardown mutations

    # 1. Unlink private source 4 junctions ONLY, then remove candidate source
    if (Test-Path -LiteralPath $receipt.candidate_source) {
        try {
            foreach ($jPath in $expectedJunctions.Keys) {
                if (Test-Path -LiteralPath $jPath) {
                    [IO.Directory]::Delete($jPath, $false)
                }
            }
            Assert-PosTreeSafe $receipt.candidate_source
            Remove-Item -LiteralPath $receipt.candidate_source -Recurse -Force
            if (Test-Path -LiteralPath $receipt.candidate_source) {
                $cleanupErrors += "Candidate source still exists after removal: $($receipt.candidate_source)"
            }
        } catch {
            $cleanupErrors += "Failed cleaning candidate_source: $($_.Exception.Message)"
        }
    }

    # 2. Capture printing trust details from receipt BEFORE uninstaller removes it
    $printingTrust = $null
    $qzReceipts = @($receipt.pos_targets | ForEach-Object { Join-Path $_ 'qz-shared-install.json' } | Where-Object { Test-Path -LiteralPath $_ })
    if ($qzReceipts.Count -gt 0) {
        try {
            $qzOwner = Get-Content -LiteralPath $qzReceipts[0] -Raw | ConvertFrom-Json
            $qzCert = [Security.Cryptography.X509Certificates.X509Certificate2]::new([Convert]::FromBase64String($qzOwner.rootCertificateBase64))
            $printingTrust = @{
                Thumbprint = $qzCert.Thumbprint
                Files = @($qzOwner.files | ForEach-Object { Join-Path $receipt.qz_root $_.path })
            }
            $qzCert.Dispose()
        } catch {
            $cleanupErrors += "Failed reading QZ ownership receipt: $($_.Exception.Message)"
        }
    }

    # 3. Stop owned processes and assert ZERO remain before delete
    $ownedAppPrefix = [IO.Path]::GetFullPath($receipt.application).TrimEnd('\') + '\'
    $ownedLabPrefix = [IO.Path]::GetFullPath($receipt.lab).TrimEnd('\') + '\'
    $receiptCreatedUtc = if ($receipt.parsed_created_at_utc) { $receipt.parsed_created_at_utc } else { [DateTime]::Parse($receipt.created_at_utc).ToUniversalTime() }

    $filterOwnedProcess = {
        param($proc)
        if (-not $proc.ExecutablePath) { return $false }
        $fullExe = [IO.Path]::GetFullPath($proc.ExecutablePath)
        if (-not ($fullExe.StartsWith($ownedAppPrefix, [StringComparison]::OrdinalIgnoreCase) -or `
                  $fullExe.StartsWith($ownedLabPrefix, [StringComparison]::OrdinalIgnoreCase))) {
            return $false
        }
        if (-not $proc.CreationDate) { return $false }
        $pCreatedUtc = $proc.CreationDate.ToUniversalTime()
        return ($pCreatedUtc -ge $receiptCreatedUtc.AddSeconds(-2))
    }

    $procsToStop = @(Get-CimInstance Win32_Process | Where-Object { & $filterOwnedProcess $_ })
    foreach ($p in $procsToStop) {
        Write-Host "Stopping owned process PID=$($p.ProcessId) Started=$($p.CreationDate) Exe=$($p.ExecutablePath)"
        try {
            Stop-Process -Id $p.ProcessId -Force -ErrorAction Stop
        } catch {
            if ($_.Exception -isnot [System.Management.Automation.ProcessNotFoundException] -and `
                $_.Exception.Message -notmatch 'Cannot find a process with the process identifier' -and `
                $_.Exception.Message -notmatch 'No process found with ID') {
                $cleanupErrors += "Stop-Process failed for PID $($p.ProcessId): $($_.Exception.Message)"
            }
        }
        try {
            Wait-Process -Id $p.ProcessId -Timeout 10 -ErrorAction Stop
        } catch {
            if ($_.Exception -isnot [System.Management.Automation.ProcessNotFoundException] -and `
                $_.Exception.Message -notmatch 'Cannot find a process with the process identifier' -and `
                $_.Exception.Message -notmatch 'No process found with ID' -and `
                $_.Exception.Message -notmatch 'Process .+ has exited') {
                $cleanupErrors += "Wait-Process failed for PID $($p.ProcessId): $($_.Exception.Message)"
            }
        }
    }

    $deadline = (Get-Date).AddSeconds(15)
    $remainingOwned = @()
    do {
        $remainingOwned = @(Get-CimInstance Win32_Process | Where-Object { & $filterOwnedProcess $_ })
        if ($remainingOwned.Count -eq 0) { break }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)

    if ($remainingOwned.Count -gt 0) {
        $survivingInfo = ($remainingOwned | ForEach-Object { "PID=$($_.ProcessId) Exe=$($_.ExecutablePath)" }) -join '; '
        throw "Owned runtime processes survived shutdown before teardown: $survivingInfo"
    }

    # 4. Invoke trusted owned uninstaller using approved copy / _?= pattern
    $uninstaller = Join-Path $receipt.application 'Uninstall POS Desktop.exe'
    if (Test-Path -LiteralPath $uninstaller) {
        Write-Host "Running trusted owned uninstaller from $uninstaller..."
        $uninstallerCopy = Join-Path $receipt.evidence ('uninstall-' + [guid]::NewGuid().ToString('N') + '.exe')
        try {
            Copy-Item -LiteralPath $uninstaller -Destination $uninstallerCopy -Force
            $process = Start-Process -FilePath $uninstallerCopy -ArgumentList @('/S', '/currentuser', "_?=$($receipt.application)") -WindowStyle Hidden -PassThru
            if (-not $process.WaitForExit(180000)) {
                $cleanupErrors += 'Silent uninstall timed out.'
            } elseif ($process.ExitCode -ne 0) {
                $cleanupErrors += "Silent uninstall failed with exit code: $($process.ExitCode)"
            }
        } catch {
            $cleanupErrors += "Failed running uninstaller: $($_.Exception.Message)"
        } finally {
            if (Test-Path -LiteralPath $uninstallerCopy) {
                Remove-Item -LiteralPath $uninstallerCopy -Force -ErrorAction SilentlyContinue
            }
        }

        # Bounded wait for uninstall to finish removing data
        $uDeadline = (Get-Date).AddSeconds(45)
        do {
            $leftTargets = @($receipt.pos_targets | Where-Object { Test-Path -LiteralPath $_ })
            if (-not $leftTargets.Count -and -not (Test-Path -LiteralPath (Join-Path $receipt.application 'POS Desktop.exe'))) { break }
            Start-Sleep -Seconds 1
        } while ((Get-Date) -lt $uDeadline)
    } else {
        Write-Host "Uninstaller not present in $($receipt.application); running fallback safe cleanup of owned roots and printing trust."
        try {
            Remove-PosOwnedPrintingTrust -Targets $receipt.pos_targets
        } catch {
            $cleanupErrors += "Remove-PosOwnedPrintingTrust fallback failed: $($_.Exception.Message)"
        }
        try {
            Invoke-PosDataRemoval -Targets $receipt.pos_targets
        } catch {
            $cleanupErrors += "Invoke-PosDataRemoval fallback failed: $($_.Exception.Message)"
        }
    }

    # 5. Clean lab safely (preserve candidate-results.json in evidence first)
    if (Test-Path -LiteralPath $receipt.lab) {
        try {
            $labCandResults = Join-Path $receipt.lab 'candidate-results.json'
            $evidCandResults = Join-Path $receipt.evidence 'candidate-results.json'
            if ((Test-Path -LiteralPath $labCandResults) -and -not (Test-Path -LiteralPath $evidCandResults)) {
                Copy-Item -LiteralPath $labCandResults -Destination $evidCandResults -Force
            }

            Remove-Item -LiteralPath $receipt.lab -Recurse -Force
            if (Test-Path -LiteralPath $receipt.lab) {
                $cleanupErrors += "Lab directory still exists after removal: $($receipt.lab)"
            }
        } catch {
            $cleanupErrors += "Failed cleaning lab: $($_.Exception.Message)"
        }
    }

    # 6. Clean application directory if still present
    if (Test-Path -LiteralPath $receipt.application) {
        try {
            Remove-Item -LiteralPath $receipt.application -Recurse -Force
            if (Test-Path -LiteralPath $receipt.application) {
                $cleanupErrors += "Application directory still exists after removal: $($receipt.application)"
            }
        } catch {
            $cleanupErrors += "Failed removing application directory: $($_.Exception.Message)"
        }
    }

    # 7. Ensure secret purged
    Remove-Item Env:UPDATE_PRIVATE_KEY -ErrorAction SilentlyContinue
    [Environment]::SetEnvironmentVariable('UPDATE_PRIVATE_KEY', $null, 'Process')

    # 8. Post-cleanup verifications:

    # 8a. Verify exact 8 roots absent
    foreach ($target in $receipt.pos_targets) {
        if (Test-Path -LiteralPath $target) {
            $cleanupErrors += "POS data root still exists after removal: $target"
        }
    }

    # 8b. Verify 4 legacy registry keys absent
    foreach ($regPath in $receipt.registry_keys) {
        if (Test-Path -LiteralPath $regPath) {
            Remove-Item -LiteralPath $regPath -Recurse -Force -ErrorAction SilentlyContinue
            if (Test-Path -LiteralPath $regPath) {
                $cleanupErrors += "Registry key still exists after removal: $regPath"
            }
        }
    }

    # 8c. Verify actual fresh registration absent (rely on actual owned uninstaller; assert absence only)
    if ($owned -and $owned.uninstall_registry_key) {
        if (Test-Path -LiteralPath $owned.uninstall_registry_key) {
            $cleanupErrors += "Fresh POS Desktop uninstall registration retained: $($owned.uninstall_registry_key)"
        }
    }
    $survivingRegs = @(Get-PosDesktopUninstallRegistrations | Where-Object {
        ($_.InstallLocation -and [IO.Path]::GetFullPath($_.InstallLocation).TrimEnd('\') -eq $ownedAppPrefix.TrimEnd('\')) -or `
        ($_.UninstallString -and $_.UninstallString.IndexOf($receipt.application, [StringComparison]::OrdinalIgnoreCase) -ge 0)
    })
    if ($survivingRegs.Count -gt 0) {
        $cleanupErrors += "Uninstall registration still bound to application: $(($survivingRegs | ForEach-Object { $_.KeyPath }) -join '; ')"
    }

    # 8d. Verify desktop and start menu shortcuts absent
    $desktopShortcut = Join-Path ([Environment]::GetFolderPath('Desktop')) 'POS Desktop.lnk'
    if (Test-Path -LiteralPath $desktopShortcut) {
        try {
            $shell = New-Object -ComObject WScript.Shell
            $link = $shell.CreateShortcut($desktopShortcut)
            if ($link.TargetPath -and $link.TargetPath.StartsWith($ownedAppPrefix, [StringComparison]::OrdinalIgnoreCase)) {
                Remove-Item -LiteralPath $desktopShortcut -Force -ErrorAction SilentlyContinue
            }
        } catch {}
        if (Test-Path -LiteralPath $desktopShortcut) {
            $cleanupErrors += "Desktop shortcut retained after cleanup: $desktopShortcut"
        }
    }

    $startMenuShortcuts = @(
        (Join-Path ([Environment]::GetFolderPath('Programs')) 'POS Desktop.lnk'),
        (Join-Path (Join-Path ([Environment]::GetFolderPath('Programs')) 'POS Desktop') 'POS Desktop.lnk')
    )
    foreach ($smShortcut in $startMenuShortcuts) {
        if (Test-Path -LiteralPath $smShortcut) {
            try {
                $shell = New-Object -ComObject WScript.Shell
                $link = $shell.CreateShortcut($smShortcut)
                if ($link.TargetPath -and $link.TargetPath.StartsWith($ownedAppPrefix, [StringComparison]::OrdinalIgnoreCase)) {
                    Remove-Item -LiteralPath $smShortcut -Force -ErrorAction SilentlyContinue
                }
            } catch {}
            if (Test-Path -LiteralPath $smShortcut) {
                $cleanupErrors += "Start Menu shortcut retained after cleanup: $smShortcut"
            }
        }
    }

    # 8e. Verify firewall rules absent
    foreach ($name in $knownFirewallRules) {
        try {
            $rules = @(Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue)
            if ($rules.Count -gt 0) {
                try { $rules | Remove-NetFirewallRule -ErrorAction Stop } catch {}
                $remainingRules = @(Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue)
                if ($remainingRules.Count -gt 0) {
                    $cleanupErrors += "Firewall rule retained after cleanup: $name"
                }
            }
        } catch {}
    }
    try {
        Assert-PosFirewallRulesAbsent -Names $knownFirewallRules
    } catch {
        $cleanupErrors += "Firewall cleanup could not be verified: $($_.Exception.Message)"
    }

    # 8f. Verify printing trust files and certificates absent
    if ($printingTrust) {
        foreach ($file in $printingTrust.Files) {
            if (Test-Path -LiteralPath $file) {
                $cleanupErrors += "Uninstall retained owned printing material: $file"
            }
        }
        foreach ($store in @('Cert:\CurrentUser\Root', 'Cert:\LocalMachine\Root')) {
            if (Test-Path -LiteralPath (Join-Path $store $printingTrust.Thumbprint)) {
                $cleanupErrors += "Uninstall retained the owned printing root certificate in $store"
            }
        }
        if (Test-Path -LiteralPath "Registry::HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\EnterpriseCertificates\Root\Certificates\$($printingTrust.Thumbprint)") {
            $cleanupErrors += "Uninstall retained the owned Enterprise printing root certificate"
        }
    }
    if ($receipt.qz_precheck_absent -and (Test-Path -LiteralPath $receipt.qz_root)) {
        $remainingQzFiles = @(Get-ChildItem -LiteralPath $receipt.qz_root -Recurse -File -Force -ErrorAction SilentlyContinue)
        if ($remainingQzFiles.Count -gt 0) {
            $cleanupErrors += "Shared QZ root contains files after cleanup: $(($remainingQzFiles | ForEach-Object { $_.FullName }) -join ', ')"
        }
    }

    # 8g. Verify candidate source, lab, and application absent
    if (Test-Path -LiteralPath $receipt.candidate_source) {
        $cleanupErrors += "Candidate source still exists after removal: $($receipt.candidate_source)"
    }
    if (Test-Path -LiteralPath $receipt.lab) {
        $cleanupErrors += "Lab still exists after removal: $($receipt.lab)"
    }
    if (Test-Path -LiteralPath $receipt.application) {
        $cleanupErrors += "Application still exists after removal: $($receipt.application)"
    }

    # Aggregate cleanup failures: throw non-zero! No silent continue false green!
    if ($cleanupErrors.Count -gt 0) {
        throw ("Cleanup verification failed:`n" + ($cleanupErrors -join "`n"))
    }

    # 9. Success Finalizer: ONLY write final acceptance.json if assertions AND cleanup both passed
    $candidateResultsFile = Join-Path $receipt.evidence 'candidate-results.json'
    if (-not (Test-Path -LiteralPath $candidateResultsFile)) {
        throw "candidate-results.json missing in evidence ($candidateResultsFile); cannot finalize acceptance."
    }

    $cRes = Get-Content -LiteralPath $candidateResultsFile -Raw | ConvertFrom-Json
    if ($cRes.run_token -ne $receipt.run_token) {
        throw "candidate-results.json run_token ($($cRes.run_token)) does not match receipt ($($receipt.run_token))"
    }

    $reqBools = @(
        'bug1_setup12_source_inventory_mismatch_reproduced',
        'candidate13_silent_upgrade',
        'candidate13_shell_root_preserved',
        'bug1_candidate13_stable_to_beta_transition',
        'bug1_candidate13_beta_to_stable_transition',
        'bug2_desktop_sql_restore_success',
        'bug2_desktop_sql_restore_rollback_and_error_classification',
        'database_integrity_verified',
        'session_preservation_on_error_verified',
        'indexeddb_offline_preserved',
        'protected_state_preserved',
        'repeated_channel_roundtrip_verified'
    )

    foreach ($b in $reqBools) {
        if ($cRes.verifications.$b -ne $true) {
            throw "Required verification boolean '$b' is not true in candidate-results.json"
        }
    }

    $finalAcceptance = [ordered]@{
        schema_version = '1.0.0'
        verdict = 'PASSED'
        scope = 'packaged'
        run_id = $receipt.github_run_id
        run_attempt = $receipt.github_run_attempt
        run_token = $receipt.run_token
        cleanup_verified = $true
        completed_at = (Get-Date).ToUniversalTime().ToString('o')
        verifications = $cRes.verifications
        details = $cRes.details
    }
    $acceptanceJsonPath = Join-Path $receipt.evidence 'acceptance.json'
    $finalAcceptance | ConvertTo-Json -Depth 16 | Set-Content -LiteralPath $acceptanceJsonPath
    Write-Host "Acceptance finalized and verified: $acceptanceJsonPath"

    Remove-Item -LiteralPath $ownershipReceiptPath -Force -ErrorAction SilentlyContinue
    Write-Host 'Cleanup phase completed successfully.'
}
