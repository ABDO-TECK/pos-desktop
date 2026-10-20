import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const root=path.resolve(process.argv[2]||'.');
const require=createRequire(path.join(root,'frontend/package.json'));
const ts=require('typescript'),axios=require('axios');
const modules=new Map(),errors=[];
let now=0;
class Clock extends Date { static now(){return now;} }
const timers=new Map();let timerId=0;
function load(file){
  const full=path.resolve(root,'frontend/src',file);
  if(modules.has(full))return modules.get(full);
  const exports={};modules.set(full,exports);
  const code=ts.transpileModule(fs.readFileSync(full,'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,esModuleInterop:true}}).outputText;
  vm.runInNewContext(code,{exports,Date:Clock,Symbol,Promise,Error,
    setTimeout:(callback,delay)=>{const id=++timerId;timers.set(id,{callback,at:now+delay});return id;},
    clearTimeout:id=>timers.delete(id),
    window:{API_BASE_URL:'http://127.0.0.1'},localStorage:{},sessionStorage:{},
    require:id=>id==='react-hot-toast'?{__esModule:true,default:{error:message=>errors.push(message)}}:
      id.startsWith('.')?load(path.relative(path.join(root,'frontend/src'),path.resolve(path.dirname(full),id+'.ts'))):require(id),
  });
  return exports;
}
const api=load('api/axios.ts').default;
function networkFailure(){api.defaults.adapter=async config=>{throw new axios.AxiosError('Network Error','ERR_NETWORK',config);};return api.get('/updates/status').catch(e=>e);}
await networkFailure();
assert.equal(errors.length,1,'normal connection errors must remain visible');
errors.length=0;

const endpoints=load('api/endpoints.ts');
await endpoints.getUpdateStatus(true,{hideGlobalError:true}).catch(e=>e);
assert.equal(errors.length,0,'background update poll must be quiet while caller handles state');
await Promise.all([
 endpoints.getUpdateHistory({hideGlobalError:true}),
 endpoints.getUpdateSnapshots({hideGlobalError:true}),
 endpoints.diagnoseUpdateRecovery({hideGlobalError:true}),
 endpoints.getRecoveryAuditLogs(20,{hideGlobalError:true}),
].map(p=>p.catch(e=>e)));
assert.equal(errors.length,0,'background recovery/history calls must not flood toasts');
const restart=load('utils/updateRestart.ts');
let release;
const pending=restart.withUpdateRestart(()=>new Promise(resolve=>{release=resolve;}));
await Promise.resolve();
await Promise.all([networkFailure(),networkFailure(),networkFailure()]);
assert.equal(errors.length,0,'planned restart must not emit repeated global network toasts');
assert.equal(restart.isUpdateRestarting(),true);

api.defaults.adapter=async config=>{throw new axios.AxiosError('Request failed','ERR_BAD_RESPONSE',config,{},{
  status:500,statusText:'Server Error',headers:{},config,data:{message:'Genuine update failure'}
});};
await api.get('/health').catch(e=>e);
assert.deepEqual(errors,['Genuine update failure'],'real HTTP failures must not be hidden during restart');
errors.length=0;
load('api/axios.ts').setCsrfSignature('a'.repeat(64));
const updateFailure=await endpoints.applyUpdate(false,true).catch(e=>e);
assert.equal(updateFailure.response.data.message,'Genuine update failure');
assert.equal(errors.length,0,'apply caller owns the genuine error notice, without duplicate global toast');
release({ok:true});await pending;
await networkFailure();assert.equal(errors.length,1,'normal error handling must recover after restart');

errors.length=0;
await assert.rejects(restart.withUpdateRestart(async()=>{throw new Error('Actual handoff failed');}),/Actual handoff failed/);
assert.equal(restart.isUpdateRestarting(),false,'failed handoff must end maintenance');
await networkFailure();assert.equal(errors.length,1);

const hung=restart.withUpdateRestart(()=>new Promise(()=>{}));
await Promise.resolve();now=240_000;
for(const t of [...timers.values()])if(t.at<=now)t.callback();
await assert.rejects(hung,/مهلة|خدمات/);
assert.equal(restart.isUpdateRestarting(),false,'hung restart must not silence future connection failures');
const component=ts.createSourceFile('UpdateSection.tsx',
 fs.readFileSync(path.join(root,'frontend/src/pages/settings/UpdateSection.tsx'),'utf8'),
 ts.ScriptTarget.Latest,true,ts.ScriptKind.TSX);
const declaration=component.statements.find(n=>ts.isFunctionDeclaration(n)&&n.name?.text==='UpdateSection');
const applyHandler=declaration.body.statements.find(n=>ts.isVariableStatement(n)&&
 n.declarationList.declarations.some(d=>d.name.getText(component)==='handleApplyUpdate'));
const printed=ts.createPrinter().printNode(ts.EmitHint.Unspecified,applyHandler,component);
const handlerCode=ts.transpileModule(printed+'; exports.invoke=handleApplyUpdate;',
 {compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText;
const handlerExports={},visibleFailures=[];
vm.runInNewContext(handlerCode,{
 exports:handlerExports,Error,setTimeout,isAxiosError:axios.isAxiosError,
 useCartStore:{getState:()=>({items:[]})},hasDeltaHandoffCapability:async()=>true,
 updaterApi:undefined,updateStatus:{type:'delta'},hasUpdate:true,bootstrapRequired:false,
 confirm:async()=>true,setApplyingUpdate:()=>{},setUpdateLogs:()=>{},
 loadStatusAndHistory:()=>new Promise(()=>{}),
 applyUpdate:async()=>{throw new axios.AxiosError('Request failed','ERR_BAD_RESPONSE',{}, {},{
  status:500,data:{message:'Actual install failed'},headers:{},config:{}
 });},
 toast:{error:message=>visibleFailures.push(message)},
});
let handlerFinished=false;
handlerExports.invoke().then(()=>{handlerFinished=true;});
await new Promise(resolve=>setTimeout(resolve,25));
assert.deepEqual(visibleFailures,['Actual install failed'],'failure notice must not wait for background refresh');
assert.equal(handlerFinished,true,'failed apply handler must finish even if history server hangs');
console.log('PASS: restart network toasts suppressed; real HTTP/IPC failures and post-restart errors remain visible; deadline releases maintenance');
