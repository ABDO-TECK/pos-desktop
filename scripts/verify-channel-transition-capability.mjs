import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import net from 'node:net';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';

const fixture=path.resolve('scripts/fixtures/channel-transition-capability.php');
assert.ok(fs.existsSync(fixture),'Capability launcher fixture is not implemented');
const php=process.env.PHP_BINARY||'php';
const wait=ms=>new Promise(r=>setTimeout(r,ms));
async function readEventually(file,predicate){const end=Date.now()+20_000;do{try{const data=JSON.parse(fs.readFileSync(file));if(predicate(data))return data;}catch{}await wait(200);}while(Date.now()<end);throw Error('Capability receipt did not advance');}
const lab=fs.mkdtempSync(path.join(os.tmpdir(),'pos-capability '));
const plan=path.join(lab,'plan.json'),heartbeat=path.join(lab,'heartbeat.json');
fs.writeFileSync(plan,JSON.stringify({mode:'launcher',directory:lab}));
const server=net.createServer();await new Promise(r=>server.listen(0,'127.0.0.1',r));const port=server.address().port;await new Promise(r=>server.close(r));
const parent=spawn(php,['-S','127.0.0.1:'+port,fixture],{windowsHide:true,stdio:'ignore',env:{...process.env,POS_CAPABILITY_PLAN:plan}});
try{
 let response;for(let i=0;i<40;i++){try{response=await fetch('http://127.0.0.1:'+port+'/');if(response.ok)break;}catch{}await wait(100);}
 const body=await response?.text();assert.equal(response?.status,200,body);assert.equal(JSON.parse(body).launched,true);
 const before=await readEventually(heartbeat,r=>r.counter>0);parent.kill();await new Promise(r=>parent.once('exit',r));
 const after=await readEventually(heartbeat,r=>r.counter>before.counter);assert.equal(after.parent_mode,'disposable_php_http');
 fs.writeFileSync(path.join(lab,'stop'),'done');
 console.log('PASS independent PHP observer advances after disposable PHP HTTP parent termination');
}finally{if(parent.exitCode===null)parent.kill();fs.writeFileSync(path.join(lab,'stop'),'done');}
if(process.argv.includes('--launcher-only'))process.exit(0);
assert.equal(process.env.GITHUB_ACTIONS,'true','Actual Setup capability requires ephemeral Windows CI');
const [application,evidence,assets]=process.argv.slice(2);
execFileSync(process.execPath,['scripts/verify-rollback-ui.mjs',application,evidence,assets],{stdio:'inherit',windowsHide:true,env:{...process.env,POS_CHANNEL_CAPABILITY:'1'}});
const result=JSON.parse(fs.readFileSync(path.join(evidence,'capability.json')));
assert.equal(result.survived_handoff,true);assert.equal(result.target_inventory_matches,true);assert.equal(result.schema_unchanged,true);assert.equal(result.ledger_unchanged,true);assert.equal(result.business_rows_preserved,true);
console.log('PASS actual Setup handoff capability: observer survived, signed original artifacts restored, SQL fixture/schema/ledger preserved');
