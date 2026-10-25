import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createRequire} from 'node:module';
import {delay} from './desktop-transition-lab.mjs';

const isInterceptionDropped=error=>/Invalid InterceptionId|WebSocket is not open|Fetch domain is not enabled/i.test(error?.message||'');

/** UI request transport only. Runs the actual deployed PHAR, auth and CSRF. */
export async function attachTransitionTransport({ui,application,storage,evidence,fixture,auth}) {
  assert.equal(process.env.GITHUB_ACTIONS,'true');
  const require=createRequire(import.meta.url);
  const {getPhpRuntimeArgs}=require('../electron/utils/php-runtime.js');
  const config=path.join(process.env.APPDATA,'POS System');
  const ports=()=>JSON.parse(fs.readFileSync(path.join(config,'runtime_ports.json')));
  const credentials=JSON.parse(fs.readFileSync(path.join(config,'db_credentials.json')));
  let realBase=ports().apiBaseUrl;
  const cookies=new Map();
  const captureCookies=response=>{for(const cookie of response.headers.getSetCookie()){const pair=cookie.split(';',1)[0],split=pair.indexOf('=');if(split>0)cookies.set(pair.slice(0,split),pair.slice(split+1));}};
  const cookieHeader=()=>[...cookies].map(([name,value])=>`${name}=${value}`).join('; ');
  let signature;

  async function reauth(){
    realBase=ports().apiBaseUrl;
    const login=await fetch(realBase+'/api/v1/login',{method:'POST',headers:{Origin:'app://pos-app','Content-Type':'application/json'},body:JSON.stringify(auth)});
    assert.equal(login.status,200,'Real fixture account login for HTTP transport failed');captureCookies(login);
    assert.ok(cookies.has('pos_token'),'Production login did not issue its session cookie');
    const csrf=await fetch(realBase+'/api/v1/csrf-cookie',{headers:{Origin:'app://pos-app',Cookie:cookieHeader()}});captureCookies(csrf);
    signature=(await csrf.json()).data?.csrf_token;assert.ok(signature&&cookies.has('XSRF-TOKEN'),'Real CSRF pair missing');
  }
  await reauth();
  const php=path.join(application,'resources/portable/php/php.exe');
  const root=path.join(application,'resources/app.asar.unpacked');
  let server,starting,base,preparing=false,beforeApplyResponse=null;
  let repairContext=null,repairServer=null,repairStarting=null,repairBase=null;
  let jobObservationHook=null;
  let handoffWinner=false,handoffStopPromise=null;
  const tasks=new Set(),httpInflight=new Set();

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
    const owned=server;server=null;base=null;
    if(owned&&owned.exitCode===null){owned.kill();await new Promise(resolve=>owned.once('exit',resolve));}
  }

  async function ensureRepairServer(){
    if(!repairContext)throw Error('Repair context not set when starting repair server');
    if(repairStarting)return repairStarting;
    if(repairServer&&repairServer.exitCode===null)return repairBase;
    repairStarting=(async()=>{
      const listener=net.createServer();await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve));const port=listener.address().port;await new Promise(resolve=>listener.close(resolve));
      const current=ports();
      const env={
        ...process.env,
        POS_REPAIR_BOOTSTRAP:'1',
        POS_REPAIR_CANDIDATE_PHAR:repairContext.candidate_phar_path,
        POS_REPAIR_EXPECTED_HASH:repairContext.candidate_backend_sha256,
        POS_REPAIR_BOUND_JOB_ID:String(repairContext.target_job_id),
        POS_TRANSITION_FIXTURE:fixture,
        APP_DEPLOY_ROOT:root,
        APP_STORAGE_DIR:storage,
        APP_ENV:'production',
        DEPLOYMENT_MODE:'desktop',
        ENV_PATH:path.join(config,'.env'),
        DB_HOST:'127.0.0.1',
        DB_PORT:String(current.mysqlPort),
        DB_NAME:'pos_db',
        DB_USER:credentials.user,
        DB_PASS:credentials.password,
        API_PORT:String(current.apiPort),
        LOGS_PATH:path.join(storage,'logs')
      };
      for(const key of ['GH_TOKEN','GITHUB_TOKEN','UPDATE_PRIVATE_KEY'])delete env[key];
      const log=fs.openSync(path.join(evidence,'repair-http.log'),'a');
      repairServer=spawn(php,[...getPhpRuntimeArgs(php,path.join(storage,'temp')),'-d','auto_prepend_file=','-S','127.0.0.1:'+port,path.resolve('scripts/fixtures/channel-transition-router.php')],{cwd:root,env,windowsHide:true,stdio:['ignore',log,log]});fs.closeSync(log);
      repairBase='http://127.0.0.1:'+port;
      for(let i=0;i<100;i++){
        if(repairServer.exitCode!==null)throw Error('Repair HTTP process exited');
        try{const r=await fetch(repairBase+'/api/health');if(r.ok)return repairBase;}catch{}
        await delay(100);
      }
      throw Error('Actual candidate PHAR repair HTTP router did not become ready');
    })();
    try{return await repairStarting;}finally{repairStarting=null;}
  }

  async function stopRepairServer(){
    const owned=repairServer;repairServer=null;repairBase=null;
    if(owned&&owned.exitCode===null){owned.kill();await new Promise(resolve=>owned.once('exit',resolve));}
  }

  async function handle(params){
    const {requestId,request}=params;
    // Preserve the real runtime's preflight response; OPTIONS never prepares a handoff.
    if(request.method==='OPTIONS'){
      try{await ui.call('Fetch.continueRequest',{requestId});}catch(err){if(!isInterceptionDropped(err))throw err;}
      return;
    }
    const url=new URL(request.url);
    const mutation=request.method==='POST'&&/\/(?:apply|rollback)$/.test(url.pathname);
    const jobMatch=request.method==='GET'&&url.pathname.match(/\/update\/jobs\/(\d+)/);
    const isJobPoll=Boolean(jobMatch);
    const polledJobId=jobMatch?Number(jobMatch[1]):null;
    const isBoundJobRepair=Boolean(
      repairContext&&
      request.method==='GET'&&
      polledJobId!==null&&
      polledJobId===Number(repairContext.target_job_id)
    );

    if(preparing){
      if(handoffStopPromise)await handoffStopPromise;
      try{await ui.call('Fetch.continueRequest',{requestId});}catch(err){if(!isInterceptionDropped(err))throw err;}
      return;
    }
    if(mutation){
      preparing=true;
      if(httpInflight.size>0)await Promise.allSettled([...httpInflight]);
    }
    let response,body,bodyStr;
    let resolveIo;
    const ioPromise=new Promise(resolve=>{resolveIo=resolve;});
    httpInflight.add(ioPromise);
    try{
      try{
        const destination=isBoundJobRepair ? await ensureRepairServer() : await ensureServer();
        const headers=new Headers(request.headers);
        headers.set('origin','app://pos-app');
        headers.set('cookie',cookieHeader());
        headers.set('x-xsrf-token',signature);
        headers.set('accept-encoding','identity');
        for(const name of ['host','content-length','connection'])headers.delete(name);
        response=await fetch(destination+url.pathname+url.search,{
          method:request.method,
          headers,
          ...(request.postData?{body:request.postData}:{})
        });
        body=Buffer.from(await response.arrayBuffer());
        bodyStr=body.toString();
      }finally{
        resolveIo();
        httpInflight.delete(ioPromise);
      }
      fs.appendFileSync(path.join(evidence,'transport-http.log'),JSON.stringify({
        time:new Date().toISOString(),
        method:request.method,
        url:url.pathname+url.search,
        status:response.status,
        backend_source:isBoundJobRepair?'signed_repair_candidate':'frozen-original',
        body:bodyStr.slice(0,1000)
      })+'\n');
      if(url.pathname.endsWith('/transition/preview')){
        let result;try{result=JSON.parse(bodyStr);}catch{}
        fs.appendFileSync(path.join(evidence,'preview-responses.jsonl'),JSON.stringify({status:response.status,channel:result?.data?.selected_channel,result:result?.data?.status,reason:result?.data?.reason_code,error:result?.status==='error'?result?.message:null})+'\n');
      }

      let parsed;
      try{parsed=JSON.parse(bodyStr);}catch{}

      const isQueuedAdmission=mutation&&response.status===200&&parsed?.data&&typeof parsed.data==='object'
        &&('job_id' in parsed.data||parsed.data.status==='pending'||parsed.data.status==='processing')
        &&!parsed.data.requires_desktop_handoff
        &&!parsed.data.result?.requires_desktop_handoff;

      const isJobHandoffReady=isJobPoll&&response.status===200
        &&parsed?.data&&typeof parsed.data==='object'
        &&parsed.data.status==='completed'
        &&(polledJobId===null||Number(parsed.data.id)===polledJobId)
        &&parsed.data.result&&typeof parsed.data.result==='object'
        &&parsed.data.result.ok===true
        &&parsed.data.result.requires_desktop_handoff===true
        &&typeof parsed.data.result.handoff_version==='string'
        &&parsed.data.result.handoff_version.length>0;

      if(isJobPoll){
        fs.appendFileSync(
          path.join(evidence,'transport-http.log'),
          JSON.stringify({
            time:new Date().toISOString(),
            job_trace:{
              id:parsed?.data?.id??polledJobId,
              status:parsed?.data?.status??null,
              backend_source:isBoundJobRepair?'signed_repair_candidate':'frozen-original',
              has_ok:parsed?.data?.result?.ok===true,
              handoff_ready:isJobHandoffReady,
              path:url.pathname,
              status_code:response.status
            }
          })+'\n'
        );
        if(jobObservationHook){
          jobObservationHook({
            jobId:parsed?.data?.id??null,
            polledJobId,
            status:parsed?.data?.status??null,
            backendSource:isBoundJobRepair?'signed_repair_candidate':'frozen-original',
            result:parsed?.data?.result??null,
            responseStatus:response.status,
            raw:parsed
          });
        }
      }else if(isQueuedAdmission){
        fs.appendFileSync(
          path.join(evidence,'transport-http.log'),
          JSON.stringify({
            time:new Date().toISOString(),
            queue_trace:{
              job_id:parsed.data.job_id??null,
              status:parsed.data.status??'pending',
              handoff_ready:false,
              path:url.pathname,
              status_code:response.status
            }
          })+'\n'
        );
      }

      const responseHeaders=[];
      for(const [name,value] of response.headers)if(!['content-length','content-encoding','transfer-encoding','set-cookie'].includes(name.toLowerCase()))responseHeaders.push({name,value});
      for(const value of response.headers.getSetCookie())responseHeaders.push({name:'set-cookie',value});

      const isReadyMutation=mutation&&response.status===200&&!isQueuedAdmission;
      if(mutation){
        if(response.status!==200){
          console.error(`Mutation ${request.method} ${url.pathname} returned HTTP ${response.status}: ${bodyStr}`);
          preparing=false;
        }else if(isQueuedAdmission){
          preparing=false;
        }
      }

      if(isReadyMutation||isJobHandoffReady){
        preparing=true;
        if(!handoffWinner){
          handoffWinner=true;
          handoffStopPromise=(async()=>{
            if(httpInflight.size>0)await Promise.allSettled([...httpInflight]);
            await stopServer();
            await stopRepairServer();
            if(beforeApplyResponse){
              const hook=beforeApplyResponse;
              beforeApplyResponse=null;
              const preparedData=isJobPoll?{
                ...(parsed?.data?.result||{}),
                ...parsed?.data,
                requires_desktop_handoff:parsed?.data?.result?.requires_desktop_handoff,
                handoff_version:parsed?.data?.result?.handoff_version
              }:parsed;
              await hook(isJobPoll?{...parsed,data:preparedData}:preparedData);
            }
          })();
        }
        await handoffStopPromise;
      }

      try{
        await ui.call('Fetch.fulfillRequest',{requestId,responseCode:response.status,responseHeaders,body:body.toString('base64')});
      }catch(err){
        if(!isInterceptionDropped(err))throw err;
      }
    }catch(error){
      preparing=false;
      if(isInterceptionDropped(error))return;
      await ui.call('Fetch.fulfillRequest',{requestId,responseCode:503,responseHeaders:[{name:'content-type',value:'application/json'},{name:'access-control-allow-origin',value:'app://pos-app'},{name:'access-control-allow-credentials',value:'true'}],body:Buffer.from(JSON.stringify({status:'error',message:'Isolated HTTP transport failed.'})).toString('base64')}).catch(()=>{});
      throw error;
    }
  }
  let failure;
  const unsubscribe=ui.onEvent((method,params)=>{
    if(method!=='Fetch.requestPaused')return;
    const task=handle(params).catch(error=>{
      if(isInterceptionDropped(error))return;
      failure=error;
      console.error('Isolated update request failed: '+error.message);
    }).finally(()=>tasks.delete(task));
    tasks.add(task);
  });
  await ui.call('Fetch.enable',{patterns:[
    {urlPattern:'*/api/*/updates/*',requestStage:'Request'},
    {urlPattern:'*/api/updates/*',requestStage:'Request'},
    {urlPattern:'*/api/*/update/jobs/*',requestStage:'Request'},
    {urlPattern:'*/api/update/jobs/*',requestStage:'Request'}
  ]});
  return {
    reauth,
    resume(){
      preparing=false;
      handoffWinner=false;
      handoffStopPromise=null;
      httpInflight.clear();
      if(failure)throw failure;
    },
    beforeApply(callback){assert.equal(beforeApplyResponse,null);beforeApplyResponse=callback;},
    onJobObservation(callback){jobObservationHook=callback;},
    async readJobStatus(id){
      assert.equal(repairContext,null,'readJobStatus requires repairContext to be null (installed production only)');
      const expectedId=Number(id);
      assert.ok(expectedId>0,'Invalid jobId for readJobStatus');
      const destination=await ensureServer();
      const headers=new Headers();
      headers.set('origin','app://pos-app');
      headers.set('cookie',cookieHeader());
      if(signature)headers.set('x-xsrf-token',signature);
      headers.set('accept-encoding','identity');
      const response=await fetch(`${destination}/api/v1/update/jobs/${expectedId}`,{
        method:'GET',
        headers
      });
      assert.equal(response.status,200,`Expected status 200 reading job ${expectedId}, got ${response.status}`);
      const rawText=await response.text();
      const parsed=JSON.parse(rawText);
      const data=parsed?.data??parsed;
      assert.equal(Number(data?.id),expectedId,'Returned job id must match requested id');
      assert.equal(data?.status,'completed','Job status must be completed');
      assert.ok(data?.result&&typeof data.result==='object','Job result must be an object');
      assert.equal(data.result.ok,true,'Job result.ok must be true');
      assert.equal(data.result.desktop_handoff_completed,true,'desktop_handoff_completed must be true');
      assert.equal(data.result.requires_desktop_handoff,false,'requires_desktop_handoff must be false');
      fs.appendFileSync(
        path.join(evidence,'transport-http.log'),
        JSON.stringify({
          time:new Date().toISOString(),
          job_trace:{
            id:data?.id??expectedId,
            status:data?.status??null,
            backend_source:'installed_application',
            has_ok:data?.result?.ok===true,
            desktop_handoff_completed:data?.result?.desktop_handoff_completed===true,
            requires_desktop_handoff:data.result.requires_desktop_handoff,
            path:`/api/v1/update/jobs/${expectedId}`,
            status_code:response.status
          }
        })+'\n'
      );
      return {
        ...parsed,
        backendSource:'installed_application'
      };
    },
    async enableRepairBootstrap(verifiedContext){
      assert.ok(verifiedContext&&typeof verifiedContext==='object','verifiedContext is required');
      assert.ok(verifiedContext.candidate_phar_path,'verified candidate_phar_path required');
      assert.ok(verifiedContext.candidate_backend_sha256,'verified candidate_backend_sha256 required');
      assert.ok(verifiedContext.target_job_id,'verified target_job_id required');
      repairContext=verifiedContext;
      await ensureRepairServer();
    },
    async disableRepairBootstrap(){
      repairContext=null;
      await stopRepairServer();
    },
    async close(){
      unsubscribe();
      await Promise.allSettled([...tasks]);
      await ui.call('Fetch.disable').catch(()=>{});
      await stopServer();
      await stopRepairServer();
      if(failure)throw failure;
    },
  };
}
