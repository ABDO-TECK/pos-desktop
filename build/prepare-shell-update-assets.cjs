const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { createPublicKey } = require('node:crypto');
const { execFileSync } = require('node:child_process');

function compileWindowsProgram(sources, output) {
  const framework = path.join(process.env.SystemRoot, 'Microsoft.NET', 'Framework64', 'v4.0.30319');
  execFileSync(path.join(framework, 'csc.exe'), [
    '/nologo', '/target:exe', '/platform:x64', '/optimize+',
    '/reference:' + path.join(framework, 'System.Web.Extensions.dll'),
    '/out:' + output, ...sources,
  ], { windowsHide: true, stdio: 'inherit' });
}

function compileBroker(appDir, output) {
  const sources = ['OwnedChildProcess.cs', 'ShellUpdateProcessBroker.cs']
    .map((name) => path.join(appDir, 'backend', 'Native', name));
  compileWindowsProgram(sources, output);
}

module.exports = function prepareShellUpdateAssets(context) {
  if (context.electronPlatformName !== 'win32') return;

  const appDir = context.packager.info.appDir;
  const assets = path.join(appDir, 'electron', 'assets');
  const publicKey = fs.readFileSync(path.join(appDir, 'backend', 'certs', 'update_public_key.pem'));
  if (publicKey.includes('PRIVATE KEY') || createPublicKey(publicKey).asymmetricKeyType !== 'rsa') {
    throw new Error('The shell updater requires the packaged RSA release public key.');
  }

  // Using files from electron/assets makes these entries ASAR contents.
  // The external PHP public key stays under its existing asarUnpack rule.
  fs.writeFileSync(path.join(assets, 'shell-update-public-key.pem'), publicKey);
  fs.copyFileSync(path.join(appDir, 'backend', 'Native', 'OwnedChildProcess.cs'),
    path.join(assets, 'OwnedChildProcess.cs'));
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-build-broker-'));
  const executable = path.join(temp, 'shell-update-broker.exe');
  try {
    compileBroker(appDir, executable);
    fs.copyFileSync(executable, path.join(assets, 'shell-update-broker.bin'));
  } finally {
    if (fs.existsSync(executable)) fs.unlinkSync(executable);
    fs.rmdirSync(temp);
  }
};

module.exports.compileBroker = compileBroker;
module.exports.compileWindowsProgram = compileWindowsProgram;
