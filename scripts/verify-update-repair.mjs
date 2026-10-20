import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
const source=path.resolve('.'),php=process.env.PHP_BINARY||'php';
const installed=process.env.POS_BASELINE_PHAR||path.join(source,'backend/backend.phar');
const lab=fs.mkdtempSync(path.join(os.tmpdir(),'pos-repair-test-'));
const {privateKey,publicKey}=crypto.generateKeyPairSync('rsa',{modulusLength:2048});
const write=(f,data)=>{fs.mkdirSync(path.dirname(f),{recursive:true});fs.writeFileSync(f,data);};
const hash=f=>crypto.createHash('sha256').update(fs.readFileSync(f)).digest('hex');
const tool=path.join(lab,'tool');
write(path.join(tool,'repair.php'),fs.readFileSync(path.join(source,'scripts/update-repair/repair.php')));
write(path.join(tool,'UpdateAssetDownloader.php'),fs.readFileSync(path.join(source,'backend/Services/UpdateAssetDownloader.php')));
write(path.join(tool,'update_public_key.pem'),publicKey.export({type:'spki',format:'pem'}));
const zipWriter=path.join(lab,'zip.php');
write(zipWriter,`<?php $zip=new ZipArchive();$zip->open($argv[1],ZipArchive::CREATE|ZipArchive::OVERWRITE);$zip->addFromString('version.json',$argv[2]);$zip->addFromString('frontend/dist/changed.js','new payload');$zip->close();`);
for(const baseline of ['0.0.1','0.0.10-beta.1']){
 const directory=path.join(lab,baseline),root=path.join(directory,'root'),work=path.join(directory,'work'),assets=path.join(directory,'assets');fs.mkdirSync(work,{recursive:true});fs.mkdirSync(assets,{recursive:true});
 write(path.join(root,'backend/backend.phar'),fs.readFileSync(installed));
 write(path.join(root,'version.json'),JSON.stringify({version:baseline}));
 write(path.join(root,'frontend/dist/changed.js'),'old payload');
 write(path.join(root,'frontend/dist/keep.js'),'unchanged');
 write(path.join(root,'backend/storage/customer.db'),'customer sentinel');
 const protectedFiles=['frontend/dist/keep.js','backend/storage/customer.db'].map(f=>path.join(root,f));
 protectedFiles.forEach(f=>fs.utimesSync(f,new Date('2020-01-01'),new Date('2020-01-01')));
 const before=protectedFiles.map(f=>({hash:hash(f),mtime:fs.statSync(f).mtimeMs,ino:fs.statSync(f).ino}));
 const versionBytes=JSON.stringify({version:'0.0.10-beta.2',channel:'beta'});
 const entries=[['version.json',versionBytes],['frontend/dist/changed.js','new payload']];
 const manifest={manifest_version:'1.0',version:'0.0.10-beta.2',from_version:baseline,minimum_version:'0.0.1',type:'delta',channel:'beta',migrations:[],
 baseline_files:Object.fromEntries(['version.json','frontend/dist/changed.js'].map(f=>[f,hash(path.join(root,f))])),
 files:entries.map(([p,b])=>({path:p,sha256:crypto.createHash('sha256').update(b).digest('hex'),size:Buffer.byteLength(b),action:'replace'})),deleted_files:[]};
 const manifestPath=path.join(assets,`manifest-${baseline}.json`),signaturePath=path.join(assets,`manifest-${baseline}.sig`);
 function sign(m){const bytes=JSON.stringify(m);write(manifestPath,bytes);write(signaturePath,crypto.sign('RSA-SHA256',Buffer.from(bytes),privateKey).toString('base64'));}
 sign(manifest);
 const zipped=spawnSync(php,[zipWriter,path.join(assets,`delta-${baseline}-to-0.0.10-beta.2.zip`),versionBytes],{encoding:'utf8',windowsHide:true});assert.equal(zipped.status,0,zipped.stderr);
 function run(mode){return spawnSync(php,[path.join(tool,'repair.php'),mode,root,work,assets],{encoding:'utf8',windowsHide:true});}
 function reject(pattern){const result=run('prepare');assert.notEqual(result.status,0,result.stdout);assert.match(result.stderr,pattern);assert.equal(fs.readFileSync(path.join(root,'frontend/dist/changed.js'),'utf8'),'old payload');}
 write(signaturePath,'invalid');reject(/RSA manifest signature rejected/);sign(manifest);
 sign({...manifest,migrations:['bad.sql']});reject(/without migrations/);sign(manifest);
 sign({...manifest,from_version:'9.9.9'});reject(/Wrong repair target or baseline/);sign(manifest);
 write(path.join(root,'frontend/dist/changed.js'),'modified baseline');let result=run('prepare');assert.notEqual(result.status,0);assert.match(result.stderr,/Installed files differ/);write(path.join(root,'frontend/dist/changed.js'),'old payload');
 result=run('prepare');assert.equal(result.status,0,result.stderr);
 // Corrupt staging after verification: apply must recheck before snapshots/writes.
 const staged=path.join(work,'state/update-staging/0.0.10-beta.2/frontend/dist/changed.js');
 // Resolve actual staging convention from the prepared directory rather than assuming.
 const find=(dir,name)=>fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?find(path.join(dir,e.name),name):e.name===name?[path.join(dir,e.name)]:[]);
 const payload=find(path.join(work,'state'),'changed.js')[0];assert.ok(payload);
 write(payload,'tampered');result=run('apply');assert.notEqual(result.status,0);assert.match(result.stderr,/hash mismatch/);assert.equal(fs.readFileSync(path.join(root,'frontend/dist/changed.js'),'utf8'),'old payload');write(payload,'new payload');
 result=run('apply');assert.equal(result.status,0,result.stderr);
 assert.equal(JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version,'0.0.10-beta.2');assert.equal(fs.readFileSync(path.join(root,'frontend/dist/changed.js'),'utf8'),'new payload');
 protectedFiles.forEach((f,i)=>assert.deepEqual({hash:hash(f),mtime:fs.statSync(f).mtimeMs,ino:fs.statSync(f).ino},before[i]));
 console.log(`PASS repair ${baseline}: signature/migrations/baseline/tampered-stage rejected; successful apply preserves unchanged file identity and customer data`);
}
