[CmdletBinding()]
param(
    [switch] $DebugGui,
    [switch] $NoStaRelaunch
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-GuiDebug {
    param([string] $Message)
    if ($DebugGui) {
        Write-Host "[release-gui] $Message"
    }
}

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$ScriptRoot = Split-Path -Parent $PSCommandPath
$RepoRoot = Resolve-Path (Join-Path $ScriptRoot '..')
$ReleaseScript = Join-Path $ScriptRoot 'release.ps1'
$ConfigPath = Join-Path $ScriptRoot 'release-manager.config.json'

function Show-StartupError {
    param([object] $ErrorRecord)

    $message = "Release Manager failed to start.`n`n$($ErrorRecord.Exception.Message)"
    Write-Error $ErrorRecord.Exception.ToString()
    if ($ErrorRecord.ScriptStackTrace) {
        Write-Host 'Script stack trace:'
        Write-Host $ErrorRecord.ScriptStackTrace
    }

    try {
        [System.Windows.Forms.MessageBox]::Show($message, 'POS Release Manager Startup Error', 'OK', 'Error') | Out-Null
    } catch {
        Write-Host $message
    }

    if ($DebugGui -or [Environment]::UserInteractive) {
        Write-Host 'Press Enter to close...'
        try { [void] [Console]::ReadLine() } catch {}
    }
}

try {
    Write-GuiDebug "Script root: $ScriptRoot"
    Write-GuiDebug "Apartment state: $([Threading.Thread]::CurrentThread.ApartmentState)"

    if (([Threading.Thread]::CurrentThread.ApartmentState -ne 'STA') -and -not $NoStaRelaunch) {
        $argumentList = @('-NoProfile', '-STA', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath)
        if ($DebugGui) {
            $argumentList += '-DebugGui'
            Write-GuiDebug "Relaunching in STA: powershell.exe $($argumentList -join ' ')"
        }
        Start-Process -FilePath 'powershell.exe' -ArgumentList $argumentList -WorkingDirectory $RepoRoot
        return
    }

    if (-not (Test-Path $ReleaseScript -PathType Leaf)) {
        throw "Missing release script: $ReleaseScript"
    }
    if (-not (Test-Path $ConfigPath -PathType Leaf)) {
        throw "Missing release manager config: $ConfigPath"
    }

    Write-GuiDebug "Release script: $ReleaseScript"
    Write-GuiDebug "Config path: $ConfigPath"

$env:RELEASE_MANAGER_TEST_IMPORT = '1'
Write-GuiDebug 'Importing release.ps1 helpers'
. $ReleaseScript
Remove-Item Env:\RELEASE_MANAGER_TEST_IMPORT -ErrorAction SilentlyContinue
Write-GuiDebug 'Imported release.ps1 helpers'

$script:CurrentProcess = $null
$script:CommandRunning = $false

function New-Font {
    param(
        [float] $Size,
        [System.Drawing.FontStyle] $Style = [System.Drawing.FontStyle]::Regular
    )
    [System.Drawing.Font]::new('Segoe UI', $Size, $Style)
}

function Add-Log {
    param([string] $Message)

    $line = "[{0}] {1}{2}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message, [Environment]::NewLine
    if ($script:LogBox.InvokeRequired) {
        $script:LogBox.BeginInvoke([Action[string]]{ param($text) $script:LogBox.AppendText($text) }, $line) | Out-Null
    } else {
        $script:LogBox.AppendText($line)
    }
}

function Format-ProcessArgument {
    param([string] $Value)

    if ($Value -notmatch '[\s"]') {
        return $Value
    }
    '"' + ($Value -replace '"', '\"') + '"'
}

function Invoke-ReleaseCommand {
    param(
        [string[]] $Arguments,
        [string] $Description
    )

    if ($script:CommandRunning) {
        [System.Windows.Forms.MessageBox]::Show('Another release command is already running.', 'Release Manager', 'OK', 'Warning') | Out-Null
        return
    }

    $argumentList = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $ReleaseScript) + $Arguments
    $argumentText = ($argumentList | ForEach-Object { Format-ProcessArgument $_ }) -join ' '

    Add-Log "COMMAND: powershell.exe $argumentText"
    $script:CommandRunning = $true
    $script:RunStatusLabel.Text = "Running: $Description"

    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo.FileName = 'powershell.exe'
    $process.StartInfo.Arguments = $argumentText
    $process.StartInfo.WorkingDirectory = $RepoRoot
    $process.StartInfo.UseShellExecute = $false
    $process.StartInfo.RedirectStandardOutput = $true
    $process.StartInfo.RedirectStandardError = $true
    $process.StartInfo.CreateNoWindow = $true
    $process.EnableRaisingEvents = $true

    Register-ObjectEvent -InputObject $process -EventName OutputDataReceived -Action {
        if ($EventArgs.Data) {
            Add-Log "OUT: $($EventArgs.Data)"
        }
    } | Out-Null
    Register-ObjectEvent -InputObject $process -EventName ErrorDataReceived -Action {
        if ($EventArgs.Data) {
            Add-Log "ERR: $($EventArgs.Data)"
        }
    } | Out-Null
    Register-ObjectEvent -InputObject $process -EventName Exited -Action {
        $code = $Event.Sender.ExitCode
        Add-Log "EXIT: $code"
        $script:CommandRunning = $false
        $script:CurrentProcess = $null
        $script:RunStatusLabel.BeginInvoke([Action]{
            if ($code -eq 0) {
                $script:RunStatusLabel.Text = 'Last command completed successfully'
            } else {
                $script:RunStatusLabel.Text = "Last command failed with exit code $code"
            }
            Refresh-StatusPanel
            Refresh-CommitFiles
        }) | Out-Null
    } | Out-Null

    $script:CurrentProcess = $process
    if (-not $process.Start()) {
        $script:CommandRunning = $false
        throw "Failed to start command: $Description"
    }
    $process.BeginOutputReadLine()
    $process.BeginErrorReadLine()
}

function New-Button {
    param(
        [string] $Text,
        [int] $Width = 150
    )

    $button = [System.Windows.Forms.Button]::new()
    $button.Text = $Text
    $button.Width = $Width
    $button.Height = 32
    $button.Margin = [System.Windows.Forms.Padding]::new(6)
    $button
}

function Add-StatusRow {
    param(
        [System.Windows.Forms.TableLayoutPanel] $Table,
        [string] $Name,
        [string] $Key
    )

    $row = $Table.RowCount
    $Table.RowCount += 1
    $Table.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::AutoSize)) | Out-Null

    $label = [System.Windows.Forms.Label]::new()
    $label.Text = $Name
    $label.AutoSize = $true
    $label.Font = New-Font 9 ([System.Drawing.FontStyle]::Bold)
    $label.Margin = [System.Windows.Forms.Padding]::new(4, 7, 16, 4)

    $value = [System.Windows.Forms.Label]::new()
    $value.Text = '(loading)'
    $value.AutoSize = $true
    $value.Margin = [System.Windows.Forms.Padding]::new(4, 7, 4, 4)

    $Table.Controls.Add($label, 0, $row)
    $Table.Controls.Add($value, 1, $row)
    $script:StatusLabels[$Key] = $value
}

function Get-LatestYmlVersion {
    $latestPath = Join-Path $DistPath 'latest.yml'
    if (-not (Test-Path $latestPath -PathType Leaf)) {
        return '(missing)'
    }
    $latestText = Read-Utf8TextFile $latestPath
    if ($latestText -match '(?m)^version:\s*(.+?)\s*$') {
        return $Matches[1]
    }
    '(unreadable)'
}

function Refresh-StatusPanel {
    try {
        $packageVersion = Get-PackageVersion
        $versionJsonVersion = Get-VersionJsonVersion
        $lockVersions = Get-PackageLockVersions
        $installer = Get-InstallerForVersion $packageVersion -AllowMissing
        $blockMapExists = $false
        if ($installer) {
            $blockMapExists = Test-Path ($installer.FullName + '.blockmap') -PathType Leaf
        }
        $gitStatus = @(Get-GitStatusLines)

        $script:StatusLabels['package'].Text = $packageVersion
        $script:StatusLabels['versionJson'].Text = $versionJsonVersion
        $script:StatusLabels['lock'].Text = if ($lockVersions) { "$($lockVersions.version) / root $($lockVersions.rootVersion)" } else { '(missing)' }
        $script:StatusLabels['branch'].Text = Get-GitBranch
        $script:StatusLabels['dirty'].Text = if ($gitStatus.Count -gt 0) { 'dirty' } else { 'clean' }
        $script:StatusLabels['token'].Text = if ([string]::IsNullOrWhiteSpace($env:GH_TOKEN)) { 'missing' } else { 'found' }
        $script:StatusLabels['phar'].Text = if (Test-Path $PharPath -PathType Leaf) { 'exists' } else { 'missing' }
        $script:StatusLabels['dist'].Text = if (Test-Path $DistPath -PathType Container) { 'exists' } else { 'missing' }
        $script:StatusLabels['latest'].Text = "$(if (Test-Path (Join-Path $DistPath 'latest.yml') -PathType Leaf) { 'exists' } else { 'missing' }) / version $(Get-LatestYmlVersion)"
        $script:StatusLabels['installer'].Text = if ($installer) { "$($installer.Name) / blockmap $blockMapExists" } else { 'missing' }
        $script:StatusLabels['running'].Text = if (Test-PosRunning) { 'running' } else { 'not running' }
        $script:StatusLabels['sync'].Text = if ($packageVersion -eq $versionJsonVersion) { 'in sync' } else { "mismatch: package $packageVersion, version.json $versionJsonVersion" }
        Write-GuiDebug 'Status panel loaded'
        Add-Log 'Status refreshed'
    } catch {
        Add-Log "STATUS ERROR: $($_.Exception.Message)"
    }
}

function Get-GuiTargetVersion {
    $current = Get-PackageVersion
    $parts = $current.Split('.') | ForEach-Object { [int] $_ }
    if ($script:PatchRadio.Checked) {
        $parts[2] += 1
    } elseif ($script:MinorRadio.Checked) {
        $parts[1] += 1
        $parts[2] = 0
    } elseif ($script:MajorRadio.Checked) {
        $parts[0] += 1
        $parts[1] = 0
        $parts[2] = 0
    } else {
        $custom = $script:CustomVersionBox.Text.Trim()
        if ($custom -notmatch '^\d+\.\d+\.\d+$') {
            throw "Invalid custom version: $custom"
        }
        return $custom
    }
    "$($parts[0]).$($parts[1]).$($parts[2])"
}

function Refresh-VersionPreview {
    try {
        $current = Get-PackageVersion
        $target = Get-GuiTargetVersion
        $script:VersionPreviewBox.Text = @(
            "Current package.json: $current",
            "Current version.json: $(Get-VersionJsonVersion)",
            "Target version: $target",
            "Files: package.json, version.json, package-lock.json when present",
            "released_at update: $(if ($script:ReleasedAtCheck.Checked) { 'yes' } else { 'no' })"
        ) -join [Environment]::NewLine
    } catch {
        $script:VersionPreviewBox.Text = $_.Exception.Message
    }
}

function Confirm-YesNo {
    param(
        [string] $Message,
        [string] $Title = 'Release Manager'
    )

    [System.Windows.Forms.MessageBox]::Show($Message, $Title, 'YesNo', 'Question') -eq [System.Windows.Forms.DialogResult]::Yes
}

function Invoke-VersionSync {
    try {
        $target = Get-GuiTargetVersion
        $args = @('-Mode', 'sync-version', '-Version', $target, '-NonInteractive')
        if ($script:ReleasedAtCheck.Checked) {
            $args += '-UpdateReleasedAt'
        }
        Invoke-ReleaseCommand $args "Sync version files to $target"
    } catch {
        [System.Windows.Forms.MessageBox]::Show($_.Exception.Message, 'Version Error', 'OK', 'Error') | Out-Null
    }
}

function Refresh-CommitFiles {
    try {
        $script:CommitList.Items.Clear()
        $classified = @(Get-ClassifiedChangedFiles (Get-GitStatusLines))
        foreach ($item in $classified) {
            $listItem = [System.Windows.Forms.ListViewItem]::new($item.Path)
            $listItem.SubItems.Add($item.Classification) | Out-Null
            $listItem.SubItems.Add($item.Status) | Out-Null
            $listItem.Tag = $item
            if ($item.Classification -eq 'blocked') {
                $listItem.ForeColor = [System.Drawing.Color]::Gray
                $listItem.BackColor = [System.Drawing.Color]::FromArgb(245, 245, 245)
                $listItem.ToolTipText = 'Blocked files can never be selected for release commits.'
            } elseif ($item.Classification -eq 'warning') {
                $listItem.ForeColor = [System.Drawing.Color]::FromArgb(130, 80, 0)
                $listItem.BackColor = [System.Drawing.Color]::FromArgb(255, 248, 230)
            } else {
                $listItem.ForeColor = [System.Drawing.Color]::FromArgb(0, 95, 55)
                $listItem.BackColor = [System.Drawing.Color]::FromArgb(236, 250, 242)
            }
            $script:CommitList.Items.Add($listItem) | Out-Null
        }
        Write-GuiDebug "Commit file list loaded: $($classified.Count)"
        Add-Log "Commit files refreshed: $($classified.Count)"
    } catch {
        Add-Log "COMMIT REFRESH ERROR: $($_.Exception.Message)"
    }
}

function Set-CommitCheckedState {
    param(
        [bool] $IncludeWarning
    )

    foreach ($item in $script:CommitList.Items) {
        $classification = $item.Tag.Classification
        $item.Checked = ($classification -eq 'safe' -or ($IncludeWarning -and $classification -eq 'warning'))
    }
}

function Invoke-GuiCommit {
    $message = $script:CommitMessageBox.Text.Trim()
    if ([string]::IsNullOrWhiteSpace($message)) {
        [System.Windows.Forms.MessageBox]::Show('Commit message is required.', 'Commit', 'OK', 'Warning') | Out-Null
        return
    }

    $selected = @()
    foreach ($item in $script:CommitList.CheckedItems) {
        if ($item.Tag.Classification -eq 'blocked') {
            Add-Log "BLOCKED SELECTION REMOVED: $($item.Tag.Path)"
            $item.Checked = $false
        } else {
            $selected += [string] $item.Tag.Path
        }
    }

    if ($selected.Count -eq 0) {
        [System.Windows.Forms.MessageBox]::Show('Select at least one safe or warning file.', 'Commit', 'OK', 'Warning') | Out-Null
        return
    }

    $args = @('-Mode', 'commit', '-CommitMessage', $message, '-NonInteractive', '-Files', ($selected -join ','))
    Invoke-ReleaseCommand $args "Commit $($selected.Count) selected file(s)"
}

function Invoke-ConfirmReleaseCommand {
    param(
        [string] $Title,
        [string[]] $Arguments,
        [string] $Summary,
        [switch] $RequiresToken
    )

    if ($RequiresToken -and [string]::IsNullOrWhiteSpace($env:GH_TOKEN)) {
        [System.Windows.Forms.MessageBox]::Show('GH_TOKEN is required for this publish action.', $Title, 'OK', 'Warning') | Out-Null
        return
    }
    if (-not (Confirm-YesNo $Summary $Title)) {
        Add-Log "Cancelled: $Title"
        return
    }
    Invoke-ReleaseCommand $Arguments $Title
}

function Refresh-RollbackCommits {
    try {
        $script:RollbackList.Items.Clear()
        Add-Log 'COMMAND: git log -n 30'
        $lines = & git -C $RepoRoot log -n 30 --date=short --pretty=format:'%H%x09%h%x09%ad%x09%an%x09%s'
        if ($LASTEXITCODE -ne 0) {
            Add-Log 'ROLLBACK ERROR: git log failed'
            return
        }

        foreach ($line in $lines) {
            $parts = $line -split "`t", 5
            if ($parts.Count -lt 5) {
                continue
            }
            $item = [System.Windows.Forms.ListViewItem]::new($parts[1])
            $item.SubItems.Add($parts[2]) | Out-Null
            $item.SubItems.Add($parts[3]) | Out-Null
            $item.SubItems.Add($parts[4]) | Out-Null
            $item.Tag = $parts[0]
            $script:RollbackList.Items.Add($item) | Out-Null
        }
        Add-Log "Rollback commits loaded: $($script:RollbackList.Items.Count)"
    } catch {
        Add-Log "ROLLBACK REFRESH ERROR: $($_.Exception.Message)"
    }
}

function Invoke-SelectedRollback {
    if ($script:RollbackList.SelectedItems.Count -eq 0) {
        [System.Windows.Forms.MessageBox]::Show('Select one commit to revert.', 'Rollback', 'OK', 'Warning') | Out-Null
        return
    }

    $item = $script:RollbackList.SelectedItems[0]
    $hash = [string] $item.Tag
    $message = [string] $item.SubItems[3].Text
    if (-not (Confirm-YesNo "Create a new rollback commit that reverts:`n`n$($item.Text) - $message`n`nThis uses git revert, not history rewriting. Continue?" 'Revert Commit')) {
        return
    }

    Invoke-ReleaseCommand @('-Mode', 'rollback-revert', '-Commit', $hash, '-NonInteractive') "Revert commit $($item.Text)"
}

function Invoke-RangeRollback {
    $from = $script:RollbackFromBox.Text.Trim()
    $to = $script:RollbackToBox.Text.Trim()
    if ([string]::IsNullOrWhiteSpace($from) -or [string]::IsNullOrWhiteSpace($to)) {
        [System.Windows.Forms.MessageBox]::Show('Enter both From and To commit hashes.', 'Rollback Range', 'OK', 'Warning') | Out-Null
        return
    }

    if (-not (Confirm-YesNo "Create rollback commits for range:`n`n$from..$to`n`nThis uses git revert, not history rewriting. Continue?" 'Revert Range')) {
        return
    }

    Invoke-ReleaseCommand @('-Mode', 'rollback-range', '-FromCommit', $from, '-ToCommit', $to, '-NonInteractive') "Revert range $from..$to"
}

Write-GuiDebug 'Building main form'
$form = [System.Windows.Forms.Form]::new()
$form.Text = 'POS Release Manager'
$form.StartPosition = 'CenterScreen'
$form.Size = [System.Drawing.Size]::new(1120, 760)
$form.MinimumSize = [System.Drawing.Size]::new(980, 680)
$form.Font = New-Font 9

$main = [System.Windows.Forms.TableLayoutPanel]::new()
$main.Dock = 'Fill'
$main.ColumnCount = 1
$main.RowCount = 3
$main.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::Absolute, 44)) | Out-Null
$main.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::Percent, 68)) | Out-Null
$main.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::Percent, 32)) | Out-Null
$form.Controls.Add($main)

$header = [System.Windows.Forms.Panel]::new()
$header.Dock = 'Fill'
$title = [System.Windows.Forms.Label]::new()
$title.Text = 'POS Release Manager'
$title.Font = New-Font 14 ([System.Drawing.FontStyle]::Bold)
$title.AutoSize = $true
$title.Location = [System.Drawing.Point]::new(12, 10)
$script:RunStatusLabel = [System.Windows.Forms.Label]::new()
$script:RunStatusLabel.Text = 'Ready'
$script:RunStatusLabel.AutoSize = $true
$script:RunStatusLabel.Location = [System.Drawing.Point]::new(250, 14)
$header.Controls.AddRange(@($title, $script:RunStatusLabel))
$main.Controls.Add($header, 0, 0)

$tabs = [System.Windows.Forms.TabControl]::new()
$tabs.Dock = 'Fill'
$main.Controls.Add($tabs, 0, 1)

$script:LogBox = [System.Windows.Forms.TextBox]::new()
$script:LogBox.Multiline = $true
$script:LogBox.ScrollBars = 'Both'
$script:LogBox.ReadOnly = $true
$script:LogBox.Dock = 'Fill'
$script:LogBox.Font = [System.Drawing.Font]::new('Consolas', 9)
$main.Controls.Add($script:LogBox, 0, 2)

Write-GuiDebug 'Building Status tab'
$statusTab = [System.Windows.Forms.TabPage]::new('Status')
$statusLayout = [System.Windows.Forms.TableLayoutPanel]::new()
$statusLayout.Dock = 'Fill'
$statusLayout.ColumnCount = 2
$statusLayout.RowCount = 1
$statusLayout.ColumnStyles.Add([System.Windows.Forms.ColumnStyle]::new([System.Windows.Forms.SizeType]::Percent, 36)) | Out-Null
$statusLayout.ColumnStyles.Add([System.Windows.Forms.ColumnStyle]::new([System.Windows.Forms.SizeType]::Percent, 64)) | Out-Null
$script:StatusLabels = @{}
$statusTable = [System.Windows.Forms.TableLayoutPanel]::new()
$statusTable.Dock = 'Fill'
$statusTable.ColumnCount = 2
$statusTable.AutoScroll = $true
foreach ($row in @(
    @('package.json version', 'package'),
    @('version.json version', 'versionJson'),
    @('package-lock version', 'lock'),
    @('Git branch', 'branch'),
    @('Git status', 'dirty'),
    @('GH_TOKEN', 'token'),
    @('backend.phar', 'phar'),
    @('dist-electron', 'dist'),
    @('latest.yml', 'latest'),
    @('installer/blockmap', 'installer'),
    @('POS System.exe', 'running'),
    @('version sync', 'sync')
)) {
    Add-StatusRow $statusTable $row[0] $row[1]
}
$statusButtons = [System.Windows.Forms.FlowLayoutPanel]::new()
$statusButtons.Dock = 'Top'
$refreshStatus = New-Button 'Refresh Status'
$refreshStatus.Add_Click({ Refresh-StatusPanel })
$openConfig = New-Button 'Open Config'
$openConfig.Add_Click({ Invoke-Item $ConfigPath })
$statusButtons.Controls.AddRange(@($refreshStatus, $openConfig))
$statusLayout.Controls.Add($statusTable, 0, 0)
$statusLayout.Controls.Add($statusButtons, 1, 0)
$statusTab.Controls.Add($statusLayout)
$tabs.TabPages.Add($statusTab)

Write-GuiDebug 'Building Version tab'
$versionTab = [System.Windows.Forms.TabPage]::new('Version')
$versionPanel = [System.Windows.Forms.FlowLayoutPanel]::new()
$versionPanel.Dock = 'Fill'
$versionPanel.FlowDirection = 'TopDown'
$versionPanel.WrapContents = $false
$script:PatchRadio = [System.Windows.Forms.RadioButton]::new(); $script:PatchRadio.Text = 'Patch bump'; $script:PatchRadio.Checked = $true
$script:MinorRadio = [System.Windows.Forms.RadioButton]::new(); $script:MinorRadio.Text = 'Minor bump'
$script:MajorRadio = [System.Windows.Forms.RadioButton]::new(); $script:MajorRadio.Text = 'Major bump'
$script:CustomRadio = [System.Windows.Forms.RadioButton]::new(); $script:CustomRadio.Text = 'Custom version'
$script:CustomVersionBox = [System.Windows.Forms.TextBox]::new(); $script:CustomVersionBox.Width = 160
$script:ReleasedAtCheck = [System.Windows.Forms.CheckBox]::new(); $script:ReleasedAtCheck.Text = 'Update released_at in version.json'; $script:ReleasedAtCheck.Width = 260
$script:VersionPreviewBox = [System.Windows.Forms.TextBox]::new(); $script:VersionPreviewBox.Multiline = $true; $script:VersionPreviewBox.ReadOnly = $true; $script:VersionPreviewBox.Width = 640; $script:VersionPreviewBox.Height = 150
$previewVersion = New-Button 'Preview'
$previewVersion.Add_Click({ Refresh-VersionPreview })
$syncVersion = New-Button 'Sync Version Files'
$syncVersion.Add_Click({ Invoke-VersionSync })
foreach ($control in @($script:PatchRadio, $script:MinorRadio, $script:MajorRadio, $script:CustomRadio, $script:CustomVersionBox, $script:ReleasedAtCheck, $previewVersion, $syncVersion, $script:VersionPreviewBox)) {
    $versionPanel.Controls.Add($control)
}
$versionTab.Controls.Add($versionPanel)
$tabs.TabPages.Add($versionTab)

Write-GuiDebug 'Building Build tab'
$buildTab = [System.Windows.Forms.TabPage]::new('Build')
$buildButtons = [System.Windows.Forms.FlowLayoutPanel]::new()
$buildButtons.Dock = 'Fill'
$buildButtons.WrapContents = $true
$buildFrontend = New-Button 'Build Frontend'
$buildFrontend.Add_Click({ Invoke-ReleaseCommand @('-Mode', 'build-frontend', '-NonInteractive') 'Build frontend' })
$buildPhar = New-Button 'Build PHAR'
$buildPhar.Add_Click({ Invoke-ReleaseCommand @('-Mode', 'build-phar', '-NonInteractive') 'Build PHAR' })
$buildElectron = New-Button 'Build Electron'
$buildElectron.Add_Click({ Invoke-ReleaseCommand @('-Mode', 'build-electron', '-NonInteractive') 'Build Electron installer' })
$buildAll = New-Button 'Full Local Build'
$buildAll.Add_Click({ Invoke-ReleaseCommand @('-Mode', 'build', '-NonInteractive') 'Full local build' })
$cleanDistButton = New-Button 'Clean dist-electron'
$cleanDistButton.Add_Click({
    if (Confirm-YesNo 'Delete dist-electron build artifacts? Backend data/log/storage folders will not be touched.' 'Clean dist-electron') {
        Invoke-ReleaseCommand @('-Mode', 'clean', '-KeepPhar', '-NonInteractive') 'Clean dist-electron'
    }
})
$verifyArtifacts = New-Button 'Verify Artifacts'
$verifyArtifacts.Add_Click({ Invoke-ReleaseCommand @('-Mode', 'verify-artifacts', '-NonInteractive') 'Verify local artifacts' })
$buildButtons.Controls.AddRange(@($buildFrontend, $buildPhar, $buildElectron, $buildAll, $cleanDistButton, $verifyArtifacts))
$buildTab.Controls.Add($buildButtons)
$tabs.TabPages.Add($buildTab)

Write-GuiDebug 'Building Commit tab'
$commitTab = [System.Windows.Forms.TabPage]::new('Commit')
$commitLayout = [System.Windows.Forms.TableLayoutPanel]::new()
$commitLayout.Dock = 'Fill'
$commitLayout.RowCount = 3
$commitLayout.ColumnCount = 1
$commitLayout.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::Absolute, 46)) | Out-Null
$commitLayout.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::Percent, 100)) | Out-Null
$commitLayout.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::Absolute, 58)) | Out-Null
$commitToolbar = [System.Windows.Forms.FlowLayoutPanel]::new()
$commitToolbar.Dock = 'Fill'
$refreshCommit = New-Button 'Refresh Files'
$refreshCommit.Add_Click({ Refresh-CommitFiles })
$selectSafe = New-Button 'Select Safe'
$selectSafe.Add_Click({ Set-CommitCheckedState $false })
$selectSafeWarning = New-Button 'Select Safe + Warning'
$selectSafeWarning.Add_Click({ Set-CommitCheckedState $true })
$clearCommit = New-Button 'Clear'
$clearCommit.Add_Click({ foreach ($item in $script:CommitList.Items) { $item.Checked = $false } })
$commitToolbar.Controls.AddRange(@($refreshCommit, $selectSafe, $selectSafeWarning, $clearCommit))
$script:CommitList = [System.Windows.Forms.ListView]::new()
$script:CommitList.Dock = 'Fill'
$script:CommitList.View = 'Details'
$script:CommitList.CheckBoxes = $true
$script:CommitList.FullRowSelect = $true
$script:CommitList.GridLines = $true
$script:CommitList.Columns.Add('File', 620) | Out-Null
$script:CommitList.Columns.Add('Class', 110) | Out-Null
$script:CommitList.Columns.Add('Git', 80) | Out-Null
$script:CommitList.Add_ItemCheck({
    param($sender, $eventArgs)
    $item = $sender.Items[$eventArgs.Index]
    if ($item.Tag -and $item.Tag.Classification -eq 'blocked' -and $eventArgs.NewValue -eq [System.Windows.Forms.CheckState]::Checked) {
        $eventArgs.NewValue = [System.Windows.Forms.CheckState]::Unchecked
        Add-Log "BLOCKED: $($item.Tag.Path) cannot be selected"
    }
})
$commitBottom = [System.Windows.Forms.FlowLayoutPanel]::new()
$commitBottom.Dock = 'Fill'
$script:CommitMessageBox = [System.Windows.Forms.TextBox]::new()
$script:CommitMessageBox.Width = 600
$commitMessageLabel = [System.Windows.Forms.Label]::new()
$commitMessageLabel.Text = 'Commit message'
$commitMessageLabel.AutoSize = $true
$commitMessageLabel.Margin = [System.Windows.Forms.Padding]::new(6, 10, 4, 4)
$commitButton = New-Button 'Commit Selected'
$commitButton.Add_Click({ Invoke-GuiCommit })
$commitBottom.Controls.AddRange(@($commitMessageLabel, $script:CommitMessageBox, $commitButton))
$commitLayout.Controls.Add($commitToolbar, 0, 0)
$commitLayout.Controls.Add($script:CommitList, 0, 1)
$commitLayout.Controls.Add($commitBottom, 0, 2)
$commitTab.Controls.Add($commitLayout)
$tabs.TabPages.Add($commitTab)

Write-GuiDebug 'Building Publish tab'
$publishTab = [System.Windows.Forms.TabPage]::new('Publish')
$publishButtons = [System.Windows.Forms.FlowLayoutPanel]::new()
$publishButtons.Dock = 'Fill'
$repoOnly = New-Button 'Repo Only'
$repoOnly.Add_Click({
    $target = Get-PackageVersion
    Invoke-ConfirmReleaseCommand 'Repo release' @('-Mode', 'repo', '-Version', $target, '-NonInteractive') "This will build frontend/PHAR, commit release files, tag v$target, and push commit/tag.`n`nContinue?"
})
$electronOnly = New-Button 'Electron Only'
$electronOnly.Add_Click({
    $target = Get-PackageVersion
    Invoke-ConfirmReleaseCommand 'Electron publish' @('-Mode', 'electron', '-Version', $target, '-NonInteractive') "This will build frontend/PHAR/Electron and publish GitHub Release artifacts for v$target.`nExpected artifacts: latest.yml, installer exe, exe.blockmap.`n`nContinue?" -RequiresToken
})
$allRelease = New-Button 'All'
$allRelease.Add_Click({
    $target = Get-PackageVersion
    Invoke-ConfirmReleaseCommand 'Full release' @('-Mode', 'all', '-Version', $target, '-NonInteractive') "This will build, commit, tag v$target, push, and publish GitHub Release artifacts.`nExpected artifacts: latest.yml, installer exe, exe.blockmap.`n`nContinue?" -RequiresToken
})
$pushOnly = New-Button 'Push'
$pushOnly.Add_Click({
    Invoke-ConfirmReleaseCommand 'Push' @('-Mode', 'push', '-Push', '-NonInteractive') 'This will push the current branch. Continue?'
})
$publishButtons.Controls.AddRange(@($repoOnly, $electronOnly, $allRelease, $pushOnly))
$publishTab.Controls.Add($publishButtons)
$tabs.TabPages.Add($publishTab)

Write-GuiDebug 'Building Rollback tab'
$rollbackTab = [System.Windows.Forms.TabPage]::new('Rollback')
$rollbackLayout = [System.Windows.Forms.TableLayoutPanel]::new()
$rollbackLayout.Dock = 'Fill'
$rollbackLayout.RowCount = 3
$rollbackLayout.ColumnCount = 1
$rollbackLayout.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::Absolute, 46)) | Out-Null
$rollbackLayout.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::Percent, 100)) | Out-Null
$rollbackLayout.RowStyles.Add([System.Windows.Forms.RowStyle]::new([System.Windows.Forms.SizeType]::Absolute, 86)) | Out-Null

$rollbackToolbar = [System.Windows.Forms.FlowLayoutPanel]::new()
$rollbackToolbar.Dock = 'Fill'
$refreshRollback = New-Button 'Refresh Commits'
$refreshRollback.Add_Click({ Refresh-RollbackCommits })
$revertSelected = New-Button 'Revert Selected'
$revertSelected.Add_Click({ Invoke-SelectedRollback })
$abortRollback = New-Button 'Abort Revert'
$abortRollback.Add_Click({
    if (Confirm-YesNo 'Abort the in-progress git revert?' 'Abort Revert') {
        Invoke-ReleaseCommand @('-Mode', 'rollback-abort', '-NonInteractive') 'Abort in-progress revert'
    }
})
$continueRollback = New-Button 'Continue Revert'
$continueRollback.Add_Click({
    Invoke-ReleaseCommand @('-Mode', 'rollback-continue', '-NonInteractive') 'Continue in-progress revert'
})
$pushRollback = New-Button 'Push Rollback Commit'
$pushRollback.Add_Click({
    if (Confirm-YesNo 'Push the rollback commit(s) to the current branch?' 'Push Rollback') {
        Invoke-ReleaseCommand @('-Mode', 'rollback-push', '-NonInteractive') 'Push rollback commit'
    }
})
$rollbackToolbar.Controls.AddRange(@($refreshRollback, $revertSelected, $abortRollback, $continueRollback, $pushRollback))

$script:RollbackList = [System.Windows.Forms.ListView]::new()
$script:RollbackList.Dock = 'Fill'
$script:RollbackList.View = 'Details'
$script:RollbackList.FullRowSelect = $true
$script:RollbackList.GridLines = $true
$script:RollbackList.Columns.Add('Hash', 100) | Out-Null
$script:RollbackList.Columns.Add('Date', 95) | Out-Null
$script:RollbackList.Columns.Add('Author', 170) | Out-Null
$script:RollbackList.Columns.Add('Message', 620) | Out-Null

$rollbackBottom = [System.Windows.Forms.FlowLayoutPanel]::new()
$rollbackBottom.Dock = 'Fill'
$fromLabel = [System.Windows.Forms.Label]::new()
$fromLabel.Text = 'From'
$fromLabel.AutoSize = $true
$fromLabel.Margin = [System.Windows.Forms.Padding]::new(6, 12, 4, 4)
$script:RollbackFromBox = [System.Windows.Forms.TextBox]::new()
$script:RollbackFromBox.Width = 180
$toLabel = [System.Windows.Forms.Label]::new()
$toLabel.Text = 'To'
$toLabel.AutoSize = $true
$toLabel.Margin = [System.Windows.Forms.Padding]::new(12, 12, 4, 4)
$script:RollbackToBox = [System.Windows.Forms.TextBox]::new()
$script:RollbackToBox.Width = 180
$rangeRollback = New-Button 'Revert Range'
$rangeRollback.Add_Click({ Invoke-RangeRollback })
$rollbackHint = [System.Windows.Forms.Label]::new()
$rollbackHint.Text = 'Rollback uses git revert and creates new commit(s). It does not rewrite pushed history.'
$rollbackHint.AutoSize = $true
$rollbackHint.Margin = [System.Windows.Forms.Padding]::new(12, 12, 4, 4)
$rollbackBottom.Controls.AddRange(@($fromLabel, $script:RollbackFromBox, $toLabel, $script:RollbackToBox, $rangeRollback, $rollbackHint))

$rollbackLayout.Controls.Add($rollbackToolbar, 0, 0)
$rollbackLayout.Controls.Add($script:RollbackList, 0, 1)
$rollbackLayout.Controls.Add($rollbackBottom, 0, 2)
$rollbackTab.Controls.Add($rollbackLayout)
$tabs.TabPages.Add($rollbackTab)

Write-GuiDebug 'Building Verify tab'
$verifyTab = [System.Windows.Forms.TabPage]::new('Verify')
$verifyButtons = [System.Windows.Forms.FlowLayoutPanel]::new()
$verifyButtons.Dock = 'Fill'
$verifyLocal = New-Button 'Verify Local Artifacts'
$verifyLocal.Add_Click({ Invoke-ReleaseCommand @('-Mode', 'verify-artifacts', '-NonInteractive') 'Verify local artifacts' })
$verifyPhar = New-Button 'Verify PHAR Contents'
$verifyPhar.Add_Click({
    try {
        Add-Log 'COMMAND: inline Test-PharContents'
        Test-PharContents
        Add-Log 'PHAR verification completed successfully'
    } catch {
        Add-Log "PHAR verification failed: $($_.Exception.Message)"
    }
})
$verifyGithub = New-Button 'Verify GitHub Release'
$verifyGithub.Add_Click({
    $target = Get-PackageVersion
    if ([string]::IsNullOrWhiteSpace($env:GH_TOKEN)) {
        $config = Get-ReleaseManagerConfig
        Add-Log "GH_TOKEN missing. Manual release URL: https://github.com/$($config.repoOwner)/$($config.repoName)/releases/tag/v$target"
    } else {
        Invoke-ReleaseCommand @('-Mode', 'verify-release', '-Version', $target, '-NonInteractive') 'Verify GitHub Release assets'
    }
})
$verifyButtons.Controls.AddRange(@($verifyLocal, $verifyPhar, $verifyGithub))
$verifyTab.Controls.Add($verifyButtons)
$tabs.TabPages.Add($verifyTab)

Write-GuiDebug 'Building Logs tab'
$logsTab = [System.Windows.Forms.TabPage]::new('Logs')
$logHelp = [System.Windows.Forms.Label]::new()
$logHelp.Text = 'Logs are shown in the lower pane for every tab. Commands, stdout, stderr, exit codes, and timestamps are recorded there.'
$logHelp.Dock = 'Top'
$logHelp.Height = 40
$logsTab.Controls.Add($logHelp)
$tabs.TabPages.Add($logsTab)

$form.Add_Shown({
    Refresh-StatusPanel
    Refresh-VersionPreview
    Refresh-CommitFiles
    Refresh-RollbackCommits
})

[System.Windows.Forms.Application]::EnableVisualStyles()
Write-GuiDebug 'Starting WinForms message loop'
[System.Windows.Forms.Application]::Run($form)
} catch {
    Show-StartupError $_
    exit 1
}
