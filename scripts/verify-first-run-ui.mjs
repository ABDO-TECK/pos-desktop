import fs from 'node:fs';
import assert from 'node:assert/strict';
if (process.env.GITHUB_ACTIONS !== 'true') throw Error('Installer UI verification is restricted to an ephemeral GitHub runner');
const [port, expectation, receipt] = process.argv.slice(2);
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
assert.equal(result.version, '0.0.1');
assert.equal(result.newAdmin, expectation === 'fresh');
assert.equal(result.modal, expectation === 'fresh');
if (expectation === 'fresh') {
  assert.equal(result.temporaryPasswordVisible, true);
  assert.equal(result.forcePasswordChange, true);
  assert.ok(result.passwordLength >= 16);
}
fs.writeFileSync(receipt, JSON.stringify(result, null, 2));
console.log(`PASS: installed UI version 0.0.1, ${expectation === 'fresh' ? 'new temporary credentials visible' : 'existing administrator retained'}`);
// Close the app via Chromium so Electron runs its normal service shutdown.
socket.send(JSON.stringify({ id: ++sequence, method: 'Browser.close' }));
socket.close();
