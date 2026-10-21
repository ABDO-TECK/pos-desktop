import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createRequire} from 'node:module';
import {delay} from './desktop-transition-lab.mjs';

/** UI request transport only. Runs the actual deployed PHAR, auth and CSRF. */
export async function attachTransitionTransport({ui,application,storage,evidence,fixture,auth}) {
  assert.equal(process.env.GITHUB_ACTIONS,'true');
  const require=createRequire(import.meta.url);
  const {getPhpRuntimeArgs}=require('../electron/utils/php-runtime.js');
  const config=path.join(process.env.APPDATA,'POS System');
  const ports=()=>JSON.parse(fs.readFileSync(path.join(config,'runtime_ports.json')));
  const credentials=JSON.parse(fs.readFileSync(path.join(config,'db_credentials.json')));
  const realBase=ports().apiBaseUrl;
  const cookies=new Map();
  const captureCookies=response=>{for(const cookie of response.headers.getSetCookie()){const pair=cookie.split(';',1)[0],split=pair.indexOf('=');if(split>0)cookies.set(pair.slice(0,split),pair.slice(split+1));}};
  const cookieHeader=()=>[...cookies].map(([name,value])=>`${name}=${value}`).join('; ');
  const login=await fetch(realBase+'/api/v1/login',{method:'POST',headers:{Origin:'app://pos-app','Content-Type':'application/json'},body:JSON.stringify(auth)});
  assert.equal(login.status,200,'Real fixture account login for HTTP transport failed');captureCookies(login);
  assert.ok(cookies.has('pos_token'),'Production login did not issue its session cookie');
  const csrf=await fetch(realBase+'/api/v1/csrf-cookie',{headers:{Origin:'app://pos-app',Cookie:cookieHeader()}});captureCookies(csrf);
  const signature=(await csrf.json()).data?.csrf_token;assert.ok(signature&&cookies.has('XSRF-TOKEN'),'Real CSRF pair missing');
  const php=path.join(application,'resources/portable/php/php.exe');
  const root=path.join(application,'resources/app.asar.unpacked');
  let server,starting,base,preparing=false,beforeApplyResponse=null;
  const tasks=new Set();
  async function ensureServer(){
    if(starting)return starting;
    if(server&&server.exitCode===null)return base;
    starting=(async()=>{
      const listener=net.createServer();await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve));const port=listener.address().port;await new Promise(resolve=>listener.close(resolve));
      const current=ports();
      const env={...process.env,POS_TRANSITION_FIXTURE:fixture,APP_DEPLOY_ROOT:root,APP_STORAGE_DIR:storage,APP_ENV:'production',DEPLOYMENT_MODE:'desktop',ENV_PATH:path.join(config,'.env'),DB_HOST:'127.0.0.1',DB_PORT:String(current.mysqlPort),DB_NAME:'pos_db',DB_USER:credentials.user,DB_PASS:credentials.password,API_PORT:String(current.apiPort),LOGS_PATH:path.join(storage,'logs')};
      for(const key of ['GH_TOKEN','GITHUB_TOKEN','UPDATE_PRIVATE_KEY'])delete env[key];
      const log=fs.openSync(path.join(evidence,'fixture-http.log'),'a');
      server=spawn(php,[...getPhpRuntimeArgs(php,path.join(storage,'temp')),'-S','127.0.0.1:'+port,path.resolve('scripts/fixtures/channel-transition-router.php')],{cwd:root,env,windowsHide:true,stdio:['ignore',log,log]});fs.closeSync(log);
      base='http://127.0.0.1:'+port;
      for(let i=0;i<100;i++){if(server.exitCode!==null)throw Error('Fixture HTTP process exited');try{const r=await fetch(base+'/api/health');if(r.ok)return base;}catch{}await delay(100);}
      throw Error('Actual PHAR fixture HTTP router did not become ready');
    })();
    try{return await starting;}finally{starting=null;}
  }
  async function stopServer(){
    const owned=server;server=null;
    if(owned&&owned.exitCode===null){owned.kill();await new Promise(resolve=>owned.once('exit',resolve));}
  }
  async function handle(params){
    const {requestId,request}=params;
    // Preserve the real runtime's preflight response; OPTIONS never prepares a handoff.
    if(request.method==='OPTIONS'){await ui.call('Fetch.continueRequest',{requestId});return;}
    const url=new URL(request.url),mutation=request.method==='POST'&&/\/(?:apply|rollback)$/.test(url.pathname);
    if(preparing){await ui.call('Fetch.continueRequest',{requestId});return;}
    if(mutation){preparing=true;await Promise.allSettled([...tasks]);}
    try{
      const destination=await ensureServer();
      const headers=new Headers(request.headers);headers.set('origin','app://pos-app');headers.set('cookie',cookieHeader());headers.set('x-xsrf-token',signature);headers.set('accept-encoding','identity');
      for(const name of ['host','content-length','connection'])headers.delete(name);
      const response=await fetch(destination+url.pathname+url.search,{method:request.method,headers,...(request.postData?{body:request.postData}:{})});
      const body=Buffer.from(await response.arrayBuffer());
      const responseHeaders=[];for(const [name,value] of response.headers)if(!['content-length','content-encoding','transfer-encoding','set-cookie'].includes(name.toLowerCase()))responseHeaders.push({name,value});
      for(const value of response.headers.getSetCookie())responseHeaders.push({name:'set-cookie',value});
      if(mutation){
        // End the actual request PHP parent before Electron stops its own runtime.
        await stopServer();
        const prepared=JSON.parse(body.toString());
        if(response.status===200&&beforeApplyResponse){const hook=beforeApplyResponse;beforeApplyResponse=null;await hook(prepared);}
      }
      await ui.call('Fetch.fulfillRequest',{requestId,responseCode:response.status,responseHeaders,body:body.toString('base64')});
      if(mutation&&response.status!==200)preparing=false;
    }catch(error){
      preparing=false;
      await ui.call('Fetch.fulfillRequest',{requestId,responseCode:503,responseHeaders:[{name:'content-type',value:'application/json'},{name:'access-control-allow-origin',value:'app://pos-app'},{name:'access-control-allow-credentials',value:'true'}],body:Buffer.from(JSON.stringify({status:'error',message:'Isolated HTTP transport failed.'})).toString('base64')}).catch(()=>{});
      throw error;
    }
  }
  let failure;
  const unsubscribe=ui.onEvent((method,params)=>{
    if(method!=='Fetch.requestPaused')return;
    const task=handle(params).catch(error=>{failure=error;console.error('Isolated update request failed: '+error.message);}).finally(()=>tasks.delete(task));tasks.add(task);
  });
  await ui.call('Fetch.enable',{patterns:[{urlPattern:'*/api/*/updates/*',requestStage:'Request'},{urlPattern:'*/api/updates/*',requestStage:'Request'}]});
  return {
    resume(){preparing=false;if(failure)throw failure;},
    beforeApply(callback){assert.equal(beforeApplyResponse,null);beforeApplyResponse=callback;},
    async close(){unsubscribe();await ui.call('Fetch.disable').catch(()=>{});await Promise.allSettled([...tasks]);await stopServer();if(failure)throw failure;},
  };
}
