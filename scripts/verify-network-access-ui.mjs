import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import https from 'node:https';
import net from 'node:net';
import crypto from 'node:crypto';
import { spawn } from 'node:child_process';
import assert from 'node:assert/strict';

assert.equal(process.env.GITHUB_ACTIONS, 'true', 'Runtime acceptance requires an ephemeral GitHub runner');
const [application, evidence] = process.argv.slice(2).map(value => path.resolve(value));
const temporary = fs.realpathSync(process.env.RUNNER_TEMP).toLowerCase() + path.sep;
assert.ok(fs.realpathSync(application).toLowerCase().startsWith(temporary));
assert.ok(evidence.toLowerCase().startsWith(temporary));
for (const directory of [path.join(process.env.PROGRAMDATA, 'POS System'), path.join(process.env.APPDATA, 'POS System'), path.join(process.env.APPDATA, 'pos-desktop')]) {
  assert.ok(!fs.existsSync(directory), 'Runner must have no existing POS data');
}
fs.mkdirSync(evidence, { recursive: true });
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const executable = path.join(application, 'POS Desktop.exe');
let child, socket, blocker, sequence = 0, authenticated = false;
const pending = new Map();
const password = 'Lab-' + crypto.randomBytes(22).toString('base64url');
let email;
async function call(method, params = {}) {
  const id = ++sequence;
  let timer;
  const response = new Promise((resolve, reject) => {
    timer = setTimeout(() => { pending.delete(id); reject(Error('UI command timed out: ' + method)); }, 25_000);
    pending.set(id, resolve);
  });
  socket.send(JSON.stringify({ id, method, params }));
  try {
    const reply = await response;
    assert.ok(!reply.error && !reply.result?.exceptionDetails, 'UI command failed: ' + method);
    return reply.result;
  } finally { clearTimeout(timer); }
}
async function evaluate(expression) {
  return (await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.value;
}
async function until(expression, label, timeout = 90_000) {
  const end = Date.now() + timeout;
  do {
    if (await evaluate(expression)) return;
    await delay(250);
  } while (Date.now() < end);
  throw Error('Timed out: ' + label);
}
async function clickText(text) {
  const clicked = await evaluate(`(() => { const button = [...document.querySelectorAll('button')].find(b => b.textContent.trim() === ${JSON.stringify(text)}); if (!button) return false; button.click(); return true; })()`);
  assert.equal(clicked, true, 'Expected UI control: ' + text);
}
async function fill(selector, value) {
  assert.equal(await evaluate(`(() => { const input = document.querySelector(${JSON.stringify(selector)}); if (!input) return false; Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, ${JSON.stringify(value)}); input.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`), true, 'Input missing');
  await delay(100);
}
async function start() {
  const env = { ...process.env }; delete env.GH_TOKEN; delete env.GITHUB_TOKEN;
  const output = fs.openSync(path.join(os.tmpdir(), 'pos-lan-runtime.log'), 'a');
  child = spawn(executable, ['--remote-debugging-port=19231'], { cwd: application, env, windowsHide: true, stdio: ['ignore', output, output] });
  fs.closeSync(output);
  let target;
  const end = Date.now() + 240_000;
  do {
    if (child.exitCode !== null) throw Error('App exited before readiness');
    try { target = (await (await fetch('http://127.0.0.1:19231/json/list')).json()).find(t => t.type === 'page' && t.url.startsWith('app://pos-app/')); } catch { /* starting */ }
    if (target) break;
    await delay(500);
  } while (Date.now() < end);
  assert.ok(target, 'Desktop page missing');
  socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
  socket.onmessage = event => { const reply = JSON.parse(event.data); const resolve = pending.get(reply.id); if (resolve) { pending.delete(reply.id); resolve(reply); } };
  await until('Boolean(window.electronAPI && document.querySelector("form, a[href=\'/settings\']"))', 'app UI', 240_000);
}
async function stop() {
  if (socket?.readyState === WebSocket.OPEN) {
    socket.send(JSON.stringify({ id: ++sequence, method: 'Browser.close' })); socket.close();
  }
  if (child && child.exitCode === null) await Promise.race([new Promise(resolve => child.once('exit', resolve)), delay(35_000)]);
  assert.ok(!child || child.exitCode !== null, 'Application did not shut down');
  await delay(2000);
}
async function login(secret) {
  await until('Boolean(document.querySelector("input[type=email]"))', 'login form');
  await fill('input[type=email]', email);
  await fill('input[type=password]', secret);
  await evaluate('document.querySelector("form button[type=submit]").click()');
}
async function route(url) {
  await until(`Boolean(document.querySelector('a[href="${url}"]'))`, 'navigation link');
  await evaluate(`document.querySelector('a[href="${url}"]').click()`);
  await until(`location.pathname === ${JSON.stringify(url)}`, 'route ' + url);
}
async function settings() {
  await route('/settings');
  await until('[...document.querySelectorAll("button")].some(b=>b.textContent.trim()==="الاتصال بالهاتف")', 'settings tab');
  await clickText('الاتصال بالهاتف');
  await until('Boolean(document.querySelector("button[role=switch]"))', 'LAN control');
}
async function toggle(enabled) {
  await evaluate('document.querySelector("button[role=switch]").click()');
  await until('[...document.querySelectorAll("button")].some(b=>b.textContent.trim()==="تأكيد")', 'confirmation');
  await clickText('تأكيد');
  await until('!document.querySelector("button[role=switch]").disabled', 'LAN operation');
  assert.equal(await evaluate('document.querySelector("button[role=switch]").getAttribute("aria-checked")'), String(enabled));
}
const address = Object.values(os.networkInterfaces()).flat().find(value => value && value.family === 'IPv4' && !value.internal)?.address;
assert.ok(address, 'Runner needs a non-loopback IPv4 address');
async function health() {
  return new Promise((resolve, reject) => {
    const request = https.get({ hostname: address, port: 8443, path: '/api/health', rejectUnauthorized: false, agent: false, timeout: 8000 }, response => {
      let bytes = ''; response.on('data', chunk => { bytes += chunk; });
      response.on('end', () => { try { assert.equal(response.statusCode, 200); assert.equal(JSON.parse(bytes).status, 'ok'); resolve(true); } catch (error) { reject(error); } });
    });
    request.on('timeout', () => request.destroy(Error('HTTPS readiness timeout'))); request.on('error', reject);
  });
}
async function screenshot(name) { const result = await call('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(evidence, name + '.png'), Buffer.from(result.data, 'base64')); }
try {
  await start();
  const initial = await evaluate('window.electronAPI.setup.getInitialAdmin()');
  assert.ok(initial?.password); email = initial.email;
  await until('Boolean(document.querySelector("[aria-labelledby=initial-admin-title]"))', 'fresh-admin dialog');
  await clickText('فهمت');
  await login(initial.password);
  await until('Boolean(document.querySelector("#forced-current-password"))', 'password change');
  await fill('#forced-current-password', initial.password);
  await fill('#forced-new-password', password); await fill('#forced-confirm-password', password);
  await evaluate('document.querySelector("#forced-current-password").closest("form").querySelector("button[type=submit]").click()');
  await until('!document.querySelector("#forced-current-password") && Boolean(document.querySelector("input[type=email]"))', 'reauthentication');
  await login(password);
  await until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'authenticated navigation'); authenticated = true;
  await settings();
  assert.equal(await evaluate('document.querySelector("[role=switch]").getAttribute("aria-checked")'), 'false');
  await toggle(true); await health(); await screenshot('phone-access-on');
  for (const url of ['/products', '/reports', '/', '/sales']) { await route(url); await health(); }
  await settings();
  assert.equal(await evaluate('document.querySelector("[role=switch]").getAttribute("aria-checked")'), 'true');
  await clickText('إعدادات المتجر'); await health(); await clickText('الاتصال بالهاتف');
  await until('Boolean(document.querySelector("[role=switch]"))', 'return to network tab');
  assert.equal(await evaluate('document.querySelector("[role=switch]").getAttribute("aria-checked")'), 'true');
  await evaluate('document.documentElement.dataset.theme="dark"'); await screenshot('phone-access-dark');
  await stop(); await start();
  if (await evaluate('Boolean(document.querySelector("input[type=email]"))')) await login(password);
  await until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'reopened authenticated UI');
  await settings(); await until('document.querySelector("[role=switch]").getAttribute("aria-checked")==="true" && !document.querySelector("[role=switch]").disabled', 'automatic restore');
  await health();
  await toggle(false);
  await assert.rejects(health, 'Explicit shutdown must close LAN HTTPS');
  blocker = net.createServer(connection => connection.destroy());
  await new Promise((resolve, reject) => { blocker.once('error', reject); blocker.listen(8443, '0.0.0.0', resolve); });
  await toggle(false); // attempt to enable fails; switch must remain OFF
  await until('Boolean(document.querySelector("[role=alert]"))', 'real bind failure is visible');
  await screenshot('phone-access-failure');
  await new Promise(resolve => blocker.close(resolve)); blocker = null;
  await toggle(true); await health(); await toggle(false);
  await stop(); await start();
  if (await evaluate('Boolean(document.querySelector("input[type=email]"))')) await login(password);
  await until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'disabled restart navigation');
  await settings();
  assert.equal(await evaluate('document.querySelector("[role=switch]").getAttribute("aria-checked")'), 'false');
  await assert.rejects(health, 'Disabled choice must persist after restart');
  fs.writeFileSync(path.join(evidence, 'acceptance.json'), JSON.stringify({ routeAccess: true, tabState: true, restartRestored: true, explicitShutdown: true, disabledAfterRestart: true, realBindFailureVisible: true, brandedSwitch: true, connection: 'HTTPS via runner non-loopback IPv4; not a physical phone' }, null, 2));
  console.log('PASS: real desktop UI, HTTPS across routes/tabs, application restart, explicit stop and real bind failure');
} catch (error) {
  if (authenticated && socket?.readyState === WebSocket.OPEN) try { await screenshot('failure'); } catch { /* no UI */ }
  throw error;
} finally {
  if (blocker) await new Promise(resolve => blocker.close(resolve));
  try { await stop(); } catch { child?.kill(); }
}
