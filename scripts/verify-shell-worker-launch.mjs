// Strict GITHUB_ACTIONS and Windows platform guards BEFORE any fs/process/crypto operations
if (process.platform !== 'win32') {
  console.error('[FATAL] Shell worker launch probe must run on Windows (win32).');
  process.exit(1);
}
if (process.env.GITHUB_ACTIONS !== 'true') {
  console.error('[FATAL] Shell worker launch probe is GHA-only. GITHUB_ACTIONS=true is required.');
  process.exit(1);
}

import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import crypto from 'node:crypto';
import child_process from 'node:child_process';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createRequire, builtinModules } from 'node:module';

const requireShim = createRequire(import.meta.url);
const currentScriptPath = fileURLToPath(import.meta.url);
const repoRoot = path.resolve(path.dirname(currentScriptPath), '..');

// Test cases using immutable commit hashes and fixture paths containing spaces and Arabic text
const TEST_CASES = [
  {
    name: 'Frozen 13 (commit ee5bf691c4acf6ffcd7730db1d7d0ed24f274787)',
    commit: 'ee5bf691c4acf6ffcd7730db1d7d0ed24f274787',
    subDir: 'case-frozen13 مسار تشغيل v13',
    injectFault: false,
    injectDiscoveryError: false,
  },
  {
    name: 'Original Beta (commit 9124ce7e2a338662611c11d903c12b99d6168999)',
    commit: '9124ce7e2a338662611c11d903c12b99d6168999',
    subDir: 'case-beta مسار تشغيل beta',
    injectFault: false,
    injectDiscoveryError: false,
  },
  {
    name: 'Teardown Fault Injection: Expected Failure Cleanup Verification',
    commit: 'ee5bf691c4acf6ffcd7730db1d7d0ed24f274787',
    subDir: 'case-fault-injection تجربة اختبار التنظيف عند الفشل',
    injectFault: true,
    injectDiscoveryError: false,
  },
  {
    name: 'Uncertainty Retention Guard: Discovery Error Retains Lab Until Proven Quiescent',
    commit: 'ee5bf691c4acf6ffcd7730db1d7d0ed24f274787',
    subDir: 'case-uncertainty-guard تجربة فحص عدم الحذف عند الشك',
    injectFault: false,
    injectDiscoveryError: true,
  },
];

function resolvePowershellExe() {
  const systemRoot = process.env.SystemRoot || 'C:\\Windows';
  const psPath = path.join(systemRoot, 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe');
  if (!fs.existsSync(psPath)) throw new Error(`Windows PowerShell 5.1 not found: ${psPath}`);
  return psPath;
}

function resolveCanonicalPhpExe() {
  let resolved = null;
  try {
    const raw = child_process.execFileSync('where.exe', ['php.exe'], { encoding: 'utf8', timeout: 10000 }).trim();
    const firstLine = raw.split(/\r?\n/)[0].trim();
    if (firstLine && fs.existsSync(firstLine)) {
      resolved = fs.realpathSync(firstLine);
    }
  } catch {}
  if (!resolved) {
    throw new Error('Could not resolve absolute canonical php.exe path via where.exe');
  }
  return resolved;
}

function isPidDead(pid) {
  if (!pid || typeof pid !== 'number' || pid <= 0) return true;
  try {
    process.kill(pid, 0);
    return false;
  } catch (err) {
    return err.code === 'ESRCH';
  }
}

async function waitForPidExit(pid, timeoutMs = 5000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    if (isPidDead(pid)) return true;
    await new Promise((r) => setTimeout(r, 100));
  }
  return isPidDead(pid);
}

function readJson(filePath) {
  try {
    let content = fs.readFileSync(filePath, 'utf8');
    if (content.charCodeAt(0) === 0xFEFF) content = content.slice(1);
    return JSON.parse(content);
  } catch {
    return null;
  }
}

function writeJsonAtomic(filePath, data) {
  const tmp = `${filePath}.tmp.${Date.now()}`;
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2), 'utf8');
  fs.renameSync(tmp, filePath);
}

async function waitForJson(filePath, predicate, timeoutMs = 5000, intervalMs = 50) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    if (fs.existsSync(filePath)) {
      const data = readJson(filePath);
      if (data && predicate(data)) return data;
    }
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

function exclusiveCreateDir(dirPath) {
  if (fs.existsSync(dirPath)) {
    throw new Error(`Exclusive directory creation failed: ${dirPath} already exists`);
  }
  fs.mkdirSync(dirPath, { recursive: false });
}

function runPowerShellEncoded(pwshExe, psScript, timeoutMs = 15000) {
  const base64 = Buffer.from('[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)\r\n' + psScript, 'utf16le').toString('base64');
  return child_process.execFileSync(
    pwshExe,
    ['-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64],
    { encoding: 'utf8', timeout: timeoutMs }
  ).trim();
}

function getControllerIdentity(pwshExe) {
  const psScript = `
  $ErrorActionPreference = 'Stop'
  $proc = Get-CimInstance Win32_Process -Filter "ProcessId = $PID"
  if ($null -eq $proc) { exit 1 }
  $owner = Invoke-CimMethod -InputObject $proc -MethodName GetOwnerSid
  if ($null -eq $owner -or $owner.ReturnValue -ne 0 -or [string]::IsNullOrWhiteSpace($owner.Sid)) {
    throw "GetOwnerSid failed for controller process"
  }
  [PSCustomObject]@{
    SessionId = [int]$proc.SessionId
    OwnerSid = [string]$owner.Sid
  } | ConvertTo-Json -Compress
  `.trim();
  const raw = runPowerShellEncoded(pwshExe, psScript, 15000);
  const data = JSON.parse(raw);
  if (typeof data.SessionId !== 'number' || !data.OwnerSid) {
    throw new Error(`Failed to capture trusted controller identity: ${raw}`);
  }
  return { trustedSessionId: data.SessionId, trustedOwnerSid: data.OwnerSid };
}

function queryWin32ProcessById(pwshExe, pid) {
  const psScript = `
  $ErrorActionPreference = 'Stop'
  $proc = Get-CimInstance Win32_Process -Filter "ProcessId = ${pid}"
  if ($null -eq $proc) { exit 404 }
  $owner = Invoke-CimMethod -InputObject $proc -MethodName GetOwnerSid
  if ($null -eq $owner -or $owner.ReturnValue -ne 0 -or [string]::IsNullOrWhiteSpace($owner.Sid)) {
    throw "GetOwnerSid failed for ProcessId ${pid}"
  }
  [PSCustomObject]@{
    ProcessId = [int]$proc.ProcessId
    ExecutablePath = [string]$proc.ExecutablePath
    CommandLine = [string]$proc.CommandLine
    CreationDate = [string]$proc.CreationDate
    SessionId = [int]$proc.SessionId
    OwnerSid = [string]$owner.Sid
  } | ConvertTo-Json -Compress
  `.trim();
  try {
    const raw = runPowerShellEncoded(pwshExe, psScript, 15000);
    return JSON.parse(raw);
  } catch (err) {
    if (isPidDead(pid)) {
      return null;
    }
    throw new Error(`CIM process query unavailable for alive PID ${pid}: ${err.message}`);
  }
}

function discoverOwnedProcessesByNonce(pwshExe, nonce, canonicalPhpExe, caseDir, trustedIdentity, queryHook = null) {
  if (typeof queryHook === 'function') {
    queryHook();
  }

  const canonicalPwshExe = resolvePowershellExe();
  const canonicalNodeExe = fs.realpathSync(process.execPath);

  // Server-side WQL query filtering on CommandLine containing the nonce
  const psScript = `
  $ErrorActionPreference = 'Stop'
  $wql = "CommandLine LIKE '%${nonce}%'"
  $procs = Get-CimInstance Win32_Process -Filter $wql
  $list = @()
  foreach ($p in $procs) {
    $owner = Invoke-CimMethod -InputObject $p -MethodName GetOwnerSid
    if ($null -eq $owner -or $owner.ReturnValue -ne 0 -or [string]::IsNullOrWhiteSpace($owner.Sid)) {
      throw "GetOwnerSid failed for ProcessId $($p.ProcessId)"
    }
    $list += [PSCustomObject]@{
      ProcessId = [int]$p.ProcessId
      ExecutablePath = [string]$p.ExecutablePath
      CommandLine = [string]$p.CommandLine
      CreationDate = [string]$p.CreationDate
      SessionId = [int]$p.SessionId
      OwnerSid = [string]$owner.Sid
    }
  }
  if ($list.Count -eq 0) {
    "[]"
  } else {
    $list | ConvertTo-Json -Compress
  }
  `.trim();

  const raw = runPowerShellEncoded(pwshExe, psScript, 15000);
  if (!raw || raw === 'null') {
    throw new Error(`CIM discovery query returned empty/null payload for nonce ${nonce}`);
  }

  const parsed = JSON.parse(raw);
  const arr = Array.isArray(parsed) ? parsed : [parsed];
  if (arr.length === 0) return [];

  const matched = [];
  for (const proc of arr) {
    if (!proc.ExecutablePath || !proc.CommandLine) {
      throw new Error(`Process ${proc.ProcessId} missing ExecutablePath or CommandLine; failing closed`);
    }

    let canonicalExec;
    try {
      canonicalExec = fs.realpathSync(proc.ExecutablePath).toLowerCase();
    } catch (err) {
      throw new Error(`Could not canonicalize ExecutablePath "${proc.ExecutablePath}" for PID ${proc.ProcessId}: ${err.message}; failing closed`);
    }

    const isPhp = canonicalExec === canonicalPhpExe.toLowerCase();
    const isPwsh = canonicalExec === canonicalPwshExe.toLowerCase();
    const isNode = canonicalExec === canonicalNodeExe.toLowerCase();
    const isKnownBinary = isPhp || isPwsh || isNode;

    const hasNonce = proc.CommandLine.includes(nonce);
    const insideCase = proc.CommandLine.toLowerCase().includes(caseDir.toLowerCase());
    const sameSession = proc.SessionId === trustedIdentity.trustedSessionId;
    const sameOwner = proc.OwnerSid === trustedIdentity.trustedOwnerSid;

    if (!isKnownBinary || !hasNonce || !insideCase || !sameSession || !sameOwner) {
      throw new Error(
        `Unexpected matching actor or unresolved identity for PID ${proc.ProcessId} (${proc.ExecutablePath}): ` +
        `isKnownBinary=${isKnownBinary}, hasNonce=${hasNonce}, insideCase=${insideCase}, ` +
        `sameSession=${sameSession}, sameOwner=${sameOwner}; failing closed`
      );
    }

    matched.push(proc);
  }

  return matched;
}

function loadFrozenPhpServerModule(commit) {
  const sourceCode = child_process.execFileSync(
    'git',
    ['show', `${commit}:electron/services/php-server.js`],
    { cwd: repoRoot, encoding: 'utf8', maxBuffer: 10 * 1024 * 1024, timeout: 15000 }
  );

  // Inert stubs for the 5 unrelated relative modules; all throw immediately if invoked
  const throwStub = (name) => () => { throw new Error(`Inert stub ${name} unexpectedly invoked during stop`); };
  const inertRelatives = new Map([
    ['./qz-certs', { getQZPrivateKeyPath: throwStub('getQZPrivateKeyPath') }],
    ['../utils/php-runtime', { getPhpRuntimeArgs: throwStub('getPhpRuntimeArgs'), resolveSystemTimeZone: throwStub('resolveSystemTimeZone') }],
    ['../utils/runtime-error', { createRuntimeError: throwStub('createRuntimeError') }],
    ['../utils/runtime-process', { formatSpawnError: throwStub('formatSpawnError'), spawnRuntimeProcess: throwStub('spawnRuntimeProcess') }],
    ['../utils/paths', { getBackendDir: throwStub('getBackendDir'), isPackaged: throwStub('isPackaged') }],
  ]);

  const nodeBuiltins = new Set(builtinModules);

  function customRequire(id) {
    if (inertRelatives.has(id)) return inertRelatives.get(id);
    const bare = id.startsWith('node:') ? id.slice(5) : id;
    if (nodeBuiltins.has(bare) || nodeBuiltins.has(id)) {
      return requireShim(id);
    }
    throw new Error(`Unexpected module require blocked in isolated vm: ${id}`);
  }

  const moduleObj = { exports: {} };
  const compiled = vm.compileFunction(
    sourceCode,
    ['exports', 'require', 'module', '__filename', '__dirname', 'process', 'console', 'Buffer', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'],
    { filename: `frozen-${commit.slice(0, 7)}-php-server.js` }
  );

  compiled(
    moduleObj.exports,
    customRequire,
    moduleObj,
    `C:\\virtual\\frozen\\${commit.slice(0, 7)}\\php-server.js`,
    `C:\\virtual\\frozen\\${commit.slice(0, 7)}`,
    process,
    console,
    Buffer,
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval
  );

  const { stopPhpServerAwaitable, isProcessAlive } = moduleObj.exports;
  if (typeof stopPhpServerAwaitable !== 'function') {
    throw new Error(`stopPhpServerAwaitable not found in exports for commit ${commit}`);
  }
  return { stopPhpServerAwaitable, isProcessAlive };
}

function safeCleanupDir(targetDir, allowedBaseDir, expectedNonce) {
  if (!targetDir || !path.isAbsolute(targetDir)) throw new Error('safeCleanupDir: targetDir must be an absolute path');
  if (!allowedBaseDir || !path.isAbsolute(allowedBaseDir)) throw new Error('safeCleanupDir: allowedBaseDir must be an absolute path');
  if (!fs.existsSync(targetDir)) return;

  // 1. Root lstat check before realpath (reject if target is junction or link)
  const targetLstat = fs.lstatSync(targetDir);
  if (targetLstat.isSymbolicLink()) {
    throw new Error(`Security refusal: target directory ${targetDir} is a symbolic link or junction`);
  }
  if (!targetLstat.isDirectory()) {
    throw new Error(`Security refusal: target path ${targetDir} is not a directory`);
  }

  // 2. Ancestors check: verify no ancestor is a junction/symlink
  let currentCheck = path.resolve(targetDir);
  const rootDir = path.parse(currentCheck).root;
  while (currentCheck && currentCheck !== rootDir) {
    if (fs.existsSync(currentCheck)) {
      const st = fs.lstatSync(currentCheck);
      if (st.isSymbolicLink()) {
        throw new Error(`Security refusal: ancestor path ${currentCheck} is a symbolic link or junction`);
      }
    }
    currentCheck = path.dirname(currentCheck);
  }

  // 3. Compare lexical expected path to canonical path (case-insensitive on Windows)
  const canonicalBase = fs.realpathSync(allowedBaseDir);
  const canonicalTarget = fs.realpathSync(targetDir);
  if (path.resolve(targetDir).toLowerCase() !== canonicalTarget.toLowerCase()) {
    throw new Error(`Security refusal: lexical target path ${targetDir} does not match canonical target ${canonicalTarget}`);
  }

  // 4. Containment within allowed base
  const rel = path.relative(canonicalBase, canonicalTarget);
  if (rel.startsWith('..') || path.isAbsolute(rel) || rel === '') {
    throw new Error(`Security refusal: ${canonicalTarget} is not strictly contained inside base ${canonicalBase}`);
  }

  // 5. Verify lab-owner.json marker (regular file, not symlink, matching owner & nonce)
  const markerPath = path.join(canonicalTarget, 'lab-owner.json');
  if (!fs.existsSync(markerPath)) {
    throw new Error(`Security refusal: missing lab-owner.json in ${canonicalTarget}`);
  }
  const markerLstat = fs.lstatSync(markerPath);
  if (markerLstat.isSymbolicLink() || !markerLstat.isFile()) {
    throw new Error(`Security refusal: lab-owner.json is not a regular file`);
  }
  const marker = readJson(markerPath);
  if (!marker || marker.owner !== 'verify-shell-worker-launch' || marker.labNonce !== expectedNonce) {
    throw new Error(`Security refusal: owner marker mismatch in ${canonicalTarget}`);
  }

  // 6. Recursive deletion: unlink child symlinks without descending, delete regular files & dirs
  function deleteNode(itemPath) {
    const lstats = fs.lstatSync(itemPath);
    if (lstats.isSymbolicLink()) {
      fs.unlinkSync(itemPath);
      return;
    }
    if (lstats.isDirectory()) {
      for (const child of fs.readdirSync(itemPath)) {
        deleteNode(path.join(itemPath, child));
      }
      fs.rmdirSync(itemPath);
    } else {
      fs.unlinkSync(itemPath);
    }
  }

  deleteNode(canonicalTarget);
}

function generateLauncherPs1() {
  return [
    '\uFEFFparam([Parameter(Mandatory=$true)][string]$HelperPhpExe, [Parameter(Mandatory=$true)][string]$HelperScript, [Parameter(Mandatory=$true)][string]$LabDir, [Parameter(Mandatory=$true)][string]$Nonce)',
    '$psi = New-Object System.Diagnostics.ProcessStartInfo; $psi.FileName = $HelperPhpExe',
    '$psi.Arguments = \'-n "{0}" "{1}" "{2}"\' -f $HelperScript, $LabDir, $Nonce',
    '$psi.UseShellExecute = $false; $psi.CreateNoWindow = $true',
    '$psi.RedirectStandardInput = $false; $psi.RedirectStandardOutput = $false; $psi.RedirectStandardError = $false',
    '$proc = [System.Diagnostics.Process]::Start($psi)',
    'if ($null -eq $proc) { [Console]::Error.WriteLine("Failed to launch helper"); exit 1 }',
    '$receipt = @{ status = "launched"; launcherPid = $PID; helperPid = $proc.Id; nonce = $Nonce } | ConvertTo-Json -Compress',
    '$utf8NoBom = New-Object System.Text.UTF8Encoding($false)',
    '$tmpFile = Join-Path $LabDir "launcher-receipt.json.tmp"; $targetFile = Join-Path $LabDir "launcher-receipt.json"',
    '[System.IO.File]::WriteAllText($tmpFile, $receipt, $utf8NoBom); Move-Item -Path $tmpFile -Destination $targetFile -Force; exit 0',
  ].join('\r\n') + '\r\n';
}

function generateHelperWorkerPhp() {
  return [
    '<?php',
    'if ($argc < 3) { fwrite(STDERR, "Usage: helper-worker.php <labDir> <nonce>\\n"); exit(1); }',
    '[$labDir, $nonce] = array_slice($argv, 1);',
    '$pid = getmypid();',
    '$tmpReady = $labDir . "/helper-ready.json.tmp." . $pid;',
    'file_put_contents($tmpReady, json_encode(["status" => "ready", "pid" => $pid, "nonce" => $nonce, "startedAt" => microtime(true)]));',
    'rename($tmpReady, $labDir . "/helper-ready.json");',
    '$deadline = microtime(true) + 60.0;',
    '$challengeReq = $labDir . "/challenge.req"; $challengeResp = $labDir . "/challenge.resp"; $stopReq = $labDir . "/helper-stop.json";',
    'while (microtime(true) < $deadline) {',
    '    if (file_exists($stopReq)) {',
    '        $data = @json_decode(@file_get_contents($stopReq), true);',
    '        if (is_array($data) && ($data["nonce"] ?? "") === $nonce) {',
    '            @file_put_contents($labDir . "/helper-stopped.json", json_encode(["status" => "stopped", "pid" => $pid, "nonce" => $nonce]));',
    '            exit(0);',
    '        }',
    '    }',
    '    if (file_exists($challengeReq)) {',
    '        $data = @json_decode(@file_get_contents($challengeReq), true);',
    '        if (is_array($data) && ($data["nonce"] ?? "") === $nonce && !empty($data["challenge"])) {',
    '            $resp = json_encode(["challenge" => $data["challenge"], "nonce" => $nonce, "pid" => $pid, "respondedAt" => microtime(true)]);',
    '            $tmpResp = $challengeResp . ".tmp." . $pid;',
    '            file_put_contents($tmpResp, $resp);',
    '            rename($tmpResp, $challengeResp);',
    '            @unlink($challengeReq);',
    '        }',
    '    }',
    '    usleep(50000);',
    '}',
    'exit(0);',
  ].join('\r\n') + '\r\n';
}

function generateSourcePhp() {
  return [
    '<?php',
    'if ($argc < 7) { fwrite(STDERR, "Usage: source-php.php <powershellExe> <launcherPs1> <helperPhpExe> <helperScript> <labDir> <nonce>\\n"); exit(1); }',
    '[$powershellExe, $launcherPs1, $helperPhpExe, $helperScript, $labDir, $nonce] = array_slice($argv, 1);',
    '$cmd = [$powershellExe, "-NoLogo", "-NoProfile", "-NonInteractive", "-File", $launcherPs1, $helperPhpExe, $helperScript, $labDir, $nonce];',
    '$descriptors = [0 => ["file", "NUL", "r"], 1 => ["file", $labDir . "/launcher.stdout.log", "w"], 2 => ["file", $labDir . "/launcher.stderr.log", "w"]];',
    '$proc = proc_open($cmd, $descriptors, $pipes, $labDir, null, ["bypass_shell" => true, "suppress_errors" => true]);',
    'if (!is_resource($proc)) { fwrite(STDERR, "proc_open failed for launcher\\n"); exit(1); }',
    '$l1ExitCode = proc_close($proc);',
    'if ($l1ExitCode !== 0) { fwrite(STDERR, "L1 launcher exited with code: {$l1ExitCode}\\n"); exit(2); }',
    '$helperReadyFile = $labDir . "/helper-ready.json"; $deadline = microtime(true) + 15.0; $helperData = null;',
    'while (microtime(true) < $deadline) {',
    '    if (file_exists($helperReadyFile)) {',
    '        $content = @file_get_contents($helperReadyFile);',
    '        if ($content) {',
    '            $data = @json_decode($content, true);',
    '            if (is_array($data) && ($data["status"] ?? "") === "ready" && ($data["nonce"] ?? "") === $nonce) { $helperData = $data; break; }',
    '        }',
    '    }',
    '    usleep(50000);',
    '}',
    'if (!$helperData) { fwrite(STDERR, "Timed out waiting for helper ready receipt\\n"); exit(3); }',
    '$tmpFile = $labDir . "/source-ready.json.tmp";',
    'file_put_contents($tmpFile, json_encode([',
    '    "status" => "ready", "sourcePid" => getmypid(), "l1ExitCode" => $l1ExitCode,',
    '    "helperPid" => $helperData["pid"], "nonce" => $nonce, "acknowledgedAt" => microtime(true)',
    ']));',
    'rename($tmpFile, $labDir . "/source-ready.json");',
    '// Bounded self-terminating ceiling (60s) to prevent orphan hang on failure',
    '$selfDeadline = microtime(true) + 60.0;',
    'while (microtime(true) < $selfDeadline) { usleep(100000); }',
    'exit(0);',
  ].join('\r\n') + '\r\n';
}

class CaseActorLedger {
  constructor(caseDir, nonce, pwshExe, canonicalPhpExe, trustedIdentity) {
    this.caseDir = caseDir;
    this.nonce = nonce;
    this.pwshExe = pwshExe;
    this.canonicalPhpExe = canonicalPhpExe;
    this.trustedIdentity = trustedIdentity;
    this.surrogateProcess = null;
    this.sourcePhpPid = null;
    this.launcherPid = null;
    this.helperPid = null;
    this.helperCreationBaseline = null;
    this.drained = false;
    this.discoveryQueryHook = null;
  }

  setSurrogate(child) {
    this.surrogateProcess = child;
  }

  recordReceipts() {
    const launcherReceipt = readJson(path.join(this.caseDir, 'launcher-receipt.json'));
    if (launcherReceipt?.launcherPid) this.launcherPid = launcherReceipt.launcherPid;
    if (launcherReceipt?.helperPid) this.helperPid = launcherReceipt.helperPid;

    const sourceReceipt = readJson(path.join(this.caseDir, 'source-ready.json'));
    if (sourceReceipt?.sourcePid) this.sourcePhpPid = sourceReceipt.sourcePid;
    if (sourceReceipt?.helperPid) this.helperPid = sourceReceipt.helperPid;

    const shutdownReceipt = readJson(path.join(this.caseDir, 'surrogate-shutdown-receipt.json'));
    if (shutdownReceipt?.sourcePhpPid) this.sourcePhpPid = shutdownReceipt.sourcePhpPid;
    if (shutdownReceipt?.launcherPid) this.launcherPid = shutdownReceipt.launcherPid;
    if (shutdownReceipt?.helperPid) this.helperPid = shutdownReceipt.helperPid;
  }

  async teardownAll() {
    this.drained = false;
    this.recordReceipts();

    // 1. Direct handle shutdown of surrogate process if still running
    if (this.surrogateProcess) {
      const isSurrogateExited = () =>
        this.surrogateProcess.exitCode !== null ||
        this.surrogateProcess.signalCode !== null ||
        isPidDead(this.surrogateProcess.pid);

      if (!isSurrogateExited()) {
        try {
          this.surrogateProcess.kill('SIGTERM');
          await waitForPidExit(this.surrogateProcess.pid, 2000);
          if (!isSurrogateExited()) {
            this.surrogateProcess.kill('SIGKILL');
            await waitForPidExit(this.surrogateProcess.pid, 2000);
          }
        } catch (err) {
          console.error(`[TEARDOWN] Direct kill on surrogate process failed: ${err.message}`);
        }
      }
    }

    // 2. Graceful stop of helper worker via authenticated stopfile
    if (this.helperPid && !isPidDead(this.helperPid)) {
      // Verify identity baseline of helper before requesting stop
      const meta = queryWin32ProcessById(this.pwshExe, this.helperPid);
      if (meta) {
        const isPhp = fs.realpathSync(meta.ExecutablePath).toLowerCase() === this.canonicalPhpExe.toLowerCase();
        const hasNonce = meta.CommandLine?.includes(this.nonce);
        const insideCase = meta.CommandLine?.toLowerCase().includes(this.caseDir.toLowerCase());
        const sameSession = meta.SessionId === this.trustedIdentity.trustedSessionId;
        const sameOwner = meta.OwnerSid === this.trustedIdentity.trustedOwnerSid;
        const hasCreation = typeof meta.CreationDate === 'string' && meta.CreationDate.length > 0;

        if (!isPhp || !hasNonce || !insideCase || !sameSession || !sameOwner || !hasCreation) {
          throw new Error(`[TEARDOWN] Helper PID ${this.helperPid} identity verification mismatch before stop`);
        }
        if (this.helperCreationBaseline && meta.CreationDate !== this.helperCreationBaseline) {
          throw new Error(`[TEARDOWN] Helper PID ${this.helperPid} CreationDate changed from baseline (${meta.CreationDate} vs ${this.helperCreationBaseline})`);
        }
      }

      writeJsonAtomic(path.join(this.caseDir, 'helper-stop.json'), { action: 'stop', nonce: this.nonce });
      let helperExited = await waitForPidExit(this.helperPid, 5000);
      if (!helperExited) {
        // Wait bounded up to helper's self-terminating ceiling (60s total)
        helperExited = await waitForPidExit(this.helperPid, 10000);
      }
    }

    // 3. Wait bounded for source PHP exit if previously recorded
    if (this.sourcePhpPid && !isPidDead(this.sourcePhpPid)) {
      await waitForPidExit(this.sourcePhpPid, 3000);
    }

    // 4. Wait bounded for launcher exit if previously recorded
    if (this.launcherPid && !isPidDead(this.launcherPid)) {
      await waitForPidExit(this.launcherPid, 3000);
    }

    // 5. Gather all active actors without ANY numeric PID kills
    const activeActors = [];
    if (this.surrogateProcess &&
        this.surrogateProcess.exitCode === null &&
        this.surrogateProcess.signalCode === null &&
        !isPidDead(this.surrogateProcess.pid)) {
      activeActors.push(`surrogate(${this.surrogateProcess.pid})`);
    }
    if (this.sourcePhpPid && !isPidDead(this.sourcePhpPid)) activeActors.push(`sourcePhp(${this.sourcePhpPid})`);
    if (this.launcherPid && !isPidDead(this.launcherPid)) activeActors.push(`launcher(${this.launcherPid})`);
    if (this.helperPid && !isPidDead(this.helperPid)) activeActors.push(`helper(${this.helperPid})`);

    // 6. Run CIM discovery to verify no unrecorded orphan matching our nonce exists
    const discovered = discoverOwnedProcessesByNonce(
      this.pwshExe,
      this.nonce,
      this.canonicalPhpExe,
      this.caseDir,
      this.trustedIdentity,
      this.discoveryQueryHook
    );
    for (const proc of discovered) {
      const desc = `discovered(${proc.ProcessId}, ${proc.ExecutablePath})`;
      if (!activeActors.includes(desc)) activeActors.push(desc);
    }

    // 7. If any actors remain, fail closed and retain lab
    if (activeActors.length > 0) {
      this.drained = false;
      throw new Error(`[NEEDS ATTENTION] Lab retained: active actors still running: ${activeActors.join(', ')}`);
    }

    // 8. Confirmed quiescence: all actors gone, discovery returned 0 matching processes
    this.drained = true;
  }
}

async function runSurrogate() {
  const getArg = (name) => {
    const prefix = `--${name}=`;
    const found = process.argv.find((a) => a.startsWith(prefix));
    return found ? found.slice(prefix.length) : null;
  };

  const commit = getArg('commit');
  const labDir = getArg('lab');
  const nonce = getArg('nonce');
  const phpExe = getArg('php');
  const pwshExe = getArg('pwsh');

  if (!commit || !labDir || !nonce || !phpExe || !pwshExe) {
    throw new Error('Missing required arguments for surrogate mode');
  }

  const { stopPhpServerAwaitable, isProcessAlive } = loadFrozenPhpServerModule(commit);

  const sourcePhpScript = path.join(labDir, 'source-php.php');
  const launcherPs1 = path.join(labDir, 'launcher.ps1');
  const helperWorkerScript = path.join(labDir, 'helper-worker.php');

  // Spawn REAL PHP fixture process
  const phpChild = child_process.spawn(
    phpExe,
    ['-n', sourcePhpScript, pwshExe, launcherPs1, phpExe, helperWorkerScript, labDir, nonce],
    { cwd: labDir, stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true }
  );

  let sourceStderr = '';
  phpChild.stderr.on('data', (c) => { sourceStderr += c.toString(); });

  let stopSucceeded = false;
  try {
    const sourceReady = await waitForJson(
      path.join(labDir, 'source-ready.json'),
      (d) => d.status === 'ready' && d.nonce === nonce,
      15000,
      100
    );

    if (!sourceReady) throw new Error(`Timed out waiting for source-ready.json. PHP stderr: ${sourceStderr}`);
    if (sourceReady.sourcePid !== phpChild.pid) throw new Error(`sourcePid mismatch: got ${sourceReady.sourcePid}, expected ${phpChild.pid}`);

    // Verify L1 launcher is already dead
    const launcherData = readJson(path.join(labDir, 'launcher-receipt.json'));
    if (!launcherData || !launcherData.launcherPid) throw new Error('launcher-receipt.json missing or invalid');

    const launcherPid = launcherData.launcherPid;
    const launcherDead = await waitForPidExit(launcherPid, 5000);
    if (!launcherDead) throw new Error(`L1 launcher (PID ${launcherPid}) still alive after launching helper`);

    // Invoke the actual stop function on the real phpChild reference
    const stopResult = await stopPhpServerAwaitable({ timeoutMs: 5000, processRef: phpChild });
    if (!stopResult.stopped) throw new Error(`stopPhpServerAwaitable failed to stop PHP: ${JSON.stringify(stopResult)}`);
    if (isProcessAlive(phpChild)) throw new Error(`PHP child process (PID ${phpChild.pid}) still reported alive by isProcessAlive`);

    stopSucceeded = true;

    writeJsonAtomic(path.join(labDir, 'surrogate-shutdown-receipt.json'), {
      status: 'stopped',
      surrogatePid: process.pid,
      sourcePhpPid: phpChild.pid,
      launcherPid,
      helperPid: sourceReady.helperPid,
      nonce,
      stopResult,
      timestamp: Date.now(),
    });

    process.exit(0);
  } finally {
    // If stop did not complete, ensure surrogate terminates its owned phpChild handle before exiting
    if (!stopSucceeded && !isPidDead(phpChild.pid)) {
      let phpChildExited = false;
      try {
        phpChild.kill('SIGTERM');
        phpChildExited = await waitForPidExit(phpChild.pid, 2000);
        if (!phpChildExited) {
          phpChild.kill('SIGKILL');
          phpChildExited = await waitForPidExit(phpChild.pid, 2000);
        }
      } catch (err) {
        console.error('[SURROGATE] Error during phpChild direct kill:', err.message);
      }
      if (!phpChildExited && !isPidDead(phpChild.pid)) {
        throw new Error(`[SURROGATE FATAL] Failed to terminate owned phpChild (PID ${phpChild.pid})`);
      }
    }
  }
}

async function runController() {
  const runnerTemp = process.env.RUNNER_TEMP || os.tmpdir();
  const pwshExe = resolvePowershellExe();
  const canonicalPhpExe = resolveCanonicalPhpExe();
  const trustedIdentity = getControllerIdentity(pwshExe);

  const labNonce = crypto.randomBytes(8).toString('hex');
  const labDir = path.join(runnerTemp, `probe-shell-worker-lab-${labNonce}`);
  exclusiveCreateDir(labDir);

  writeJsonAtomic(path.join(labDir, 'lab-owner.json'), {
    owner: 'verify-shell-worker-launch',
    controllerPid: process.pid,
    labNonce,
    createdAt: Date.now(),
  });

  console.log(`[INIT] Controller PID: ${process.pid}`);
  console.log(`[INIT] Trusted SessionId: ${trustedIdentity.trustedSessionId}; Owner SID: ${trustedIdentity.trustedOwnerSid}`);
  console.log(`[INIT] Windows PowerShell 5.1: ${pwshExe}`);
  console.log(`[INIT] Canonical PHP CLI: ${canonicalPhpExe}`);
  console.log(`[INIT] Exclusive Owned Lab: ${labDir}\n`);

  const registeredLedgers = [];
  const results = [];
  let primaryError = null;
  let cleanupError = null;

  try {
    for (const testCase of TEST_CASES) {
      console.log(`--- Starting Test Case: ${testCase.name} ---`);
      const caseNonce = crypto.randomBytes(12).toString('hex');
      const caseDir = path.join(labDir, testCase.subDir);
      exclusiveCreateDir(caseDir);

      writeJsonAtomic(path.join(caseDir, 'lab-owner.json'), {
        owner: 'verify-shell-worker-launch',
        controllerPid: process.pid,
        labNonce: caseNonce,
        createdAt: Date.now(),
      });

      fs.writeFileSync(path.join(caseDir, 'launcher.ps1'), generateLauncherPs1(), 'utf8');
      fs.writeFileSync(path.join(caseDir, 'helper-worker.php'), generateHelperWorkerPhp(), 'utf8');
      fs.writeFileSync(path.join(caseDir, 'source-php.php'), generateSourcePhp(), 'utf8');

      const ledger = new CaseActorLedger(caseDir, caseNonce, pwshExe, canonicalPhpExe, trustedIdentity);
      registeredLedgers.push(ledger);

      let casePrimaryError = null;
      let caseCleanupError = null;

      try {
        const surrogateArgs = [
          currentScriptPath,
          '--surrogate',
          `--commit=${testCase.commit}`,
          `--lab=${caseDir}`,
          `--nonce=${caseNonce}`,
          `--php=${canonicalPhpExe}`,
          `--pwsh=${pwshExe}`,
        ];

        const surrogate = child_process.spawn(process.execPath, surrogateArgs, {
          cwd: caseDir,
          stdio: ['ignore', 'pipe', 'pipe'],
          windowsHide: true,
        });
        ledger.setSurrogate(surrogate);

        let surrogateStdout = '';
        let surrogateStderr = '';
        surrogate.stdout.on('data', (c) => { surrogateStdout += c.toString(); });
        surrogate.stderr.on('data', (c) => { surrogateStderr += c.toString(); });

        // Bounded wait for surrogate completion with explicitly cleared timer
        let surrogateTimer = null;
        const surrogateExitPromise = new Promise((resolve, reject) => {
          const onExit = (code, signal) => {
            if (surrogateTimer) { clearTimeout(surrogateTimer); surrogateTimer = null; }
            surrogate.removeListener('exit', onExit);
            surrogate.removeListener('error', onError);
            resolve({ code, signal });
          };
          const onError = (err) => {
            if (surrogateTimer) { clearTimeout(surrogateTimer); surrogateTimer = null; }
            surrogate.removeListener('exit', onExit);
            surrogate.removeListener('error', onError);
            reject(err);
          };
          surrogate.once('exit', onExit);
          surrogate.once('error', onError);
        });

        const surrogateTimeoutPromise = new Promise((_, reject) => {
          surrogateTimer = setTimeout(() => {
            try { surrogate.kill('SIGTERM'); } catch {}
            reject(new Error('Surrogate process timed out after 30s'));
          }, 30000);
          surrogateTimer.unref?.();
        });

        const { code: surrogateExitCode, signal: surrogateSignal } = await Promise.race([
          surrogateExitPromise,
          surrogateTimeoutPromise,
        ]);

        if (surrogateExitCode !== 0) {
          throw new Error(`Surrogate exited with code ${surrogateExitCode} (signal ${surrogateSignal}). Stdout: ${surrogateStdout} Stderr: ${surrogateStderr}`);
        }

        ledger.recordReceipts();
        const shutdownData = readJson(path.join(caseDir, 'surrogate-shutdown-receipt.json'));
        if (!shutdownData) throw new Error('surrogate-shutdown-receipt.json missing after surrogate exit');
        const { sourcePhpPid, launcherPid, helperPid } = shutdownData;

        if (!isPidDead(sourcePhpPid)) throw new Error(`Source PHP (PID ${sourcePhpPid}) is unexpectedly still alive`);
        if (!isPidDead(launcherPid)) throw new Error(`Launcher L1 (PID ${launcherPid}) is unexpectedly still alive`);

        console.log(`  [OK] Surrogate and Source PHP (PID ${sourcePhpPid}) confirmed terminated.`);

        // Challenge-Response verification on live helper
        const freshChallenge = crypto.randomBytes(16).toString('hex');
        const challengeReqPath = path.join(caseDir, 'challenge.req');
        fs.writeFileSync(challengeReqPath, JSON.stringify({ challenge: freshChallenge, nonce: caseNonce }));

        const challengeResp = await waitForJson(
          path.join(caseDir, 'challenge.resp'),
          (d) => d.challenge === freshChallenge && d.nonce === caseNonce,
          5000,
          50
        );

        if (!challengeResp) throw new Error(`Helper process (PID ${helperPid}) failed to respond to challenge after parent shutdown`);
        if (challengeResp.pid !== helperPid) throw new Error(`Helper PID mismatch in challenge response: expected ${helperPid}, got ${challengeResp.pid}`);

        console.log(`  [OK] Dynamic challenge-response verified for Helper PID ${helperPid}.`);

        // Win32 Process Metadata verification via Get-CimInstance
        const procMeta = queryWin32ProcessById(pwshExe, helperPid);
        if (!procMeta || procMeta.ProcessId !== helperPid) throw new Error(`CIM Process query failed or PID mismatch for ${helperPid}`);

        const isExactPhp = fs.realpathSync(procMeta.ExecutablePath).toLowerCase() === canonicalPhpExe.toLowerCase();
        if (!isExactPhp) throw new Error(`CIM ExecutablePath mismatch: expected ${canonicalPhpExe}, got ${procMeta.ExecutablePath}`);
        if (!procMeta.CommandLine || !procMeta.CommandLine.includes(caseNonce)) throw new Error('CIM CommandLine missing expected nonce');
        if (!procMeta.CreationDate || typeof procMeta.CreationDate !== 'string') throw new Error('CIM CreationDate missing or invalid');
        if (procMeta.SessionId !== trustedIdentity.trustedSessionId) throw new Error(`CIM SessionId mismatch: expected ${trustedIdentity.trustedSessionId}, got ${procMeta.SessionId}`);
        if (procMeta.OwnerSid !== trustedIdentity.trustedOwnerSid) throw new Error(`CIM OwnerSid mismatch: expected ${trustedIdentity.trustedOwnerSid}, got ${procMeta.OwnerSid}`);

        ledger.helperCreationBaseline = procMeta.CreationDate;
        console.log(`  [OK] Win32 process metadata verified (SessionId: ${procMeta.SessionId}, OwnerSid: ${procMeta.OwnerSid}, Nonce confirmed).`);

        // Deterministic Fault Injection subcase: simulate unexpected failure after helper readiness and verification
        if (testCase.injectFault) {
          throw new Error('Deterministic fault injection: verifying teardown on unexpected failure');
        }

        // Uncertainty Retention Guard subcase: set query hook to throw during teardown
        if (testCase.injectDiscoveryError) {
          ledger.discoveryQueryHook = () => {
            throw new Error('Simulated CIM discovery uncertainty hook for retention guard test');
          };
        }
      } catch (err) {
        casePrimaryError = err;
      } finally {
        try {
          await ledger.teardownAll();
        } catch (err) {
          caseCleanupError = err;
        }
      }

      // Handle expected and standard case outcomes
      if (testCase.injectDiscoveryError) {
        if (!caseCleanupError || !caseCleanupError.message.includes('Simulated CIM discovery uncertainty hook')) {
          throw new Error(`Uncertainty guard test failed: expected discovery hook error, got: ${caseCleanupError?.message}`);
        }
        if (ledger.drained) {
          throw new Error('Uncertainty guard test failed: ledger was marked drained despite discovery uncertainty');
        }
        if (!fs.existsSync(caseDir)) {
          throw new Error('Uncertainty guard test failed: case directory was deleted under uncertainty');
        }
        console.log(`  [OK] Uncertainty retention guard verified: case files retained when discovery failed.`);

        // Now restore the real query hook and perform exact teardown to proven quiescence
        ledger.discoveryQueryHook = null;
        await ledger.teardownAll();

        if (!ledger.drained) {
          throw new Error('Uncertainty guard test recovery failed: ledger not drained after restoring discovery');
        }
        safeCleanupDir(caseDir, runnerTemp, caseNonce);
        results.push({ name: testCase.name, passed: true });
        console.log(`  [OK] Post-uncertainty recovery verified: full teardown and lab cleanup completed.`);
        console.log(`--- Finished Test Case: ${testCase.name} [PASS] ---\n`);
      } else if (testCase.injectFault) {
        if (!casePrimaryError || !casePrimaryError.message.includes('Deterministic fault injection')) {
          throw new Error(`Teardown fault injection test did not catch expected injected error: ${casePrimaryError?.message}`);
        }
        if (caseCleanupError) {
          throw new Error(`Teardown fault injection test encountered unexpected cleanup error: ${caseCleanupError.message}`);
        }
        if (!ledger.drained) {
          throw new Error('Teardown fault injection test failed: ledger not drained after teardown');
        }
        safeCleanupDir(caseDir, runnerTemp, caseNonce);
        results.push({ name: testCase.name, passed: true });
        console.log(`  [OK] Teardown fault injection test verified: full teardown and lab cleanup completed on abort.`);
        console.log(`--- Finished Test Case: ${testCase.name} [PASS] ---\n`);
      } else {
        if (casePrimaryError && caseCleanupError) {
          throw new AggregateError([casePrimaryError, caseCleanupError], `Case "${testCase.name}" encountered both primary and cleanup errors`);
        }
        if (casePrimaryError) throw casePrimaryError;
        if (caseCleanupError) throw caseCleanupError;
        if (!ledger.drained) {
          throw new Error(`Case "${testCase.name}" ledger not drained; lab retained for inspection.`);
        }
        safeCleanupDir(caseDir, runnerTemp, caseNonce);
        results.push({ name: testCase.name, passed: true });
        console.log(`--- Finished Test Case: ${testCase.name} [PASS] ---\n`);
      }
    }
  } catch (err) {
    primaryError = err;
  } finally {
    try {
      const undrained = registeredLedgers.filter((l) => !l.drained);
      if (undrained.length > 0) {
        console.warn(`[NEEDS ATTENTION] Lab retained at ${labDir}: ${undrained.length} case ledger(s) not drained.`);
      } else if (fs.existsSync(labDir)) {
        safeCleanupDir(labDir, runnerTemp, labNonce);
      }
    } catch (cleanupErr) {
      cleanupError = cleanupErr;
    }
  }

  if (primaryError || cleanupError) {
    if (primaryError && cleanupError) {
      console.error('[PROBE PRIMARY FAILURE]:', primaryError.stack || primaryError.message);
      console.error('[PROBE ROOT CLEANUP FAILURE]:', cleanupError.stack || cleanupError.message);
    } else if (primaryError) {
      console.error('[PROBE PRIMARY FAILURE]:', primaryError.stack || primaryError.message);
    } else {
      console.error('[PROBE ROOT CLEANUP FAILURE]:', cleanupError.stack || cleanupError.message);
    }
    process.exit(1);
  }

  console.log('============================================================');
  console.log('SHELL WORKER LAUNCH BOUNDARY PROBE: ALL CHECKS PASSED');
  console.log('------------------------------------------------------------');
  console.log(`Cases Tested: ${results.length}`);
  for (const r of results) {
    console.log(`  [PASS] ${r.name}`);
  }
  console.log('\n[NOTICE] Synthetic probe verifies the out-of-process process-lifecycle boundary on Windows.');
  console.log('Synthetics cannot substitute future installed 13 + Beta 12.1 binary/bootstrap proof.');
  console.log('Future production integration requires installed binary verification with real Electron packages.');
  console.log('============================================================\n');
}

if (process.argv.includes('--surrogate')) {
  runSurrogate().catch((err) => {
    console.error('[SURROGATE FATAL]:', err.stack || err.message);
    process.exit(1);
  });
} else {
  runController().catch((err) => {
    console.error('[CONTROLLER FATAL]:', err.stack || err.message);
    process.exit(1);
  });
}
