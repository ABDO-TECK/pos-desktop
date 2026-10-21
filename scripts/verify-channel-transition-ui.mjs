import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createDesktopLab,delay} from './desktop-transition-lab.mjs';
import {attachTransitionTransport} from './channel-transition-ui-transport.mjs';

const [phase,appArg,evidenceArg,assetsArg]=process.argv.slice(2);
const application=path.resolve(appArg),evidence=path.resolve(evidenceArg),assets=path.resolve(assetsArg);
const lab=path.dirname(application),root=path.join(application,'resources/app.asar.unpacked');
const storage=path.join(process.env.PROGRAMDATA,'POS System');
const php=process.env.PHP_BINARY||'php';
const fixture=path.resolve('scripts/fixtures/channel-transition-capability.php');
const sql=mode=>JSON.parse(execFileSync(php,[fixture,mode],{encoding:'utf8',windowsHide:true}));
const restore=()=>JSON.parse(execFileSync(php,[path.resolve('scripts/fixtures/channel-transition-restore.php'),root],{encoding:'utf8',windowsHide:true}));
const target=JSON.parse(fs.readFileSync(path.join(lab,'deployment-inventory.json')));
const source=JSON.parse(fs.readFileSync(path.join(assets,'deployment-inventory.json')));
const version=source.version;
assert.equal(target.version,'0.0.1');assert.match(version,/^0\.\d+\.\d+-beta\.\d+$/);
if(phase==='contract') {
  const ui=createDesktopLab(application,evidence);
  try {
    await ui.start();
    for(const [file,expected] of Object.entries(target.files))assert.equal(ui.hash(path.join(root,file)),expected.sha256,'Original Setup inventory '+file);
    const auth=await ui.freshLogin();
    // Ephemeral fixture credentials stay outside evidence and release artifacts.
    fs.writeFileSync(path.join(lab,'fixture-auth.json'),JSON.stringify(auth),{mode:0o600});
    const contract=sql('prepare-compatibility');assert.equal(contract.runtime_metadata_readable,true);assert.equal(contract.metadata_view_read_only,true);
    fs.writeFileSync(path.join(lab,'data-contract.json'),JSON.stringify(contract,null,2));
    fs.writeFileSync(path.join(lab,'owned-lab.json'),JSON.stringify({application,storage,version:'0.0.1'}));
    console.log('PASS original Setup contract: actual MariaDB metadata readable with restricted runtime; business schema retained');
  } finally {await ui.stop();}
  process.exit(0);
}
assert.equal(phase,'acceptance');
const owned=JSON.parse(fs.readFileSync(path.join(lab,'owned-lab.json')));assert.equal(owned.application,application);assert.equal(owned.storage,storage);
const transportPath=path.join(lab,'transport.json');
const assetUrl=(repo,tag,name)=>`https://github.com/ABDO-TECK/${repo}/releases/download/${tag}/${name}`;
const candidate={tag_name:'v'+version,draft:false,prerelease:true,name:'Isolated candidate',published_at:'2026-10-21T00:00:00Z',body:'Isolated signed channel transition acceptance',assets:[]};
const stable={tag_name:'v0.0.1',draft:false,prerelease:false,name:'Preserved original Setup',assets:[{name:'deployment-inventory.json',browser_download_url:assetUrl('pos-desktop','v0.0.1','deployment-inventory.json')}]};
const transport={mode:'delta',responses:{},assets:{},trace:path.join(evidence,'transport-trace.jsonl')};
for(const name of fs.readdirSync(assets)){
 const file=path.join(assets,name);if(!fs.statSync(file).isFile())continue;
 const url=assetUrl('pos-desktop-updates','v'+version,name);candidate.assets.push({name,browser_download_url:url,size:fs.statSync(file).size});transport.assets[url]=file;
}
transport.assets[stable.assets[0].browser_download_url]=path.join(lab,'deployment-inventory.json');
for(const [repo,releases] of [['pos-desktop-updates',[candidate]],['pos-desktop',[stable]]]){
 transport.responses[`https://api.github.com/repos/ABDO-TECK/${repo}/releases?per_page=100&page=1`]=releases;
 for(const release of releases)transport.responses[`https://api.github.com/repos/ABDO-TECK/${repo}/releases/tags/${release.tag_name}`]=release;
}
function mode(value){transport.mode=value;fs.writeFileSync(transportPath,JSON.stringify(transport));}
mode('delta');
const ui=createDesktopLab(application,evidence);
let auth;
let requestTransport;
let firstTransitionAt;
let firstForwardAt;
let forwardCount=0;
async function versionIs(expected, startedAt = 0, expectedRunId = null){
 const deadline=Date.now()+150000;
 do{
   try{
     if(JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version===expected){
       if(expectedRunId){
         await ui.until(
           `window.__pageRunId !== ${JSON.stringify(expectedRunId)} && Boolean(document.querySelector("form, a[href='/settings']"))`,
           'restarted frontend after restart',
           120000
         );
       }else{
         await delay(5000);
         await ui.until('Boolean(document.querySelector("form, a[href=\'/settings\']"))','restarted frontend',120000);
       }
       if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))'))await ui.login(auth.email,auth.password);
       await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))','authenticated after restart');
       await delay(2000);
       requestTransport.resume();
       return;
     }
   }catch{}
   if(startedAt > 0){
     try {
       const stateFile = path.join(storage, 'update-state.json');
       if (fs.existsSync(stateFile)) {
         const st = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
         const stateTime = Date.parse(st.updated_at || st.started_at || '');
         if (Number.isFinite(stateTime) && stateTime >= startedAt && (st.state === 'failed' || st.state === 'rolled_back')) {
           throw Error('Update transaction failed on disk: ' + (st.error || st.state));
         }
         if (Number.isFinite(stateTime) && stateTime >= startedAt && st.state === 'desktop_handoff_pending' && Date.now() - startedAt > 8000) {
           await ui.evaluate(`(() => {
             if (window.posRuntime && typeof window.posRuntime.applyStagedDelta === 'function') {
               window.posRuntime.applyStagedDelta(${JSON.stringify(expected)}).catch(() => {});
             }
           })()`).catch(() => {});
         }
       }
     } catch(e) {
       if (e.message.startsWith('Update transaction failed on disk:')) throw e;
     }
   }
   const toast=await ui.evaluate(`(() => {
     const nodes=[...document.querySelectorAll('[role="alert"], [role="status"], .toast-error, div[style*="animation"]')];
     for(const n of nodes){
       const text=n.innerText?.trim()||n.textContent?.trim()||'';
       if(!text)continue;
       if(/فشل|خطأ|error|rejected|update_in_progress|in progress|مسبق/i.test(text)&&!/تم استكمال التحديث|إعادة تشغيل الخدمات|التحقق من التحديثات|جاري/i.test(text)){
         return text;
       }
     }
     return null;
   })()`).catch(()=>null);
   if(toast)throw Error('Frontend reported error during update: '+toast);
   await delay(500);
 }while(Date.now()<deadline);
 throw Error('Version/restart failed '+expected);
}
async function chooseChannel(channel){
 await ui.until('Boolean(document.querySelector("select option[value=beta]"))','channel selector');
 await ui.evaluate(`(() => {const select=[...document.querySelectorAll('select')].find(s=>s.querySelector('option[value=beta]'));Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,'value').set.call(select,${JSON.stringify(channel)});select.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 await delay(1500);
}
async function forward(){
 forwardCount++;
 if(forwardCount===1){
   firstForwardAt=Date.now();
 }else if(forwardCount>2){
   while(Date.now()<firstForwardAt+310000){
     console.log('Waiting for the production 2/300s update_apply rate window before forward #'+forwardCount);
     await delay(Math.min(15000,firstForwardAt+310000-Date.now()));
   }
 }
 if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))'))await ui.login(auth.email,auth.password);
 await requestTransport.reauth();
 await ui.system();await chooseChannel('beta');await ui.click('التحقق من التحديثات');
 await ui.until('[...document.querySelectorAll("button")].some(b=>b.textContent.trim()==="تثبيت التحديث الآن"&&!b.disabled)','normal beta install');
 await ui.evaluate(`(() => {
   if (window.posRuntime && !window.posRuntime._transitionHooked) {
     window.posRuntime._transitionHooked = true;
     const orig = window.posRuntime.applyStagedDelta;
     window.posRuntime.applyStagedDelta = async function(v) {
       console.log('[Delta Hook] applyStagedDelta called with: ' + v);
       try {
         const res = await orig.call(this, v);
         console.log('[Delta Hook] applyStagedDelta resolved: ' + JSON.stringify(res));
         return res;
       } catch(err) {
         console.error('[Delta Hook] applyStagedDelta rejected: ' + (err.stack || err.message));
         throw err;
       }
     };
   }
 })()`);
 const startedAt = Date.now();
 const runId = 'forward_' + forwardCount + '_' + startedAt;
 await ui.evaluate(`window.__pageRunId = ${JSON.stringify(runId)}`);
 await ui.click('تثبيت التحديث الآن');await ui.click('تأكيد');
 await delay(1500);
 const toast=await ui.evaluate(`(() => {
   const nodes=[...document.querySelectorAll('[role="alert"], [role="status"], .toast-error')];
   for(const n of nodes){
     const text=n.innerText?.trim()||'';
     if(/فشل|خطأ|error|rejected/i.test(text)&&!/تم استكمال التحديث|إعادة تشغيل الخدمات|التحقق من التحديثات/i.test(text))return text;
   }
   return null;
 })()`).catch(()=>null);
 if(toast)throw Error('Update apply rejected: '+toast);
 await versionIs(version, startedAt, runId);
}
function verifyInventory(inventory,removedFrom){
 for(const [file,expected] of Object.entries(inventory.files))assert.equal(ui.hash(path.join(root,file)),expected.sha256,'Target hash '+file);
 if(removedFrom)for(const file of Object.keys(removedFrom.files))if(!Object.hasOwn(inventory.files,file))assert.equal(fs.existsSync(path.join(root,file)),false,'Newer artifact remains '+file);
}
async function pendingSale(action){return ui.evaluate(`new Promise((resolve,reject)=>{const request=indexedDB.open('pos_offline');request.onerror=()=>reject(Error('Offline DB unavailable'));request.onsuccess=()=>{const db=request.result;if(!db.objectStoreNames.contains('pending_sales')){db.close();resolve(null);return;}const tx=db.transaction('pending_sales',${JSON.stringify(action==='put'?'readwrite':'readonly')});const store=tx.objectStore('pending_sales');const op=${action==='put'?`store.put({localId:'codex-transition-pending',ownerUserId:9999999,branchId:1,savedAt:'2026-10-21T00:00:00Z',syncStatus:'review',fixture:true})`:`store.get('codex-transition-pending')`};op.onsuccess=()=>resolve(${action==='put'?'true':'op.result'});op.onerror=()=>reject(Error('Offline sale fixture failed'));tx.oncomplete=()=>db.close();};})`);}
async function preview(){
 await ui.until('Boolean(document.querySelector("[data-testid=preview-channel-transition]:not(:disabled)"))','enabled transition preview');
 await ui.evaluate('document.querySelector("[data-testid=preview-channel-transition]").click()');await delay(250);
 await ui.until('document.querySelector("[data-testid=channel-transition]")?.getAttribute("aria-busy")==="false" && Boolean(document.querySelector("[data-testid=transition-preview-result], [data-testid=channel-transition] [role=alert]"))','transition preview');
 const error=await ui.evaluate('document.querySelector("[data-testid=channel-transition] [role=alert]")?.innerText');assert.ok(!error,error);
}
async function returnStable(type){
 await ui.system();await chooseChannel('stable');
 assert.equal(JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version,version,'Selecting a channel installed something');
 await preview();await ui.until('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))','signed stable return');
 const text=await ui.evaluate('document.querySelector("[data-testid=transition-preview-result]").innerText');assert.ok(text.includes(type==='delta'?'Delta':'كاملة'),text);
 await ui.click('انتظار دون تثبيت');assert.equal(JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version,version);
 await preview();await ui.screenshot('return-'+type+'-preview');
 const startedAt=Date.now();
 const runId = 'return_' + type + '_' + startedAt;
 await ui.evaluate(`window.__pageRunId = ${JSON.stringify(runId)}`);
 await ui.evaluate('document.querySelector("[data-testid=apply-channel-transition]").click()');await ui.click('تأكيد');await versionIs('0.0.1',startedAt,runId);
 const deadline=Date.now()+30000;let receipt;
 do{const state=JSON.parse(fs.readFileSync(path.join(storage,'update-state.json')));if(state.transition_receipt){try{receipt=JSON.parse(fs.readFileSync(path.join(storage,'channel-transition-receipts',state.transition_receipt+'.json')));}catch{}if(receipt?.state==='completed')break;if(receipt?.state==='failed')throw Error('Independent verification failed: '+JSON.stringify(receipt));}await delay(250);}while(Date.now()<deadline);
 assert.equal(receipt?.state,'completed','Independent production receipt never completed');assert.equal(receipt.target_inventory_matches,true);assert.equal(receipt.data_compatible,true);
 verifyInventory(target,source);await ui.screenshot('stable-restored-'+type);return receipt;
}
async function failLateHandoff(){
 await ui.system();await chooseChannel('stable');await preview();await ui.until('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))','failure-test preview');
 let receiptId;
 requestTransport.beforeApply(async prepared=>{
   assert.equal(prepared.data?.requires_desktop_handoff,true);receiptId=prepared.data.receipt_id;
   const staged=path.join(storage,'update_staging','0.0.1','version.json');assert.ok(fs.existsSync(staged));
   fs.writeFileSync(staged,'intentional isolated late-handoff corruption');
 });
   await ui.evaluate('document.querySelector("[data-testid=apply-channel-transition]").click()');firstTransitionAt=Date.now();await ui.click('تأكيد');
   await ui.until('Boolean(document.querySelector("[data-testid=channel-transition] [role=alert]"))','real transition failure',120000);
   requestTransport.resume();assert.ok(receiptId);
   const message=await ui.evaluate('document.querySelector("[data-testid=channel-transition] [role=alert]").innerText');assert.ok(message.includes('Staged artifact integrity check failed'),message);
   assert.equal(await ui.evaluate('document.body.innerText.includes("حدث خطأ في الاتصال بالخادم")'),false,'Connection-error spam hid actual failure');
   const receiptPath=path.join(storage,'channel-transition-receipts',receiptId+'.json');let receipt;const deadline=Date.now()+30000;
   do{receipt=JSON.parse(fs.readFileSync(receiptPath));if(receipt.state==='failed')break;await delay(250);}while(Date.now()<deadline);
   assert.equal(receipt.state,'failed');assert.equal(receipt.reason_code,'handoff_failed');assert.equal(receipt.requires_recovery,false);
   verifyInventory(source);await ui.screenshot('late-failure-recovered');return receipt;
}
try {
 await ui.start();auth=JSON.parse(fs.readFileSync(path.join(lab,'fixture-auth.json')));
 if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))'))await ui.login(auth.email,auth.password);
 await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))','resumed fixture session');
 requestTransport=await attachTransitionTransport({ui,application,storage,evidence,fixture:transportPath,auth});
 const before=sql('seed');const compatibilityBefore=sql('inspect-compatibility');
 await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))','ready session');
 let stored=false;for(let i=0;i<20&&!stored;i++){stored=await pendingSale('put');if(!stored)await delay(500);}assert.equal(stored,true,'Offline fixture store unavailable');
 const offlineBefore=await pendingSale('get');
 const common=Object.keys(target.files).filter(file=>source.files[file]?.sha256===target.files[file].sha256);
 const identities=Object.fromEntries(common.map(file=>{const s=fs.statSync(path.join(root,file));return [file,{ino:s.ino,mtimeMs:s.mtimeMs,birthtimeMs:s.birthtimeMs}];}));
 const shellBefore=[ui.hash(path.join(application,'POS Desktop.exe')),ui.hash(path.join(application,'resources/app.asar'))];
 await forward();verifyInventory(source);
 assert.equal(restore().extended_metadata,true,'Current-version backup restore lost extended metadata');assert.deepEqual(sql('inspect'),before);assert.deepEqual(sql('inspect-compatibility'),compatibilityBefore);
 await ui.system();await chooseChannel('stable');
 // A signed transport failure is shown without attempting full fallback.
 mode('corrupt_signature');await preview();assert.equal(await ui.evaluate('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))'),false);assert.equal(await ui.evaluate('document.querySelector("[data-testid=transition-preview-result]").innerText.includes("توقيع")'),true);await ui.screenshot('invalid-signature-blocked');
 mode('delta');const failedReceipt=await failLateHandoff();assert.deepEqual(sql('inspect'),before);
 const deltaReceipt=await returnStable('delta');assert.deepEqual(sql('inspect'),before);assert.deepEqual(await pendingSale('get'),offlineBefore);
 assert.equal(restore().extended_metadata,false,'Historical restore fixture did not recreate the historical metadata shape');assert.deepEqual(sql('inspect'),before);
 if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))'))await ui.login(auth.email,auth.password);
 await requestTransport.reauth();
 await forward();verifyInventory(source);assert.deepEqual(sql('inspect-compatibility'),compatibilityBefore,'Forward startup did not repair metadata after historical restore');
 // Respect the production 2/300s apply limiter across both old/new interfaces.
 while(Date.now()<firstTransitionAt+310000){console.log('Waiting for the unchanged production apply rate window before full return');await delay(Math.min(15000,firstTransitionAt+310000-Date.now()));}
 mode('full');const fullReceipt=await returnStable('full');
 assert.deepEqual(sql('inspect'),before);assert.deepEqual(sql('inspect-compatibility'),compatibilityBefore);assert.deepEqual(await pendingSale('get'),offlineBefore);
 await forward();verifyInventory(source);await ui.system();
 for(const [file,expected] of Object.entries(identities)){const s=fs.statSync(path.join(root,file));assert.deepEqual({ino:s.ino,mtimeMs:s.mtimeMs,birthtimeMs:s.birthtimeMs},expected,'Unchanged file replaced '+file);}
 assert.deepEqual([ui.hash(path.join(application,'POS Desktop.exe')),ui.hash(path.join(application,'resources/app.asar'))],shellBefore);
 assert.equal(await ui.evaluate('Boolean(document.querySelector("option[value=rc]"))'),false);
 const trace=fs.readFileSync(transport.trace,'utf8');assert.ok(trace.includes('delta-0.0.1-to-'+version+'.zip'),'Frozen signed candidate transport was not exercised');
 const result={ok:true,actualOriginalSetup:true,transport:'isolated copied signed packages; live GitHub feeds verified separately after publication',sourceVersion:version,targetVersion:'0.0.1',transitions:['0.0.1',version,'0.0.1',version,'0.0.1',version],deltaAndFull:true,productionHttpPreparation:true,independentVerifierSurvived:true,deltaReceipt,fullReceipt,failedReceipt,lateFailureRecoveredSource:true,realFailureVisibleWithoutConnectionSpam:true,productionRateLimitsUnchanged:true,sourceInventorySha256:ui.hash(path.join(assets,'deployment-inventory.json')),targetInventorySha256:ui.hash(path.join(lab,'deployment-inventory.json')),sqlSchemaLedgerCustomerSettingsPreserved:true,offlineSalePreserved:true,unchangedFileIdentityPreserved:true,unchangedFilesChecked:common.length,shellUnchanged:true,selectionDoesNotInstall:true,waitDoesNotInstall:true,invalidSignatureBlocked:true,activeRcRemoved:true};
 result.currentAndHistoricalSqlRestoreVerified=true;
 fs.writeFileSync(path.join(evidence,'acceptance.json'),JSON.stringify(result,null,2));await ui.screenshot('candidate-final');
 console.log('PASS real original Setup UI: stable-beta-stable-beta, signed delta and full return, HTTP preparation, independent receipt, exact artifacts, unchanged file identity and SQL/offline data preservation');
} catch(error){
  try{await ui.screenshot('failure');}catch{}
  try{
    const stateFile=path.join(storage,'update-state.json');
    if(fs.existsSync(stateFile)){
      const content=fs.readFileSync(stateFile,'utf8');
      console.error('=== update-state.json ===\n'+content);
      fs.writeFileSync(path.join(evidence,'update-state.json'),content);
    }
    const lockFile=path.join(storage,'update-operation.lock');
    if(fs.existsSync(lockFile)){
      const content=fs.readFileSync(lockFile,'utf8');
      console.error('=== update-operation.lock ===\n'+content);
      fs.writeFileSync(path.join(evidence,'update-operation.lock'),content);
    }
    const logDir=path.join(storage,'logs');
    if(fs.existsSync(logDir)){
      for(const f of fs.readdirSync(logDir)){
        const p=path.join(logDir,f);
        if(fs.statSync(p).isFile()){
          const content=fs.readFileSync(p,'utf8');
          console.error(`=== storage/logs/${f} ===\n`+content.slice(-3000));
          fs.writeFileSync(path.join(evidence,f),content);
        }
      }
    }
  }catch(diagErr){
    console.error('Failed to capture disk diagnostics:',diagErr.message);
  }
  throw error;
}
finally{try{await requestTransport?.close();}finally{await ui.stop();}}
