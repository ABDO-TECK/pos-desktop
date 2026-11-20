import assert from 'node:assert/strict';
import { createHash, createPublicKey, randomBytes } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { createDesktopLab, delay } from './desktop-transition-lab.mjs';
import supervisor from '../electron/services/shell-update-files.js';

assert.equal(process.platform, 'win32');
assert.equal(process.env.GITHUB_ACTIONS, 'true');
assert.equal(process.env.RUNNER_ENVIRONMENT, 'github-hosted');
assert.ok(!['UPDATE_SERVER_URL', 'UPDATE_REPO_NAME', 'UPDATE_REPO_OWNER', 'UPDATE_ALLOWED_HOSTS', 'HTTPS_PROXY',
  'ALL_PROXY', 'NODE_EXTRA_CA_CERTS', 'CURL_CA_BUNDLE', 'SSL_CERT_FILE'].some(name => process.env[name]),
  'Expected the default GitHub provider and TLS configuration on the disposable runner.');

const lab = path.join(process.env.RUNNER_TEMP, 'p14-live');
assert.equal(process.env.POS_LIVE_PROOF_ROOT, lab);
const install = path.join(lab, 'app');
const resources = path.join(install, 'resources');
const evidence = path.join(lab, 'evidence');
const profile = {
  LOCALAPPDATA: path.join(lab, 'local'),
  APPDATA: path.join(lab, 'roaming'),
  PROGRAMDATA: path.join(lab, 'shared'),
};
const storage = path.join(profile.PROGRAMDATA, 'POS System');
const providerCache = path.join(storage, 'remote_version_cache_stable.json');
const failureCache = path.join(storage, 'remote_version_failure_cache_stable.json');
const version = '0.0.14';
const setupRepository = 'ABDO-TECK/pos-desktop';
const feedRepository = 'ABDO-TECK/pos-desktop-updates';
const feedApi = 'https://api.github.com/repos/' + feedRepository + '/releases';
const checkRoute = '/api/v1/updates/check';
const checkButton = 'التحقق من التحديثات';
const successToast = '✅ النظام محدّث لأحدث إصدار.';
const debugPort = 19231;
const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
const readJson = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const writeEvidence = (name, data) => fs.writeFileSync(path.join(evidence, name), JSON.stringify(data, null, 2) + '\n');
for (const directory of Object.values(profile)) fs.mkdirSync(directory);
const app = createDesktopLab(install, path.join(lab, 'diagnostics'), profile);
const receipt = { passed: false, check_requests: [] };

function readUpdaterAssets(archive) {
  // ASAR starts with a size pickle followed by the header's JSON string pickle.
  const fd = fs.openSync(archive, 'r');
  try {
    const archiveSize = fs.fstatSync(fd).size;
    const read = (offset, size) => {
      assert.ok(Number.isSafeInteger(offset) && Number.isSafeInteger(size) && offset >= 0 &&
        size > 0 && size <= 8 * 1024 * 1024 && offset + size <= archiveSize, 'Invalid ASAR entry bounds.');
      const bytes = Buffer.alloc(size);
      assert.equal(fs.readSync(fd, bytes, 0, size, offset), size, 'Truncated installed ASAR.');
      return bytes;
    };
    const prefix = read(0, 16);
    assert.equal(prefix.readUInt32LE(0), 4, 'Unexpected ASAR size pickle.');
    const headerSize = prefix.readUInt32LE(4);
    const jsonSize = prefix.readUInt32LE(12);
    assert.ok(jsonSize + 8 <= headerSize, 'Unexpected ASAR header size.');
    const header = JSON.parse(read(16, jsonSize).toString('utf8'));
    const assets = header.files.electron.files.assets.files;
    const readAsset = name => {
      const entry = assets[name];
      assert.ok(entry && !entry.unpacked && /^\d+$/.test(entry.offset), 'Missing packaged update asset ' + name);
      return read(8 + headerSize + Number(entry.offset), entry.size);
    };
    return {
      key: readAsset('shell-update-public-key.pem'),
      broker: readAsset('shell-update-broker.bin'),
    };
  } finally {
    fs.closeSync(fd);
  }
}

function inspectInstalledUpdater() {
  const archive = path.join(resources, 'app.asar');
  const unpacked = path.join(resources, 'app.asar.unpacked');
  const updater = fs.readFileSync(path.join(resources, 'app-update.yml'), 'utf8');
  const { key, broker } = readUpdaterAssets(archive);
  const backendKey = fs.readFileSync(path.join(unpacked, 'backend', 'certs', 'update_public_key.pem'));
  const reviewedKey = fs.readFileSync(new URL('../backend/certs/update_public_key.pem', import.meta.url));
  validateInstalledVersion(unpacked);
  assert.match(updater, /^provider:\s*github\s*$/m);
  assert.match(updater, /^owner:\s*ABDO-TECK\s*$/m);
  assert.match(updater, /^repo:\s*pos-desktop\s*$/m);
  assert.deepEqual(key, backendKey, 'Desktop and PHP trust roots differ.');
  assert.deepEqual(key, reviewedKey, 'Installed key differs from the reviewed public release key.');
  assert.equal(createPublicKey(key).asymmetricKeyType, 'rsa');
  assert.equal(broker.toString('ascii', 0, 2), 'MZ', 'The packaged shell broker must be a Windows executable.');
  const local = fs.realpathSync.native(profile.LOCALAPPDATA);
  const root = fs.realpathSync.native(install);
  const stateRoot = supervisor.stateLocation(root, local);
  supervisor.assertOperationPathBudget(supervisor.operationPaths(stateRoot, 'tx_' + '0'.repeat(32)));
  assert.equal(fs.statSync(root).dev, fs.statSync(local).dev, 'Runner install and local profile must share a volume.');
  assert.equal(fs.existsSync(path.join(stateRoot, 'active.json')), false, 'An update transaction already exists.');
  return {
    version, full_setup_provider: setupRepository, setup_provider_config_sha256: sha256(updater),
    shell_public_key_sha256: sha256(key), shell_broker_sha256: sha256(broker),
    shell_transaction_active: false, same_volume_and_path_budget: true,
    app_asar_sha256: app.hash(archive), app_exe_sha256: app.hash(path.join(install, 'POS Desktop.exe')),
    backend_phar_sha256: app.hash(path.join(unpacked, 'backend', 'backend.phar')),
  };
}

function validateInstalledVersion(unpacked) {
  const deployed = readJson(path.join(unpacked, 'version.json'));
  assert.equal(deployed.version, version);
}

function inspectLoopbackProcess(port, image, requiredArgument) {
  assert.ok(Number.isInteger(port) && port > 0 && port <= 65535);
  const script = [
    "$ErrorActionPreference = 'Stop'",
    '$listeners = @(Get-NetTCPConnection -LocalPort ' + port + ' -State Listen)',
    "if (!$listeners -or @($listeners | Where-Object { $_.LocalAddress -notin @('127.0.0.1','::1') }).Count) { throw 'Expected loopback listeners.' }",
    "$owners = @($listeners | Select-Object -ExpandProperty OwningProcess -Unique)",
    "if ($owners.Count -ne 1) { throw 'Expected one process to own the loopback port.' }",
    '$proc = Get-CimInstance Win32_Process -Filter "ProcessId = $($owners[0])"',
    '[ordered]@{ pid = $proc.ProcessId; image = $proc.ExecutablePath; command_line = $proc.CommandLine } | ConvertTo-Json -Compress',
  ].join('\n');
  const observed = JSON.parse(execFileSync('pwsh', ['-NoProfile', '-NonInteractive', '-Command', script], {
    encoding: 'utf8', windowsHide: true, timeout: 20_000,
  }));
  assert.equal(fs.realpathSync.native(observed.image).toLowerCase(),
    fs.realpathSync.native(path.join(install, image)).toLowerCase());
  assert.ok(observed.command_line.toLowerCase().includes(requiredArgument.toLowerCase()),
    'The loopback process is missing its expected runtime argument.');
  return { pid: observed.pid, image, port };
}

async function completeUiLogin() {
  const credentialSelector = '[aria-labelledby=initial-admin-title] code';
  await app.until('document.querySelectorAll(' + JSON.stringify(credentialSelector) + ').length === 2',
    'temporary credentials displayed by the installed application');
  const [email, initialPassword] = await app.evaluate(
    '[...document.querySelectorAll(' + JSON.stringify(credentialSelector) + ')].map(element => element.textContent.trim())');
  assert.ok(email && initialPassword, 'Initial credentials were missing from the sign-in dialog.');
  await app.click('فهمت');
  await app.login(email, initialPassword);
  const password = 'Lab-' + randomBytes(22).toString('base64url');
  await app.until('Boolean(document.querySelector("#forced-current-password"))', 'forced password change');
  await app.fill('#forced-current-password', initialPassword);
  await app.fill('#forced-new-password', password);
  await app.fill('#forced-confirm-password', password);
  await app.evaluate('document.querySelector("#forced-current-password").closest("form").querySelector("button[type=submit]").click()');
  await app.until('!document.querySelector("#forced-current-password") && Boolean(document.querySelector("input[type=email]"))',
    'sign-in with the changed password');
  await app.login(email, password);
  await app.system();
}

function observeCheckRequests() {
  const tracked = new Map();
  app.onEvent((method, event) => {
    if (method === 'Network.requestWillBeSent' && event.request.method === 'GET') {
      const url = new URL(event.request.url);
      if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1' || url.pathname !== checkRoute ||
        url.searchParams.has('background') || !receipt.click || Date.now() < receipt.click.started_at_ms) return;
      const handoffHeader = Object.entries(event.request.headers).find(([name]) => name.toLowerCase() === 'x-pos-delta-handoff');
      const request = {
        method: 'GET', path: url.pathname, port: Number(url.port),
        requested_at_ms: Date.now(), delta_handoff_header: handoffHeader?.[1],
      };
      tracked.set(event.requestId, request);
      receipt.check_requests.push(request);
    }
    const request = tracked.get(event.requestId);
    if (!request) return;
    if (method === 'Network.responseReceived') {
      request.http_status = event.response.status;
      request.from_cache = event.response.fromDiskCache || event.response.fromServiceWorker || event.response.fromPrefetchCache || false;
    }
    if (method !== 'Network.loadingFinished') return;
    app.call('Network.getResponseBody', { requestId: event.requestId }).then(({ body, base64Encoded }) => {
      assert.ok(!base64Encoded && body.length <= 100_000, 'Expected a bounded JSON check response.');
      const envelope = JSON.parse(body);
      const fields = ['success', 'status', 'current_version', 'latest_version', 'has_update', 'client_channel', 'release_channel',
        'source', 'release_tag', 'release_url', 'released_at', 'checkedUrl', 'updates_disabled', 'updates_unreachable',
        'errorCode', 'bootstrap_required', 'compatibility_reason_code', 'fallback_reason_code'];
      request.response = Object.fromEntries(fields.map(name => [name, envelope.data?.[name]]));
      request.api_status = envelope.status;
      request.completed_at_ms = Date.now();
    }).catch(() => { request.response_capture_failed = true; });
  });
}

async function clickVisibleCheck() {
  const locator = '[...document.querySelectorAll("button")].find(button => !button.disabled && button.textContent.trim() === ' +
    JSON.stringify(checkButton) + ')';
  await app.until('Boolean(' + locator + ')', 'enabled Check for updates button');
  await app.evaluate('(' + locator + ').scrollIntoView({block:"center",inline:"center"})');
  const point = await app.evaluate('(() => {const button = ' + locator + '; const bounds = button.getBoundingClientRect();' +
    'const x = bounds.left + bounds.width / 2; const y = bounds.top + bounds.height / 2;' +
    'return button.contains(document.elementFromPoint(x,y)) ? {x,y} : null;})()');
  assert.ok(point, 'Check for updates must be visible at the actual mouse position.');
  receipt.click = { label: checkButton, started_at_ms: Date.now() };
  await app.call('Input.dispatchMouseEvent', { ...point, type: 'mousePressed', button: 'left', clickCount: 1 });
  await app.call('Input.dispatchMouseEvent', { ...point, type: 'mouseReleased', button: 'left', clickCount: 1 });
}

async function getCheckResponse() {
  const deadline = Date.now() + 50_000;
  while (Date.now() < deadline) {
    const request = receipt.check_requests.find(item => item.response || item.response_capture_failed);
    if (request) return request;
    await delay(100);
  }
  throw new Error('No manual API response was observed after the Check for updates click.');
}

async function verifyPublicCatalog(result, request) {
  const cache = readJson(providerCache);
  const cacheTime = fs.statSync(providerCache).mtimeMs;
  assert.ok(cacheTime >= request.requested_at_ms - 250 && cacheTime <= request.completed_at_ms,
    'Installed PHP did not write a fresh GitHub provider cache during the manual check.');
  assert.ok(cacheTime > (receipt.cache_before_click_ms ?? 0), 'The provider cache did not refresh.');
  assert.equal(fs.existsSync(failureCache), false, 'The installed provider recorded a GitHub request failure.');
  assert.equal(cache.ok, true);
  assert.equal(cache.errorCode, null);
  assert.equal(cache.checkedUrl, result.checkedUrl);
  assert.equal(cache.data.channel, 'stable');
  assert.equal(cache.data.version, result.latest_version);
  assert.equal(cache.data.tag_name, result.release_tag);
  assert.equal(cache.data.release_url, result.release_url);
  assert.equal(cache.data.released_at, result.released_at);
  receipt.php_cache = {
    written_at_ms: cacheTime, version: cache.data.version, tag: cache.data.tag_name,
    release: cache.data.release_url, published_at: cache.data.released_at, status: 'ok',
  };

  const listingUrl = feedApi + '?per_page=100&page=1';
  const response = await fetch(listingUrl, {
    headers: { Accept: 'application/vnd.github+json', 'Cache-Control': 'no-cache' },
    signal: AbortSignal.timeout(30_000),
  });
  assert.equal(response.status, 200, 'The exact public update catalog must return HTTP 200.');
  const releases = await response.json();
  assert.ok(Array.isArray(releases) && releases.length < 100, 'Expected the complete current catalog on page one.');
  const stable = releases.filter(release => !release.draft && !release.prerelease && /^v0\.\d+\.\d+$/.test(release.tag_name));
  const selected = stable.find(release => release.tag_name === result.release_tag);
  assert.ok(selected, 'The application-selected release must be a published stable release in the live updates catalog.');
  const laterStable = stable.filter(release =>
    release.tag_name.localeCompare('v' + version, 'en', { numeric: true }) > 0);
  assert.deepEqual(laterStable.map(release => release.tag_name), [], 'The feed contains a newer public stable release.');
  assert.equal(selected.html_url, 'https://github.com/' + feedRepository + '/releases/tag/' + result.release_tag);
  assert.equal(result.release_url, selected.html_url);
  assert.equal(result.released_at, selected.published_at);
  for (const [name, url] of Object.entries({ ...cache.data.assets, ...cache.data.shell_assets })) {
    assert.equal(selected.assets.find(asset => asset.name === name)?.browser_download_url, url,
      'Cached release asset differs from public GitHub metadata for ' + name);
  }
  receipt.github_catalog = {
    requested_after_click: true, url: listingUrl, http_status: response.status,
    request_id: response.headers.get('x-github-request-id'), release_count: releases.length,
    selected_release_id: selected.id, selected_release_tag: selected.tag_name,
    selected_release_url: selected.html_url, selected_release_published_at: selected.published_at,
    matched_release_assets: Object.keys({ ...cache.data.assets, ...cache.data.shell_assets }).sort(),
    newer_stable_tags: [],
  };
}

try {
  receipt.before = inspectInstalledUpdater();
  await app.start(debugPort);
  receipt.desktop = inspectLoopbackProcess(debugPort, 'POS Desktop.exe', '--remote-debugging-port=' + debugPort);
  assert.equal(await app.evaluate('location.origin'), 'app://pos-app');
  await completeUiLogin();
  observeCheckRequests();
  await app.call('Network.enable');
  receipt.cache_before_click_ms = fs.existsSync(providerCache) ? fs.statSync(providerCache).mtimeMs : null;
  await clickVisibleCheck();
  const check = await getCheckResponse();
  assert.ok(check.response, 'The check response could not be read.');
  assert.equal(check.http_status, 200);
  assert.equal(check.from_cache, false);
  assert.equal(check.api_status, 'success');
  const result = check.response;
  assert.equal(result.success, true);
  assert.equal(result.status, 'no_update_available');
  assert.equal(result.current_version, version);
  assert.equal(result.has_update, false);
  assert.equal(result.source, 'github_release');
  assert.equal(result.client_channel, 'stable');
  assert.equal(result.release_channel, 'stable');
  assert.equal(result.checkedUrl, feedApi + '/latest');
  assert.equal(result.errorCode, null);
  assert.equal(result.updates_unreachable, false);
  assert.equal(result.updates_disabled, false);
  assert.equal(result.bootstrap_required, false);
  assert.equal(result.fallback_reason_code, null);
  assert.equal(result.compatibility_reason_code, null);
  await app.until('[...document.querySelectorAll("[role=status]")].some(element => element.textContent.trim() === ' +
    JSON.stringify(successToast) + ')', 'up-to-date confirmation emitted by the button', 12_000);
  assert.equal(await app.evaluate('[...document.querySelectorAll("button")].some(button => ' +
    'button.textContent.trim() === "تثبيت التحديث الآن" && !button.disabled)'), false);
  receipt.ui_confirmation = successToast;
  const capture = await app.call('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(path.join(evidence, 'up-to-date.png'), Buffer.from(capture.data, 'base64'));
  assert.equal(receipt.check_requests.length, 1, 'Expected one foreground check request from the clicked button.');
  await verifyPublicCatalog(result, check);
  receipt.php = inspectLoopbackProcess(check.port, 'resources/portable/php/php.exe',
    path.join(resources, 'app.asar.unpacked', 'backend', 'backend.phar'));
  receipt.after = inspectInstalledUpdater();
  assert.deepEqual(receipt.after, receipt.before, 'Installed managed binaries changed during the check.');
  receipt.passed = true;
  console.log('PASS: Public Setup 0.0.14 Update Center checked the live stable GitHub feed and showed that no update is available.');
} catch (error) {
  receipt.failure = error.message;
  throw error;
} finally {
  writeEvidence('update-center.json', receipt);
  await app.stop().catch(() => {});
}
