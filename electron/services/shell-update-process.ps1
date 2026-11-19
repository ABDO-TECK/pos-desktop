$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)

function Send-Frame($value) {
    [Console]::WriteLine(($value | ConvertTo-Json -Compress))
}

$owner = $null
$released = $false
$launchAttempted = $false
try {
    Add-Type -Path (Join-Path $PSScriptRoot 'OwnedChildProcess.cs')
    $stdin = [IO.StreamReader]::new([Console]::OpenStandardInput(), [Console]::InputEncoding)
    $request = $stdin.ReadLine() | ConvertFrom-Json
    $root = [IO.Path]::GetFullPath([string]$request.root)
    $exe = Join-Path $root 'POS Desktop.exe'
    $environment = [System.Collections.Generic.Dictionary[string,string]]::new([StringComparer]::OrdinalIgnoreCase)
    foreach ($entry in $request.environment.PSObject.Properties) {
        if ($null -ne $entry.Value) {
            $environment.Add($entry.Name, [string]$entry.Value)
        }
    }
    $launchAttempted = $true
    $owner = [PosDesktop.Updates.Native.OwnedChildProcess]::Start($exe, [string[]]@(), $root, $environment)
    Send-Frame @{event = 'started'; pid = $owner.ProcessId}
    $inputTask = $stdin.ReadLineAsync()
    $exitReported = $false
    while (!$inputTask.IsCompleted) {
        if (!$exitReported -and $owner.Observe().RootState -eq 'Dead') {
            Send-Frame @{event = 'exited'; code = $owner.GetExitedRootCode()}
            $exitReported = $true
        }
        Start-Sleep -Milliseconds 100
    }
    if ($inputTask.Result -eq 'release') {
        $owner.ReleaseHealthyRuntime()
        $released = $true
        Send-Frame @{event = 'released'}
    }
} catch {
    $failure = $_.Exception
    while ($failure) {
        if ($failure.GetType().FullName -eq 'PosDesktop.Updates.Native.OwnedChildProcessStartException') {
            $owner = $failure.RetainedOwner
            break
        }
        $failure = $failure.InnerException
    }
    Send-Frame @{event = 'error'; error = $_.Exception.Message}
    if ($null -eq $owner) {
        # Start either completed its own cleanup or was never invoked.
        # The supervisor additionally performs an installed process scan.
        Send-Frame @{event = 'stopped'; launch_attempted = $launchAttempted}
    }
    exit 1
} finally {
    if (!$released -and $null -ne $owner) {
        $nativeError = 0
        if ($owner.TryStopAndClose(15000, [ref]$nativeError)) {
            Send-Frame @{event = 'stopped'}
        } else {
            Send-Frame @{event = 'error'; error = "Candidate job could not prove termination ($nativeError)."}
            exit 1
        }
    }
}

