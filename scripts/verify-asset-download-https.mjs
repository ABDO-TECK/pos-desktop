import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import https from 'node:https';
import assert from 'node:assert/strict';
import {spawn, execFileSync} from 'node:child_process';
const lab=fs.mkdtempSync(path.join(os.tmpdir(),'pos-download-https-'));
const root=path.resolve('.'), php=process.env.PHP_BINARY||'php';
const openssl=process.env.OPENSSL_BINARY||(process.platform==='win32'?'C:/Program Files/Git/usr/bin/openssl.exe':'openssl');
fs.writeFileSync(path.join(lab,'openssl.cnf'),'');
const cert=path.join(lab,'cert.pem'),key=path.join(lab,'key.pem');
execFileSync(openssl,['req','-x509','-newkey','rsa:2048','-nodes','-keyout',key,'-out',cert,'-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1'],{windowsHide:true,stdio:'ignore',env:{...process.env,OPENSSL_CONF:path.join(lab,'openssl.cnf')}});
fs.writeFileSync(path.join(lab,'download.php'),`<?php
require $argv[1].'/backend/vendor/autoload.php';
$state=dirname($argv[3]).'/state-'.basename($argv[3]);
putenv('APP_STORAGE_DIR='.$state);
$lock=new App\\Services\\UpdateOperationLock($state);
$lease=$lock->acquire('download-test');
if (!$lease['acquired']) throw new RuntimeException('Cannot acquire fixture lease');
$delta=new App\\Services\\DeltaUpdateService(null,dirname($argv[3]),$state);
$delta->setUpdateState('downloading',['owner_id'=>$lease['owner_id']]);
$calls=0;
$result=(new App\\Services\\UpdateAssetDownloader($argv[4], '', 90, 3, 0))->download($argv[2],$argv[3],function()use(&$calls,$delta){$calls++;$delta->setUpdateState('downloading');});
$owner=json_decode(file_get_contents($state.'/update-operation.lock'),true);
$age=time()-strtotime($owner['updated_at']);
$contender=new App\\Services\\UpdateOperationLock($state);
$blocked=!$contender->acquire('concurrent-update')['acquired'];
$lock->release();
echo json_encode(['result'=>$result,'heartbeats'=>$calls,'leaseAge'=>$age,'concurrentBlocked'=>$blocked]);
`);
let interrupted=0, failed=0;
const payload=Buffer.alloc(32*1024,7);
const server=https.createServer({key:fs.readFileSync(key),cert:fs.readFileSync(cert)},(req,res)=>{
  if(req.url==='/slow'){
    res.writeHead(200,{'Content-Length':66*2048});let chunks=0;
    const timer=setInterval(()=>{res.write(Buffer.alloc(2048,9));if(++chunks===66){clearInterval(timer);res.end();}},1000);
    res.on('close',()=>clearInterval(timer));
  }else if(req.url==='/recover' && interrupted++>0){res.writeHead(200,{'Content-Length':payload.length});res.end(payload);}
  else{if(req.url==='/fail')failed++;res.writeHead(200,{'Content-Length':1024*1024});res.write(payload);setTimeout(()=>res.destroy(),30);}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
function download(route){return new Promise((resolve,reject)=>{
 const destination=path.join(lab,route+'.zip');const child=spawn(php,[path.join(lab,'download.php'),root,`https://127.0.0.1:${server.address().port}/${route}`,destination,cert],{windowsHide:true});let out='',err='';
 child.stdout.on('data',d=>out+=d);child.stderr.on('data',d=>err+=d);child.on('error',reject);child.on('close',code=>{if(code)reject(Error(err+out));else resolve({...JSON.parse(out),destination});});
});}
try{
 const recovered=await download('recover');assert.equal(recovered.result.ok,true);assert.equal(interrupted,2);assert.deepEqual(fs.readFileSync(recovered.destination),payload);
 const failure=await download('fail');assert.equal(failure.result.ok,false);assert.equal(failed,3);assert.match(failure.result.error,/cURL 18|cURL 56/);assert.match(failure.result.error,/HTTP 200/);assert.equal(fs.existsSync(failure.destination),false);
 console.log('PASS real HTTPS interruption: retry recovers exact bytes; three interrupted attempts fail with real cause and no partial file');
 const slow=await download('slow');assert.equal(slow.result.ok,true);assert.equal(fs.statSync(slow.destination).size,66*2048);assert.ok(slow.heartbeats>=6);assert.ok(slow.leaseAge<15);assert.equal(slow.concurrentBlocked,true);
 console.log('PASS real HTTPS slow transfer: 66 seconds exceeds the old 60-second limit; progress heartbeats continue');
}finally{server.closeAllConnections();server.close();}
