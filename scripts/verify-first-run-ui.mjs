import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import net from 'node:net';
import { spawnSync } from 'node:child_process';
if (process.env.GITHUB_ACTIONS !== 'true') throw Error('Installer UI verification is restricted to an ephemeral GitHub runner');
const [port, expectation, receipt, expectedVersionParam, fixtureAction, fixtureOutput] = process.argv.slice(2);
const expectedVersion = expectedVersionParam || (fs.existsSync('package.json') ? JSON.parse(fs.readFileSync('package.json', 'utf8')).version : '0.0.1');
const until = Date.now() + 240_000;
let target;
while (Date.now() < until) {
  try {
    const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
    target = list.find(t => t.type === 'page' && t.url.startsWith('app://pos-app/'));
    if (target) break;
  } catch { /* app still starting */ }
  await new Promise(resolve => setTimeout(resolve, 1000));
}
assert.ok(target, 'Installed app did not reach the login UI');
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
let sequence = 0;
const pending = new Map();
socket.onmessage = event => {
  const result = JSON.parse(event.data);
  if (result.id && pending.has(result.id)) { pending.get(result.id)(result); pending.delete(result.id); }
};
async function call(method, params = {}) {
  const id = ++sequence;
  const reply = new Promise(resolve => pending.set(id, resolve));
  socket.send(JSON.stringify({ id, method, params }));
  const result = await Promise.race([reply, new Promise((_, reject) => setTimeout(() => reject(Error('CDP timeout')), 20_000))]);
  assert.ok(!result.error && !result.result?.exceptionDetails, 'Installed UI inspection failed');
  return result.result;
}
let result;
while (Date.now() < until) {
  const response = await call('Runtime.evaluate', { awaitPromise: true, returnByValue: true, expression: `(async () => {
    if (!window.electronAPI) return {ready:false};
    const credentials = await window.electronAPI.setup.getInitialAdmin();
    const modal = document.querySelector('[aria-labelledby="initial-admin-title"]');
    const version = await window.electronAPI.getVersion();
    if (!credentials) return {ready:!!document.querySelector('form'),version,newAdmin:false,modal:!!modal};
    const bytes = await crypto.subtle.digest('SHA-256',new TextEncoder().encode(credentials.password));
    return {ready:!!modal,version,newAdmin:true,modal:!!modal,
      temporaryPasswordVisible:!!modal && modal.textContent.includes(credentials.password),
      passwordLength:credentials.password.length,forcePasswordChange:credentials.forcePasswordChange,
      passwordDigest:Array.from(new Uint8Array(bytes),b=>b.toString(16).padStart(2,'0')).join('')};
  })()` });
  result = response.result?.value;
  if (result?.ready) break;
  await new Promise(resolve => setTimeout(resolve, 500));
}
assert.equal(result?.ready, true);
assert.equal(result.version, expectedVersion);
assert.equal(result.newAdmin, expectation === 'fresh');
assert.equal(result.modal, expectation === 'fresh');
if (expectation === 'fresh') {
  assert.equal(result.temporaryPasswordVisible, true);
  assert.equal(result.forcePasswordChange, true);
  assert.ok(result.passwordLength >= 16);
}
fs.writeFileSync(receipt, JSON.stringify(result, null, 2));
console.log(`PASS: installed UI version ${expectedVersion}, ${expectation === 'fresh' ? 'new temporary credentials visible' : 'existing administrator retained'}`);
// Printing starts in the background after the login UI. Do not quit while
// its certificate setup is still running, or the ownership receipt cannot
// be written and the following uninstall test would be premature.
const printingDeadline = Date.now() + 120_000;
let printingReady = false;
while (Date.now() < printingDeadline) {
  printingReady = await new Promise(resolve => {
    const socket = net.connect({ host: '127.0.0.1', port: 8182 });
    socket.setTimeout(1000);
    const finish = ready => { socket.destroy(); resolve(ready); };
    socket.once('connect', () => finish(true));
    socket.once('error', () => finish(false));
    socket.once('timeout', () => finish(false));
  });
  if (printingReady) break;
  await new Promise(resolve => setTimeout(resolve, 1000));
}
assert.ok(printingReady, 'Printing service did not finish background initialization');
console.log('PASS: printing service finished background initialization');

if (fixtureAction) {
  const repoRoot = process.cwd();
  const fixtureScript = path.join(repoRoot, 'scripts/fixtures/channel-transition-capability.php');
  const phpCandidates = [
    process.env.PHP_BINARY,
    'php',
    path.join(repoRoot, 'portable/php/php.exe'),
    'C:\\xampp\\php\\php.exe'
  ].filter(Boolean);

  let phpExe = null;
  for (const candidate of phpCandidates) {
    if (path.isAbsolute(candidate) && !fs.existsSync(candidate)) continue;
    const probe = spawnSync(candidate, ['--version'], { encoding: 'utf8', windowsHide: true });
    if (!probe.error && probe.status === 0) {
      phpExe = candidate;
      break;
    }
  }

  if (!phpExe) {
    throw new Error('PHP CLI not found for running fixtureAction ' + fixtureAction);
  }

  const actions = fixtureAction.split(',').map(s => s.trim()).filter(Boolean);
  const outputs = fixtureOutput ? fixtureOutput.split(',').map(s => s.trim()).filter(Boolean) : [];

  for (let i = 0; i < actions.length; i++) {
    const action = actions[i];
    const outputFile = outputs[i];

    const fixtureResult = spawnSync(phpExe, [fixtureScript, action], {
      cwd: repoRoot,
      env: process.env,
      encoding: 'utf8',
      windowsHide: true
    });

    if (fixtureResult.status !== 0) {
      throw new Error(`Fixture hook '${action}' failed (exit ${fixtureResult.status}): ${fixtureResult.stderr || fixtureResult.stdout}`);
    }

    if (outputFile) {
      fs.writeFileSync(outputFile, fixtureResult.stdout);
      console.log(`PASS: fixture hook '${action}' completed, output saved to ${outputFile}`);
    }
  }
}

// Close the app via Chromium so Electron runs its normal service shutdown.
socket.send(JSON.stringify({ id: ++sequence, method: 'Browser.close' }));
socket.close();
