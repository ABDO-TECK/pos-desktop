$ErrorActionPreference = 'Stop'
if ($env:GITHUB_ACTIONS -ne 'true' -or !$env:RUNNER_TEMP -or !$IsWindows) {
    throw 'The shell process broker verification requires GitHub Actions on Windows.'
}

$root = Join-Path ([IO.Path]::GetFullPath($env:RUNNER_TEMP)) ('pos-shell-broker-' + [guid]::NewGuid().ToString('N'))
[void][IO.Directory]::CreateDirectory($root)
$broker = Join-Path $root 'shell-update-process.ps1'
Copy-Item -LiteralPath (Join-Path $PSScriptRoot '../electron/services/shell-update-process.ps1') -Destination $broker
Copy-Item -LiteralPath (Join-Path $PSScriptRoot '../backend/Native/OwnedChildProcess.cs') -Destination $root
$windowsPowerShell = Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe'
$fixture = Join-Path $root 'POS Desktop.exe'

$fixtureSource = @"
using System;
using System.Diagnostics;
using System.IO;
using System.Threading;

public static class Fixture
{
    public static int Main(string[] args)
    {
        if (args.Length == 1 && args[0] == "leaf")
        {
            using (var locked = File.Open("owned.lock", FileMode.Create, FileAccess.ReadWrite, FileShare.None))
            {
                File.WriteAllText("leaf.pid", Process.GetCurrentProcess().Id.ToString());
                Thread.Sleep(60000);
            }
            return 0;
        }
        var info = new ProcessStartInfo(Process.GetCurrentProcess().MainModule.FileName, "leaf");
        info.UseShellExecute = false;
        using (var leaf = Process.Start(info))
        {
            var deadline = Stopwatch.StartNew();
            while (!File.Exists("leaf.pid") && deadline.ElapsedMilliseconds < 6000) Thread.Sleep(30);
            return File.Exists("leaf.pid") ? 78 : 79;
        }
    }
}
"@

$source = Join-Path $root 'Fixture.cs'
[IO.File]::WriteAllText($source, $fixtureSource)
& $windowsPowerShell -NoProfile -NonInteractive -Command "& { Add-Type -Path '$source' -OutputAssembly '$fixture' -OutputType ConsoleApplication }"
if ($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath $fixture)) { throw 'Could not compile the confined job fixture.' }

$processInfo = [Diagnostics.ProcessStartInfo]::new($windowsPowerShell)
$processInfo.Arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $broker + '"'
$processInfo.WorkingDirectory = $root
$processInfo.UseShellExecute = $false
$processInfo.RedirectStandardInput = $true
$processInfo.RedirectStandardOutput = $true
$processInfo.CreateNoWindow = $true
$process = [Diagnostics.Process]::Start($processInfo)
try {
    function Read-BrokerFrame {
        $read = $process.StandardOutput.ReadLineAsync()
        if (!$read.Wait(20000) -or !$read.Result) { throw 'The broker did not return a bounded control frame.' }
        return ($read.Result | ConvertFrom-Json)
    }

    $process.StandardInput.WriteLine((@{
        root = $root
        environment = @{
            SystemRoot = $env:SystemRoot
            PATH = (Join-Path $env:SystemRoot 'System32')
            TEMP = $root
        }
    } | ConvertTo-Json -Compress))
    $start = Read-BrokerFrame
    if ($start.event -ne 'started' -or $start.pid -le 0) { throw 'Candidate root identity was not returned.' }
    $exitFrame = Read-BrokerFrame
    if ($exitFrame.event -ne 'exited' -or $exitFrame.code -ne 78) { throw 'The candidate exited before its owned service was confirmed.' }
    $leafPid = [int](Get-Content -LiteralPath (Join-Path $root 'leaf.pid') -Raw)
    $leaf = [Diagnostics.Process]::GetProcessById($leafPid)
    try {
        if ($leaf.HasExited -or $leaf.MainModule.FileName -ne $fixture) {
            throw 'A live fixture descendant must remain after the candidate exits.'
        }
        $process.StandardInput.WriteLine('stop')
        $process.StandardInput.Close()
        $stopped = Read-BrokerFrame
        if ($stopped.event -ne 'stopped' -or !$leaf.WaitForExit(20000) -or !$process.WaitForExit(20000) -or $process.ExitCode -ne 0) {
            throw 'The job broker did not release the orphan descendant.'
        }
        $file = [IO.File]::Open((Join-Path $root 'owned.lock'), 'Open', 'Read', 'None')
        $file.Dispose()
    } finally {
        $leaf.Dispose()
    }
} finally {
    if (!$process.HasExited) {
        $process.Kill()
        [void]$process.WaitForExit(20000)
    }
    $process.Dispose()
}
Write-Output 'Verified candidate exit 78, owned descendant termination and file release.'

