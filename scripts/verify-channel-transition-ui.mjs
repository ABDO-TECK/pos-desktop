import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createDesktopLab,delay} from './desktop-transition-lab.mjs';

const [phase,appArg,evidenceArg,assetsArg]=process.argv.slice(2);
const application=path.resolve(appArg),evidence=path.resolve(evidenceArg),assets=path.resolve(assetsArg);
const lab=path.dirname(application),root=path.join(application,'resources/app.asar.unpacked');
const storage=path.join(process.env.PROGRAMDATA,'POS System');
const php=process.env.PHP_BINARY||'php';
const fixture=path.resolve('scripts/fixtures/channel-transition-capability.php');
const sql=mode=>JSON.parse(execFileSync(php,[fixture,mode],{encoding:'utf8',windowsHide:true}));
const target=JSON.parse(fs.readFileSync(path.join(lab,'deployment-inventory.json')));
const source=JSON.parse(fs.readFileSync(path.join(assets,'deployment-inventory.json')));
const version=source.version;
assert.equal(target.version,'0.0.1');assert.match(version,/^0\.0\.10-beta\.\d+$/);
if(phase==='contract') {
  const ui=createDesktopLab(application,evidence);
  try {
    await ui.start();
    for(const [file,expected] of Object.entries(target.files))assert.equal(ui.hash(path.join(root,file)),expected.sha256,'Original Setup inventory '+file);
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
const transport={mode:'delta',responses:{},assets:{}};
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
const ini=path.join(application,'resources/portable/php/php.ini');
assert.ok(fs.existsSync(ini),'Portable PHP ini missing');
fs.appendFileSync(ini,`\nauto_prepend_file="${path.resolve('scripts/fixtures/channel-transition-transport.php').replaceAll('\\','/')}"\n`);
const ui=createDesktopLab(application,evidence,{POS_TRANSITION_FIXTURE:transportPath});
let auth;
async function versionIs(expected){
 const deadline=Date.now()+150000;
 do{try{if(JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version===expected){await delay(5000);await ui.until('Boolean(document.querySelector("form, a[href=\'/settings\']"))','restarted frontend',120000);if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))'))await ui.login(auth.email,auth.password);await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))','authenticated after restart');return;}}catch{}await delay(500);}while(Date.now()<deadline);throw Error('Version/restart failed '+expected);
}
async function chooseChannel(channel){
 await ui.until('Boolean(document.querySelector("select option[value=beta]"))','channel selector');
 await ui.evaluate(`(() => {const select=[...document.querySelectorAll('select')].find(s=>s.querySelector('option[value=beta]'));Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,'value').set.call(select,${JSON.stringify(channel)});select.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 await delay(1500);
}
async function forward(){
 await ui.system();await chooseChannel('beta');await ui.click('التحقق من التحديثات');
 await ui.until('[...document.querySelectorAll("button")].some(b=>b.textContent.trim()==="تثبيت التحديث الآن"&&!b.disabled)','normal beta install');
 await ui.click('تثبيت التحديث الآن');await ui.click('تأكيد');await versionIs(version);
}
function verifyInventory(inventory,removedFrom){
 for(const [file,expected] of Object.entries(inventory.files))assert.equal(ui.hash(path.join(root,file)),expected.sha256,'Target hash '+file);
 if(removedFrom)for(const file of Object.keys(removedFrom.files))if(!Object.hasOwn(inventory.files,file))assert.equal(fs.existsSync(path.join(root,file)),false,'Newer artifact remains '+file);
}
async function pendingSale(action){return ui.evaluate(`new Promise((resolve,reject)=>{const request=indexedDB.open('pos_offline');request.onerror=()=>reject(Error('Offline DB unavailable'));request.onsuccess=()=>{const db=request.result;if(!db.objectStoreNames.contains('pending_sales')){db.close();resolve(null);return;}const tx=db.transaction('pending_sales',${JSON.stringify(action==='put'?'readwrite':'readonly')});const store=tx.objectStore('pending_sales');const op=${action==='put'?`store.put({localId:'codex-transition-pending',ownerUserId:9999999,branchId:1,savedAt:'2026-10-21T00:00:00Z',syncStatus:'review',fixture:true})`:`store.get('codex-transition-pending')`};op.onsuccess=()=>resolve(${action==='put'?'true':'op.result'});op.onerror=()=>reject(Error('Offline sale fixture failed'));tx.oncomplete=()=>db.close();};})`);}
async function preview(){await ui.evaluate('document.querySelector("[data-testid=preview-channel-transition]").click()');await ui.until('Boolean(document.querySelector("[data-testid=transition-preview-result]"))','transition preview');}
async function returnStable(type){
 await ui.system();await chooseChannel('stable');
 assert.equal(JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version,version,'Selecting a channel installed something');
 await preview();await ui.until('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))','signed stable return');
 const text=await ui.evaluate('document.querySelector("[data-testid=transition-preview-result]").innerText');assert.ok(text.includes(type==='delta'?'Delta':'كاملة'),text);
 await ui.click('انتظار دون تثبيت');assert.equal(JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version,version);
 await preview();await ui.screenshot('return-'+type+'-preview');
 await ui.evaluate('document.querySelector("[data-testid=apply-channel-transition]").click()');await ui.click('تأكيد');await versionIs('0.0.1');
 const deadline=Date.now()+30000;let receipt;
 do{const state=JSON.parse(fs.readFileSync(path.join(storage,'update-state.json')));if(state.transition_receipt){try{receipt=JSON.parse(fs.readFileSync(path.join(storage,'channel-transition-receipts',state.transition_receipt+'.json')));}catch{}if(receipt?.state==='completed')break;if(receipt?.state==='failed')throw Error('Independent verification failed: '+JSON.stringify(receipt));}await delay(250);}while(Date.now()<deadline);
 assert.equal(receipt?.state,'completed','Independent production receipt never completed');assert.equal(receipt.target_inventory_matches,true);assert.equal(receipt.data_compatible,true);
 verifyInventory(target,source);await ui.screenshot('stable-restored-'+type);return receipt;
}
try {
 await ui.start();auth=await ui.freshLogin();
 const before=sql('seed');const compatibilityBefore=sql('inspect-compatibility');
 await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))','ready session');
 let stored=false;for(let i=0;i<20&&!stored;i++){stored=await pendingSale('put');if(!stored)await delay(500);}assert.equal(stored,true,'Offline fixture store unavailable');
 const offlineBefore=await pendingSale('get');
 const common=Object.keys(target.files).filter(file=>source.files[file]?.sha256===target.files[file].sha256);
 const identities=Object.fromEntries(common.map(file=>{const s=fs.statSync(path.join(root,file));return [file,{ino:s.ino,mtimeMs:s.mtimeMs,birthtimeMs:s.birthtimeMs}];}));
 const shellBefore=[ui.hash(path.join(application,'POS Desktop.exe')),ui.hash(path.join(application,'resources/app.asar'))];
 await forward();verifyInventory(source);await ui.system();await chooseChannel('stable');
 // A signed transport failure is shown without attempting full fallback.
 mode('corrupt_signature');await preview();assert.equal(await ui.evaluate('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))'),false);assert.equal(await ui.evaluate('document.querySelector("[data-testid=transition-preview-result]").innerText.includes("توقيع")'),true);await ui.screenshot('invalid-signature-blocked');
 mode('delta');const deltaReceipt=await returnStable('delta');assert.deepEqual(sql('inspect'),before);assert.deepEqual(await pendingSale('get'),offlineBefore);
 await forward();verifyInventory(source);mode('full');const fullReceipt=await returnStable('full');
 assert.deepEqual(sql('inspect'),before);assert.deepEqual(sql('inspect-compatibility'),compatibilityBefore);assert.deepEqual(await pendingSale('get'),offlineBefore);
 await forward();verifyInventory(source);await ui.system();
 for(const [file,expected] of Object.entries(identities)){const s=fs.statSync(path.join(root,file));assert.deepEqual({ino:s.ino,mtimeMs:s.mtimeMs,birthtimeMs:s.birthtimeMs},expected,'Unchanged file replaced '+file);}
 assert.deepEqual([ui.hash(path.join(application,'POS Desktop.exe')),ui.hash(path.join(application,'resources/app.asar'))],shellBefore);
 assert.equal(await ui.evaluate('Boolean(document.querySelector("option[value=rc]"))'),false);
 const result={ok:true,actualOriginalSetup:true,transport:'isolated copied signed packages; live GitHub feeds verified separately after publication',sourceVersion:version,targetVersion:'0.0.1',transitions:['0.0.1',version,'0.0.1',version,'0.0.1',version],deltaAndFull:true,productionHttpPreparation:true,independentVerifierSurvived:true,deltaReceipt,fullReceipt,sourceInventorySha256:ui.hash(path.join(assets,'deployment-inventory.json')),targetInventorySha256:ui.hash(path.join(lab,'deployment-inventory.json')),sqlSchemaLedgerCustomerSettingsPreserved:true,offlineSalePreserved:true,unchangedFileIdentityPreserved:true,unchangedFilesChecked:common.length,shellUnchanged:true,selectionDoesNotInstall:true,waitDoesNotInstall:true,invalidSignatureBlocked:true,activeRcRemoved:true};
 fs.writeFileSync(path.join(evidence,'acceptance.json'),JSON.stringify(result,null,2));await ui.screenshot('candidate-final');
 console.log('PASS real original Setup UI: stable-beta-stable-beta, signed delta and full return, HTTP preparation, independent receipt, exact artifacts, unchanged file identity and SQL/offline data preservation');
} catch(error){try{await ui.screenshot('failure');}catch{}throw error;}
finally{await ui.stop();}
