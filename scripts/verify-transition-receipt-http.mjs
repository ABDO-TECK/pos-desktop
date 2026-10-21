import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import net from 'node:net';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {spawn} from 'node:child_process';
const fixture=path.resolve('scripts/fixtures/channel-transition-receipt-http.php');
assert.ok(fs.existsSync(fixture),'Production receipt HTTP launcher fixture missing');
const php=process.env.PHP_BINARY||'php';
const lab=fs.mkdtempSync(path.join(os.tmpdir(),'pos-transition-receipt-http lab '));
const retained=path.join(lab,'source.phar');fs.copyFileSync('backend/backend.phar',retained);
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function read(file,predicate){const deadline=Date.now()+15000;do{try{const value=JSON.parse(fs.readFileSync(file));if(predicate(value))return value;}catch{}await delay(100);}while(Date.now()<deadline);throw Error('Production observer receipt did not advance: '+file);}
try{
 for(const scenario of ['wrong_target','wrong_target_locked','superseded','rollback_incomplete','rollback_recovered']){
  const directory=path.join(lab,scenario),root=path.join(directory,'root'),storage=path.join(directory,'storage'),snapshot=path.join(directory,'snapshot');
  for(const dir of [root,storage,snapshot])fs.mkdirSync(dir,{recursive:true});fs.writeFileSync(path.join(root,'version.json'),scenario==='rollback_recovered'?'source':'wrong');
  const plan={root,storage,hold_lease:scenario==='wrong_target_locked',source_phar:retained,snapshot_path:snapshot,target_version:'0.0.1',source_inventory:{files:{'version.json':{sha256:crypto.createHash('sha256').update('source').digest('hex'),size:6}}},introduced_files:[],target_inventory:{files:{'version.json':{sha256:'a'.repeat(64),size:5}}},deleted_files:[],data_compatibility:{}};
  const planPath=path.join(directory,'input.json');fs.writeFileSync(planPath,JSON.stringify(plan));
  const listener=net.createServer();await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve));const port=listener.address().port;await new Promise(resolve=>listener.close(resolve));
  const parent=spawn(php,['-S','127.0.0.1:'+port,fixture],{windowsHide:true,stdio:'ignore',env:{...process.env,POS_RECEIPT_LAB_PLAN:planPath,APP_DEPLOY_ROOT:root,APP_STORAGE_DIR:storage,APP_ENV:'development',DEPLOYMENT_MODE:'desktop',DB_USER:'pos_fixture',DB_PASS:'not-used-no-database-connection'}});
  try{
   let response;for(let i=0;i<50;i++){try{response=await fetch('http://127.0.0.1:'+port);break;}catch{}await delay(100);}
   const body=await response?.text();assert.equal(response?.status,200,body);const {receipt_id:id,lease}=JSON.parse(body);assert.match(id,/^[a-f0-9]{64}$/);
   const statePath=path.join(storage,'update-state.json');const before=JSON.parse(fs.readFileSync(statePath));
   assert.equal(before.transition_receipt,id);
   parent.kill();await new Promise(resolve=>parent.once('exit',resolve));
   const state={...before,state:scenario.startsWith('wrong_target')?'completed':scenario.startsWith('rollback_')?'rolled_back':'downloading',operation:scenario==='superseded'?'backend_delta_apply':'electron_delta_install'};
   fs.writeFileSync(statePath,JSON.stringify(state));
   if(plan.hold_lease){
    assert.equal(lease.acquired,true);await delay(750);
    assert.equal(JSON.parse(fs.readFileSync(path.join(storage,'channel-transition-receipts',id+'.json'))).state,'observing','Observer crossed an active operation lease');
    assert.deepEqual(JSON.parse(fs.readFileSync(statePath)),state,'Observer wrote state while another operation owned its lease');
    assert.equal(path.resolve(lease.lock_path),path.join(storage,'update-operation.lock'));fs.unlinkSync(lease.lock_path);
   }
   const receipt=await read(path.join(storage,'channel-transition-receipts',id+'.json'),value=>value.state==='failed');
   assert.equal(receipt.reason_code,scenario.startsWith('wrong_target')?'target_inventory_mismatch':scenario.startsWith('rollback_')?'handoff_failed':'operation_superseded');
   if(scenario.startsWith('wrong_target'))assert.equal((await read(statePath,value=>value.state==='failed')).requires_recovery,true);
   else if(scenario==='superseded')assert.deepEqual(JSON.parse(fs.readFileSync(statePath)),state,'Obsolete observer overwrote a newer operation');
   else {assert.equal(receipt.requires_recovery,scenario==='rollback_incomplete','Rollback recovery was claimed without proving exact source files');assert.equal(receipt.source_inventory_matches,scenario==='rollback_recovered');}
  }finally{if(parent.exitCode===null)parent.kill();}
 }
 console.log('PASS production receipt HTTP launch survives parent termination, rejects wrong target and cannot overwrite a superseding operation; no database connection');
}finally{
 await delay(500);
 const canonical=fs.realpathSync(lab);assert.ok(canonical.toLowerCase().startsWith(fs.realpathSync(os.tmpdir()).toLowerCase()+path.sep));assert.match(path.basename(canonical),/^pos-transition-receipt-http lab /);
 fs.rmSync(canonical,{recursive:true,force:true,maxRetries:10,retryDelay:100});
}
