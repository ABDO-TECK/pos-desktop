import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import https from 'node:https';
import net from 'node:net';
import crypto from 'node:crypto';
import { spawn, execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

assert.equal(process.env.GITHUB_ACTIONS, 'true', 'Runtime acceptance requires an ephemeral GitHub runner');
const [application, evidence, assets] = process.argv.slice(2).map(value => path.resolve(value));
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
  await until(`[...document.querySelectorAll('button')].some(b => b.textContent.trim() === ${JSON.stringify(text)})`, 'ready UI control: ' + text);
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

async function screenshot(name) { const r=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(evidence,name+'.png'),Buffer.from(r.data,'base64')); }
const root=path.join(application,'resources/app.asar.unpacked'),storage=path.join(process.env.PROGRAMDATA,'POS System');
const hash=f=>crypto.createHash('sha256').update(fs.readFileSync(f)).digest('hex');
async function versionIs(version){const end=Date.now()+120_000;do{try{if(JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version===version){await delay(5000);await until('Boolean(document.querySelector("form, a[href=\'/settings\']"))','restarted UI',120_000);return;}}catch{}await delay(500);}while(Date.now()<end);throw Error('Version/restart failed '+version);}
async function system(){await route('/settings');await clickText('النظام والصيانة');await until('[...document.querySelectorAll("button")].some(b=>b.textContent.includes("نقاط الاسترجاع ("))','recovery points');await evaluate('[...document.querySelectorAll("button")].find(b=>b.textContent.includes("نقاط الاسترجاع (")).click()');}
async function restore(version, legacy=false){
 const clicked=await evaluate(`(() => { const buttons=[...document.querySelectorAll('button')];const b=buttons.find(b=>${legacy ? `b.textContent.trim()==='استرجاع هذه النسخة' && b.parentElement.textContent.includes('من v'+${JSON.stringify(version)}+' إلى')` : `b.textContent.replace(/\\s+/g,' ').trim()==='الرجوع إلى v'+${JSON.stringify(version)}`});if(!b)return false;b.click();return true; })()`);
 assert.equal(clicked,true,'Expected rollback destination '+version);
 await until('[...document.querySelectorAll("button")].some(b=>b.textContent.trim()==="تأكيد")','confirm rollback');
 if(!legacy)assert.equal(await evaluate('document.body.innerText.includes("واجهة الإصدار المختار وسلوكه")'),true,'Old UI warning missing');
 await clickText('تأكيد');
}
try{
 await start();const initial=await evaluate('window.electronAPI.setup.getInitialAdmin()');assert.ok(initial?.password);email=initial.email;
 await until('Boolean(document.querySelector("[aria-labelledby=initial-admin-title]"))','fresh-admin dialog');await clickText('فهمت');await login(initial.password);
 await until('Boolean(document.querySelector("#forced-current-password"))','password change');await fill('#forced-current-password',initial.password);await fill('#forced-new-password',password);await fill('#forced-confirm-password',password);
 await evaluate('document.querySelector("#forced-current-password").closest("form").querySelector("button[type=submit]").click()');await until('!document.querySelector("#forced-current-password") && Boolean(document.querySelector("input[type=email]"))','reauthentication');await login(password);
 await until('Boolean(document.querySelector("a[href=\'/settings\']"))','authenticated');authenticated=true;
 const sentinel=path.join(storage,'business-sentinel.db');fs.writeFileSync(sentinel,'customer data fixture must remain identical');const sentinelHash=hash(sentinel);
 const oldPhar=hash(path.join(root,'backend/backend.phar'));
 const priorAssets=fs.realpathSync(process.env.POS_ROLLBACK_PRIOR_ASSETS);assert.ok(priorAssets.toLowerCase().startsWith(temporary));
 let capabilityBefore;
 const capability=process.env.POS_CHANNEL_CAPABILITY==='1';
 const fixture=path.resolve('scripts/fixtures/channel-transition-capability.php');
 const shellFiles=[path.join(application,'POS Desktop.exe'),path.join(application,'resources/app.asar')];
 const shellBefore=shellFiles.map(hash);
 if(capability){
   capabilityBefore=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[fixture,'seed'],{encoding:'utf8',windowsHide:true}));
   const plan=path.join(evidence,'observer-plan.json');
   fs.writeFileSync(plan,JSON.stringify({mode:'desktop',directory:evidence,root,storage,inventory:JSON.parse(fs.readFileSync(path.join(priorAssets,'manifest-0.0.1.json'))).baseline_files}));
   execFileSync(process.env.PHP_BINARY||'php',[fixture,'launch',plan],{windowsHide:true});
   const end=Date.now()+10_000;while(!fs.existsSync(path.join(evidence,'heartbeat.json'))&&Date.now()<end)await delay(100);
   assert.ok(fs.existsSync(path.join(evidence,'heartbeat.json')),'Independent observer did not start');
 }
 const prior=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',['scripts/stage-acceptance-delta.php',root,storage,priorAssets],{encoding:'utf8',windowsHide:true}));
 await evaluate('void window.posRuntime.applyStagedDelta('+JSON.stringify(prior.version)+').catch(()=>{})');await versionIs(prior.version);
 const staged=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',['scripts/stage-acceptance-delta.php',root,storage,assets],{encoding:'utf8',windowsHide:true}));
 // Exercise the published Setup shell's real IPC stop/replace/restart path.
 await evaluate('void window.posRuntime.applyStagedDelta('+JSON.stringify(staged.version)+').catch(()=>{})');await versionIs(staged.version);
 if(await evaluate('Boolean(document.querySelector("input[type=email]"))'))await login(password);
 await until('Boolean(document.querySelector("a[href=\'/settings\']"))','updated navigation');await system();
 await until('document.querySelectorAll("[data-testid=rollback-destination]").length===1','one valid destination');
 assert.equal(await evaluate('document.querySelector("[data-testid=rollback-destination]").innerText.includes("v0.0.10-beta.3")'),true);
 assert.equal(await evaluate('document.querySelector("[data-testid=rollback-points]").innerText.includes("patch_")'),false);
 await screenshot('rollback-available-destination');
 const currentPhar=hash(path.join(root,'backend/backend.phar'));
 const saved=path.join(staged.snapshot,'files/backend/backend.phar'),safe=path.join(path.dirname(evidence),'rollback-baseline.phar');fs.copyFileSync(saved,safe);fs.writeFileSync(saved,'corrupted snapshot');
 await restore(prior.version);await until('document.body.innerText.includes("Recovery payload is missing or corrupt")','actual backup error');assert.equal(hash(path.join(root,'backend/backend.phar')),currentPhar);assert.equal(await evaluate('document.body.innerText.includes("حدث خطأ في الاتصال بالخادم")'),false);await screenshot('rollback-corrupt-rejected');
 fs.copyFileSync(safe,saved);
 await restore(prior.version);await versionIs(prior.version);
 const intermediate=JSON.parse(fs.readFileSync(path.join(assets,'manifest-'+prior.version+'.json')));for(const [file,expected] of Object.entries(intermediate.baseline_files))assert.equal(hash(path.join(root,file)),expected,file);
 assert.equal(hash(sentinel),sentinelHash);await screenshot('rollback-intermediate-restored');
 await system();await restore('0.0.1',true);await versionIs('0.0.1');
 assert.equal(hash(path.join(root,'backend/backend.phar')),oldPhar);assert.equal(hash(sentinel),sentinelHash);
 const manifest=JSON.parse(fs.readFileSync(path.join(priorAssets,'manifest-0.0.1.json')));for(const [file,expected] of Object.entries(manifest.baseline_files))assert.equal(hash(path.join(root,file)),expected,file);
 for(const file of [...intermediate.files,...manifest.files])if(!Object.hasOwn(manifest.baseline_files,file.path))assert.equal(fs.existsSync(path.join(root,file.path)),false,'Newer artifact survived: '+file.path);
 assert.equal(await evaluate('document.body.innerText.includes("حدث خطأ في الاتصال بالخادم")'),false);await screenshot('rollback-restored');
 fs.unlinkSync(safe);
 if(capability){
   const after=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[fixture,'inspect'],{encoding:'utf8',windowsHide:true}));
   assert.deepEqual(after,capabilityBefore,'SQL schema, migration ledger or business fixture changed across return');
   assert.deepEqual(shellFiles.map(hash),shellBefore,'Original Setup shell changed');
   const observer=JSON.parse(fs.readFileSync(path.join(evidence,'observer.json')));assert.equal(observer.target_inventory_matches,true);
   fs.writeFileSync(path.join(evidence,'capability.json'),JSON.stringify({...observer,schema_unchanged:true,ledger_unchanged:true,business_rows_preserved:true,shell_unchanged:true,launcherParent:'fixture_php_cli',actualDesktopHandoffs:3},null,2));
 }
 fs.writeFileSync(path.join(evidence,'acceptance.json'),JSON.stringify({ok:true,actualSetupShell:true,signedUpdateApplied:true,rollbackButton:true,validDestinationsOnly:true,oldInterfaceWarning:true,corruptSnapshotRejected:true,realErrorVisible:true,noConnectionErrorSpam:true,phpRestarted:true,baselineArtifactsRestored:true,newerAssetsRemoved:true,businessSentinelPreserved:true,transitions:[staged.version,prior.version,'0.0.1'],legacyInterfaceRestoredAsExpected:true,target:'0.0.1'},null,2));
 console.log('PASS real desktop UI: signed update, corrupt snapshot refusal, rollback button, PHP stop/replace/restart, exact baseline recovery and preserved business data');
} catch(error){try{await screenshot('failure');}catch{}throw error;}finally{try{await stop();}catch{child?.kill();}}
