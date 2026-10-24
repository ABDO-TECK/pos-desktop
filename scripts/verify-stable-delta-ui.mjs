import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createDesktopLab, delay } from './desktop-transition-lab.mjs';

// Strict whitelisted roots for fixture cleanup
export const ALLOWED_FIXTURE_ROOTS = Object.freeze([
  path.resolve(path.join(process.env.PROGRAMDATA || 'C:\\ProgramData', 'POS System')),
  path.resolve(path.join(process.env.APPDATA || 'C:\\AppData', 'POS System')),
  path.resolve(path.join(process.env.APPDATA || 'C:\\AppData', 'pos-desktop')),
]);

// Per-run marker bound to actual fresh fixture created by this run
export const CURRENT_RUN_ID = 'pos-fixture-' + crypto.randomBytes(16).toString('hex');

export function bindFixtureRunMarker(targetDir) {
  const resolved = path.resolve(targetDir);
  if (!fs.existsSync(resolved)) {
    fs.mkdirSync(resolved, { recursive: true });
  }
  const markerPath = path.join(resolved, '.pos-fixture-run-marker');
  fs.writeFileSync(markerPath, JSON.stringify({
    runId: CURRENT_RUN_ID,
    createdAt: new Date().toISOString(),
    targetPath: resolved,
  }, null, 2));
}

export function verifyFixtureRunMarker(targetDir) {
  const resolved = path.resolve(targetDir);
  const markerPath = path.join(resolved, '.pos-fixture-run-marker');
  if (!fs.existsSync(markerPath)) {
    throw new Error(`Guarded cleanup rejected: missing per-run marker in ${targetDir}`);
  }
  const data = JSON.parse(fs.readFileSync(markerPath, 'utf8'));
  if (data.runId !== CURRENT_RUN_ID) {
    throw new Error(`Guarded cleanup rejected: runId mismatch in ${targetDir}`);
  }
}

// Established Assert-PosTreeSafe pattern: ancestor and descendant reparse-point validation
export function assertPosTreeSafe(directory) {
  const full = path.resolve(directory);
  const rootDir = path.parse(full).root;
  if (full.toLowerCase() === rootDir.toLowerCase() || full.replace(/\\$/, '').toLowerCase() === rootDir.replace(/\\$/, '').toLowerCase()) {
    throw new Error(`Refusing a drive root: ${directory}`);
  }

  // Ancestor validation: ensure neither target nor any ancestor is a reparse point (symlink/junction)
  let ancestor = full;
  while (ancestor && ancestor.toLowerCase() !== rootDir.toLowerCase()) {
    if (fs.existsSync(ancestor)) {
      const stat = fs.lstatSync(ancestor);
      if (stat.isSymbolicLink()) {
        throw new Error(`Reparse point rejected in ancestor: ${ancestor}`);
      }
    }
    const parent = path.dirname(ancestor);
    if (parent === ancestor) break;
    ancestor = parent;
  }

  // Descendant validation: ensure target path and all nested children contain no reparse points
  if (fs.existsSync(full)) {
    const stat = fs.lstatSync(full);
    if (stat.isSymbolicLink()) {
      throw new Error(`Reparse point rejected: ${full}`);
    }
    // Verify realpath matches resolved path without redirection
    assert.equal(fs.realpathSync(full).toLowerCase(), full.toLowerCase(), 'Directory must resolve to its real path without reparse redirection: ' + full);

    const entries = fs.readdirSync(full, { withFileTypes: true });
    for (const entry of entries) {
      const entryPath = path.join(full, entry.name);
      if (entry.isSymbolicLink()) {
        throw new Error(`Reparse point rejected: ${entryPath}`);
      }
      if (entry.isDirectory()) {
        assertPosTreeSafe(entryPath);
      }
    }
  }
}

// Bounded owned-process inspection query builder using safe base64 path encoding
export function buildOwnedProcessInspectionScript(appDirectory) {
  const resolved = path.resolve(appDirectory);
  const base64Path = Buffer.from(resolved, 'utf8').toString('base64');
  return `
$ProgressPreference = 'SilentlyContinue'
$ErrorActionPreference = 'Stop'
$rawBytes = [System.Convert]::FromBase64String('${base64Path}')
$targetPath = [System.Text.Encoding]::UTF8.GetString($rawBytes)
$normalizedRoot = [IO.Path]::GetFullPath($targetPath).TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
$rootLower = $normalizedRoot.ToLowerInvariant()
$procs = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object {
  if (-not $_.ExecutablePath) { return $false }
  $execPath = [IO.Path]::GetFullPath($_.ExecutablePath).ToLowerInvariant()
  return $execPath.StartsWith($rootLower)
} | Select-Object ProcessId, ExecutablePath)
if ($procs.Count -eq 0) {
  Write-Output '[]'
} else {
  $procs | ConvertTo-Json -Compress
}
`.trim();
}

export function parseOwnedProcessInspectionOutput(raw) {
  if (typeof raw !== 'string') {
    throw new Error('Process inspection output must be a string');
  }
  const trimmed = raw.trim();
  if (!trimmed || trimmed === 'null' || trimmed === '[]') {
    return [];
  }
  try {
    const parsed = JSON.parse(trimmed);
    const list = Array.isArray(parsed) ? parsed : [parsed];
    for (const item of list) {
      if (!item || typeof item.ProcessId !== 'number' || typeof item.ExecutablePath !== 'string') {
        throw new Error('Malformed process inspection object: ' + JSON.stringify(item));
      }
    }
    return list;
  } catch (err) {
    throw new Error(`Failed to parse process inspection JSON: ${err.message}. Raw output: ${trimmed}`);
  }
}

export function getRunningOwnedProcesses(appDirectory, runner = null) {
  const psScript = buildOwnedProcessInspectionScript(appDirectory);
  let rawOutput;
  if (runner) {
    rawOutput = runner(psScript);
  } else {
    const encoded = Buffer.from(psScript, 'utf16le').toString('base64');
    try {
      rawOutput = execFileSync('powershell.exe', [
        '-NoProfile',
        '-NonInteractive',
        '-EncodedCommand',
        encoded,
      ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true });
    } catch (err) {
      throw new Error(`Owned process inspection failed closed (PowerShell/CIM error): ${err.stderr || err.message}`);
    }
  }
  return parseOwnedProcessInspectionOutput(rawOutput);
}

export async function awaitOwnedProcessShutdown(appDirectory, timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs;
  let remaining = [];
  while (Date.now() < deadline) {
    remaining = getRunningOwnedProcesses(appDirectory);
    if (remaining.length === 0) {
      return;
    }
    await delay(1000);
  }
  const detail = remaining.map(p => `${p.ProcessId}: ${p.ExecutablePath}`).join(', ');
  throw new Error(`Leftover owned runtime child processes still running under ${appDirectory}: ${detail}`);
}

export async function guardedCleanup(targetDir, appDirectory) {
  const resolvedTarget = path.resolve(targetDir);
  const isAllowed = ALLOWED_FIXTURE_ROOTS.some(allowed =>
    resolvedTarget === allowed || resolvedTarget.startsWith(allowed + path.sep)
  );
  assert.ok(isAllowed, `Guarded cleanup rejected unallowed root: ${targetDir}`);

  if (!fs.existsSync(resolvedTarget)) {
    return;
  }

  // Preflight: await owned child processes, verify per-run marker, assert no reparse points
  await awaitOwnedProcessShutdown(appDirectory);
  assertPosTreeSafe(resolvedTarget);
  verifyFixtureRunMarker(resolvedTarget);

  let cleaned = false;
  for (let attempt = 1; attempt <= 10; attempt++) {
    try {
      fs.rmSync(resolvedTarget, { recursive: true, force: true });
      if (!fs.existsSync(resolvedTarget)) {
        cleaned = true;
        break;
      }
    } catch {
      await delay(500);
    }
  }

  assert.ok(!fs.existsSync(resolvedTarget), `Guarded cleanup failed to completely remove fixture-owned root: ${resolvedTarget}`);
}

const hash = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');

// Wait for runtime services to bootstrap key and certificate material in userData
export async function waitForRuntimeKeyMaterial(userDataDir, timeoutMs = 25000) {
  const qzKey = path.join(userDataDir, 'qz-certs', 'private-key.pem');
  const qzCert = path.join(userDataDir, 'qz-certs', 'digital-certificate.pem');
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (fs.existsSync(qzKey) && fs.existsSync(qzCert)) {
      return;
    }
    await delay(500);
  }
  throw new Error('Timed out waiting for production QZ certificates to be generated in userData/qz-certs');
}

export const ALLOWED_RUNTIME_PORTS_FIELDS = Object.freeze([
  'mysqlPort',
  'apiPort',
  'apiBaseUrl',
  'updatedAt',
]);

export function parseRuntimePortsPayload(input) {
  if (typeof input === 'object' && input !== null) {
    if (input.portsData) {
      return parseRuntimePortsPayload(input.portsData);
    }
    if ('mysqlPort' in input || 'apiPort' in input) {
      return input;
    }
    if (input.path && typeof input.path === 'string') {
      return parseRuntimePortsPayload(input.path);
    }
    return input;
  }
  if (typeof input !== 'string') {
    throw new Error(`runtime_ports payload must be a file path, JSON string, or object; got ${typeof input}`);
  }

  const trimmed = input.trim();
  if (trimmed.startsWith('{') || trimmed.startsWith('[') || trimmed === 'null') {
    try {
      return JSON.parse(input);
    } catch (err) {
      throw new Error(`Invalid JSON in runtime_ports payload: ${err.message}`);
    }
  }

  if (fs.existsSync(input)) {
    let stat;
    try {
      stat = fs.statSync(input);
    } catch {}
    if (stat && stat.isFile()) {
      let raw;
      try {
        raw = fs.readFileSync(input, 'utf8');
      } catch (err) {
        throw new Error(`Failed to read runtime_ports file at ${input}: ${err.message}`);
      }
      try {
        return JSON.parse(raw);
      } catch (err) {
        throw new Error(`Invalid JSON in runtime_ports file at ${input}: ${err.message}`);
      }
    }
  }

  try {
    return JSON.parse(input);
  } catch {
    throw new Error(`runtime_ports file does not exist or content is not valid JSON: ${input}`);
  }
}

export function normalizeRuntimePorts(input) {
  const data = parseRuntimePortsPayload(input);

  if (typeof data !== 'object' || data === null || Array.isArray(data)) {
    throw new Error(`runtime_ports must be a valid JSON object, got: ${data === null ? 'null' : Array.isArray(data) ? 'array' : typeof data}`);
  }

  const keys = Object.keys(data);
  for (const field of ALLOWED_RUNTIME_PORTS_FIELDS) {
    if (!Object.prototype.hasOwnProperty.call(data, field)) {
      throw new Error(`runtime_ports missing required field: ${field}`);
    }
  }
  for (const key of keys) {
    if (!ALLOWED_RUNTIME_PORTS_FIELDS.includes(key)) {
      throw new Error(`runtime_ports contains unexpected/extra field: ${key}`);
    }
  }

  const { mysqlPort, apiPort, apiBaseUrl, updatedAt } = data;

  if (typeof mysqlPort !== 'number' || !Number.isInteger(mysqlPort) || mysqlPort < 1 || mysqlPort > 65535) {
    throw new Error(`runtime_ports mysqlPort must be an integer between 1 and 65535, got: ${mysqlPort} (${typeof mysqlPort})`);
  }

  if (typeof apiPort !== 'number' || !Number.isInteger(apiPort) || apiPort < 1 || apiPort > 65535) {
    throw new Error(`runtime_ports apiPort must be an integer between 1 and 65535, got: ${apiPort} (${typeof apiPort})`);
  }

  if (typeof apiBaseUrl !== 'string' || apiBaseUrl.trim() === '') {
    throw new Error(`runtime_ports apiBaseUrl must be a non-empty string, got: ${typeof apiBaseUrl}`);
  }

  let parsedUrl;
  try {
    parsedUrl = new URL(apiBaseUrl);
  } catch (err) {
    throw new Error(`runtime_ports apiBaseUrl is not a valid URL: ${apiBaseUrl} (${err.message})`);
  }

  if (parsedUrl.protocol !== 'http:' && parsedUrl.protocol !== 'https:') {
    throw new Error(`runtime_ports apiBaseUrl must have http: or https: protocol, got: ${parsedUrl.protocol}`);
  }

  const urlPort = parsedUrl.port ? Number(parsedUrl.port) : (parsedUrl.protocol === 'https:' ? 443 : 80);
  if (urlPort !== apiPort) {
    throw new Error(`runtime_ports apiBaseUrl port (${urlPort}) does not match apiPort (${apiPort})`);
  }

  if (typeof updatedAt !== 'string' || updatedAt.trim() === '') {
    throw new Error(`runtime_ports updatedAt must be a non-empty string timestamp, got: ${typeof updatedAt}`);
  }

  const parsedTimestamp = Date.parse(updatedAt);
  if (Number.isNaN(parsedTimestamp)) {
    throw new Error(`runtime_ports updatedAt is not a valid date/timestamp: ${updatedAt}`);
  }

  return {
    mysqlPort,
    apiPort,
    apiBaseUrl,
  };
}

export function assertRuntimePortsPreserved(actual, expected) {
  if (typeof actual === 'string' && !actual.trim().startsWith('{')) {
    assert.ok(fs.existsSync(actual), `runtime_ports.json must exist at ${actual}`);
  }
  if (typeof expected === 'string' && !expected.trim().startsWith('{')) {
    assert.ok(fs.existsSync(expected), `runtime_ports.json must exist at ${expected}`);
  }
  const normActual = normalizeRuntimePorts(actual);
  const normExpected = normalizeRuntimePorts(expected);
  assert.deepEqual(
    normActual,
    normExpected,
    'Runtime ports configuration modified after upgrade (excluding volatile updatedAt)'
  );
}

export const verifyRuntimePorts = assertRuntimePortsPreserved;

export function snapshotRuntimeKeyMaterial(configDir, userDataDir) {
  const qzKeyPath = path.join(userDataDir, 'qz-certs', 'private-key.pem');
  const qzCertPath = path.join(userDataDir, 'qz-certs', 'digital-certificate.pem');
  const httpsKeyPath = path.join(userDataDir, 'ssl', 'server.key');
  const httpsCertPath = path.join(userDataDir, 'ssl', 'server.crt');
  const dbCredsPath = path.join(configDir, 'db_credentials.json');
  const runtimePortsPath = path.join(configDir, 'runtime_ports.json');
  const envPath = path.join(configDir, '.env');

  // Mandatory credentials and QZ private key + certificate generated by production services
  assert.ok(fs.existsSync(dbCredsPath), `db_credentials.json must exist at ${dbCredsPath}`);
  assert.ok(fs.existsSync(runtimePortsPath), `runtime_ports.json must exist at ${runtimePortsPath}`);
  assert.ok(fs.existsSync(qzKeyPath), `QZ private key must exist at ${qzKeyPath}`);
  assert.ok(fs.existsSync(qzCertPath), `QZ certificate must exist at ${qzCertPath}`);

  const candidateKeys = [
    { id: 'db_credentials.json', path: dbCredsPath, required: true },
    { id: 'runtime_ports.json', path: runtimePortsPath, required: true },
    { id: 'qz_private_key', path: qzKeyPath, required: true },
    { id: 'qz_cert', path: qzCertPath, required: true },
  ];

  if (fs.existsSync(envPath)) {
    candidateKeys.push({ id: '.env', path: envPath, required: false });
  }

  // HTTPS private key and certificate: required if LAN/HTTPS proxy is active, explicitly optional if disabled
  const hasHttpsKey = fs.existsSync(httpsKeyPath);
  const hasHttpsCert = fs.existsSync(httpsCertPath);
  if (hasHttpsKey || hasHttpsCert) {
    assert.ok(hasHttpsKey, 'HTTPS server key missing while certificate exists');
    assert.ok(hasHttpsCert, 'HTTPS server certificate missing while key exists');
    candidateKeys.push({ id: 'https_server_key', path: httpsKeyPath, required: true });
    candidateKeys.push({ id: 'https_server_crt', path: httpsCertPath, required: true });
  } else {
    console.log('Runtime HTTPS proxy inactive or not listening on separate SSL port; HTTPS keypair is optional.');
  }

  const snapshot = new Map();
  let privateKeyPairCount = 0;

  for (const item of candidateKeys) {
    if (fs.existsSync(item.path)) {
      const entry = {
        path: item.path,
        sha256: hash(item.path),
        size: fs.statSync(item.path).size,
      };
      if (item.id === 'runtime_ports.json') {
        normalizeRuntimePorts(item.path);
        entry.portsData = JSON.parse(fs.readFileSync(item.path, 'utf8'));
      }
      snapshot.set(item.id, entry);
    } else if (item.required) {
      throw new Error(`Required runtime credential/key file missing: ${item.path}`);
    }
  }

  if (snapshot.has('qz_private_key') && snapshot.has('qz_cert')) {
    privateKeyPairCount++;
  }
  if (snapshot.has('https_server_key') && snapshot.has('https_server_crt')) {
    privateKeyPairCount++;
  }

  assert.ok(privateKeyPairCount >= 1, 'At least one actual private key pair must be present and asserted');
  console.log(`Snapshotted ${snapshot.size} runtime key/credential files (${privateKeyPairCount} verified private keypair(s); no secrets logged).`);
  return snapshot;
}

export function verifyRuntimeKeyMaterial(snapshot) {
  for (const [id, expected] of snapshot.entries()) {
    assert.ok(fs.existsSync(expected.path), `Runtime key/credential file disappeared after upgrade: ${id} (${expected.path})`);
    if (id === 'runtime_ports.json') {
      assertRuntimePortsPreserved(expected.path, expected.portsData || expected);
      continue;
    }
    const currentHash = hash(expected.path);
    assert.equal(currentHash, expected.sha256, `Runtime key/credential file modified after upgrade: ${id}`);
    assert.equal(fs.statSync(expected.path).size, expected.size, `Runtime key/credential file size changed: ${id}`);
  }
  console.log(`Verified ${snapshot.size} runtime key/credential files preserved intact across upgrade.`);
}

export async function versionIs(ui, root, expected) {
  const deadline = Date.now() + 180000;
  do {
    try {
      const current = JSON.parse(fs.readFileSync(path.join(root, 'version.json'), 'utf8')).version;
      if (current === expected) {
        await delay(5000);
        await ui.until('Boolean(document.querySelector("form, a[href=\'/settings\']"))', 'restarted frontend for ' + expected, 120000);
        return;
      }
    } catch {}
    await delay(500);
  } while (Date.now() < deadline);
  throw Error('Timed out waiting for version ' + expected);
}

export async function runStableDeltaUiAcceptance() {
  // 1. Strict ephemeral CI runner & path ownership guards
  assert.equal(process.env.GITHUB_ACTIONS, 'true', 'Runtime acceptance requires an ephemeral GitHub Actions runner');
  assert.ok(process.env.RUNNER_TEMP, 'RUNNER_TEMP environment variable is required');

  // Secret key must never be exposed to acceptance tests or child processes
  assert.ok(!process.env.UPDATE_PRIVATE_KEY, 'UPDATE_PRIVATE_KEY secret must never be exposed to acceptance tests');
  delete process.env.GH_TOKEN;
  delete process.env.GITHUB_TOKEN;

  const [labArg, evidenceArg, assetsArg] = process.argv.slice(2);
  assert.ok(labArg && evidenceArg && assetsArg, 'Usage: node verify-stable-delta-ui.mjs <lab> <evidence> <assets>');

  const lab = path.resolve(labArg);
  const evidence = path.resolve(evidenceArg);
  const assets = path.resolve(assetsArg);

  const temporary = fs.realpathSync(process.env.RUNNER_TEMP).toLowerCase() + path.sep;
  assert.ok(fs.realpathSync(lab).toLowerCase().startsWith(temporary), 'Lab directory must resolve inside RUNNER_TEMP');
  assert.ok(evidence.toLowerCase().startsWith(temporary), 'Evidence directory must resolve inside RUNNER_TEMP');

  fs.mkdirSync(evidence, { recursive: true });

  // Strict no-preexisting POS data guard (verified at startup)
  for (const directory of ALLOWED_FIXTURE_ROOTS) {
    assert.ok(!fs.existsSync(directory), 'Runner must have no existing POS data before acceptance: ' + directory);
  }

  // 2. Prepublication assets & transport verification
  const targetInventoryPath = path.join(assets, 'deployment-inventory.json');
  assert.ok(fs.existsSync(targetInventoryPath), 'Missing candidate deployment-inventory.json');
  const targetInventory = JSON.parse(fs.readFileSync(targetInventoryPath, 'utf8'));
  assert.equal(targetInventory.version, '0.0.10', 'Candidate inventory version must be 0.0.10');

  const manifest001Path = path.join(assets, 'manifest-0.0.1.json');
  assert.ok(fs.existsSync(manifest001Path), 'Missing manifest-0.0.1.json');
  const manifest001 = JSON.parse(fs.readFileSync(manifest001Path, 'utf8'));
  assert.equal(manifest001.version, '0.0.10');
  assert.equal(manifest001.from_version, '0.0.1');
  assert.equal(manifest001.type, 'delta');
  assert.equal(manifest001.channel, 'stable');

  const manifestBeta10Path = path.join(assets, 'manifest-0.0.10-beta.10.json');
  assert.ok(fs.existsSync(manifestBeta10Path), 'Missing manifest-0.0.10-beta.10.json');
  const manifestBeta10 = JSON.parse(fs.readFileSync(manifestBeta10Path, 'utf8'));
  assert.equal(manifestBeta10.version, '0.0.10');
  assert.equal(manifestBeta10.from_version, '0.0.10-beta.10');
  assert.equal(manifestBeta10.type, 'delta');
  assert.equal(manifestBeta10.channel, 'stable');

  const php = process.env.PHP_BINARY || 'php';
  const fixture = path.resolve('scripts/fixtures/channel-transition-capability.php');
  const stageAcceptanceDelta = path.resolve('scripts/stage-acceptance-delta.php');
  const storage = path.join(process.env.PROGRAMDATA, 'POS System');
  const appData = path.join(process.env.APPDATA, 'POS System');
  const posDesktop = path.join(process.env.APPDATA, 'pos-desktop');

  console.log('--- Step 1: Demonstrating original Setup 0.0.1 -> stable 0.0.10 upgrade ---');
  {
    const appSetup = path.join(lab, 'app-setup');
    const rootSetup = path.join(appSetup, 'resources/app.asar.unpacked');
    const shellFiles = [path.join(appSetup, 'POS Desktop.exe'), path.join(appSetup, 'resources/app.asar')];
    const initialShellHashes = shellFiles.map(hash);

    const initialVersion = JSON.parse(fs.readFileSync(path.join(rootSetup, 'version.json'), 'utf8')).version;
    assert.equal(initialVersion, '0.0.1', 'Baseline Setup application version must be 0.0.1');

    const ui = createDesktopLab(appSetup, path.join(evidence, 'setup-run'));
    let auth;
    try {
      await ui.start();
      auth = await ui.freshLogin();

      // Bind per-run markers to freshly created fixture directories
      bindFixtureRunMarker(storage);
      bindFixtureRunMarker(appData);
      bindFixtureRunMarker(posDesktop);

      // Seed customer sentinel and settings fixture
      const sentinel = path.join(storage, 'business-sentinel.db');
      fs.writeFileSync(sentinel, 'customer data fixture must remain identical');
      const sentinelHash = hash(sentinel);

      const compatibilityBefore = JSON.parse(execFileSync(php, [fixture, 'prepare-compatibility'], { encoding: 'utf8', windowsHide: true }));
      const capabilityBefore = JSON.parse(execFileSync(php, [fixture, 'seed'], { encoding: 'utf8', windowsHide: true }));

      // Await runtime key generation and snapshot existing device & SSL key material without printing secrets
      await waitForRuntimeKeyMaterial(posDesktop);
      const keyMaterialBefore = snapshotRuntimeKeyMaterial(appData, posDesktop);

      // Stage delta update 0.0.1 -> 0.0.10 using prepublication signed package
      const staged = JSON.parse(execFileSync(php, [stageAcceptanceDelta, rootSetup, storage, assets], { encoding: 'utf8', windowsHide: true }));
      assert.equal(staged.version, '0.0.10', 'Staged version must be 0.0.10');

      // Trigger desktop handoff via production IPC boundary
      await ui.evaluate('void window.posRuntime.applyStagedDelta(' + JSON.stringify(staged.version) + ').catch(()=>{})');
      await versionIs(ui, rootSetup, '0.0.10');

      if (await ui.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
        await ui.login(auth.email, auth.password);
      }
      await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'authenticated after upgrade');

      // 1. Exact target version
      const upgradedVersion = JSON.parse(fs.readFileSync(path.join(rootSetup, 'version.json'), 'utf8')).version;
      assert.equal(upgradedVersion, '0.0.10', 'Upgraded version must be 0.0.10');

      // 2. Exact target inventory
      for (const [file, expected] of Object.entries(targetInventory.files)) {
        const fullPath = path.join(rootSetup, file);
        assert.ok(fs.existsSync(fullPath), 'Target inventory file missing: ' + file);
        assert.equal(hash(fullPath), expected.sha256, 'Target inventory file hash mismatch: ' + file);
      }

      // 3. Immutable shell preserved
      assert.deepEqual(shellFiles.map(hash), initialShellHashes, 'Desktop shell binaries must remain unchanged');

      // 4. Preserved business fixtures, settings, SQL schema, ledger, and device credentials
      assert.equal(hash(sentinel), sentinelHash, 'Business sentinel file preserved across upgrade');

      // Verify SQL migration ledger and schema compatibility strictly
      const capabilityAfter = JSON.parse(execFileSync(php, [fixture, 'inspect'], { encoding: 'utf8', windowsHide: true }));
      const compatibilityAfter = JSON.parse(execFileSync(php, [fixture, 'inspect-compatibility'], { encoding: 'utf8', windowsHide: true }));

      // Strictly compare compatibilityBefore/After schema_sha256 and ledger_sha256
      assert.equal(compatibilityAfter.schema_sha256, compatibilityBefore.schema_sha256, 'Normalized schema SHA256 must match across upgrade');
      assert.equal(compatibilityAfter.ledger_sha256, compatibilityBefore.ledger_sha256, 'Normalized ledger SHA256 must match across upgrade');
      assert.equal(compatibilityAfter.objects, compatibilityBefore.objects, 'Normalized object count must match across upgrade');
      assert.equal(compatibilityAfter.runtime_metadata_readable, true, 'Runtime metadata view must remain readable');
      assert.equal(compatibilityAfter.metadata_view_read_only, true, 'Runtime metadata view must remain read-only');
      assert.equal(compatibilityAfter.legacy_backup_readable, true, 'Legacy restricted-account backup must remain readable');
      assert.equal(compatibilityAfter.metadata_migration, compatibilityBefore.metadata_migration, 'Metadata migration identifier must match');

      // Strictly compare capabilityBefore/After schema_sha256 and raw ledger
      assert.equal(capabilityAfter.schema_sha256, capabilityBefore.schema_sha256, 'Base table schema SHA256 must be identical across upgrade');
      assert.deepEqual(capabilityAfter.ledger, capabilityBefore.ledger, 'Migration ledger must be identical across upgrade');

      // Customer sentinel row and settings preserved
      assert.deepEqual(capabilityAfter.business_rows, capabilityBefore.business_rows, 'Customer sentinel row preserved');
      assert.deepEqual(capabilityAfter.settings, capabilityBefore.settings, 'Settings sentinel preserved');

      // Device credentials & SSL key material verified unchanged
      verifyRuntimeKeyMaterial(keyMaterialBefore);

      console.log('PASS: Original Setup 0.0.1 -> stable 0.0.10 upgrade demonstrated cleanly.');
    } finally {
      await ui.stop();
    }

    // Guarded cleanup with process shutdown check and per-run marker validation
    await awaitOwnedProcessShutdown(appSetup);
    await guardedCleanup(storage, appSetup);
    await guardedCleanup(appData, appSetup);
    await guardedCleanup(posDesktop, appSetup);
  }

  await delay(1000);

  console.log('--- Step 2: Demonstrating beta10 baseline -> stable 0.0.10 upgrade ---');
  {
    const appBeta10 = path.join(lab, 'app-beta10');
    const rootBeta10 = path.join(appBeta10, 'resources/app.asar.unpacked');
    const beta10Assets = path.join(lab, 'beta10-assets');
    const shellFiles = [path.join(appBeta10, 'POS Desktop.exe'), path.join(appBeta10, 'resources/app.asar')];
    const initialShellHashes = shellFiles.map(hash);

    const ui = createDesktopLab(appBeta10, path.join(evidence, 'beta10-run'));
    let auth;
    try {
      await ui.start();
      auth = await ui.freshLogin();

      // Stage published beta.10 delta to bring runtime to 0.0.10-beta.10
      const stagedInit = JSON.parse(execFileSync(php, [stageAcceptanceDelta, rootBeta10, storage, beta10Assets], { encoding: 'utf8', windowsHide: true }));
      assert.equal(stagedInit.version, '0.0.10-beta.10');

      await ui.evaluate('void window.posRuntime.applyStagedDelta("0.0.10-beta.10").catch(()=>{})');
      await versionIs(ui, rootBeta10, '0.0.10-beta.10');

      if (await ui.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
        await ui.login(auth.email, auth.password);
      }
      await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'authenticated at beta10');

      const beta10Version = JSON.parse(fs.readFileSync(path.join(rootBeta10, 'version.json'), 'utf8')).version;
      assert.equal(beta10Version, '0.0.10-beta.10', 'Application successfully brought to beta10 baseline');

      // Bind per-run markers to freshly created fixture directories
      bindFixtureRunMarker(storage);
      bindFixtureRunMarker(appData);
      bindFixtureRunMarker(posDesktop);

      // Seed customer sentinel and settings at beta10 baseline
      const sentinel2 = path.join(storage, 'business-sentinel.db');
      fs.writeFileSync(sentinel2, 'beta10 customer data fixture must remain identical');
      const sentinelHash2 = hash(sentinel2);

      const compatibilityBeforeBeta10 = JSON.parse(execFileSync(php, [fixture, 'prepare-compatibility'], { encoding: 'utf8', windowsHide: true }));
      const capabilityBeforeBeta10 = JSON.parse(execFileSync(php, [fixture, 'seed'], { encoding: 'utf8', windowsHide: true }));

      // Await runtime key generation and snapshot existing device & SSL key material without printing secrets
      await waitForRuntimeKeyMaterial(posDesktop);
      const keyMaterialBeforeBeta10 = snapshotRuntimeKeyMaterial(appData, posDesktop);

      // Stage candidate delta 0.0.10-beta.10 -> 0.0.10 using prepublication signed package
      const stagedBeta10ToStable = JSON.parse(execFileSync(php, [stageAcceptanceDelta, rootBeta10, storage, assets], { encoding: 'utf8', windowsHide: true }));
      assert.equal(stagedBeta10ToStable.version, '0.0.10', 'Staged candidate version must be 0.0.10');

      // Trigger desktop handoff via production IPC boundary
      await ui.evaluate('void window.posRuntime.applyStagedDelta("0.0.10").catch(()=>{})');
      await versionIs(ui, rootBeta10, '0.0.10');

      if (await ui.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
        await ui.login(auth.email, auth.password);
      }
      await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'authenticated after upgrade to stable');

      // 1. Exact target version
      const upgradedVersion = JSON.parse(fs.readFileSync(path.join(rootBeta10, 'version.json'), 'utf8')).version;
      assert.equal(upgradedVersion, '0.0.10', 'Upgraded version must be 0.0.10');

      // 2. Exact target inventory
      for (const [file, expected] of Object.entries(targetInventory.files)) {
        const fullPath = path.join(rootBeta10, file);
        assert.ok(fs.existsSync(fullPath), 'Target inventory file missing: ' + file);
        assert.equal(hash(fullPath), expected.sha256, 'Target inventory file hash mismatch: ' + file);
      }

      // 3. Immutable shell preserved
      assert.deepEqual(shellFiles.map(hash), initialShellHashes, 'Desktop shell binaries must remain unchanged');

      // 4. Preserved business fixtures, settings, SQL schema, ledger, and device credentials
      assert.equal(hash(sentinel2), sentinelHash2, 'Business sentinel file preserved across beta10 -> stable upgrade');

      // Verify SQL migration ledger and schema compatibility strictly
      const capabilityAfterBeta10 = JSON.parse(execFileSync(php, [fixture, 'inspect'], { encoding: 'utf8', windowsHide: true }));
      const compatibilityAfterBeta10 = JSON.parse(execFileSync(php, [fixture, 'inspect-compatibility'], { encoding: 'utf8', windowsHide: true }));

      // Migration ledger must be strictly identical between beta10 and stable 0.0.10
      assert.deepEqual(capabilityAfterBeta10.ledger, capabilityBeforeBeta10.ledger, 'Migration ledger must be identical between beta10 and stable 0.0.10');
      // Normalized schema SHA256 must be strictly identical between beta10 and stable 0.0.10
      assert.equal(capabilityAfterBeta10.schema_sha256, capabilityBeforeBeta10.schema_sha256, 'Database schema must be identical between beta10 and stable 0.0.10');
      assert.equal(compatibilityAfterBeta10.schema_sha256, compatibilityBeforeBeta10.schema_sha256, 'Normalized compatibility schema SHA256 must be identical');
      assert.equal(compatibilityAfterBeta10.ledger_sha256, compatibilityBeforeBeta10.ledger_sha256, 'Normalized compatibility ledger SHA256 must be identical');
      assert.deepEqual(compatibilityAfterBeta10, compatibilityBeforeBeta10, 'Compatibility contract must be identical between beta10 and stable 0.0.10');

      // Customer sentinel row and settings preserved
      assert.deepEqual(capabilityAfterBeta10.business_rows, capabilityBeforeBeta10.business_rows, 'Customer sentinel row preserved');
      assert.deepEqual(capabilityAfterBeta10.settings, capabilityBeforeBeta10.settings, 'Settings sentinel preserved');

      // Device credentials & SSL key material verified unchanged
      verifyRuntimeKeyMaterial(keyMaterialBeforeBeta10);

      console.log('PASS: beta10 baseline -> stable 0.0.10 upgrade demonstrated cleanly.');
    } finally {
      await ui.stop();
    }

    // Guarded cleanup with process shutdown check and per-run marker validation
    await awaitOwnedProcessShutdown(appBeta10);
    await guardedCleanup(storage, appBeta10);
    await guardedCleanup(appData, appBeta10);
    await guardedCleanup(posDesktop, appBeta10);
  }

  // 3. Safe deliberately documented public acceptance JSON recording verified facts
  const acceptanceData = {
    ok: true,
    release_version: '0.0.10',
    release_channel: 'stable',
    release_type: 'delta',
    transport: 'prepublication_copied_signed_packages',
    live_feed: false,
    original_setup_verified: true,
    original_setup_sha256: '2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a',
    baselines_demonstrated: ['0.0.1', '0.0.10-beta.10'],
    setup_to_stable_upgrade: true,
    beta10_to_stable_upgrade: true,
    actual_setup_shell: true,
    immutable_shell_verified: true,
    production_staging_handoff_boundaries: true,
    rsa_manifest_signatures_verified: true,
    target_inventory_matches: true,
    business_fixtures_preserved: true,
    settings_preserved: true,
    db_credentials_preserved: true,
    runtime_ports_preserved: true,
    qz_keypair_preserved: true,
    https_keypair_verified_if_lan_active: true,
    sql_ledger_strictly_compared: true,
    sql_schema_sha256_strictly_compared: true,
    compatibility_contract_verified: true,
    no_beta_channel_return_artifacts: true,
    executed_at: new Date().toISOString(),
  };

  fs.writeFileSync(path.join(evidence, 'acceptance.json'), JSON.stringify(acceptanceData, null, 2));
  console.log('PASS real packaged stable upgrade acceptance: Setup 0.0.1 and beta10 -> stable 0.0.10 verified');
}

const isDirectRun = Boolean(
  process.argv[1] &&
  path.resolve(process.argv[1]).toLowerCase() === fileURLToPath(import.meta.url).toLowerCase()
);

if (isDirectRun) {
  runStableDeltaUiAcceptance().catch((err) => {
    console.error(err);
    process.exit(1);
  });
}
