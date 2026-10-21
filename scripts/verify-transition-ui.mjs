import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
import {pathToFileURL} from 'node:url';
const root=process.cwd(),lab=fs.mkdtempSync(path.join(os.tmpdir(),'pos-channel-hook-'));
const require=createRequire(import.meta.url);
const {rolldown}=await import(pathToFileURL(path.join(root,'frontend/node_modules/rolldown/dist/index.mjs')).href);
try {
 const entry=path.join(lab,'entry.js');
 fs.writeFileSync(entry,`import React from ${JSON.stringify(path.join(root,'frontend/node_modules/react/index.js'))};
import {createRoot} from ${JSON.stringify(path.join(root,'frontend/node_modules/react-dom/client.js'))};
import useChannelTransition from ${JSON.stringify(path.join(root,'frontend/src/hooks/useChannelTransition.ts'))};
window.requests=[];window.installRequests=0;let renderer;
function Probe(){const [channel,setChannel]=React.useState('stable');const hook=useChannelTransition(channel);window.probe={hook,setChannel};return null;}
window.mount=()=>{renderer=createRoot(document.getElementById('root'));renderer.render(React.createElement(Probe));};window.unmount=()=>renderer.unmount();window.mount();`);
 const bundle=await rolldown({input:entry,plugins:[{name:'isolated-hook-dependencies',resolveId(source,importer){
  if(!importer?.endsWith('useChannelTransition.ts'))return;
  if(source.includes('api/endpoints'))return '\0fixture-api';
  if(source.includes('cartStore'))return '\0fixture-cart';
  if(source.includes('confirmStore'))return '\0fixture-confirm';
  if(source.includes('updateRestart'))return '\0fixture-restart';
  if(source==='axios')return '\0fixture-axios';
  if(source==='react-hot-toast')return '\0fixture-toast';
 },load(id){return ({
  '\0fixture-api':`export const previewChannelTransition=()=>new Promise(resolve=>window.requests.push(resolve));export const applyChannelTransition=()=>{window.installRequests++;throw Error('Unexpected installation');};export const getUpdateStatus=()=>Promise.resolve({});`,
  '\0fixture-cart':`export default {getState:()=>({items:[]})};`,
  '\0fixture-confirm':`export const useConfirmStore=()=>({confirm:async()=>false});`,
  '\0fixture-restart':`export const withUpdateRestart=fn=>fn();`,
  '\0fixture-axios':`export const isAxiosError=()=>false;`,
  '\0fixture-toast':`export default {error(){},success(){}};`,
 })[id];}}]});
 await bundle.write({file:path.join(lab,'hook.js'),format:'iife'});await bundle.close();
 fs.writeFileSync(path.join(lab,'index.html'),'<div id="root"></div><script src="hook.js"></script>');
 fs.writeFileSync(path.join(lab,'main.cjs'),`const {app,BrowserWindow}=require('electron');const assert=require('node:assert/strict');
const delay=ms=>new Promise(r=>setTimeout(r,ms));
app.setPath('userData',${JSON.stringify(path.join(lab,'profile'))});
app.whenReady().then(async()=>{const window=new BrowserWindow({show:false,webPreferences:{contextIsolation:true,nodeIntegration:false}});const evaluate=s=>window.webContents.executeJavaScript(s);
try{await window.loadFile(${JSON.stringify(path.join(lab,'index.html'))});await delay(200);
assert.equal(await evaluate('requests.length'),1,'Entering the channel panel did not fetch its preview automatically');
const result=channel=>({data:{data:{status:'ready',selected_channel:channel,preview_id:'a'.repeat(64),installed:{version:'0.0.10-beta.5'},target:{version:channel==='stable'?'0.0.1':'0.0.10-beta.6',channel}}}});
await evaluate('requests[0]('+JSON.stringify(result('stable'))+')');await delay(100);
await evaluate('probe.hook.cancel()');await delay(100);assert.equal(await evaluate('probe.hook.preview?.target.version'),'0.0.1','Waiting removed known target/readiness');assert.equal(await evaluate('probe.hook.waiting'),true);
await evaluate('unmount();mount()');await delay(100);assert.equal(await evaluate('requests.length'),2);await evaluate('requests[1]('+JSON.stringify(result('stable'))+')');await delay(100);assert.equal(await evaluate('probe.hook.waiting'),true,'Reopening lost the waiting choice');
await evaluate('probe.setChannel("beta")');await delay(100);assert.equal(await evaluate('requests.length'),3,'Channel selection did not fetch preview');assert.equal(await evaluate('probe.hook.preview'),null,'Old preview survived a channel change');
await evaluate('probe.setChannel("stable")');await delay(100);await evaluate('requests[3]('+JSON.stringify(result('stable'))+')');await delay(100);await evaluate('requests[2]('+JSON.stringify(result('beta'))+')');await delay(100);assert.equal(await evaluate('probe.hook.preview.selected_channel'),'stable','A stale channel response replaced the latest choice');
assert.equal(await evaluate('installRequests'),0,'Preview/wait/selection installed an update');console.log('PASS actual React hook: automatic preview, retained wait across reopening, stale responses ignored and zero installation requests');app.exit(0);
}catch(error){console.error(error.stack);app.exit(1);}});`);
 const env={...process.env};delete env.ELECTRON_RUN_AS_NODE;
 const electronPackage=path.join(root,'node_modules/electron');
 if(!fs.existsSync(path.join(electronPackage,'dist/electron.exe')))execFileSync(process.execPath,[path.join(electronPackage,'install.js')],{env:{...env,ELECTRON_SKIP_BINARY_DOWNLOAD:''},windowsHide:true,stdio:'inherit'});
 const electron=require(electronPackage);assert.ok(fs.existsSync(electron),'Pinned test Electron runtime was not installed');
 const child=spawn(electron,[path.join(lab,'main.cjs')],{env,windowsHide:true,stdio:['ignore','pipe','pipe']});let output='';child.stdout.on('data',d=>output+=d);child.stderr.on('data',d=>output+=d);
 const code=await new Promise((resolve,reject)=>{const timer=setTimeout(()=>{child.kill();reject(Error('Hook fixture timeout'));},30000);child.on('error',reject);child.on('exit',code=>{clearTimeout(timer);resolve(code);});});assert.equal(code,0,output);console.log(output.trim());
}finally{assert.ok(fs.realpathSync(lab).startsWith(fs.realpathSync(os.tmpdir())+path.sep));fs.rmSync(lab,{recursive:true,force:true,maxRetries:10,retryDelay:100});}

