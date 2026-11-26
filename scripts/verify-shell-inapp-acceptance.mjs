import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createDesktopLab, delay } from './desktop-transition-lab.mjs';
import { assetNames, binaryAssetNames, olderVersion, startReleaseFixture } from './fixtures/shell-inapp-release-origin.mjs';
import { binaryAcceptance, installedFixturePaths, shellScenarios, sourceVersion, targetVersion } from './fixtures/shell-inapp-layout.mjs';
import { verifyTransport } from './fixtures/shell-inapp-transport.mjs';
import supervisor from '../electron/services/shell-update-files.js';

assert.equal(process.platform, 'win32');
assert.equal(process.env.GITHUB_ACTIONS, 'true');
assert.equal(process.env.RUNNER_ENVIRONMENT, 'github-hosted');
const scenario = process.argv[2];
assert.ok(shellScenarios.includes(scenario), 'Expected a known acceptance scenario.');

const { lab, installRoot, profile, local, roaming, shared, supervisorStateRoot } = installedFixturePaths(scenario);
const dataDir = path.join(shared, 'POS System');
const configDir = path.join(roaming, 'POS System');
const failureTrace = path.join(roaming, 'candidate-failure.json');
const initialDebugPort = scenario === 'rollback' ? 19231 : 19233;
const restartedDebugPort = initialDebugPort + 1;
const releaseRole = scenario === 'rollback' ? 'failing' : 'target';
const releaseRoot = path.join(lab, releaseRole, 'release');
const evidence = path.join(lab, 'evidence');
const readJson = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const writeJson = (file, data) => fs.writeFileSync(file, JSON.stringify(data, null, 2)+'\n');
const hash = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const inventory = readJson(path.join(releaseRoot, 'shell-manifest.json'));
const requests = [];
const updateNetwork = [];
const networkResults = new Map();
const observations = { supervisor_phases: [] };
let lastSupervisorSnapshot;

function safeFields(value, fields) {
  if (!value || typeof value !== 'object') return null;
  return Object.fromEntries(fields.filter(field => Object.hasOwn(value, field)).map(field => {
    const entry = value[field];
    return [field, typeof entry === 'string'
      ? entry.replace(/(password|authorization|token|secret)\s*[:=]\s*\S+/gi, '$1=[redacted]')
          .replace(/(?:Command failed:|-\s*EncodedCommand).*$/gim, '[Windows invocation omitted]').slice(0, 800)
      : (typeof entry === 'number' || typeof entry === 'boolean' ? entry : null)];
  }));
}

function optionalJson(file) {
  try {
    return readJson(file);
  } catch (error) {
    if (error.code !== 'ENOENT' && !(error instanceof SyntaxError)) throw error;
    return null;
  }
}

function supervisorState() {
  const state = optionalJson(path.join(dataDir, 'update-state.json'));
  const active = optionalJson(path.join(supervisorStateRoot, 'active.json'));
  return {
    state: safeFields(state, ['state', 'phase', 'operation', 'from_version', 'to_version', 'reason_code', 'error', 'requires_recovery']),
    supervisor_active: safeFields(active, ['id', 'phase', 'sourcePid', 'candidatePid', 'restoredPid', 'candidateExit', 'error',
      'candidateFailure', 'restoredFailure', 'candidateBrokerPhase', 'restoredBrokerPhase']),
  };
}

function observeSupervisorPhase() {
  const snapshot = supervisorState();
  const serialized = JSON.stringify(snapshot);
  if (serialized === lastSupervisorSnapshot || observations.supervisor_phases.length >= 80) return;
  lastSupervisorSnapshot = serialized;
  observations.supervisor_phases.push({ observed_at: new Date().toISOString(), ...snapshot });
}

function safeSupervisorReceipt(receipt) {
  if (!receipt) return null;
  const fields = ['operation_id', 'outcome', 'phase', 'source_version', 'target_version', 'source_pid',
    'candidate_pid', 'restored_pid', 'candidate_exit_code', 'database_backup_sha256', 'transaction_digest', 'error',
    'candidate_failure', 'restored_failure', 'candidate_broker_phase', 'restored_broker_phase', 'source_reopened_without_mutation'];
  const health = ['ui', 'php', 'database', 'version', 'native_version', 'window_visible'];
  return { ...safeFields(receipt, fields),
    candidate_health: safeFields(receipt.candidate_health, health),
    restored_health: safeFields(receipt.restored_health, health),
  };
}

function updaterDiagnostics() {
  const stage = path.join(dataDir, 'update_staging', targetVersion, 'shell-delta');
  const stagedFileSizes = {};
  for (const name of [...assetNames, ...binaryAssetNames]) {
    if (fs.existsSync(path.join(stage, name))) stagedFileSizes[name] = fs.statSync(path.join(stage, name)).size;
  }
  return {
    ...supervisorState(),
    staged_file_sizes: stagedFileSizes,
    terminal: safeSupervisorReceipt(readTerminalReceipt()),
  };
}

function observeNetwork(app) {
  const updateRoute = /\/(?:updates\/(?:apply|rollback|check)|update\/jobs\/\d+)(?:\?|$)/;
  app.onEvent((method, event) => {
    const url = event.request?.url ?? event.response?.url;
    if (method === 'Network.requestWillBeSent' && updateRoute.test(url)) {
      const requestUrl = new URL(url);
      updateNetwork.push({ type: 'request', method: event.request.method, path: requestUrl.pathname,
        request_id: event.requestId, background: requestUrl.searchParams.get('background') === 'true' });
    }
    if (method === 'Network.responseReceived' && updateRoute.test(url)) {
      const response = { type: 'response', status: event.response.status, path: new URL(url).pathname, request_id: event.requestId };
      updateNetwork.push(response);
      networkResults.set(event.requestId, response);
    }
    if (method === 'Network.loadingFinished' && networkResults.has(event.requestId)) {
      const response = networkResults.get(event.requestId);
      networkResults.delete(event.requestId);
      app.call('Network.getResponseBody', { requestId: event.requestId }).then(({ body, base64Encoded }) => {
        if (base64Encoded || body.length > 200_000) return;
        const json = JSON.parse(body);
        const fields = ['success', 'ok', 'status', 'state', 'code', 'reason_code', 'error_code', 'errorCode', 'message', 'error',
          'id', 'job_id', 'last_error', 'phase', 'settlement_error', 'latest_version', 'handoff_version', 'requires_desktop_handoff',
          'desktop_handoff_completed', 'update_type', 'shell_update_available', 'target_version',
          'current_version', 'has_update', 'is_up_to_date', 'release_tag', 'shell_transport', 'shell_transport_bytes'];
        response.result = {
          envelope: safeFields(json, fields),
          data: safeFields(json.data, fields),
          result: safeFields(json.data?.result, fields),
          errors: safeFields(json.errors, fields),
        };
      }).catch(() => {});
    }
  });
}

async function observeRendererHandoff(app) {
  const binding = 'posCiObserveHandoff';
  app.onEvent((event, detail) => {
    if (event !== 'Runtime.bindingCalled' || detail.name !== binding || detail.payload.length > 2_000) return;
    const observation = JSON.parse(detail.payload);
    if (observation.status !== 'failed' || !/^\d+$/.test(observation.job_id)) return;
    observations.renderer_handoff = safeFields(observation, ['status', 'job_id', 'displayed_error']);
  });
  await app.call('Runtime.addBinding', {name: binding});
  await app.evaluate("(() => {"+
    "let previous = ''; const watch = new MutationObserver(() => {"+
    "const job_id = sessionStorage.getItem('pos.active_update_job_id');"+
    "if (!job_id || sessionStorage.getItem('pos.update_handoff_'+job_id) !== 'failed') return;"+
    "const displayed_error = [...document.querySelectorAll('[role=\"status\"][aria-live=\"polite\"]')]"+
    ".map(element => element.innerText.trim()).filter(Boolean).slice(-2).join('\\n').slice(0, 1000);"+
    "if (!displayed_error || displayed_error === previous) return; previous = displayed_error;"+
    "window.posCiObserveHandoff(JSON.stringify({job_id,status:'failed',displayed_error}));"+
    "}); watch.observe(document.body,{childList:true,subtree:true,characterData:true}); })()");
}

async function waitFor(description, predicate, timeout = 240_000) {
  const deadline = Date.now()+timeout;
  while (Date.now() < deadline) {
    const result = await predicate();
    if (result) return result;
    await delay(500);
  }
  throw new Error('Timed out waiting for '+description);
}

function probeProcesses(apiPort, mysqlPort, debugPort) {
  const script = [
    "$ErrorActionPreference = 'Stop'",
    '$processes = foreach ($port in @('+debugPort+','+Number(apiPort)+','+Number(mysqlPort)+')) {',
    '  $owners = @(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique)',
    '  if ($owners.Count -ne 1) { throw "Expected one process listening on port $port." }',
    '  $process = Get-CimInstance Win32_Process -Filter "ProcessId = $($owners[0])"',
    '  if (-not $process.ExecutablePath) { throw "Listener executable missing for $port." }',
    '  [ordered]@{ pid = $process.ProcessId; parent_pid = $process.ParentProcessId; exe = $process.ExecutablePath; port = $port; started_at = $process.CreationDate.ToUniversalTime().ToString("o"); serves_phar = $process.CommandLine -like "*backend.phar*"; command = $process.CommandLine }',
    '}',
    'ConvertTo-Json -InputObject @($processes) -Compress',
  ].join('\n');
  const output = execFileSync('pwsh', ['-NoProfile', '-NonInteractive', '-Command', script], {
    windowsHide: true,
    encoding: 'utf8',
    timeout: 30_000,
  });
  const [desktop, php, database] = JSON.parse(output);
  assert.equal(path.resolve(desktop.exe).toLowerCase(), path.join(installRoot, 'POS Desktop.exe').toLowerCase());
  assert.match(php.exe.toLowerCase(), /\\resources\\portable\\php\\php\.exe$/);
  assert.match(database.exe.toLowerCase(), /\\resources\\portable\\mysql\\bin\\(?:mysqld|mariadbd)\.exe$/);
  assert.ok(php.exe.toLowerCase().startsWith(installRoot.toLowerCase()+path.sep));
  assert.ok(database.exe.toLowerCase().startsWith(installRoot.toLowerCase()+path.sep));
  assert.equal(php.serves_phar, true);
  assert.ok(database.command.toLowerCase().includes(path.join(dataDir, 'mysql_data').toLowerCase()),
    'Packaged MariaDB must use the disposable profile data directory.');
  for (const process of [desktop, php, database]) {
    delete process.command;
    delete process.serves_phar;
  }
  return { desktop, php, database };
}

function inspectReopenedProcess(pid, debugPort) {
  if (!Number.isSafeInteger(pid) || pid <= 0) return { expected_pid: pid, debug_port: debugPort, present: false };
  const script = [
    '$proc = Get-Process -Id '+pid+' -ErrorAction SilentlyContinue',
    '$details = Get-CimInstance Win32_Process -Filter "ProcessId = '+pid+'" -ErrorAction SilentlyContinue',
    '$listener = Get-NetTCPConnection -LocalPort '+debugPort+' -State Listen -ErrorAction SilentlyContinue',
    '$record = [ordered]@{',
    '  present = $null -ne $proc',
    '  window_handle_present = $null -ne $proc -and $proc.MainWindowHandle -ne 0',
    '  listening_owners = @($listener | Select-Object -ExpandProperty OwningProcess -Unique)',
    '  debugging_flag_present = $null -ne $details -and $details.CommandLine -like "*--remote-debugging-port='+debugPort+'*"',
    '}',
    '$record | ConvertTo-Json -Compress',
  ].join('\n');
  try {
    const caps = execFileSync('pwsh', ['-NoProfile', '-NonInteractive', '-Command', script], {
      encoding: 'utf8', windowsHide: true, timeout: 12_000,
    });
    return { expected_pid: pid, debug_port: debugPort, ...JSON.parse(caps) };
  } catch {
    return { expected_pid: pid, debug_port: debugPort, diagnostic_unavailable: true };
  }
}

function verifyProcessRestart(previous, reopened, name) {
  const prior = { pid: previous.pid, started_at: previous.started_at };
  const current = { pid: reopened.pid, started_at: reopened.started_at };
  assert.notDeepEqual(current, prior, name+' process identity did not change.');
  assert.ok(Date.parse(current.started_at) > Date.parse(prior.started_at), name+' start time did not advance.');
}

function checkInstalledInventory(expected, deleted = []) {
  const requiredFiles = [
    'POS Desktop.exe',
    'resources/app.asar',
    'resources/app.asar.unpacked/backend/backend.phar',
    'resources/app.asar.unpacked/frontend/dist/index.html',
    'resources/app.asar.unpacked/version.json',
  ];
  const requiredSha256 = {};
  for (const [relative, entry] of Object.entries(expected.files)) {
    const file = path.join(installRoot, relative);
    assert.equal(fs.statSync(file).size, entry.size, 'Wrong installed size for '+relative);
    const sha256 = hash(file);
    assert.equal(sha256, entry.sha256, 'Wrong installed SHA-256 for '+relative);
    if (requiredFiles.includes(relative)) requiredSha256[relative] = sha256;
  }
  for (const relative of requiredFiles) {
    assert.ok(Object.hasOwn(requiredSha256, relative), 'Missing required signed file '+relative);
  }
  for (const relative of deleted) {
    assert.ok(!fs.existsSync(path.join(installRoot, relative)), 'Unexpected retained file '+relative);
  }
  return { files: Object.keys(expected.files).length, required_sha256: requiredSha256 };
}

function observeInstalledInventory(role) {
  const php = path.join(installRoot, 'resources', 'portable', 'php', 'php.exe');
  const script = path.resolve('scripts/observe-shell-install-inventory.php');
  const result = execFileSync(php, ['-n', script, releaseRole, role, scenario], {
    encoding: 'utf8', windowsHide: true, timeout: 60_000, maxBuffer: 128 * 1024,
  });
  return JSON.parse(result);
}

function readTerminalReceipt() {
  try {
    const receipts = fs.readdirSync(supervisorStateRoot, { withFileTypes: true })
      .filter(entry => entry.isDirectory() && supervisor.VALID_OPERATION.test(entry.name))
      .map(entry => supervisor.operationPaths(supervisorStateRoot, entry.name).receipt)
      .filter(file => fs.existsSync(file));
    assert.ok(receipts.length <= 1, 'Expected one operation receipt for the isolated scenario.');
    return receipts.length === 1 ? readJson(receipts[0]) : null;
  } catch (error) {
    if (error.code !== 'ENOENT' && !(error instanceof SyntaxError)) throw error;
    return null;
  }
}

async function getCategory(app, name) {
  const response = await app.evaluate("(async () => {"+
    "const base = window.API_BASE_URL.replace(/\\/+$/, '') + '/api/v1';"+
    "const res = await fetch(base + '/categories?limit=100', {credentials:'include', cache:'no-store'});"+
    "const json = await res.json();"+
    "return {status:res.status, rows:json.data?.filter(row => row.name === "+JSON.stringify(name)+")};})()");
  assert.equal(response.status, 200, 'Category API failed through the packaged renderer.');
  assert.equal(response.rows.length, 1, 'Expected one matching business row in the live database.');
  return response.rows[0];
}

async function createCategory(app, name) {
  const link = "document.querySelector('a[href=\"/products\"]')";
  await app.until("Boolean("+link+")", "products navigation");
  await app.evaluate(link+".click()");
  await app.until("location.pathname === '/products'", "products route");
  await clickVisibleButton(app, 'الفئات', { prefix: true });
  await clickVisibleButton(app, 'إضافة فئة');
  await app.fill('.modal-overlay input', name);
  await clickVisibleButton(app, 'حفظ');
  await app.until("[...document.querySelectorAll('table tbody td')].some(cell=>cell.textContent.trim()==="+JSON.stringify(name)+")",
    "persisted category row");
  return getCategory(app, name);
}

async function verifyUsableRuntime(app, version, marker, engine, debugPort) {
  const authenticated = "Boolean(document.querySelector('a[href=\"/settings\"]'))";
  await app.until(authenticated, 'authenticated navigation');
  const frontend = await app.evaluate("(async () => ({ version: await window.electronAPI.getVersion(), "+
    "engine: window.posCiElectronEngine, marker: document.querySelector('meta[name=\"pos-ci-shell-build\"]')?.content, origin: location.origin }))()");
  assert.equal(frontend.marker, marker);
  assert.equal(frontend.version, version);
  assert.equal(frontend.engine, engine, 'The running packaged Electron engine differs from the expected version.');
  assert.equal(frontend.origin, 'app://pos-app');
  const ports = readJson(path.join(configDir, 'runtime_ports.json'));
  const api = new URL(ports.apiBaseUrl);
  assert.equal(api.hostname, '127.0.0.1');
  assert.equal(Number(api.port), ports.apiPort);
  const http = await fetch(new URL('/api/health', api), { signal: AbortSignal.timeout(10_000) });
  assert.equal(http.status, 200);
  const backendHealth = await http.json();
  assert.equal(backendHealth.status, 'ok');
  assert.equal(backendHealth.version, version);
  if (binaryAcceptance) assert.equal(backendHealth.pos_ci_shell_build, marker, 'The running backend PHAR differs from the expected build.');
  const processes = probeProcesses(ports.apiPort, ports.mysqlPort, debugPort);
  return { frontend, backend_health: backendHealth, processes, runtime_ports: ports };
}

async function clickVisibleButton(app, label, { prefix = false } = {}) {
  const source = JSON.stringify(label);
  const comparison = prefix ? "b.textContent.trim().startsWith("+source+")" : "b.textContent.trim() === "+source;
  const locator = "[...document.querySelectorAll('button')].find(b => !b.disabled && "+comparison+")";
  await app.until("Boolean("+locator+")", "enabled button "+label);
  await app.evaluate("("+locator+").scrollIntoView({block:'center',inline:'center'})");
  const position = await waitFor("visible click target "+label, () => app.evaluate("(() => {"+
    "const el = "+locator+"; if (!el) return null; const box = el.getBoundingClientRect();"+
    "const x = box.left + box.width / 2; const y = box.top + box.height / 2;"+
    "return el.contains(document.elementFromPoint(x,y)) ? {x,y} : null; })()"), 10_000);
  await app.call('Input.dispatchMouseEvent', { ...position, type: 'mousePressed', button: 'left', clickCount: 1 });
  await app.call('Input.dispatchMouseEvent', { ...position, type: 'mouseReleased', button: 'left', clickCount: 1 });
}

async function screenshot(app, name) {
  await app.screenshot(name);
  fs.copyFileSync(path.join(profile, 'diagnostics', name+'.png'), path.join(evidence, name+'.png'));
}

async function verifyOlderStatus(app, installButton) {
  const check = await waitFor('the older-feed check response', () => updateNetwork.find(event =>
    event.type === 'response' && event.path.endsWith('/updates/check') && event.result &&
    updateNetwork.some(request => request.type === 'request' && request.request_id === event.request_id && !request.background)), 60_000);
  assert.equal(check.status, 200);
  assert.equal(check.result.data.current_version, sourceVersion);
  assert.equal(check.result.data.has_update, false);
  assert.equal(check.result.data.is_up_to_date, true);
  assert.ok(check.result.data.latest_version === olderVersion || check.result.data.release_tag === 'v'+olderVersion,
    'The foreground check must evaluate the older stable feed.');
  const readCard = "(() => { const label = [...document.querySelectorAll('div')].find(el => "+
    "el.children.length===0 && el.textContent.trim()==='حالة النظام'); "+
    "return label?.parentElement?.innerText ?? ''; })()";
  await app.until(readCard+".includes('النظام محدّث')", 'Arabic up-to-date value in the installed Update Center');
  const visible = await app.evaluate("({card:"+readCard+", body:document.body.innerText})");
  assert.ok(!visible.body.includes('v'+olderVersion));
  for (const label of ['أحدث إصدار متاح', 'نوع التحديث', 'Full (كامل)']) assert.ok(!visible.body.includes(label));
  assert.ok(!visible.body.includes(installButton));
  assert.ok(requests.some(request => request.authority === 'api.github.com' && request.status === 200 &&
    request.path.endsWith('/pos-desktop-updates/releases')), 'The older stable catalog was not requested.');
  assert.ok(!requests.some(request => request.authority === 'github.com' && request.path.includes('/releases/download/')));
  assert.ok(!updateNetwork.some(event => event.path.endsWith('/updates/apply')));
  await screenshot(app, scenario+'-status');
  observations.older_feed = { version: olderVersion, current_version: sourceVersion, card: visible.card, check: check.result };
}

for (const folder of [local, configDir, shared, path.join(local, 'pos-ci-chromium')]) {
  fs.mkdirSync(folder, { recursive: true });
}
fs.writeFileSync(path.join(configDir, '.env'), '# Synthetic protected configuration witness\n', { flag: 'wx' });
const fixture = await startReleaseFixture(lab, releaseRoot, requests, { version: targetVersion, binary: binaryAcceptance, scenario });
const app = createDesktopLab(installRoot, path.join(profile, 'diagnostics'), {
  LOCALAPPDATA: local,
  APPDATA: roaming,
  PROGRAMDATA: shared,
  HTTPS_PROXY: fixture.proxyUrl,
  NO_PROXY: 'localhost,127.0.0.1',
});

async function runAcceptance() {
  assert.equal(inventory.format, 2);
  assert.equal(inventory.from_version, sourceVersion);
  assert.equal(inventory.version, targetVersion);
  const beforeFiles = checkInstalledInventory(inventory.source_inventory);
  const missingAtSource = inventory.files.map(file => file.path).filter(file => !inventory.source_inventory.files[file]);
  for (const relative of missingAtSource) assert.ok(!fs.existsSync(path.join(installRoot, relative)));
  const uninstaller = path.join(installRoot, 'Uninstall POS Desktop.exe');
  const beforeUninstaller = hash(uninstaller);
  await app.start(initialDebugPort);
  const credentials = await app.freshLogin();
  const before = await verifyUsableRuntime(app, sourceVersion, 'source', '43.2.0', initialDebugPort);
  const marker = 'Shell acceptance '+crypto.randomBytes(10).toString('hex');
  const row = await createCategory(app, marker);
  assert.ok(Number(row?.id) > 0 && row.name === marker, 'Expected a real business row created before update.');

  const witnessFolder = path.join(dataDir, 'uploads', 'shell-acceptance');
  fs.mkdirSync(witnessFolder, { recursive: true });
  const witnessFile = path.join(witnessFolder, 'business-document.bin');
  fs.writeFileSync(witnessFile, crypto.randomBytes(128));
  const protectedFiles = [
    witnessFile,
    path.join(configDir, '.env'),
    path.join(configDir, 'db_credentials.json'),
  ];
  const witnesses = protectedFiles.map(file => ({ file, sha256: hash(file) }));
  observations.before = { ...before, inventory: beforeFiles, row_id: row.id, uninstaller_sha256: beforeUninstaller };
  const liveSourceInventory = observeInstalledInventory('source');
  observations.before.live_inventory = liveSourceInventory;
  assert.equal(liveSourceInventory.digest_matches, true, 'Running source has an unexpected managed inventory.');
  const capabilities = await app.evaluate('window.posRuntime.getDeltaCapability()');
  observations.desktop_capabilities = capabilities;
  assert.equal(capabilities.shellDelta, true, 'The packaged source lacks the native shell update capability.');

  observeNetwork(app);
  await app.call('Network.enable');
  await app.system();
  const checkButton = 'التحقق من التحديثات';
  const installButton = 'تثبيت التحديث الآن';
  await app.until("[...document.querySelectorAll('button')].some(b=>!b.disabled&&b.textContent.trim()==="+JSON.stringify(checkButton)+")", 'enabled update check');
  await clickVisibleButton(app, checkButton);
  if (scenario === 'older') {
    await verifyOlderStatus(app, installButton);
    assert.deepEqual(checkInstalledInventory(inventory.source_inventory), beforeFiles);
    assert.equal(hash(uninstaller), beforeUninstaller);
    assert.equal(readTerminalReceipt(), null);
    await app.stop();
    writeJson(path.join(evidence, scenario+'.json'), { ...observations, scenario, passed: true, provider: requests, update_network: updateNetwork });
    console.log('PASS: installed Update Center rendered the Arabic up-to-date status for the older stable feed.');
    return;
  }
  await app.until("document.body.innerText.includes("+JSON.stringify('v'+targetVersion)+") && [...document.querySelectorAll('button')].some(b=>!b.disabled&&b.textContent.trim()==="+JSON.stringify(installButton)+")", 'version '+targetVersion+' install card');
  await screenshot(app, scenario+'-offered');
  await observeRendererHandoff(app);
  assert.equal(await app.evaluate("document.body.innerText.includes('تحديث ملفات تطبيق سطح المكتب')"), true,
    'The offered update must be the signed shell file update.');
  await clickVisibleButton(app, installButton);
  await clickVisibleButton(app, 'تأكيد');

  // From confirmation onward the harness only observes until the new process
  // has finished the transaction. It makes no update, health-signal, or rollback call.
  const outcome = await waitFor('automatic terminal updater receipt', async () => {
    observeSupervisorPhase();
    const receipt = readTerminalReceipt();
    if (receipt) return receipt;
    if (observations.renderer_handoff && !observations.renderer_handoff.displayed_error?.includes('انتهت مهلة انتظار')) {
      throw new Error('Desktop handoff rejected: '+JSON.stringify(observations.renderer_handoff));
    }
    const rejection = updateNetwork.find(event => event.type === 'response' && event.result && (
      (event.path.endsWith('/updates/apply') && event.status >= 400)
      || (/\/update\/jobs\/\d+$/.test(event.path) && event.result.data?.status === 'failed')
    ));
    if (rejection) {
      throw new Error('Update Center rejected the update: '+JSON.stringify(rejection.result));
    }
    return null;
  }, 360_000);
  observations.supervisor = safeSupervisorReceipt(outcome);
  assert.equal(outcome.outcome, scenario === 'rollback' ? 'rolled_back' : 'completed', 'The supervisor produced the wrong terminal outcome.');
  assert.equal(outcome.source_version, sourceVersion);
  assert.equal(outcome.target_version, targetVersion);
  assert.equal(outcome.source_pid, before.processes.desktop.pid);
  assert.ok(Number.isInteger(outcome.candidate_pid) && outcome.candidate_pid > 0);
  assert.match(outcome.database_backup_sha256, /^[a-f0-9]{64}$/);

  const expectedVersion = scenario === 'rollback' ? sourceVersion : targetVersion;
  const health = scenario === 'rollback' ? outcome.restored_health : outcome.candidate_health;
  assert.equal(health.version, expectedVersion);
  assert.equal(health.php, true);
  assert.equal(health.database, true);
  assert.ok(['sign-in', 'workspace', 'password-change'].includes(health.ui), 'Expected the production window to render its actual state.');
  assert.equal(health.window_visible, true, 'The supervisor settled before the real main window became visible.');
  if (scenario === 'rollback') {
    const attempted = readJson(failureTrace);
    observations.candidate_failure = attempted;
    assert.equal(attempted.pid, outcome.candidate_pid);
    verifyProcessRestart(before.processes.desktop, attempted, 'Failing desktop');
    assert.ok(attempted.phpPort > 0 && attempted.mysqlPort > 0);
    assert.equal(attempted.electronEngine, '43.8.11', 'The failing process must use the new Electron engine.');
    assert.equal(attempted.phase, 'core-ready-before-exit');
    assert.equal(outcome.candidate_exit_code, 78);
    assert.equal(outcome.candidate_failure, 'The restarted POS process exited before health proof: 78');
    assert.equal(outcome.candidate_health, null);
    assert.equal(outcome.restored_failure, null);
    assert.ok(Number.isInteger(outcome.restored_pid) && outcome.restored_pid > 0);
  } else {
    assert.ok(!fs.existsSync(failureTrace));
  }

  const expectedPid = scenario === 'rollback' ? outcome.restored_pid : outcome.candidate_pid;
  try {
    await app.reconnect(false, restartedDebugPort);
  } catch (error) {
    observations.reopened_discovery = inspectReopenedProcess(expectedPid, restartedDebugPort);
    throw error;
  }
  const needsLogin = await app.evaluate("Boolean(document.querySelector('input[type=email]'))");
  if (needsLogin) await app.login(credentials.email, credentials.password);
  const after = await verifyUsableRuntime(app, expectedVersion,
    scenario === 'rollback' ? 'source' : 'target', scenario === 'rollback' ? '43.2.0' : '43.8.11', restartedDebugPort);
  assert.equal(after.processes.desktop.pid, expectedPid);
  for (const name of ['desktop', 'php', 'database']) {
    verifyProcessRestart(before.processes[name], after.processes[name], name);
  }
  if (scenario === 'rollback') verifyProcessRestart(observations.candidate_failure, after.processes.desktop, 'Restored desktop');
  const preservedRow = await getCategory(app, marker);
  assert.equal(preservedRow.id, row.id, 'The original MariaDB business row did not survive.');
  const afterFiles = scenario === 'rollback'
    ? checkInstalledInventory(inventory.source_inventory, missingAtSource)
    : checkInstalledInventory(inventory.target_inventory, inventory.deleted_files);
  const liveAfterInventory = observeInstalledInventory(scenario === 'rollback' ? 'source' : 'target');
  observations.after = { ...after, live_inventory: liveAfterInventory };
  assert.equal(liveAfterInventory.digest_matches, true, 'The reopened application has an unexpected managed inventory.');
  for (const [name, witness] of Object.entries(liveSourceInventory.qz_device_witnesses)) {
    assert.equal(witness.classification, 'path_protected', 'An existing QZ state file is not protected: '+name);
    assert.deepEqual(liveAfterInventory.qz_device_witnesses[name], witness, 'Existing QZ device state changed: '+name);
  }
  for (const { file, sha256 } of witnesses) assert.equal(hash(file), sha256, 'Protected file changed: '+path.basename(file));
  assert.equal(hash(uninstaller), beforeUninstaller, 'Installer-owned uninstaller bytes changed.');
  const applies = updateNetwork.filter(event => event.path.endsWith('/updates/apply'));
  const submissions = applies.filter(event => event.type === 'request' && event.method === 'POST');
  assert.equal(submissions.length, 1, 'Expected exactly one Update Center submission.');
  const [submission] = submissions;
  const responses = applies.filter(event => event.type === 'response' && event.request_id === submission.request_id);
  assert.equal(responses.length, 1, 'Expected one response to the sole update POST.');
  const [admission] = responses;
  assert.ok(admission.status >= 200 && admission.status < 300, 'The submitted update POST was not admitted.');
  const jobId = admission.result?.data?.job_id;
  assert.ok(Number.isSafeInteger(jobId) && jobId > 0, 'The submitted update POST did not return a valid background job.');
  observations.apply_job_id = jobId;
  assert.ok(!updateNetwork.some(event => event.path.endsWith('/updates/rollback')), 'A rollback API call was observed.');
  observations.transport = verifyTransport({ requests, releaseRoot, lab, inventory, binary: binaryAcceptance, scenario });
  if (binaryAcceptance) {
    const staging = updateNetwork.find(event => event.type === 'response' && event.path.endsWith('/update/jobs/'+jobId) &&
      event.result?.result?.shell_transport);
    assert.ok(staging, 'The apply job did not report the selected shell transport.');
    assert.equal(staging.result.result.shell_transport, observations.transport.transport);
    assert.equal(staging.result.result.shell_transport_bytes, observations.transport.selected_transport_bytes);
  }
  assert.ok(requests.some(request => request.authority === 'api.github.com' && request.status === 200 && request.path.endsWith('/pos-desktop-updates/releases')));
  await screenshot(app, scenario+'-reopened');
  observations.after = {
    ...observations.after, inventory: afterFiles, row_id: preservedRow.id,
    uninstaller_sha256: beforeUninstaller,
    protected_sha256: Object.fromEntries(witnesses.map(({file, sha256}) => [path.basename(file), sha256])),
  };
  await app.stop();
  await waitFor('the reopened desktop to exit cleanly', () => {
    try {
      process.kill(expectedPid, 0);
      return false;
    } catch (error) {
      if (error.code !== 'ESRCH') throw error;
      return true;
    }
  }, 30_000);
  writeJson(path.join(evidence, scenario+'.json'), {
    ...observations, scenario, passed: true, provider: requests, update_network: updateNetwork,
    manifest_sha256: hash(path.join(releaseRoot, 'shell-manifest.json')),
    source_inventory_sha256: inventory.source_inventory_sha256,
    target_inventory_sha256: inventory.target_inventory_sha256,
    zip_sha256: inventory.package.sha256,
  });
  console.log('PASS: installed Update Center '+scenario+' and actual desktop/PHP/MariaDB restart verified.');
}

try {
  await runAcceptance();
} catch (error) {
  observations.failure = { ...safeFields(error, ['message']), diagnostics: updaterDiagnostics() };
  if (observations.before) {
    try {
      observations.failure.ui = safeFields(await app.evaluate("(() => {"+
        "const button = [...document.querySelectorAll('button')].find(b => b.innerText.includes('التحقق من التحديثات'));"+
        "const job_id = sessionStorage.getItem('pos.active_update_job_id');"+
        "const handoff_marker = job_id ? sessionStorage.getItem('pos.update_handoff_'+job_id) : null;"+
        "return { route: location.pathname, update_center: button?.closest('section')?.innerText, job_id, handoff_marker }; })()"),
      ['route', 'update_center', 'job_id', 'handoff_marker']);
    } catch { /* The old renderer may have exited before the failure. */ }
  }
  throw error;
} finally {
  await app.stop().catch(() => {});
  fixture.close();
  writeJson(path.join(evidence, scenario+'-trace.json'), { ...observations, provider: requests, update_network: updateNetwork });
}
