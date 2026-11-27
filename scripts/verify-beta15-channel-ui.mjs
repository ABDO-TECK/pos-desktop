import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { createDesktopLab, delay } from './desktop-transition-lab.mjs';
import { attachTransitionTransport } from './channel-transition-ui-transport.mjs';

assert.equal(process.platform, 'win32');
assert.equal(process.env.GITHUB_ACTIONS, 'true');
assert.equal(process.env.RUNNER_ENVIRONMENT, 'github-hosted');
assert.ok(!process.env.UPDATE_PRIVATE_KEY && !process.env.SOURCE_PRIVATE_SIGNING_KEY);

const [mode, lab, baseline, assets] = process.argv.slice(2);
assert.ok(['staged', 'live'].includes(mode));
const runner = fs.realpathSync(process.env.RUNNER_TEMP);
assert.equal(fs.realpathSync(lab).toLowerCase(), path.join(runner, 'beta15-channel-' + mode).toLowerCase());
const stable = '0.0.14';
const beta = '0.0.15-beta.1';
const sourceRepo = 'pos-desktop';
const updatesRepo = 'pos-desktop-updates';
const installed = path.join(lab, 'installed');
const root = path.join(installed, 'resources', 'app.asar.unpacked');
const original = path.join(baseline, 'source');
const evidence = path.join(lab, 'evidence');
const diagnostics = path.join(lab, 'diagnostics');
const inventoryName = 'deployment-inventory.json';
const stableInventoryPath = path.join(baseline, inventoryName);
const betaInventoryPath = path.join(assets, inventoryName);
const read = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const write = (file, object) => fs.writeFileSync(file, JSON.stringify(object, null, 2) + '\n');
const digest = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const installedVersion = () => read(path.join(root, 'version.json'));
const stableInventory = read(stableInventoryPath);
const betaInventory = read(betaInventoryPath);
assert.equal(digest(stableInventoryPath), '1d65fabbc8ec83c7786bb94fd530f587e5065a6bd0b78139ac4a4f63af7fa0fd');
assert.equal(stableInventory.version, stable);
assert.equal(betaInventory.version, beta);
assert.equal(betaInventory.shell_sha256, stableInventory.shell_sha256);

for (const [environment, folder] of Object.entries({
  APPDATA: 'roaming', LOCALAPPDATA: 'local', PROGRAMDATA: 'shared',
})) {
  process.env[environment] = path.join(lab, folder);
  fs.mkdirSync(process.env[environment], { recursive: true });
}
fs.mkdirSync(evidence);
const config = path.join(process.env.APPDATA, 'POS System');
const storage = path.join(process.env.PROGRAMDATA, 'POS System');
const trace = path.join(diagnostics, 'release-transport.jsonl');
const shell = ['POS Desktop.exe', 'resources/app.asar'];
const initialShellHashes = Object.fromEntries(shell.map(file => [file, digest(path.join(original, file))]));
const markerName = 'CI beta acceptance ' + crypto.randomBytes(8).toString('hex');
const app = createDesktopLab(installed, diagnostics);
const accountLink = 'Boolean(document.querySelector("a[href=\'/settings\']"))';
const loginInput = 'Boolean(document.querySelector("input[type=email]"))';
const select = 'document.querySelector("select:has(option[value=beta])")';
const panel = '[data-testid=channel-transition]';
const applyButton = '[data-testid=apply-channel-transition]';
const preview = '[data-testid=transition-preview-result]';
const evidenceFile = path.join(evidence, 'acceptance.json');
const summary = { mode, stable_version: stable, beta_version: beta, passed: false, transitions: [] };
let transport;
let auth;

function makeStagedProvider() {
  const localFiles = {};
  const responses = {};
  const release = (repo, version, files, prerelease) => ({
    tag_name: 'v' + version,
    name: 'POS Desktop v' + version,
    body: 'Signed Windows release acceptance',
    html_url: 'https://github.com/ABDO-TECK/' + repo + '/releases/tag/v' + version,
    published_at: '2026-11-27T00:00:00Z',
    draft: false,
    prerelease,
    assets: files.map(file => {
      const name = path.basename(file);
      const url = 'https://github.com/ABDO-TECK/' + repo + '/releases/download/v' + version + '/' + name;
      localFiles[url] = file;
      return { name, browser_download_url: url, size: fs.statSync(file).size };
    }),
  });
  const candidate = release(updatesRepo, beta, fs.readdirSync(assets, { withFileTypes: true })
    .filter(entry => entry.isFile()).map(entry => path.join(assets, entry.name)), true);
  const setup = release(sourceRepo, stable, [stableInventoryPath], false);
  const missing = { http_code: 404 };
  for (const [repo, record, missingVersion] of [[updatesRepo, candidate, stable], [sourceRepo, setup, beta]]) {
    const prefix = 'https://api.github.com/repos/ABDO-TECK/' + repo + '/releases';
    responses[prefix + '?per_page=100&page=1'] = [record];
    responses[prefix + '/tags/' + record.tag_name] = record;
    responses[prefix + '/tags/v' + missingVersion] = missing;
  }
  const fixture = path.join(lab, 'provider-fixture.json');
  write(fixture, { mode: 'delta', responses, assets: localFiles, trace });
  return fixture;
}

function assertInventory(inventory, previous = inventory) {
  for (const [file, expected] of Object.entries(inventory.files)) {
    const name = path.join(root, file);
    assert.equal(fs.statSync(name).size, expected.size, file);
    assert.equal(digest(name), expected.sha256, file);
  }
  for (const file of Object.keys(previous.files)) {
    if (!(file in inventory.files)) assert.equal(fs.existsSync(path.join(root, file)), false, file);
  }
  for (const [file, expected] of Object.entries(initialShellHashes)) {
    assert.equal(digest(path.join(installed, file)), expected, file);
  }
}

async function screenshot(name) {
  await app.screenshot(name);
  fs.renameSync(path.join(diagnostics, name + '.png'), path.join(evidence, name + '.png'));
}

async function verifyRuntime(version, channel, inventory, previous) {
  assert.equal(installedVersion().version, version);
  assert.equal(installedVersion().channel, channel);
  assertInventory(inventory, previous);
  const native = await app.evaluate('window.electronAPI.getVersion()');
  assert.equal(native, stable, 'Installed native runtime version changed.');
  const ports = read(path.join(config, 'runtime_ports.json'));
  const address = new URL('/api/health', ports.apiBaseUrl);
  assert.equal(address.hostname, '127.0.0.1');
  const response = await fetch(address, { signal: AbortSignal.timeout(10_000) });
  assert.equal(response.status, 200);
  const health = await response.json();
  assert.equal(health.status, 'ok');
  assert.equal(health.version, version);
  return { app: version, channel, native, health: health.status, managed_files: Object.keys(inventory.files).length };
}

async function category(create = false) {
  return app.evaluate('(async () => {' +
    'const api = new URL("/api/v1/", window.API_BASE_URL).href;' +
    'const name = ' + JSON.stringify(markerName) + ';' +
    'const options = {credentials: "include", cache: "no-store"};' +
    (create
      ? 'const auth = await fetch(api + "csrf-cookie", options);' +
        'const csrf = (await auth.json()).data.csrf_token;' +
        'const reply = await fetch(api + "categories", {...options, method: "POST", ' +
        'headers: {"Content-Type": "application/json", "X-XSRF-TOKEN": csrf}, body: JSON.stringify({name})});' +
        'return {status: reply.status, row: (await reply.json()).data};'
      : 'const reply = await fetch(api + "categories?limit=100", options);' +
        'return {status: reply.status, row: (await reply.json()).data.find(row => row.name === name)};') +
    '})()');
}

async function selectChannel(channel) {
  await app.until('Boolean(' + select + ' && !(' + select + ').disabled)', 'enabled update channel');
  await app.evaluate('(() => {const select = ' + select + ';' +
    'Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, "value").set.call(select, ' + JSON.stringify(channel) + ');' +
    'select.dispatchEvent(new Event("change", {bubbles: true}));})()');
  await app.until(select + '?.value === ' + JSON.stringify(channel) + ' && !(' + select + ').disabled',
    'saved ' + channel + ' preference');
}

async function showPreview(version) {
  const selector = 'document.querySelector(' + JSON.stringify(applyButton) + ')';
  await app.until('Boolean(' + selector + ' && !(' + selector + ').disabled && ' + selector +
    '.textContent.includes(' + JSON.stringify('v' + version) + '))', 'signed transition offer for ' + version, 180_000);
  const text = await app.evaluate('document.querySelector(' + JSON.stringify(preview) + ').innerText');
  assert.ok(text.includes('v' + version) && text.includes('Delta'), text);
}

async function transition(from, to, channel, inventory, previous, section) {
  await selectChannel(channel);
  await showPreview(to);
  assert.equal(installedVersion().version, from, 'The channel preference installed an update before confirmation.');
  const priorReceipt = readState()?.transition_receipt;
  await screenshot(section + '-offer');
  const page = crypto.randomBytes(8).toString('hex');
  await app.evaluate('window.posCiTransitionPage = ' + JSON.stringify(page));
  await app.evaluate('document.querySelector(' + JSON.stringify(applyButton) + ').click()');
  await app.click('تأكيد');

  const finished = await waitForReceipt(to, priorReceipt);
  await app.until('window.posCiTransitionPage !== ' + JSON.stringify(page) + ' && (' + loginInput + ' || ' +
    accountLink + ')', 'renderer reload after signed transition', 120_000);
  if (await app.evaluate(loginInput)) await app.login(auth.email, auth.password);
  await app.until(accountLink, 'authenticated workspace after signed transition');
  if (transport) {
    transport.resume();
    await transport.reauth();
  }
  const runtime = await verifyRuntime(to, channel, inventory, previous);
  const marker = await category();
  assert.equal(marker.status, 200);
  assert.equal(marker.row?.id, summary.business_record_id);
  await app.system();
  await app.until('document.querySelector(' + JSON.stringify(panel) + ')?.innerText.includes(' +
    JSON.stringify('v' + to + ' · ' + channel.toUpperCase()) + ')', 'deployed ' + channel + ' version in Update Center');
  await screenshot(section + '-installed');
  summary.transitions.push({ from, to, receipt: finished.receipt_id, runtime, business_record_preserved: true });
  write(evidenceFile, summary);
}

function readState() {
  const file = path.join(storage, 'update-state.json');
  try {
    return read(file);
  } catch (error) {
    // The runtime holds a write lock while replacing this state. A partial
    // read is transient and cannot satisfy the terminal receipt checks.
    if (error.code === 'ENOENT' || error instanceof SyntaxError) return null;
    throw error;
  }
}

async function waitForReceipt(version, prior) {
  const deadline = Date.now() + 250_000;
  while (Date.now() < deadline) {
    const state = readState();
    const id = state?.transition_receipt;
    if (state?.to_version === version && id !== prior && /^[a-f0-9]{64}$/.test(id ?? '')) {
      const file = path.join(storage, 'channel-transition-receipts', id + '.json');
      if (fs.existsSync(file)) {
        const receipt = read(file);
        assert.notEqual(receipt.state, 'failed', String(receipt.reason_code));
        if (receipt.state === 'completed' && state.transition_verified === true) {
          assert.equal(receipt.target_inventory_matches, true);
          assert.equal(receipt.data_compatible, true);
          assert.equal(receipt.requires_recovery, false);
          return receipt;
        }
      }
      assert.ok(!['failed', 'rolled_back'].includes(state.state), String(state.state));
    }
    await delay(500);
  }
  throw new Error('No completed signed transition receipt for ' + version);
}

try {
  assertInventory(stableInventory);
  await app.start(19235);
  auth = await app.freshLogin();
  summary.baseline = await verifyRuntime(stable, 'stable', stableInventory);
  const marker = await category(true);
  assert.equal(marker.status, 201, 'Could not create the isolated business record.');
  assert.ok(Number(marker.row?.id) > 0);
  summary.business_record_id = marker.row.id;
  if (mode === 'staged') transport = await attachTransitionTransport({
    ui: app, application: installed, storage, evidence: diagnostics, fixture: makeStagedProvider(), auth,
  });
  await app.system();
  await app.until(select + '?.value === "stable"', 'default stable channel');
  await app.until('document.querySelector(' + JSON.stringify(panel) + ')?.innerText.includes("v0.0.14 · STABLE")',
    'stable version shown before opting into beta');
  assert.equal(await app.evaluate('document.body.innerText.includes(' + JSON.stringify(beta) + ')'), false,
    'The stable channel displayed the beta before opt-in.');
  await screenshot('stable-before-opt-in');

  await transition(stable, beta, 'beta', betaInventory, stableInventory, 'beta');
  await app.click('التحقق من التحديثات');
  await app.until('/النظام محدّث|لا يوجد تحديث متاح للتثبيت/.test(document.body.innerText)',
    'Arabic status with no installable update for the deployed beta', 180_000);
  assert.equal(await app.evaluate('[...document.querySelectorAll("button")].some(button => ' +
    'button.textContent.trim() === "تثبيت التحديث الآن" && !button.disabled)'), false);
  await screenshot('beta-update-status');
  await transition(beta, stable, 'stable', stableInventory, betaInventory, 'stable');
  assert.notEqual(summary.transitions[0].receipt, summary.transitions[1].receipt);
  if (mode === 'staged') {
    const transfers = fs.readFileSync(trace, 'utf8').trim().split('\n').map(JSON.parse);
    const copied = transfers.filter(item => item.transport === 'copied_signed_package').map(item => item.asset);
    assert.ok(copied.includes('transition-0.0.14-to-beta-0.0.15-beta.1-delta.zip'));
    assert.ok(copied.includes('transition-0.0.15-beta.1-to-stable-0.0.14-delta.zip'));
  }
  summary.signed_inventory_sha256 = digest(betaInventoryPath);
  summary.native_shell_sha256 = initialShellHashes;
  summary.passed = true;
  console.log('PASS: official Setup 0.0.14 opted into the signed beta and returned to stable with its shell and business record intact (' + mode + ').');
} catch (error) {
  summary.error = error.message;
  await screenshot('failure').catch(() => {});
  throw error;
} finally {
  write(evidenceFile, summary);
  try {
    await transport?.close();
  } finally {
    await app.stop();
  }
}
