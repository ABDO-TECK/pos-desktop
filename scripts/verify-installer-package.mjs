import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { listPackage } from '@electron/asar';
const root=process.cwd(), out=path.join(root,'dist-electron');
const resources=path.join(out,'win-unpacked/resources');
const archive=path.join(resources,'app.asar');
const entries=listPackage(archive).map(p=>p.replaceAll('\\','/').replace(/^\//,''));
const forbidden=entries.filter(p=>/^(?:release|certs|docs|scripts|\.github|\.git)(?:\/|$)|(?:^|\/)(?:\.env|db_credentials\.json|cookies\.json|private[-_]key\.pem)(?:\/|$)|\.(?:pfx|p12|key)$/.test(p));
if(forbidden.length)throw Error('Unexpected private/build files in ASAR: '+forbidden.join(', '));
for(const rel of ['electron/main.js','electron/preload.js','electron/utils/receipt-print-window.js','version.json']){
 if(!entries.includes(rel))throw Error('Missing ASAR source: '+rel);
}
for(const rel of ['app.asar.unpacked/frontend/dist/index.html','app.asar.unpacked/backend/backend.phar','app.asar.unpacked/backend/certs/cacert.pem','app.asar.unpacked/database/pos_schema.sql','portable/php/php.exe','portable/mysql/bin/mysqld.exe','portable/java/bin/java.exe','portable/qz-tray/qz-tray.jar','portable/runtime-manifest.json','portable/printing-manifest.json']){
 if(!fs.existsSync(path.join(resources,rel)))throw Error('Missing packaged runtime: '+rel);
}
const v=JSON.parse(fs.readFileSync('version.json','utf8')).version;
const setup=`POS-Desktop-Setup-${v}.exe`;
for(const rel of [setup,setup+'.blockmap','latest.yml'])if(!fs.existsSync(path.join(out,rel)))throw Error('Missing release asset: '+rel);
const names=[setup,setup+'.blockmap','latest.yml'];
const hashes=names.map(name=>crypto.createHash('sha256').update(fs.readFileSync(path.join(out,name))).digest('hex')+'  '+name).join('\n')+'\n';
fs.writeFileSync(path.join(out,'SHA256SUMS.txt'),hashes);
console.log(JSON.stringify({packageVerified:true,asarEntries:entries.length,requiredRuntimeFiles:true,releaseAssets:names}));
