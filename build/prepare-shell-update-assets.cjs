const fs = require('node:fs');
const path = require('node:path');
const { createPublicKey } = require('node:crypto');

module.exports = function prepareShellUpdateAssets(context) {
  if (context.electronPlatformName !== 'win32') return;

  const appDir = context.packager.info.appDir;
  const assets = path.join(appDir, 'electron', 'assets');
  const publicKey = fs.readFileSync(path.join(appDir, 'backend', 'certs', 'update_public_key.pem'));
  if (publicKey.includes('PRIVATE KEY') || createPublicKey(publicKey).asymmetricKeyType !== 'rsa') {
    throw new Error('The shell updater requires the packaged RSA release public key.');
  }

  // Using files from electron/assets makes both entries ASAR contents.
  // The external PHP public key stays under its existing asarUnpack rule.
  fs.writeFileSync(path.join(assets, 'shell-update-public-key.pem'), publicKey);
  fs.copyFileSync(path.join(appDir, 'backend', 'Native', 'OwnedChildProcess.cs'),
    path.join(assets, 'OwnedChildProcess.cs'));
};
