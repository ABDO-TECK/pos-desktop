import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';

export const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
export function createDesktopLab(application,evidence,extraEnv={}) {
  assert.equal(process.env.GITHUB_ACTIONS,'true','Desktop lab requires ephemeral CI');
  const temporary=fs.realpathSync(process.env.RUNNER_TEMP).toLowerCase()+path.sep;
  assert.ok(fs.realpathSync(application).toLowerCase().startsWith(temporary),'Application must be isolated');
  fs.mkdirSync(evidence,{recursive:true});
  let child,socket,sequence=0;
  const pending=new Map();
  const listeners=new Set();
  const hash=file=>crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
  async function call(method,params={}) {
    const id=++sequence;let timer;
    const response=new Promise((resolve,reject)=>{timer=setTimeout(()=>{pending.delete(id);reject(Error('UI command timed out: '+method));},25000);pending.set(id,resolve);});
    socket.send(JSON.stringify({id,method,params}));
    try {const reply=await response;assert.ok(!reply.error&&!reply.result?.exceptionDetails,'UI command failed: '+JSON.stringify(reply.error||reply.result?.exceptionDetails||method));return reply.result;}
    finally{clearTimeout(timer);}
  }
  async function evaluate(expression){return (await call('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true})).result?.value;}
  async function until(expression,label,timeout=90000){const deadline=Date.now()+timeout;do{if(await evaluate(expression))return;await delay(250);}while(Date.now()<deadline);throw Error('Timed out: '+label);}
  async function start(){
    const env={...process.env,...extraEnv};for(const key of ['GH_TOKEN','GITHUB_TOKEN','UPDATE_PRIVATE_KEY'])delete env[key];
    const output=fs.openSync(path.join(evidence,'desktop.log'),'a');
    child=spawn(path.join(application,'POS Desktop.exe'),['--remote-debugging-port=19231'],{cwd:application,env,windowsHide:true,stdio:['ignore',output,output]});fs.closeSync(output);
    const deadline=Date.now()+240000;let target;
    do{if(child.exitCode!==null)throw Error('Desktop exited during startup');try{target=(await(await fetch('http://127.0.0.1:19231/json/list')).json()).find(t=>t.type==='page'&&t.url.startsWith('app://pos-app/'));}catch{}if(target)break;await delay(500);}while(Date.now()<deadline);
    assert.ok(target,'Isolated desktop page missing');socket=new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
    socket.onmessage=event=>{const reply=JSON.parse(event.data);const resolve=pending.get(reply.id);if(resolve){pending.delete(reply.id);resolve(reply);}if(reply.method)for(const listener of listeners)listener(reply.method,reply.params);};
    listeners.add((method,params)=>{
      if(method==='Runtime.consoleAPICalled'){
        const text=(params.args||[]).map(a=>a.value??a.description??JSON.stringify(a)).join(' ');
        console.log(`[Renderer Console ${params.type}] ${text}`);
      }else if(method==='Runtime.exceptionThrown'){
        const desc=params.exceptionDetails?.exception?.description||params.exceptionDetails?.text||'unknown';
        console.error(`[Renderer Exception] ${desc}`);
      }
    });
    await call('Runtime.enable');
    await call('Console.enable').catch(()=>{});
    await until('Boolean(window.electronAPI && document.querySelector("form, a[href=\'/settings\']"))','initial page',240000);
  }
  async function stop(){
    if(socket?.readyState===WebSocket.OPEN){socket.send(JSON.stringify({id:++sequence,method:'Browser.close'}));socket.close();}
    if(child&&child.exitCode===null)await Promise.race([new Promise(resolve=>child.once('exit',resolve)),delay(35000)]);
    assert.ok(!child||child.exitCode!==null,'Isolated desktop did not stop');await delay(2000);
  }
  async function click(text){await until(`[...document.querySelectorAll('button')].some(b=>b.textContent.trim()===${JSON.stringify(text)})`,'button '+text);await evaluate(`(() => {const b=[...document.querySelectorAll('button')].find(b=>b.textContent.trim()===${JSON.stringify(text)});b.scrollIntoView({block:'center'});b.click();})()`);}
  async function fill(selector,value){assert.equal(await evaluate(`(() => {const e=document.querySelector(${JSON.stringify(selector)});if(!e)return false;Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}));return true;})()`),true);await delay(100);}
  async function login(email,password){await until('Boolean(document.querySelector("input[type=email]"))','login');await fill('input[type=email]',email);await fill('input[type=password]',password);await evaluate('document.querySelector("form button[type=submit]").click()');}
  async function freshLogin(){
    const initial=await evaluate('window.electronAPI.setup.getInitialAdmin()');assert.ok(initial?.password,'Fresh temporary admin missing');
    await until('Boolean(document.querySelector("[aria-labelledby=initial-admin-title]"))','temporary password notice');await click('فهمت');await login(initial.email,initial.password);
    const password='Lab-'+crypto.randomBytes(22).toString('base64url');
    await until('Boolean(document.querySelector("#forced-current-password"))','forced password change');
    await fill('#forced-current-password',initial.password);await fill('#forced-new-password',password);await fill('#forced-confirm-password',password);
    await evaluate('document.querySelector("#forced-current-password").closest("form").querySelector("button[type=submit]").click()');
    await until('!document.querySelector("#forced-current-password") && Boolean(document.querySelector("input[type=email]"))','reauthentication');await login(initial.email,password);
    await until('Boolean(document.querySelector("a[href=\'/settings\']"))','authenticated navigation');return {email:initial.email,password};
  }
  async function system(){await until('Boolean(document.querySelector("a[href=\'/settings\']"))','settings link');await evaluate('document.querySelector("a[href=\'/settings\']").click()');await until('location.pathname==="/settings"','settings route');await click('النظام والصيانة');}
  async function screenshot(name){await evaluate('document.querySelector("[data-testid=channel-transition]")?.scrollIntoView({block:"center"})');const capture=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(evidence,name+'.png'),Buffer.from(capture.data,'base64'));}
  return {start,stop,evaluate,until,click,fill,login,freshLogin,system,screenshot,hash,call,onEvent:listener=>{listeners.add(listener);return ()=>listeners.delete(listener);}};
}
