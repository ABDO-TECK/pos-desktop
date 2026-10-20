import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const lab = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-qz-upgrade-'));
try {
  const user = path.join(lab, 'userdata'), runtime = path.join(lab, 'runtime');
  const certs = path.join(user, 'qz-certs'), shared = path.join(lab, 'shared');
  for (const directory of [certs, runtime, path.join(shared, 'qz/ssl')]) fs.mkdirSync(directory, { recursive: true });
  for (const file of ['digital-certificate.pem', 'private-key.pem', 'ssl-installed.flag']) fs.writeFileSync(path.join(certs, file), 'fixture');
  fs.writeFileSync(path.join(shared, 'qz/ssl/root-ca.crt'), 'preexisting external fixture');
  const saved = path.join(certs, 'qz-tray.properties'), deployed = path.join(runtime, 'qz-tray.properties');
  const properties = 'fixture SSL configuration, not real credentials';
  let elevations = 0;
  const module = { exports: {} };
  vm.runInNewContext(fs.readFileSync('electron/services/qz-certs.js', 'utf8'), {
    module, console: { log() {}, warn() {}, error() {} }, process: { env: { PROGRAMDATA: shared } },
    require: id => id === 'electron' ? { app: { getPath: () => user } }
      : id === '../utils/paths' ? { getBackendDir: () => path.join(lab, 'backend') }
      : id === '@vscode/sudo-prompt' ? { exec(command, options, callback) {
        elevations++;
        fs.writeFileSync(deployed, properties);
        callback(null, '', '');
      } } : require(id),
  });
  const ensure = () => module.exports.ensureQZCerts(runtime, path.join(lab, 'java.exe'));
  fs.writeFileSync(saved, properties);
  await ensure();
  assert.equal(fs.readFileSync(deployed, 'utf8'), properties, 'Setup upgrade must restore the existing SSL configuration');
  assert.equal(elevations, 0, 'Restoring preserved configuration must not regenerate certificates');
  fs.unlinkSync(saved);
  await ensure();
  assert.equal(fs.readFileSync(saved, 'utf8'), properties, 'Existing runtime configuration must be preserved for the next Setup');
  fs.unlinkSync(saved); fs.unlinkSync(deployed);
  await ensure();
  assert.equal(elevations, 1, 'An old installed flag must not hide missing SSL configuration');
  assert.equal(fs.readFileSync(saved, 'utf8'), properties, 'Successful SSL setup must persist its configuration outside the installation');
  assert.equal(fs.readFileSync(path.join(shared, 'qz/ssl/root-ca.crt'), 'utf8'), 'preexisting external fixture');
  console.log('PASS: QZ SSL settings survive Setup replacement; missing configuration is repaired without deleting shared data');
} finally {
  assert.ok(fs.realpathSync(lab).startsWith(fs.realpathSync(os.tmpdir()) + path.sep));
  fs.rmSync(lab, { recursive: true, force: true });
}
