import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const source = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const lab = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-delta-acceptance-'));
const php = process.env.PHP_BINARY || 'php';
const require = createRequire(import.meta.url);
const run = (cmd, args, cwd) => execFileSync(cmd, args, { cwd, encoding: 'utf8', windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
const write = (file, data) => { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, data); };
const hash = (file) => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
write(path.join(lab, 'test-private.pem'), privateKey.export({ type: 'pkcs8', format: 'pem' }));
const checks = [];

for (const [channel, version, baselineVersion, name] of [['beta', '0.0.3-beta.1', '0.0.2', 'beta'], ['stable', '0.0.3', '0.0.2', 'stable'], ['stable', '0.0.3', '0.0.3-beta.1', 'promotion']]) {
  const root = path.join(lab, name);
  fs.mkdirSync(root);
  for (const directory of ['scripts', 'backend', 'electron', 'build']) {
    fs.cpSync(path.join(source, directory), path.join(root, directory), {
      recursive: true,
      filter: (file) => !/[/\\](?:storage|logs|cache|tests)(?:[/\\]|$)|[/\\]\.env(?:\.|$)|backend\.phar$/.test(file),
    });
  }
  write(path.join(root, 'backend/certs/update_public_key.pem'), publicKey.export({ type: 'spki', format: 'pem' }));
  for (const file of ['package.json', 'package-lock.json']) fs.copyFileSync(path.join(source, file), path.join(root, file));
  write(path.join(root, 'frontend/package.json'), fs.readFileSync(path.join(source, 'frontend/package.json')));
  write(path.join(root, 'backend/backend.phar'), 'immutable fixture PHAR');
  write(path.join(root, 'database/pos_schema.sql'), '-- fixture schema');
  write(path.join(root, 'frontend/dist/index.html'), 'old frontend');
  write(path.join(root, 'frontend/dist/keep.js'), 'unchanged javascript');
  write(path.join(root, 'frontend/dist/remove.js'), 'obsolete javascript');
  write(path.join(root, 'frontend/dist/digital-certificate.txt'), 'build-runner generated printing certificate');
  write(path.join(root, 'frontend/src/app.ts'), 'old source');
  write(path.join(root, 'version.json'), JSON.stringify({ version: baselineVersion, update_engine_version: '1.0.0', channel: baselineVersion.includes('-beta.') ? 'beta' : 'stable' }));
  run('git', ['init', '-q'], root);
  run('git', ['config', 'user.name', 'Delta acceptance'], root);
  run('git', ['config', 'user.email', 'delta@invalid.example'], root);
  run('git', ['add', 'version.json', 'frontend/src/app.ts', 'electron', 'build', 'package.json', 'package-lock.json', 'scripts/desktop-runtime.json'], root);
  run('git', ['commit', '-qm', 'baseline'], root);
  const baseline = run('git', ['rev-parse', 'HEAD'], root).trim();
  const inventory = path.join(root, 'baseline.json');
  run(php, ['scripts/build-deployment-inventory.php', '--root=.', `--output=${inventory}`], root);
  const deployed = path.join(root, 'installed');
  for (const file of Object.keys(JSON.parse(fs.readFileSync(inventory)).files)) {
    write(path.join(deployed, file), fs.readFileSync(path.join(root, file)));
  }
  const sentinel = path.join(deployed, 'backend/storage/business.db');
  write(sentinel, 'business data must stay identical');
  const deviceCertificate = path.join(deployed, 'frontend/dist/digital-certificate.txt');
  write(deviceCertificate, 'this device printing certificate must stay identical');
  const keep = path.join(deployed, 'frontend/dist/keep.js');
  for (const file of [keep, sentinel, deviceCertificate]) fs.utimesSync(file, new Date('2020-01-01'), new Date('2020-01-01'));
  const before = [keep, sentinel, deviceCertificate].map(file => ({ hash: hash(file), mtime: fs.statSync(file).mtimeMs, ino: fs.statSync(file).ino }));
  write(path.join(root, 'frontend/dist/index.html'), 'new frontend');
  write(path.join(root, 'frontend/dist/add.js'), 'new javascript');
  fs.unlinkSync(path.join(root, 'frontend/dist/remove.js'));
  write(path.join(root, 'frontend/src/app.ts'), 'new source');
  write(path.join(root, 'version.json'), JSON.stringify({ version, application_version: version, channel, update_engine_version: '1.0.0' }, null, 2) + '\n');
  run('git', ['add', 'version.json', 'frontend/src/app.ts'], root);
  run('git', ['commit', '-qm', channel], root);
  run('git', ['tag', `v${version}`], root);
  run('node', ['scripts/validate-release-source.mjs', '--mode=delta', `--tag=v${version}`, `--release-channel=${channel}`, `--baseline-ref=${baseline}`, `--baseline-version=${baselineVersion}`], root);
  const output = path.join(root, 'release');
  run(php, ['scripts/build-release-package.php', `--tag=v${version}`, `--from-ref=${baseline}`, `--from-version=${baselineVersion}`, `--baseline-inventory=${inventory}`, `--private-key=${path.join(lab, 'test-private.pem')}`, `--output-dir=${output}`], root);
  const manifest = JSON.parse(fs.readFileSync(path.join(output, 'manifest.json')));
  assert.equal(manifest.version, version, 'beta version must retain its suffix');
  assert.equal(manifest.channel, channel);
  assert.equal(manifest.from_version, baselineVersion);
  assert.deepEqual(manifest.files.map(f => f.path).sort(), ['frontend/dist/add.js', 'frontend/dist/index.html', 'version.json']);
  assert.deepEqual(manifest.deleted_files, ['frontend/dist/remove.js']);
  run(php, ['scripts/verify-release-package.php', `--release-dir=${output}`, `--target-version=${version}`, `--minimum-version=${baselineVersion}`], root);
  const signaturePath = path.join(output, 'manifest.sig');
  const validSignature = fs.readFileSync(signaturePath);
  write(signaturePath, 'invalid signature');
  assert.throws(() => run(php, ['scripts/verify-release-package.php', `--release-dir=${output}`, `--target-version=${version}`, `--minimum-version=${baselineVersion}`], root), /RSA manifest signature verification failed/);
  write(signaturePath, validSignature);
  write(path.join(root, 'apply.php'), `<?php
require __DIR__.'/backend/vendor/autoload.php';
$manifest=json_decode(file_get_contents($argv[1]),true);
$service=new App\\Services\\DeltaUpdateService(null,$argv[2],$argv[3]);
$check=new App\\Services\\UpdateManifestService();
if($check->checkVersionCompatibility('0.0.1',$manifest)['compatible'])throw new Exception('wrong baseline accepted');
if($check->checkChannelCompatibility('stable','beta')['compatible'])throw new Exception('stable accepted beta');
if($check->checkChannelCompatibility('beta','stable')['compatible'])throw new Exception('beta accepted stable');
$stage=$service->extractZipToStaging($argv[4],$service->getStagingDir($manifest['version']));
if(!$stage['ok'])throw new Exception(json_encode($stage));
$backup=$service->createBackupSnapshot($manifest['from_version'],$manifest['version'],$manifest);
if(!$backup['ok'])throw new Exception(json_encode($backup));
if(($argv[5]??'')==='php'){
  $applied=$service->applyStagedFiles($manifest,$backup['snapshot_path']);
  if(!$applied['ok'])throw new Exception(json_encode($applied));
  echo json_encode($applied);exit;
}
$handoff=$service->prepareDesktopHandoff($manifest,$backup['snapshot_path']);
if(!$handoff['ok'])throw new Exception(json_encode($handoff));
echo json_encode(['snapshot'=>$backup['snapshot_path']]);
`);
  const storage = path.join(root, 'runtime');
  fs.mkdirSync(storage);
  const manifestPath = path.join(output, 'manifest.json');
  run(php, ['apply.php', manifestPath, deployed, storage, path.join(output, 'delta.zip')], root);
  const handoff = require(path.join(source, 'electron/services/delta-update-handoff.js'));
  const applied = await handoff.applyPendingDelta(storage, version, deployed);
  assert.equal(applied.ok, true, applied.error);
  for (const entry of manifest.files) assert.equal(hash(path.join(deployed, entry.path)), entry.sha256);
  assert.equal(fs.existsSync(path.join(deployed, 'frontend/dist/remove.js')), false);
  [keep, sentinel, deviceCertificate].forEach((file, index) => assert.deepEqual({ hash: hash(file), mtime: fs.statSync(file).mtimeMs, ino: fs.statSync(file).ino }, before[index], 'untouched file identity, time and bytes must stay identical'));
  await handoff.rollback(applied.plan);
  assert.equal(fs.readFileSync(path.join(deployed, 'frontend/dist/index.html'), 'utf8'), 'old frontend');
  assert.equal(fs.existsSync(path.join(deployed, 'frontend/dist/add.js')), false);
  assert.equal(fs.readFileSync(path.join(deployed, 'frontend/dist/remove.js'), 'utf8'), 'obsolete javascript');
  // A late integrity failure must restore earlier replacements.
  write(path.join(applied.plan.staging_dir, 'version.json'), 'corrupted payload');
  const rejected = await handoff.applyPendingDelta(storage, version, deployed);
  assert.equal(rejected.ok, false);
  assert.equal(fs.readFileSync(path.join(deployed, 'frontend/dist/index.html'), 'utf8'), 'old frontend');
  assert.equal(hash(sentinel), before[1].hash);
  write(path.join(applied.plan.staging_dir, 'version.json'), fs.readFileSync(path.join(root, 'version.json')));
  write(keep, 'unexpected local artifact edit');
  assert.equal((await handoff.applyPendingDelta(storage, version, deployed)).ok, false, 'Mismatched installed baseline must fail before writes');
  assert.equal(fs.readFileSync(path.join(deployed, 'frontend/dist/index.html'), 'utf8'), 'old frontend');
  write(keep, 'unchanged javascript');
  fs.utimesSync(keep, new Date('2020-01-01'), new Date('2020-01-01'));
  run(php, ['apply.php', manifestPath, deployed, storage, path.join(output, 'delta.zip'), 'php'], root);
  for (const entry of manifest.files) assert.equal(hash(path.join(deployed, entry.path)), entry.sha256, 'PHP must preserve exact signed bytes');
  assert.equal(fs.statSync(keep).mtimeMs, before[0].mtime);
  assert.equal(fs.statSync(sentinel).mtimeMs, before[1].mtime);
  assert.equal(hash(deviceCertificate), before[2].hash);
  assert.equal(fs.statSync(deviceCertificate).mtimeMs, before[2].mtime);
  checks.push({ channel, version, baselineVersion, replacements: manifest.files.map(f => f.path), deleted: manifest.deleted_files, unchangedFilesPreserved: true, phpApplyVerified: true, rollback: true, corruptPayloadRejected: true, corruptSignatureRejected: true, wrongBaselineRejected: true });
}
const report = JSON.stringify({ ok: true, lab, checks }, null, 2);
if (process.env.DELTA_REPORT_PATH) fs.writeFileSync(process.env.DELTA_REPORT_PATH, report + '\n');
console.log(report);
