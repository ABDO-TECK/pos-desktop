import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createDesktopLab, delay } from './desktop-transition-lab.mjs';
import { assetNames, startReleaseFixture } from './fixtures/shell-inapp-release-origin.mjs';

assert.equal(process.platform, 'win32');
assert.equal(process.env.GITHUB_ACTIONS, 'true');
assert.equal(process.env.RUNNER_ENVIRONMENT, 'github-hosted');
const scenario = process.argv[2];
assert.ok(['rollback', 'commit'].includes(scenario), 'Expected a known acceptance scenario.');

const lab = path.join(process.env.RUNNER_TEMP, 'shell-inapp');
const installRoot = path.join(lab, 'installed');
const profile = path.join(lab, scenario+'-profile');
const local = path.join(profile, 'local');
const roaming = path.join(profile, 'roaming');
const shared = path.join(profile, 'shared');
const dataDir = path.join(shared, 'POS System');
const configDir = path.join(roaming, 'POS System');
const failureTrace = path.join(roaming, 'candidate-failure.json');
const releaseRoot = path.join(lab, scenario === 'rollback' ? 'failing' : 'target', 'release');
const evidence = path.join(lab, 'evidence');
const readJson = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const writeJson = (file, data) => fs.writeFileSync(file, JSON.stringify(data, null, 2)+'\n');
const hash = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const inventory = readJson(path.join(releaseRoot, 'shell-manifest.json'));
const requests = [];
const updateNetwork = [];
const observations = {};

async function waitFor(description, predicate, timeout = 240_000) {
  const deadline = Date.now()+timeout;
  while (Date.now() < deadline) {
    const result = await predicate();
    if (result) return result;
    await delay(500);
  }
  throw new Error('Timed out waiting for '+description);
}

function probeProcesses(apiPort, mysqlPort) {
  const script = [
    "$ErrorActionPreference = 'Stop'",
    '$processes = foreach ($port in @(19231,'+Number(apiPort)+','+Number(mysqlPort)+')) {',
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

function checkInstalledInventory(expected, deleted = []) {
  const requiredFiles = [
    'POS Desktop.exe',
    'resources/app.asar',
    'resources/app.asar.unpacked/backend/backend.phar',
    'resources/app.asar.unpacked/frontend/dist/index.html',
    'resources/app.asar.unpacked/version.json',
  ];
  const observations = {};
  for (const [relative, entry] of Object.entries(expected.files)) {
    const file = path.join(installRoot, relative);
    assert.equal(fs.statSync(file).size, entry.size, 'Wrong installed size for '+relative);
    const sha256 = hash(file);
    assert.equal(sha256, entry.sha256, 'Wrong installed SHA-256 for '+relative);
    if (requiredFiles.includes(relative)) observations[relative] = sha256;
  }
  assert.equal(Object.keys(observations).length, requiredFiles.length, 'Missing required package coverage.');
  for (const relative of deleted) {
    assert.ok(!fs.existsSync(path.join(installRoot, relative)), 'Unexpected retained file '+relative);
  }
  return { files: Object.keys(expected.files).length, required_sha256: observations };
}

function readTerminalReceipt() {
  const state = path.join(local, 'pos-shell-update-v2');
  try {
    const installations = fs.readdirSync(state);
    const receipts = installations.flatMap(installation => {
      const directory = path.join(state, installation);
      if (!/^[a-f0-9]{64}$/.test(installation) || !fs.statSync(directory).isDirectory()) return [];
      return fs.readdirSync(directory).filter(name => /^tx_[a-f0-9]{32}$/.test(name))
        .map(name => path.join(directory, name, 'receipt.json'))
        .filter(file => fs.existsSync(file));
    });
    assert.ok(receipts.length <= 1, 'Expected one operation receipt for the isolated scenario.');
    return receipts.length === 1 ? readJson(receipts[0]) : null;
  } catch (error) {
    if (error.code !== 'ENOENT' && !(error instanceof SyntaxError)) throw error;
    return null;
  }
}

async function category(app, method, name) {
  const response = await app.evaluate("(async () => {"+
    "const base = window.API_BASE_URL.replace(/\\/+$/, '') + '/api/v1';"+
    "const request = (route, options = {}) => fetch(base + route, { credentials: 'include', cache: 'no-store', ...options });"+
    (method === 'POST'
      ? "const protection = await (await request('/csrf-cookie')).json();"+
        "const res = await request('/categories', {method: 'POST', headers:{'Content-Type':'application/json','X-XSRF-TOKEN':protection.data.csrf_token},"+
        "body: JSON.stringify({name:"+JSON.stringify(name)+"})});"
      : "const res = await request('/categories?limit=100');")+
    "const json = await res.json();"+
    "return {status:res.status, data:"+ (method === 'POST' ? "json.data" : "json.data?.filter(row => row.name === "+JSON.stringify(name)+")") +"};})()");
  assert.equal(response.status, method === 'POST' ? 201 : 200, 'Category API failed through the packaged renderer.');
  return response.data;
}

async function verifyUsableRuntime(app, version, marker) {
  const authenticated = "Boolean(document.querySelector('a[href=\"/settings\"]'))";
  await app.until(authenticated, 'authenticated navigation');
  const frontend = await app.evaluate("(async () => ({ version: await window.electronAPI.getVersion(), "+
    "marker: document.querySelector('meta[name=\"pos-ci-shell-build\"]')?.content, origin: location.origin }))()");
  assert.equal(frontend.marker, marker);
  assert.equal(frontend.version, version);
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
  const processes = probeProcesses(ports.apiPort, ports.mysqlPort);
  return { frontend, backend_health: backendHealth, processes, runtime_ports: ports };
}

async function clickVisibleButton(app, label) {
  const source = JSON.stringify(label);
  const locator = "[...document.querySelectorAll('button')].find(b => !b.disabled && b.textContent.trim() === "+source+")";
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

for (const folder of [local, roaming, shared, path.join(local, 'pos-ci-chromium')]) {
  fs.mkdirSync(folder, { recursive: true });
}
const fixture = await startReleaseFixture(lab, releaseRoot, requests);
const app = createDesktopLab(installRoot, path.join(profile, 'diagnostics'), {
  LOCALAPPDATA: local,
  APPDATA: roaming,
  PROGRAMDATA: shared,
  HTTPS_PROXY: fixture.proxyUrl,
  NO_PROXY: 'localhost,127.0.0.1',
});

try {
  assert.equal(inventory.format, 2);
  assert.equal(inventory.from_version, '0.0.13');
  assert.equal(inventory.version, '0.0.14');
  const beforeFiles = checkInstalledInventory(inventory.source_inventory);
  const missingAtSource = inventory.files.map(file => file.path).filter(file => !inventory.source_inventory.files[file]);
  for (const relative of missingAtSource) assert.ok(!fs.existsSync(path.join(installRoot, relative)));
  const uninstaller = path.join(installRoot, 'Uninstall POS Desktop.exe');
  const beforeUninstaller = hash(uninstaller);
  await app.start();
  const credentials = await app.freshLogin();
  const before = await verifyUsableRuntime(app, '0.0.13', 'source');
  const marker = 'Shell acceptance '+crypto.randomBytes(10).toString('hex');
  const row = await category(app, 'POST', marker);
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

  await app.system();
  const checkButton = 'التحقق من التحديثات';
  const installButton = 'تثبيت التحديث الآن';
  await app.until("[...document.querySelectorAll('button')].some(b=>!b.disabled&&b.textContent.trim()==="+JSON.stringify(checkButton)+")", 'enabled update check');
  await clickVisibleButton(app, checkButton);
  await app.until("document.body.innerText.includes('v0.0.14') && [...document.querySelectorAll('button')].some(b=>!b.disabled&&b.textContent.trim()==="+JSON.stringify(installButton)+")", 'version 0.0.14 install card');
  await screenshot(app, scenario+'-offered');
  app.onEvent((method, event) => {
    if (method === 'Network.requestWillBeSent' && /\/updates\/(?:apply|rollback)(?:\?|$)/.test(event.request.url)) {
      updateNetwork.push({ type: 'request', method: event.request.method, path: new URL(event.request.url).pathname, request_id: event.requestId });
    }
    if (method === 'Network.responseReceived' && /\/updates\/(?:apply|rollback)(?:\?|$)/.test(event.response.url)) {
      updateNetwork.push({ type: 'response', status: event.response.status, path: new URL(event.response.url).pathname, request_id: event.requestId });
    }
  });
  await app.call('Network.enable');
  await clickVisibleButton(app, installButton);
  await clickVisibleButton(app, 'تأكيد');

  // From confirmation onward the harness only observes until the new process
  // has finished the transaction. It makes no update, health-signal, or rollback call.
  const outcome = await waitFor('automatic terminal updater receipt', readTerminalReceipt, 360_000);
  observations.supervisor = outcome;
  assert.equal(outcome.outcome, scenario === 'rollback' ? 'rolled_back' : 'completed', 'The supervisor produced the wrong terminal outcome.');
  assert.equal(outcome.source_version, '0.0.13');
  assert.equal(outcome.target_version, '0.0.14');
  assert.equal(outcome.source_pid, before.processes.desktop.pid);
  assert.ok(Number.isInteger(outcome.candidate_pid) && outcome.candidate_pid !== outcome.source_pid);
  assert.match(outcome.database_backup_sha256, /^[a-f0-9]{64}$/);

  const expectedVersion = scenario === 'rollback' ? '0.0.13' : '0.0.14';
  const health = scenario === 'rollback' ? outcome.restored_health : outcome.candidate_health;
  assert.equal(health.version, expectedVersion);
  assert.equal(health.php, true);
  assert.equal(health.database, true);
  assert.equal(health.ui, 'sign-in');
  if (scenario === 'rollback') {
    const attempted = readJson(failureTrace);
    observations.candidate_failure = attempted;
    assert.equal(attempted.pid, outcome.candidate_pid);
    assert.ok(attempted.phpPort > 0 && attempted.mysqlPort > 0);
    assert.equal(outcome.candidate_exit_code, 78);
    assert.ok(outcome.restored_pid !== outcome.source_pid && outcome.restored_pid !== outcome.candidate_pid);
  } else {
    assert.ok(!fs.existsSync(failureTrace));
  }

  await app.reconnect();
  const needsLogin = await app.evaluate("Boolean(document.querySelector('input[type=email]'))");
  if (needsLogin) await app.login(credentials.email, credentials.password);
  const after = await verifyUsableRuntime(app, expectedVersion, scenario === 'rollback' ? 'source' : 'target');
  const expectedPid = scenario === 'rollback' ? outcome.restored_pid : outcome.candidate_pid;
  assert.equal(after.processes.desktop.pid, expectedPid);
  for (const name of ['desktop', 'php', 'database']) {
    assert.notEqual(after.processes[name].pid, before.processes[name].pid, name+' process did not restart.');
    assert.ok(Date.parse(after.processes[name].started_at) > Date.parse(before.processes[name].started_at), name+' start time did not advance.');
  }
  const rows = await category(app, 'GET', marker);
  assert.equal(rows.length, 1);
  assert.equal(rows[0].id, row.id, 'The original MariaDB business row did not survive.');
  const afterFiles = scenario === 'rollback'
    ? checkInstalledInventory(inventory.source_inventory, missingAtSource)
    : checkInstalledInventory(inventory.target_inventory, inventory.deleted_files);
  for (const { file, sha256 } of witnesses) assert.equal(hash(file), sha256, 'Protected file changed: '+path.basename(file));
  assert.equal(hash(uninstaller), beforeUninstaller, 'Installer-owned uninstaller bytes changed.');
  const applies = updateNetwork.filter(event => event.path.endsWith('/updates/apply'));
  assert.equal(applies.filter(event => event.type === 'request' && event.method === 'POST').length, 1);
  assert.equal(applies.filter(event => event.type === 'response' && event.status >= 200 && event.status < 300).length, 1);
  assert.ok(!updateNetwork.some(event => event.path.endsWith('/updates/rollback')), 'A rollback API call was observed.');
  for (const name of assetNames) {
    const bytes = fs.statSync(path.join(releaseRoot, name)).size;
    assert.ok(requests.some(request => request.status === 200 && request.path.endsWith('/'+name) && request.delivered_bytes === bytes),
      'No complete HTTPS fixture transfer observed for '+name);
  }
  assert.ok(requests.some(request => request.authority === 'api.github.com' && request.status === 200 && request.path.endsWith('/pos-desktop-updates/releases')));
  await screenshot(app, scenario+'-reopened');
  observations.after = {
    ...after, inventory: afterFiles, row_id: rows[0].id,
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
    zip_sha256: inventory.package.sha256,
  });
  console.log('PASS: installed Update Center '+scenario+' and actual desktop/PHP/MariaDB restart verified.');
} finally {
  await app.stop().catch(() => {});
  fixture.close();
  writeJson(path.join(evidence, scenario+'-trace.json'), { ...observations, provider: requests, update_network: updateNetwork });
}
