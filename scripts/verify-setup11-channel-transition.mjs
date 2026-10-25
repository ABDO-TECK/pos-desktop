import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createDesktopLab,delay} from './desktop-transition-lab.mjs';
import {attachTransitionTransport} from './channel-transition-ui-transport.mjs';

const [phase,appArg,evidenceArg,assetsArg]=process.argv.slice(2);
const application=path.resolve(appArg),evidence=path.resolve(evidenceArg),assets=path.resolve(assetsArg);
const lab=path.dirname(application),root=path.join(application,'resources/app.asar.unpacked');
const storage=path.join(process.env.PROGRAMDATA,'POS System');
const php=process.env.PHP_BINARY||'php';
const fixture=path.resolve('scripts/fixtures/channel-transition-capability.php');
const sql=mode=>JSON.parse(execFileSync(php,[fixture,mode],{encoding:'utf8',windowsHide:true}));

const setup11InventoryPath=path.join(lab,'setup11-inventory.json');
const candidateInventoryPath=path.join(lab,'candidate-inventory.json');
const beta10InventoryPath=path.join(lab,'beta10-inventory.json');

const setup11Inventory=JSON.parse(fs.readFileSync(setup11InventoryPath,'utf8'));
assert.equal(setup11Inventory.version,'0.0.11','Setup 11 baseline version check');

// The 8 canonical POS data targets managed across Windows profiles (matching Get-PosCleanupTargets)
const posTargets=[
  path.join(process.env.PROGRAMDATA||'C:\\ProgramData','POS System'),
  path.join(process.env.APPDATA||'','POS System'),
  path.join(process.env.APPDATA||'','POS Desktop'),
  path.join(process.env.APPDATA||'','pos-desktop'),
  path.join(process.env.LOCALAPPDATA||'','POS System'),
  path.join(process.env.LOCALAPPDATA||'','POS Desktop'),
  path.join(process.env.LOCALAPPDATA||'','pos-desktop'),
  path.join(process.env.LOCALAPPDATA||'','pos-desktop-updater'),
].map(p=>path.resolve(p));

function fileSha256(file){
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

function sanitizeReason(str){
  if(typeof str!=='string')return '';
  return str
    .replace(/(["']?(?:password|token|secret|key|private_key|auth|api_key)["']?\s*[:=]\s*["'])([^"'\n]+)(["'])/gi,'$1[REDACTED]$3')
    .replace(/-----BEGIN (?:RSA )?PRIVATE KEY-----[\s\S]+?-----END (?:RSA )?PRIVATE KEY-----/g,'[REDACTED PRIVATE KEY]')
    .replace(/bearer\s+[A-Za-z0-9_\-\.]+/gi,'Bearer [REDACTED]')
    .replace(/p(?:assword)?=[^;&\s]+/gi,'password=[REDACTED]');
}

function getPortableBinariesHashes(appDir,desktopLabUi){
  const hasher=desktopLabUi?.hash?desktopLabUi.hash.bind(desktopLabUi):fileSha256;
  const portableDir=path.join(appDir,'resources','portable');
  const hashes={};
  function scan(dir){
    if(!fs.existsSync(dir))return;
    for(const item of fs.readdirSync(dir,{withFileTypes:true})){
      const full=path.join(dir,item.name);
      if(item.isDirectory()){
        scan(full);
      }else if(item.isFile()){
        const rel=path.relative(portableDir,full).replace(/\\/g,'/');
        const ext=path.extname(item.name).toLowerCase();
        if(ext==='.exe'||ext==='.dll'||ext==='.jar'){
          hashes[rel]=hasher(full);
        }
      }
    }
  }
  scan(portableDir);
  // Also scan resources root for Java / QZ runtime jar if bundled
  const resourcesDir=path.join(appDir,'resources');
  if(fs.existsSync(resourcesDir)){
    for(const item of fs.readdirSync(resourcesDir,{withFileTypes:true})){
      if(item.isFile()&&item.name.toLowerCase().endsWith('.jar')){
        hashes['resources/'+item.name]=hasher(path.join(resourcesDir,item.name));
      }
    }
  }
  assert.ok(Object.keys(hashes).length>0,'Portable binaries list (.exe, .dll, .jar) must be non-zero');
  return hashes;
}

async function getSystemProtectedState(desktopLabUi){
  // 1. Mandatory credentials: look across the 8 POS targets
  const credFiles=[];
  for(const target of posTargets){
    const p=path.join(target,'db_credentials.json');
    if(fs.existsSync(p)&&fs.statSync(p).isFile()&&fs.statSync(p).size>0){
      credFiles.push(p);
    }
  }
  assert.ok(credFiles.length>0,'Mandatory credentials file missing or empty in POS data targets');

  // 2. Environment files (.env): look across the 8 POS targets
  const envFiles=[];
  for(const target of posTargets){
    const p=path.join(target,'.env');
    if(fs.existsSync(p)&&fs.statSync(p).isFile()&&fs.statSync(p).size>0){
      envFiles.push(p);
    }
  }

  // 3. Mandatory runtime QZ certificate pair (private-key.pem and digital-certificate.pem)
  let foundQzDir=null;
  for(const target of posTargets){
    const dir=path.join(target,'qz-certs');
    const key=path.join(dir,'private-key.pem');
    const cert=path.join(dir,'digital-certificate.pem');
    if(fs.existsSync(key)&&fs.existsSync(cert)&&fs.statSync(key).size>0&&fs.statSync(cert).size>0){
      foundQzDir=dir;
      break;
    }
  }

  // If not initialized, trigger real production IPC / UI printing initialization
  if(!foundQzDir){
    console.log('QZ cert pair not yet on disk; triggering real production printing IPC initialization...');
    await desktopLabUi.evaluate('window.pos?.getQZCert ? window.pos.getQZCert() : null').catch(()=>null);
    for(let i=0;i<20&&!foundQzDir;i++){
      await delay(500);
      for(const target of posTargets){
        const dir=path.join(target,'qz-certs');
        const key=path.join(dir,'private-key.pem');
        const cert=path.join(dir,'digital-certificate.pem');
        if(fs.existsSync(key)&&fs.existsSync(cert)&&fs.statSync(key).size>0&&fs.statSync(cert).size>0){
          foundQzDir=dir;
          break;
        }
      }
    }
  }
  assert.ok(foundQzDir,'Mandatory runtime QZ private-key.pem and digital-certificate.pem pair missing after printing initialization');
  const qzKeyPath=path.join(foundQzDir,'private-key.pem');
  const qzCertPath=path.join(foundQzDir,'digital-certificate.pem');

  // Verify ssl-installed.flag when architecture present
  const qzJar=path.join(application,'resources','portable','qz-tray','qz-tray.jar');
  const javaBin=path.join(application,'resources','portable','java','bin','java.exe');
  const architecturePresent=fs.existsSync(qzJar)&&fs.existsSync(javaBin);
  if(architecturePresent){
    const flagPath=path.join(foundQzDir,'ssl-installed.flag');
    assert.ok(fs.existsSync(flagPath),'Mandatory ssl-installed.flag missing when architecture present: '+flagPath);
    const flagContent=fs.readFileSync(flagPath,'utf8').trim();
    assert.equal(flagContent,'installed',`ssl-installed.flag must be installed, got: '${flagContent}'`);
  }

  // Discover all device key/cert files across the 8 targets (matching verify-original-setup-upgrade.ps1:276-290)
  const deviceKeyMap={
    [qzKeyPath]:desktopLabUi.hash(qzKeyPath),
    [qzCertPath]:desktopLabUi.hash(qzCertPath)
  };
  for(const target of posTargets){
    if(!fs.existsSync(target))continue;
    function scanKeys(dir){
      for(const item of fs.readdirSync(dir,{withFileTypes:true})){
        const full=path.join(dir,item.name);
        if(item.isDirectory()){
          scanKeys(full);
        }else if(item.isFile()){
          const ext=path.extname(item.name).toLowerCase();
          if(ext==='.key'||ext==='.crt'||ext==='.pem'){
            const resolved=path.resolve(full);
            if(!deviceKeyMap[resolved]&&fs.statSync(resolved).size>0){
              deviceKeyMap[resolved]=desktopLabUi.hash(resolved);
            }
          }
        }
      }
    }
    scanKeys(target);
  }
  assert.ok(Object.keys(deviceKeyMap).length>=2,'At least 2 device key/certificate files must be discovered');

  // 4. Snapshot printing ownership receipt (discovered across the 8 POS targets)
  // Matching verify-original-setup-upgrade.ps1:208-238: REQUIRES exactly ONE ownership receipt, qz root files nonzero, Enterprise trust exists
  const receipts=[];
  for(const target of posTargets){
    const p=path.join(target,'qz-shared-install.json');
    if(fs.existsSync(p)&&fs.statSync(p).isFile()){
      assert.ok(fs.statSync(p).size>0,'QZ ownership receipt file is empty: '+p);
      receipts.push(p);
    }
  }
  assert.equal(receipts.length,1,`Original baseline did not record ownership of QZ printing trust in POS data targets (found ${receipts.length})`);
  const receiptPath=path.resolve(receipts[0]);
  const receiptDigest=desktopLabUi.hash(receiptPath);
  const ownership=JSON.parse(fs.readFileSync(receiptPath,'utf8'));

  assert.ok(typeof ownership.rootCertificateBase64==='string'&&ownership.rootCertificateBase64.length>0,'Ownership receipt missing rootCertificateBase64');
  const certDer=Buffer.from(ownership.rootCertificateBase64,'base64');
  assert.ok(certDer.length>0,'Root certificate base64 decoded to zero bytes');
  const printingThumbprint=crypto.createHash('sha1').update(certDer).digest('hex').toUpperCase();
  assert.equal(printingThumbprint.length,40,'Computed certificate thumbprint must be 40 hex characters');

  // Verify printing root certificate is installed in Enterprise store; subprocess errors MUST fail (not warn/catch)
  const enterpriseKeyPath=`Registry::HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\EnterpriseCertificates\\Root\\Certificates\\${printingThumbprint}`;
  const enterpriseCheck=execFileSync('powershell.exe',[
    '-NoProfile','-NonInteractive','-Command',
    `if (Test-Path -LiteralPath '${enterpriseKeyPath}') { 'True' } else { 'False' }`
  ],{encoding:'utf8',windowsHide:true}).trim();
  assert.equal(enterpriseCheck,'True',`Printing root certificate was not installed in Enterprise trust store: ${enterpriseKeyPath}`);

  // ownership.files must be non-empty array, each valid normalized REL path under PROGRAMDATA/qz (no absolute, traversal, reparses)
  assert.ok(Array.isArray(ownership.files)&&ownership.files.length>0,'Ownership receipt must declare non-empty files array');
  const qzRoot=path.resolve(process.env.PROGRAMDATA||'C:\\ProgramData','qz');
  const qzTrustFiles={};
  for(const file of ownership.files){
    assert.ok(file&&typeof file.path==='string'&&file.path.length>0,'Invalid owned file entry in ownership receipt');
    assert.ok(!path.isAbsolute(file.path),'Owned file path must not be absolute: '+file.path);
    const normalized=path.normalize(file.path).replace(/^[/\\]+/,'');
    assert.ok(!normalized.startsWith('..')&&!path.isAbsolute(normalized),'Owned file path traversal detected: '+file.path);
    const absPath=path.resolve(qzRoot,normalized);
    assert.ok(absPath.startsWith(qzRoot+path.sep)||absPath===qzRoot,'Owned file path escapes PROGRAMDATA/qz: '+file.path);
    assert.ok(fs.existsSync(absPath)&&fs.statSync(absPath).isFile(),'Owned printing file missing from disk: '+absPath);
    assert.ok(fs.statSync(absPath).size>0,'Owned printing file must be non-empty: '+absPath);
    qzTrustFiles[normalized]=desktopLabUi.hash(absPath).toLowerCase();
  }

  // 5. Optional LAN SSL keypair capture: look across the 8 POS targets
  let lanSslEnabled=false;
  const sslFiles=[];
  for(const target of posTargets){
    const key=path.join(target,'ssl','server.key');
    const cert=path.join(target,'ssl','server.crt');
    if(fs.existsSync(key)&&fs.existsSync(cert)&&fs.statSync(key).size>0&&fs.statSync(cert).size>0){
      lanSslEnabled=true;
      sslFiles.push(key,cert);
      break;
    }
  }

  // Keep all secret hashes in-memory only (never exported to evidence or logs)
  const secrets={
    credentials:Object.fromEntries(credFiles.map(f=>[f,desktopLabUi.hash(f)])),
    env:Object.fromEntries(envFiles.map(f=>[f,desktopLabUi.hash(f)])),
    deviceKeys:deviceKeyMap,
    qzReceipt:{
      [receiptPath]:receiptDigest
    },
    qzTrust:qzTrustFiles,
    sslCerts:Object.fromEntries(sslFiles.map(f=>[f,desktopLabUi.hash(f)]))
  };

  const publicData={
    portableHashes:getPortableBinariesHashes(application,desktopLabUi),
    lanSslEnabled,
    printingThumbprint,
    printingTrustInstalled:true,
    ownedPrintingFilesCount:Object.keys(qzTrustFiles).length,
    deviceKeyFilesCount:Object.keys(deviceKeyMap).length,
    envFilesCount:envFiles.length
  };

  return {secrets,public:publicData};
}

function assertProtectedStatePreserved(initial,current,stageName){
  // Compare secret state strictly in-memory using boolean comparisons
  // DO NOT use assert.deepEqual on secrets to prevent Node from printing secret hashes in failure traces
  for(const [category,initialFiles] of Object.entries(initial.secrets)){
    const currentFiles=current.secrets[category]||{};
    const initKeys=Object.keys(initialFiles);
    const currKeys=Object.keys(currentFiles);
    if(initKeys.length!==currKeys.length){
      throw new Error(`Protected file count mismatch in category '${category}' during ${stageName}`);
    }
    for(const [filePath,expectedHash] of Object.entries(initialFiles)){
      const currentHash=currentFiles[filePath];
      if(typeof currentHash!=='string'||currentHash!==expectedHash){
        const baseName=path.basename(filePath);
        throw new Error(`Protected file modified in category '${category}': ${baseName} during ${stageName}`);
      }
    }
  }

  // Public portable binary hashes: standard assertion
  assert.deepEqual(current.public.portableHashes,initial.public.portableHashes,`Portable binaries modified during ${stageName}`);
  assert.equal(current.public.lanSslEnabled,initial.public.lanSslEnabled,`LAN SSL status changed during ${stageName}`);
  assert.equal(current.public.printingThumbprint,initial.public.printingThumbprint,`Printing thumbprint changed during ${stageName}`);
  assert.equal(current.public.printingTrustInstalled,true,`Printing trust not installed during ${stageName}`);
  assert.equal(current.public.printingTrustInstalled,initial.public.printingTrustInstalled,`Printing trust installed flag changed during ${stageName}`);
  assert.equal(current.public.ownedPrintingFilesCount,initial.public.ownedPrintingFilesCount,`Owned printing files count changed during ${stageName}`);
}

async function waitForQZProvisioning(desktopLabUi,appDir,evidenceDir,timeoutMs=180000){
  const deadline=Date.now()+timeoutMs;
  const qzJar=path.join(appDir,'resources','portable','qz-tray','qz-tray.jar');
  const javaBin=path.join(appDir,'resources','portable','java','bin','java.exe');
  const architecturePresent=fs.existsSync(qzJar)&&fs.existsSync(javaBin);

  let lastFailureReason='INITIALIZING';

  while(Date.now()<deadline){
    // 1. Probe keypair
    let foundQzDir=null;
    for(const target of posTargets){
      const dir=path.join(target,'qz-certs');
      const key=path.join(dir,'private-key.pem');
      const cert=path.join(dir,'digital-certificate.pem');
      if(fs.existsSync(key)&&fs.existsSync(cert)&&fs.statSync(key).size>0&&fs.statSync(cert).size>0){
        foundQzDir=dir;
        break;
      }
    }

    if(!foundQzDir){
      await desktopLabUi.evaluate('window.pos?.getQZCert ? window.pos.getQZCert() : null').catch(()=>null);
      lastFailureReason='KEYPAIR_MISSING';
      await delay(1000);
      continue;
    }

    // 2. Check ssl-installed.flag when architecture present
    if(architecturePresent){
      const flagPath=path.join(foundQzDir,'ssl-installed.flag');
      if(!fs.existsSync(flagPath)){
        lastFailureReason='SSL_FLAG_MISSING';
        await delay(1000);
        continue;
      }
      const flagContent=fs.readFileSync(flagPath,'utf8').trim();
      if(flagContent==='skipped'){
        throw new Error('QZ silent SSL installation was skipped (UAC cancellation or execution error)');
      }
      if(flagContent!=='installed'){
        throw new Error(`Unexpected ssl-installed.flag content: ${flagContent}`);
      }
    }

    // 3. Check ownership receipt
    const receipts=[];
    for(const target of posTargets){
      const p=path.join(target,'qz-shared-install.json');
      if(fs.existsSync(p)&&fs.statSync(p).isFile()){
        receipts.push(p);
      }
    }

    if(receipts.length===0){
      lastFailureReason='RECEIPT_MISSING';
      await delay(1000);
      continue;
    }

    if(receipts.length>1){
      throw new Error(`Multiple QZ ownership receipts found (${receipts.length})`);
    }

    const receiptPath=receipts[0];
    if(fs.statSync(receiptPath).size===0){
      lastFailureReason='RECEIPT_EMPTY';
      await delay(1000);
      continue;
    }

    let ownership;
    try{
      ownership=JSON.parse(fs.readFileSync(receiptPath,'utf8'));
    }catch(e){
      throw new Error(`Corrupt QZ ownership receipt JSON: ${e.message}`);
    }

    if(typeof ownership.rootCertificateBase64!=='string'||!ownership.rootCertificateBase64){
      throw new Error('Ownership receipt missing rootCertificateBase64');
    }

    if(!Array.isArray(ownership.files)||ownership.files.length===0){
      throw new Error('Ownership receipt must declare non-empty files array');
    }

    const certDer=Buffer.from(ownership.rootCertificateBase64,'base64');
    if(certDer.length===0){
      throw new Error('Root certificate base64 decoded to zero bytes');
    }

    const printingThumbprint=crypto.createHash('sha1').update(certDer).digest('hex').toUpperCase();
    if(printingThumbprint.length!==40){
      throw new Error('Computed certificate thumbprint must be 40 hex characters');
    }

    // 4. Check Enterprise Windows registry store
    const enterpriseKeyPath=`Registry::HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\EnterpriseCertificates\\Root\\Certificates\\${printingThumbprint}`;
    const enterpriseCheck=execFileSync('powershell.exe',[
      '-NoProfile','-NonInteractive','-Command',
      `if (Test-Path -LiteralPath '${enterpriseKeyPath}') { 'True' } else { 'False' }`
    ],{encoding:'utf8',windowsHide:true}).trim();

    if(enterpriseCheck!=='True'){
      lastFailureReason='ENTERPRISE_REGISTRY_CERT_NOT_INSTALLED';
      await delay(1000);
      continue;
    }

    // All readiness predicates satisfied!
    return true;
  }

  // Timed out: assemble allowlist diagnostics
  const receiptCount=posTargets.filter(t=>fs.existsSync(path.join(t,'qz-shared-install.json'))).length;
  let keypairPresence=false;
  let sslFlagStatus='missing';
  for(const target of posTargets){
    const k=path.join(target,'qz-certs','private-key.pem');
    const c=path.join(target,'qz-certs','digital-certificate.pem');
    if(fs.existsSync(k)&&fs.existsSync(c)&&fs.statSync(k).size>0&&fs.statSync(c).size>0){
      keypairPresence=true;
    }
    const f=path.join(target,'qz-certs','ssl-installed.flag');
    if(fs.existsSync(f)){
      try{sslFlagStatus=fs.readFileSync(f,'utf8').trim();}catch{}
    }
  }
  const rootCertPresence=fs.existsSync(path.join(process.env.PROGRAMDATA||'C:\\ProgramData','qz','ssl','root-ca.crt'));

  const safeLogLines=[];
  const logFile=path.join(evidenceDir,'desktop.log');
  if(fs.existsSync(logFile)){
    try{
      const lines=fs.readFileSync(logFile,'utf8').split(/\r?\n/);
      for(const line of lines){
        if(/\[(?:QZ Certs|QZ Tray|QZ Service)\]/i.test(line)){
          safeLogLines.push(sanitizeReason(line.trim()));
        }
      }
    }catch{}
  }

  const diagnostics={
    receiptCount,
    keypairPresence,
    rootCertPresence,
    sslFlagStatus,
    publicStageOrFailureCode:lastFailureReason,
    safeQZMessageLines:safeLogLines.slice(-25)
  };

  try{
    fs.writeFileSync(path.join(evidenceDir,'contract-qz-diagnostics.json'),JSON.stringify(diagnostics,null,2));
  }catch{}

  console.error('[Contract QZ Readiness Diagnostics]',JSON.stringify(diagnostics,null,2));
  throw new Error(`Bounded wait timed out waiting for QZ provisioning completion (${lastFailureReason}): receipts=${receiptCount}, keypair=${keypairPresence}, rootCert=${rootCertPresence}, sslFlag=${sslFlagStatus}`);
}

if(phase==='contract'){
  const ui=createDesktopLab(application,evidence);
  try{
    await ui.start();
    for(const [file,expected] of Object.entries(setup11Inventory.files)){
      assert.equal(ui.hash(path.join(root,file)),expected.sha256,'Original Setup 11 inventory '+file);
    }
    const auth=await ui.freshLogin();
    // Ephemeral fixture credentials stay outside evidence and release artifacts.
    fs.writeFileSync(path.join(lab,'fixture-auth.json'),JSON.stringify(auth),{mode:0o600});

    // Wait for actual native QZ provisioning to finish before first shutdown
    await waitForQZProvisioning(ui,application,evidence,180000);

    // Snapshot and verify real protected system state (keypair, Enterprise root cert, receipt, owned files)
    const systemState=await getSystemProtectedState(ui);
    assert.equal(systemState.public.printingTrustInstalled,true,'Printing trust must be installed in Enterprise store');
    assert.ok(systemState.public.ownedPrintingFilesCount>0,'Must own at least one printing file');

    const contract=sql('prepare-compatibility');
    assert.equal(contract.runtime_metadata_readable,true);
    assert.equal(contract.metadata_view_read_only,true);
    fs.writeFileSync(path.join(lab,'data-contract.json'),JSON.stringify(contract,null,2));
    fs.writeFileSync(path.join(lab,'owned-lab.json'),JSON.stringify({application,storage,version:'0.0.11'}));
    console.log('PASS Setup 0.0.11 contract: actual MariaDB metadata readable with restricted runtime; business schema retained; owned QZ trust established');
  }finally{
    await ui.stop();
  }
  process.exit(0);
}

assert.equal(phase,'acceptance');
const owned=JSON.parse(fs.readFileSync(path.join(lab,'owned-lab.json'),'utf8'));
assert.equal(owned.application,application);
assert.equal(owned.storage,storage);

const candidateInventory=JSON.parse(fs.readFileSync(candidateInventoryPath,'utf8'));
assert.equal(candidateInventory.version,'0.0.12','Candidate version check');
const beta10Inventory=JSON.parse(fs.readFileSync(beta10InventoryPath,'utf8'));
assert.equal(beta10Inventory.version,'0.0.10-beta.10','Beta 10 target version check');

const transportPath=path.join(lab,'transport.json');
const assetUrl=(repo,tag,name)=>`https://github.com/ABDO-TECK/${repo}/releases/download/${tag}/${name}`;

const candidateRelease={
  tag_name:'v0.0.12',
  draft:false,
  prerelease:false,
  name:'Repair Candidate v0.0.12',
  published_at:'2026-10-25T00:00:00Z',
  body:'Setup 0.0.11 channel transition repair candidate',
  assets:[]
};

const beta10Release={
  tag_name:'v0.0.10-beta.10',
  draft:false,
  prerelease:true,
  name:'POS Desktop v0.0.10-beta.10',
  published_at:'2026-10-21T00:00:00Z',
  body:'Published beta release',
  assets:[]
};

const setup11Release={
  tag_name:'v0.0.11',
  draft:false,
  prerelease:false,
  name:'POS Desktop Setup v0.0.11',
  published_at:'2026-10-20T00:00:00Z',
  body:'Published setup release',
  assets:[{name:'deployment-inventory.json',browser_download_url:assetUrl('pos-desktop','v0.0.11','deployment-inventory.json'),size:fs.statSync(setup11InventoryPath).size}]
};

const transport={mode:'delta',responses:{},assets:{},trace:path.join(evidence,'transport-trace.jsonl')};

// Populate candidate assets from assets directory
for(const name of fs.readdirSync(assets)){
  const file=path.join(assets,name);
  if(!fs.statSync(file).isFile())continue;
  const url=assetUrl('pos-desktop-updates','v0.0.12',name);
  candidateRelease.assets.push({name,browser_download_url:url,size:fs.statSync(file).size});
  transport.assets[url]=file;
}

// Populate Beta 10 assets from beta10 download directory
const beta10DownloadDir=path.join(lab,'beta10-download');
if(fs.existsSync(beta10DownloadDir)){
  for(const name of fs.readdirSync(beta10DownloadDir)){
    const file=path.join(beta10DownloadDir,name);
    if(!fs.statSync(file).isFile())continue;
    const url=assetUrl('pos-desktop-updates','v0.0.10-beta.10',name);
    beta10Release.assets.push({name,browser_download_url:url,size:fs.statSync(file).size});
    transport.assets[url]=file;
  }
}

// Map setup 11 inventory download
transport.assets[setup11Release.assets[0].browser_download_url]=setup11InventoryPath;

// Map endpoints for updates repo and setup repo
const allCandidateAssets=[...candidateRelease.assets];
transport.responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases?per_page=100&page=1']=[candidateRelease,beta10Release];
transport.responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.12']=candidateRelease;
transport.responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.10-beta.10']=beta10Release;

transport.responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases?per_page=100&page=1']=[setup11Release];
transport.responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.11']=setup11Release;

// Explicit 404 mock responses for absent canonical pairs probed by carrier discovery
const notFoundSentinel={
  http_code:404,
  body:JSON.stringify({message:'Not Found',documentation_url:'https://docs.github.com/rest'})
};
transport.responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.12']=notFoundSentinel;
transport.responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.10-beta.10']=notFoundSentinel;
transport.responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.11']=notFoundSentinel;

function mode(value){
  transport.mode=value;
  // Mode 'full' filtering removes ONLY delta ZIP files (*-delta.zip), preserving descriptors and signatures
  if(value==='full'){
    candidateRelease.assets=allCandidateAssets.filter(a=>!a.name.endsWith('-delta.zip'));
  }else{
    candidateRelease.assets=[...allCandidateAssets];
  }
  fs.writeFileSync(transportPath,JSON.stringify(transport,null,2));
}

mode('delta');

// Distinct rate-limit trackers for update_apply (POST /api/updates/apply) and transition_apply (POST /api/updates/transition/apply)
// Corroborating backend/routes/admin.php lines 18 and 44: separate 2 requests per 300s buckets (no global shared single budget)
const applyHistory={
  update_apply:[],
  transition_apply:[]
};

async function waitForApplySlot(limiter='transition_apply'){
  const history=applyHistory[limiter];
  if(history.length>=2){
    const earliestInWindow=history[history.length-2];
    const waitUntil=earliestInWindow+310000;
    while(Date.now()<waitUntil){
      const remainingSec=Math.ceil((waitUntil-Date.now())/1000);
      console.log(`Waiting ${remainingSec}s for ${limiter} rate-limit window (2 per 300s)...`);
      await delay(Math.min(15000,waitUntil-Date.now()));
    }
  }
  history.push(Date.now());
}

// Setup ephemeral CI PHP ini scan directory for the Electron-owned background worker
const canonicalTemp=fs.realpathSync(process.env.RUNNER_TEMP).toLowerCase()+path.sep;
const canonicalLab=fs.realpathSync(lab).toLowerCase()+path.sep;
assert.ok(canonicalLab.startsWith(canonicalTemp),'Lab must reside within RUNNER_TEMP boundary');
assert.ok(!canonicalLab.startsWith(fs.realpathSync(application).toLowerCase()+path.sep),'Lab must not reside inside application root');
assert.ok(!canonicalLab.startsWith(fs.realpathSync(evidence).toLowerCase()+path.sep),'Lab must not reside inside evidence directory');

const phpIniDir=path.join(lab,'php-ini');
fs.mkdirSync(phpIniDir,{recursive:true});
const canonicalIniDir=fs.realpathSync(phpIniDir);
assert.ok((canonicalIniDir.toLowerCase()+path.sep).startsWith(canonicalTemp),'php-ini dir must reside within RUNNER_TEMP boundary');

// Canonical no-reparse guard: verify no symbolic link or reparse point exists in phpIniDir ancestors up to RUNNER_TEMP
let iniCursor=canonicalIniDir;
const tempRoot=fs.realpathSync(process.env.RUNNER_TEMP).toLowerCase();
while(iniCursor&&iniCursor.toLowerCase()!==tempRoot&&iniCursor!==path.dirname(iniCursor)){
  assert.equal(fs.lstatSync(iniCursor).isSymbolicLink(),false,'Reparse point / symlink rejected in php-ini path: '+iniCursor);
  iniCursor=path.dirname(iniCursor);
}

const fixtureTransportPhp=path.resolve('scripts/fixtures/channel-transition-transport.php').replace(/\\/g,'/');
assert.ok(fs.existsSync(fixtureTransportPhp),'Fixture transport script must exist: '+fixtureTransportPhp);
fs.writeFileSync(path.join(phpIniDir,'fixture-transport.ini'),`auto_prepend_file = "${fixtureTransportPhp}"\n`,'utf8');

const existingScan=process.env.PHP_INI_SCAN_DIR?`;${process.env.PHP_INI_SCAN_DIR}`:'';
const phpIniScanDir=`${phpIniDir}${existingScan}`;

const extraEnv={
  PHP_INI_SCAN_DIR:phpIniScanDir,
  POS_TRANSITION_FIXTURE:transportPath,
  APP_DEPLOY_ROOT:root
};

const ui=createDesktopLab(application,evidence,extraEnv);
let auth;
let requestTransport;

async function versionIs(expected,startedAt=0,expectedRunId=null){
  const deadline=Date.now()+180000;
  do{
    try{
      const currentVersion=JSON.parse(fs.readFileSync(path.join(root,'version.json'),'utf8')).version;
      if(currentVersion===expected){
        if(expectedRunId){
          await ui.until(
            `window.__pageRunId !== ${JSON.stringify(expectedRunId)} && Boolean(document.querySelector("form, a[href='/settings']"))`,
            'restarted frontend after update',
            120000
          );
        }else{
          await delay(5000);
          await ui.until('Boolean(document.querySelector("form, a[href=\'/settings\']"))','restarted frontend',120000);
        }
        if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))')){
          await ui.login(auth.email,auth.password);
        }
        await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))','authenticated after update restart');
        await delay(2000);
        requestTransport.resume();
        return;
      }
    }catch{}
    if(startedAt>0){
      try{
        const stateFile=path.join(storage,'update-state.json');
        if(fs.existsSync(stateFile)){
          const st=JSON.parse(fs.readFileSync(stateFile,'utf8'));
          const stateTime=Date.parse(st.updated_at||st.started_at||'');
          if(Number.isFinite(stateTime)&&stateTime>=startedAt&&(st.state==='failed'||st.state==='rolled_back')){
            throw Error('Update transaction failed on disk: '+(st.error||st.state));
          }
        }
      }catch(e){
        if(e.message?.startsWith('Update transaction failed on disk:'))throw e;
      }
    }
    const toast=await ui.evaluate(`(() => {
      const nodes=[...document.querySelectorAll('[role="alert"], [role="status"], .toast-error, div[style*="animation"]')];
      for(const n of nodes){
        const text=n.innerText?.trim()||n.textContent?.trim()||'';
        if(!text)continue;
        if(/فشل|خطأ|error|rejected|update_in_progress|in progress|مسبق/i.test(text)&&!/تم استكمال التحديث|إعادة تشغيل الخدمات|التحقق من التحديثات|جاري/i.test(text)){
          return text;
        }
      }
      return null;
    })()`).catch(()=>null);
    if(toast)throw Error('Frontend reported error during update: '+toast);
    await delay(500);
  }while(Date.now()<deadline);
  throw Error('Version/restart failed waiting for '+expected);
}

async function chooseChannel(channel){
  await ui.until('Boolean(document.querySelector("select option[value=beta]"))','channel selector');
  await ui.evaluate(`(() => {
    const select=[...document.querySelectorAll('select')].find(s=>s.querySelector('option[value=beta]'));
    Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,'value').set.call(select,${JSON.stringify(channel)});
    select.dispatchEvent(new Event('change',{bubbles:true}));
  })()`);
  await delay(1500);
}

function verifyInventory(inventory,removedFrom){
  for(const [file,expected] of Object.entries(inventory.files)){
    assert.equal(ui.hash(path.join(root,file)),expected.sha256,'Target hash '+file);
  }
  if(removedFrom){
    for(const file of Object.keys(removedFrom.files)){
      if(!Object.hasOwn(inventory.files,file)){
        assert.equal(fs.existsSync(path.join(root,file)),false,'Newer artifact remains: '+file);
      }
    }
  }
}

async function pendingSale(action){
  return ui.evaluate(`new Promise((resolve,reject)=>{
    const request=indexedDB.open('pos_offline');
    request.onerror=()=>reject(Error('Offline DB unavailable'));
    request.onsuccess=()=>{
      const db=request.result;
      if(!db.objectStoreNames.contains('pending_sales')){
        db.close();
        resolve(null);
        return;
      }
      const tx=db.transaction('pending_sales',${JSON.stringify(action==='put'?'readwrite':'readonly')});
      const store=tx.objectStore('pending_sales');
      const op=${action==='put'?`store.put({localId:'codex-transition-pending',ownerUserId:9999999,branchId:1,savedAt:'2026-10-21T00:00:00Z',syncStatus:'review',fixture:true})`:`store.get('codex-transition-pending')`};
      op.onsuccess=()=>resolve(${action==='put'?'true':'op.result'});
      op.onerror=()=>reject(Error('Offline sale fixture failed'));
      tx.oncomplete=()=>db.close();
    };
  })`);
}

async function preview(){
  await ui.until('Boolean(document.querySelector("[data-testid=preview-channel-transition]:not(:disabled)"))','enabled transition preview');
  await ui.evaluate('document.querySelector("[data-testid=preview-channel-transition]").click()');
  await delay(250);
  await ui.until('document.querySelector("[data-testid=channel-transition]")?.getAttribute("aria-busy")==="false" && Boolean(document.querySelector("[data-testid=transition-preview-result], [data-testid=channel-transition] [role=alert]"))','transition preview');
  const error=await ui.evaluate('document.querySelector("[data-testid=channel-transition] [role=alert]")?.innerText');
  assert.ok(!error,error);
}

try{
  await ui.start();
  auth=JSON.parse(fs.readFileSync(path.join(lab,'fixture-auth.json'),'utf8'));
  if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))')){
    await ui.login(auth.email,auth.password);
  }
  await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))','resumed fixture session');
  requestTransport=await attachTransitionTransport({ui,application,storage,evidence,fixture:transportPath,auth});

  // Seed data & capture baselines
  const before=sql('seed');
  assert.ok(before.admin_digest,'Baseline database did not produce an admin user digest');
  const compatibilityBefore=sql('inspect-compatibility');
  await ui.until('Boolean(document.querySelector("a[href=\'/settings\']"))','ready session');

  let stored=false;
  for(let i=0;i<20&&!stored;i++){
    stored=await pendingSale('put');
    if(!stored)await delay(500);
  }
  assert.equal(stored,true,'Offline fixture store unavailable');
  const offlineBefore=await pendingSale('get');

  const shellBefore=[ui.hash(path.join(application,'POS Desktop.exe')),ui.hash(path.join(application,'resources/app.asar'))];
  const systemStateBefore=await getSystemProtectedState(ui);

  // ═════════════════════════════════════════════════════════════════════════
  // STEP 1: Bootstrap Setup 11 (stable) -> Candidate 12 (stable) via delta
  // ═════════════════════════════════════════════════════════════════════════
  console.log('Step 1: Upgrading Setup 11 to Candidate 12 via standard delta updater...');
  // Routes/admin.php line 44 uses update_apply limiter key (separate from transition_apply)
  await waitForApplySlot('update_apply');

  await ui.system();
  await ui.click('التحقق من التحديثات');
  await ui.until('[...document.querySelectorAll("button")].some(b=>b.textContent.trim()==="تثبيت التحديث الآن"&&!b.disabled)','update button visible');

  const bootstrapStart=Date.now();
  const bootstrapRunId='bootstrap_'+bootstrapStart;
  await ui.evaluate(`window.__pageRunId = ${JSON.stringify(bootstrapRunId)}`);
  await ui.click('تثبيت التحديث الآن');
  await ui.click('تأكيد');
  await delay(1500);
  await versionIs('0.0.12',bootstrapStart,bootstrapRunId);
  verifyInventory(candidateInventory,setup11Inventory);
  assert.deepEqual(sql('inspect'),before,'Database data preserved across 11 -> 12 bootstrap');
  assert.deepEqual(sql('inspect-compatibility'),compatibilityBefore,'Compatibility preserved across bootstrap');
  assert.deepEqual([ui.hash(path.join(application,'POS Desktop.exe')),ui.hash(path.join(application,'resources/app.asar'))],shellBefore,'Native shell intact after bootstrap');
  assertProtectedStatePreserved(systemStateBefore,await getSystemProtectedState(ui),'Step 1 (bootstrap)');
  console.log('Step 1 complete: Candidate 12 is running with Setup 11 native shell.');

  // Snapshot Candidate 12 vs Beta 10 file identity AFTER bootstrap
  const common=Object.keys(candidateInventory.files).filter(file=>beta10Inventory.files[file]?.sha256===candidateInventory.files[file].sha256);
  assert.ok(common.length>0,'Must have common files between Candidate 12 and Beta 10');
  const candidate12Stats=Object.fromEntries(common.map(file=>{
    const s=fs.statSync(path.join(root,file));
    return [file,{ino:s.ino,mtimeMs:s.mtimeMs,birthtimeMs:s.birthtimeMs}];
  }));

  // ═════════════════════════════════════════════════════════════════════════
  // STEP 2: Channel transition Candidate 12 (stable) -> Beta 10 (beta)
  // ═════════════════════════════════════════════════════════════════════════
  console.log('Step 2: Transitioning Candidate 12 (stable) -> Beta 10 (beta)...');
  await ui.system();
  await chooseChannel('beta');

  // Assert channel selection does not install immediately
  const verAfterSelect=JSON.parse(fs.readFileSync(path.join(root,'version.json'),'utf8')).version;
  assert.equal(verAfterSelect,'0.0.12','Channel selection must not install immediately');

  // Verify corrupt signature blocks transition preview/apply
  mode('corrupt_signature');
  await preview();
  assert.equal(await ui.evaluate('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))'),false,'Apply button shown despite corrupt signature');
  assert.equal(await ui.evaluate('document.querySelector("[data-testid=transition-preview-result]").innerText.includes("توقيع")'),true,'Missing signature error message');
  await ui.screenshot('invalid-signature-blocked');

  // Switch back to valid delta mode
  mode('delta');
  await preview();
  await ui.until('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))','signed beta preview ready');
  const previewText=await ui.evaluate('document.querySelector("[data-testid=transition-preview-result]").innerText');
  assert.ok(previewText.includes('Delta')||previewText.includes('دلتا')||previewText.includes('حزمة'),'Unexpected preview: '+previewText);

  // Test "wait without install" button does not install
  await ui.click('انتظار دون تثبيت');
  const verAfterWait=JSON.parse(fs.readFileSync(path.join(root,'version.json'),'utf8')).version;
  assert.equal(verAfterWait,'0.0.12','Wait button must not install');

  await preview();
  await ui.screenshot('transition-beta10-preview');

  await waitForApplySlot('transition_apply');
  const firstTransitionAt=Date.now();
  const transition1RunId='trans1_'+firstTransitionAt;
  await ui.evaluate(`window.__pageRunId = ${JSON.stringify(transition1RunId)}`);
  await ui.evaluate('document.querySelector("[data-testid=apply-channel-transition]").click()');
  await ui.click('تأكيد');
  await versionIs('0.0.10-beta.10',firstTransitionAt,transition1RunId);

  // Verify independent transition receipt
  const deadline=Date.now()+30000;
  let receipt1;
  do{
    const state=JSON.parse(fs.readFileSync(path.join(storage,'update-state.json'),'utf8'));
    if(state.transition_receipt){
      try{receipt1=JSON.parse(fs.readFileSync(path.join(storage,'channel-transition-receipts',state.transition_receipt+'.json'),'utf8'));}catch{}
      if(receipt1?.state==='completed')break;
      if(receipt1?.state==='failed')throw Error('Transition receipt failed: '+JSON.stringify(receipt1));
    }
    await delay(250);
  }while(Date.now()<deadline);

  assert.equal(receipt1?.state,'completed','Transition 12 -> beta.10 receipt never completed');
  assert.equal(receipt1.target_inventory_matches,true);
  assert.equal(receipt1.data_compatible,true);

  verifyInventory(beta10Inventory,candidateInventory);
  assert.deepEqual(sql('inspect'),before,'Data preserved after transition to Beta 10');
  assert.deepEqual(sql('inspect-compatibility'),compatibilityBefore,'Compatibility preserved after transition to Beta 10');
  assert.deepEqual(await pendingSale('get'),offlineBefore,'Offline sale preserved after transition to Beta 10');
  assert.deepEqual([ui.hash(path.join(application,'POS Desktop.exe')),ui.hash(path.join(application,'resources/app.asar'))],shellBefore,'Shell unchanged after transition to Beta 10');
  assertProtectedStatePreserved(systemStateBefore,await getSystemProtectedState(ui),'Step 2 (transition 12->beta.10)');
  console.log('Step 2 complete: Successfully transitioned Candidate 12 -> Beta 10.');

  // ═════════════════════════════════════════════════════════════════════════
  // STEP 3: Return Channel transition Beta 10 (beta) -> Candidate 12 (stable)
  // ═════════════════════════════════════════════════════════════════════════
  console.log('Step 3: Returning Beta 10 (beta) -> Candidate 12 (stable) [Delta]...');
  if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))'))await ui.login(auth.email,auth.password);
  await requestTransport.reauth();
  await ui.system();
  await chooseChannel('stable');
  await preview();
  await ui.until('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))','signed stable return');
  const returnPreviewText=await ui.evaluate('document.querySelector("[data-testid=transition-preview-result]").innerText');
  assert.ok(returnPreviewText.includes('Delta')||returnPreviewText.includes('دلتا')||returnPreviewText.includes('حزمة'),returnPreviewText);

  await waitForApplySlot('transition_apply');
  const secondTransitionAt=Date.now();
  const transition2RunId='trans2_'+secondTransitionAt;
  await ui.evaluate(`window.__pageRunId = ${JSON.stringify(transition2RunId)}`);
  await ui.evaluate('document.querySelector("[data-testid=apply-channel-transition]").click()');
  await ui.click('تأكيد');
  await versionIs('0.0.12',secondTransitionAt,transition2RunId);

  let deltaReceipt;
  const deadline2=Date.now()+30000;
  do{
    const state=JSON.parse(fs.readFileSync(path.join(storage,'update-state.json'),'utf8'));
    if(state.transition_receipt){
      try{deltaReceipt=JSON.parse(fs.readFileSync(path.join(storage,'channel-transition-receipts',state.transition_receipt+'.json'),'utf8'));}catch{}
      if(deltaReceipt?.state==='completed')break;
      if(deltaReceipt?.state==='failed')throw Error('Return delta receipt failed: '+JSON.stringify(deltaReceipt));
    }
    await delay(250);
  }while(Date.now()<deadline2);

  assert.equal(deltaReceipt?.state,'completed','Return delta receipt not completed');
  assert.equal(deltaReceipt.target_inventory_matches,true);
  assert.equal(deltaReceipt.data_compatible,true);

  verifyInventory(candidateInventory,beta10Inventory);
  assert.deepEqual(sql('inspect'),before,'Data preserved after delta return to Candidate 12');
  assert.deepEqual(sql('inspect-compatibility'),compatibilityBefore,'Compatibility preserved after delta return to Candidate 12');
  assert.deepEqual(await pendingSale('get'),offlineBefore,'Offline sale preserved after return');
  assert.deepEqual([ui.hash(path.join(application,'POS Desktop.exe')),ui.hash(path.join(application,'resources/app.asar'))],shellBefore,'Shell unchanged after return');
  assertProtectedStatePreserved(systemStateBefore,await getSystemProtectedState(ui),'Step 3 (delta return)');

  // Verify unchanged file identity restored after delta return
  for(const [file,expected] of Object.entries(candidate12Stats)){
    const s=fs.statSync(path.join(root,file));
    assert.deepEqual({ino:s.ino,mtimeMs:s.mtimeMs,birthtimeMs:s.birthtimeMs},expected,'Unchanged file replaced after delta return: '+file);
  }
  console.log('Step 3 complete: Successfully returned Beta 10 -> Candidate 12 via Delta.');

  // ═════════════════════════════════════════════════════════════════════════
  // STEP 4: Exercise Full Fallback Return Path
  // ═════════════════════════════════════════════════════════════════════════
  console.log('Step 4: Exercising Full fallback return...');
  await waitForApplySlot('transition_apply');

  if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))'))await ui.login(auth.email,auth.password);
  await requestTransport.reauth();
  await ui.system();
  await chooseChannel('beta');
  await preview();
  const transition3Start=Date.now();
  const transition3RunId='trans3_'+transition3Start;
  await ui.evaluate(`window.__pageRunId = ${JSON.stringify(transition3RunId)}`);
  await ui.evaluate('document.querySelector("[data-testid=apply-channel-transition]").click()');
  await ui.click('تأكيد');
  await versionIs('0.0.10-beta.10',transition3Start,transition3RunId);
  verifyInventory(beta10Inventory,candidateInventory);
  assert.deepEqual(sql('inspect'),before,'Data preserved after transition 3 (forward to Beta 10)');
  assert.deepEqual(sql('inspect-compatibility'),compatibilityBefore,'Compatibility preserved after transition 3');
  assertProtectedStatePreserved(systemStateBefore,await getSystemProtectedState(ui),'Step 4 (forward to Beta 10)');

  // Wait for transition_apply slot before 4th transition (Full fallback)
  await waitForApplySlot('transition_apply');

  if(await ui.evaluate('Boolean(document.querySelector("input[type=email]"))'))await ui.login(auth.email,auth.password);
  await requestTransport.reauth();
  // Enable Full fallback mode by removing delta assets
  mode('full');
  await ui.system();
  await chooseChannel('stable');
  await preview();
  const fullPreviewText=await ui.evaluate('document.querySelector("[data-testid=transition-preview-result]").innerText');
  assert.ok(fullPreviewText.includes('كاملة')||fullPreviewText.includes('full')||fullPreviewText.includes('Full'),'Expected Full preview, got: '+fullPreviewText);
  await ui.screenshot('return-full-preview');

  const transition4Start=Date.now();
  const transition4RunId='trans4_'+transition4Start;
  await ui.evaluate(`window.__pageRunId = ${JSON.stringify(transition4RunId)}`);
  await ui.evaluate('document.querySelector("[data-testid=apply-channel-transition]").click()');
  await ui.click('تأكيد');
  await versionIs('0.0.12',transition4Start,transition4RunId);

  let fullReceipt;
  const deadline4=Date.now()+30000;
  do{
    const state=JSON.parse(fs.readFileSync(path.join(storage,'update-state.json'),'utf8'));
    if(state.transition_receipt){
      try{fullReceipt=JSON.parse(fs.readFileSync(path.join(storage,'channel-transition-receipts',state.transition_receipt+'.json'),'utf8'));}catch{}
      if(fullReceipt?.state==='completed')break;
      if(fullReceipt?.state==='failed')throw Error('Full return receipt failed: '+JSON.stringify(fullReceipt));
    }
    await delay(250);
  }while(Date.now()<deadline4);

  // Validate receipts schema and independent execution
  assert.equal(typeof deltaReceipt?.receipt_id,'string','Delta receipt missing receipt_id');
  assert.equal(typeof fullReceipt?.receipt_id,'string','Full receipt missing receipt_id');
  assert.notEqual(deltaReceipt.receipt_id,fullReceipt.receipt_id,'Delta and Full return receipts must have different IDs');
  assert.equal(deltaReceipt.state,'completed','Delta return receipt state must be completed');
  assert.equal(fullReceipt.state,'completed','Full return receipt state must be completed');
  assert.equal(deltaReceipt.target_inventory_matches,true,'Delta return target_inventory_matches must be true');
  assert.equal(fullReceipt.target_inventory_matches,true,'Full return target_inventory_matches must be true');
  assert.equal(deltaReceipt.data_compatible,true,'Delta return data_compatible must be true');
  assert.equal(fullReceipt.data_compatible,true,'Full return data_compatible must be true');
  assert.equal(deltaReceipt.requires_recovery,false,'Delta return requires_recovery must be false');
  assert.equal(fullReceipt.requires_recovery,false,'Full return requires_recovery must be false');

  const deltaPlanPath=path.join(storage,'channel-transition-receipts',deltaReceipt.receipt_id+'.plan.json');
  assert.ok(fs.existsSync(deltaPlanPath),'Delta plan file must exist');
  const deltaPlan=JSON.parse(fs.readFileSync(deltaPlanPath,'utf8'));
  assert.equal(deltaPlan.target_version,'0.0.12','Delta plan target_version must be 0.0.12');

  const fullPlanPath=path.join(storage,'channel-transition-receipts',fullReceipt.receipt_id+'.plan.json');
  assert.ok(fs.existsSync(fullPlanPath),'Full plan file must exist');
  const fullPlan=JSON.parse(fs.readFileSync(fullPlanPath,'utf8'));
  assert.equal(fullPlan.target_version,'0.0.12','Full plan target_version must be 0.0.12');

  verifyInventory(candidateInventory,beta10Inventory);
  assert.deepEqual(sql('inspect'),before,'Data preserved after full return');
  assert.deepEqual(sql('inspect-compatibility'),compatibilityBefore,'Compatibility preserved after full return');
  assert.deepEqual(await pendingSale('get'),offlineBefore,'Offline sale preserved after full return');
  assert.deepEqual([ui.hash(path.join(application,'POS Desktop.exe')),ui.hash(path.join(application,'resources/app.asar'))],shellBefore,'Shell unchanged after full return');
  assertProtectedStatePreserved(systemStateBefore,await getSystemProtectedState(ui),'Step 4 (full return)');

  // Unchanged file identity preserved EVEN AFTER FULL RETURN (handoff filters identical byte files)
  for(const [file,expected] of Object.entries(candidate12Stats)){
    const s=fs.statSync(path.join(root,file));
    assert.deepEqual({ino:s.ino,mtimeMs:s.mtimeMs,birthtimeMs:s.birthtimeMs},expected,'Unchanged file replaced after full return: '+file);
  }

  assert.equal(await ui.evaluate('Boolean(document.querySelector("option[value=rc]"))'),false,'Active RC channel not removed');

  const trace=fs.readFileSync(transport.trace,'utf8');
  assert.ok(trace.includes('transition-0.0.12-to-beta-0.0.10-beta.10-delta.zip'),'Candidate transition forward delta package not exercised');
  assert.ok(trace.includes('transition-0.0.10-beta.10-to-stable-0.0.12-delta.zip'),'Candidate transition return delta package not exercised');
  assert.ok(trace.includes('transition-0.0.10-beta.10-to-stable-0.0.12-full.zip'),'Candidate transition return stable 12-full.zip not exercised');

  const result={
    ok:true,
    actualSetup11:true,
    transport:'isolated copied signed packages; live GitHub feeds verified separately after publication',
    bootstrapVersion:'0.0.11',
    candidateVersion:'0.0.12',
    betaTargetVersion:'0.0.10-beta.10',
    transitions:['0.0.11','0.0.12','0.0.10-beta.10','0.0.12','0.0.10-beta.10','0.0.12'],
    deltaAndFull:true,
    productionHttpPreparation:true,
    independentVerifierSurvived:true,
    deltaReceipt,
    fullReceipt,
    productionRateLimitsUnchanged:true,
    candidateInventorySha256:ui.hash(candidateInventoryPath),
    setup11InventorySha256:ui.hash(setup11InventoryPath),
    beta10InventorySha256:ui.hash(beta10InventoryPath),
    sqlSchemaLedgerCustomerSettingsPreserved:true,
    offlineSalePreserved:true,
    unchangedFileIdentityPreserved:true,
    unchangedFilesChecked:common.length,
    shellUnchanged:true,
    credentialsPreserved:true,
    envPreserved:systemStateBefore.public.envFilesCount>0,
    envFilesChecked:systemStateBefore.public.envFilesCount,
    deviceSslPreserved:systemStateBefore.public.lanSslEnabled?true:'not_enabled',
    deviceSslConfigured:systemStateBefore.public.lanSslEnabled,
    qzOwnershipPreserved:true,
    printingTrustPreserved:true,
    printingThumbprint:systemStateBefore.public.printingThumbprint,
    ownedPrintingFilesChecked:systemStateBefore.public.ownedPrintingFilesCount,
    deviceKeyFilesChecked:systemStateBefore.public.deviceKeyFilesCount,
    portableBinariesPreserved:true,
    portableBinariesChecked:Object.keys(systemStateBefore.public.portableHashes).length,
    selectionDoesNotInstall:true,
    waitDoesNotInstall:true,
    invalidSignatureBlocked:true,
    activeRcRemoved:true
  };

  fs.writeFileSync(path.join(evidence,'acceptance.json'),JSON.stringify(result,null,2));
  await ui.screenshot('candidate-final');
  console.log('PASS real Setup 0.0.11 bootstrap to 0.0.12 and bidirectional channel transition to 0.0.10-beta.10: delta and full return, HTTP preparation, independent receipts, exact artifacts, unchanged file identity and SQL/offline data preservation');
}catch(error){
  try{await ui.screenshot('failure');}catch{}
  try{
    // Export ALLOWLIST state and receipts facts ONLY - DO NOT export arbitrary raw logs
    const stateFile=path.join(storage,'update-state.json');
    if(fs.existsSync(stateFile)){
      try{
        const st=JSON.parse(fs.readFileSync(stateFile,'utf8'));
        const allowlistState={
          state:st.state??null,
          operation:st.operation??null,
          from_version:st.from_version??null,
          to_version:st.to_version??null,
          updated_at:st.updated_at??null,
          error:typeof st.error==='string'?sanitizeReason(st.error):null,
          transition_receipt:st.transition_receipt??null,
          transition_verified:st.transition_verified??null,
          requires_recovery:st.requires_recovery??null,
        };
        fs.writeFileSync(path.join(evidence,'diagnostic-update-state.json'),JSON.stringify(allowlistState,null,2));
      }catch{}
    }
    const lockFile=path.join(storage,'update-operation.lock');
    if(fs.existsSync(lockFile)){
      try{
        const lk=JSON.parse(fs.readFileSync(lockFile,'utf8'));
        const allowlistLock={
          operation:lk.operation??null,
          acquired_at:lk.acquired_at??null,
          expires_at:lk.expires_at??null,
          has_owner:Boolean(lk.owner_id),
        };
        fs.writeFileSync(path.join(evidence,'diagnostic-lock.json'),JSON.stringify(allowlistLock,null,2));
      }catch{}
    }
    const receiptsDir=path.join(storage,'channel-transition-receipts');
    if(fs.existsSync(receiptsDir)){
      try{
        const allowlistReceipts=[];
        for(const file of fs.readdirSync(receiptsDir)){
          if(file.endsWith('.json')&&!file.endsWith('.plan.json')){
            const rc=JSON.parse(fs.readFileSync(path.join(receiptsDir,file),'utf8'));
            allowlistReceipts.push({
              receipt_id:rc.receipt_id??null,
              state:rc.state??null,
              reason_code:typeof rc.reason_code==='string'?sanitizeReason(rc.reason_code):null,
              target_inventory_matches:rc.target_inventory_matches??null,
              data_compatible:rc.data_compatible??null,
              requires_recovery:rc.requires_recovery??null,
              updated_at:rc.updated_at??null,
            });
          }
        }
        fs.writeFileSync(path.join(evidence,'diagnostic-receipts.json'),JSON.stringify(allowlistReceipts,null,2));
      }catch{}
    }
    const jobsDir=path.join(storage,'update-jobs');
    if(fs.existsSync(jobsDir)){
      try{
        const allowlistJobs=[];
        for(const file of fs.readdirSync(jobsDir)){
          if(file.endsWith('.json')){
            const jb=JSON.parse(fs.readFileSync(path.join(jobsDir,file),'utf8'));
            allowlistJobs.push({
              job_id:jb.job_id??null,
              status:jb.status??null,
              handoff_ready:jb.result?.data?.requires_desktop_handoff??false,
              updated_at:jb.updated_at??jb.completed_at??null,
              error:typeof jb.result?.error==='string'?sanitizeReason(jb.result.error):null,
            });
          }
        }
        fs.writeFileSync(path.join(evidence,'diagnostic-jobs.json'),JSON.stringify(allowlistJobs,null,2));
      }catch{}
    }
  }catch(diagErr){
    console.error('Failed to capture diagnostics:',diagErr.message);
  }
  throw error;
}finally{
  try{await requestTransport?.close();}finally{await ui.stop();}
}
