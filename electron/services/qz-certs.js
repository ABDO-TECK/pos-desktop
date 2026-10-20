const forge = require('node-forge');
const fs = require('fs');
const path = require('path');
const { app } = require('electron');
const crypto = require('crypto');
const sudo = require('@vscode/sudo-prompt');

const CERTS_DIR_NAME = 'qz-certs';

function listQzFiles(root, directory = root) {
  if (!fs.existsSync(directory)) return [];
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    const file = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) throw new Error('QZ shared directory contains a symbolic link');
    return entry.isDirectory() ? listQzFiles(root, file) : [path.relative(root, file).replace(/\\/g, '/')];
  });
}

/**
 * يُرجع مسار مجلد الشهادات في userData.
 * مثال: C:\Users\abdmo\AppData\Roaming\pos-desktop\qz-certs\
 */
function getCertsDir() {
  const base = app && typeof app.getPath === 'function'
    ? app.getPath('userData')
    : path.join(require('os').tmpdir(), 'pos-desktop');
  const dir = path.join(base, CERTS_DIR_NAME);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  return dir;
}

/**
 * Return the private signing-key path in Electron's per-user writable data.
 * This location is deliberately outside every HTTP document root.
 */
function getQZPrivateKeyPath() {
  return path.join(getCertsDir(), 'private-key.pem');
}

/**
 * يتحقق من وجود الشهادات. إذا غير موجودة، يولّدها.
 * يُستدعى مرة واحدة من main.js قبل startQZTray.
 *
 * @param {string} qzTrayDir - مسار مجلد qz-tray (portable/qz-tray أو المبني)
 */
async function ensureQZCerts(qzTrayDir, javaPath) {
  const certsDir = getCertsDir();
  const certPath = path.join(certsDir, 'digital-certificate.pem');
  const keyPath  = getQZPrivateKeyPath();
  const flagPath = path.join(certsDir, 'ssl-installed.flag');

  if (fs.existsSync(certPath) && fs.existsSync(keyPath)) {
    console.log('[QZ Certs] Certificates already exist for message signing');
    _copyOverrideCert(certPath, qzTrayDir);
    _publishCertificateForBrowser(certPath);
    
    if (!fs.existsSync(flagPath)) {
      console.log('[QZ Certs] WSS SSL not installed silently. Installing now via QZ Tray certgen...');
      await installSSLcertSilently(qzTrayDir, javaPath, flagPath);
    }
    return;
  }

  console.log('[QZ Certs] Generating new certificate pair...');

  // 1. توليد مفتاح RSA 2048-bit
  const keys = forge.pki.rsa.generateKeyPair(2048);

  // 2. إنشاء شهادة X.509 self-signed
  const cert = forge.pki.createCertificate();
  cert.publicKey = keys.publicKey;
  cert.serialNumber = '01' + crypto.randomBytes(8).toString('hex');

  // صالحة لمدة 20 سنة
  cert.validity.notBefore = new Date();
  cert.validity.notAfter = new Date();
  cert.validity.notAfter.setFullYear(cert.validity.notAfter.getFullYear() + 20);

  const attrs = [
    { name: 'commonName',    value: 'localhost' },
    { name: 'countryName',   value: 'US' },
    { shortName: 'ST',       value: 'NY' },
    { name: 'localityName',  value: 'Canastota' },
    { name: 'organizationName', value: 'QZ Industries, LLC' },
    { shortName: 'OU',       value: 'QZ Industries, LLC' },
    { name: 'emailAddress',  value: 'support@qz.io' },
  ];
  cert.setSubject(attrs);
  cert.setIssuer(attrs);

  // إضافة extensions (مطلوبة حتى يقبلها QZ Tray كـ CA)
  cert.setExtensions([
    { name: 'basicConstraints', cA: true, critical: true, pathLenConstraint: 1 },
    { name: 'keyUsage', keyCertSign: true, critical: true },
    { name: 'subjectKeyIdentifier' },
    { name: 'subjectAltName', altNames: [{ type: 2, value: 'localhost' }, { type: 7, ip: '127.0.0.1' }] }
  ]);

  // 3. توقيع الشهادة بمفتاحها الخاص (self-signed)
  cert.sign(keys.privateKey, forge.md.sha256.create());

  // 4. حفظ الملفات
  const certPem = forge.pki.certificateToPem(cert);
  const keyPem  = forge.pki.privateKeyToPem(keys.privateKey);

  fs.writeFileSync(certPath, certPem, 'utf-8');
  fs.writeFileSync(keyPath, keyPem, { encoding: 'utf-8', mode: 0o600 });
  console.log('[QZ Certs] Certificate saved to:', certPath);
  console.log('[QZ Certs] Private key saved to:', keyPath);

  // 5. نسخ الشهادة كـ override.crt
  _copyOverrideCert(certPath, qzTrayDir);

  // 5.5 Publish the public certificate for LAN browsers.
  _publishCertificateForBrowser(certPath);
  
  // 6. تشغيل التثبيت الصامت لشهادات الـ SSL الخاصة بـ QZ Tray
  await installSSLcertSilently(qzTrayDir, javaPath, flagPath);
}

/**
 * دالة التثبيت الصامت باستخدام صلاحيات المسؤول لتشغيل أمر certgen الخاص بـ QZ Tray
 */
function installSSLcertSilently(qzTrayDir, javaPath, flagPath) {
  return new Promise((resolve) => {
    const sharedRootCertificate = path.join(process.env.PROGRAMDATA || 'C:\\ProgramData', 'qz', 'ssl', 'root-ca.crt');
    const createdByThisInstall = !fs.existsSync(sharedRootCertificate);
    const sharedDirectory = path.dirname(path.dirname(sharedRootCertificate));
    const existingSharedFiles = new Set(createdByThisInstall ? listQzFiles(sharedDirectory) : []);
    // بناء المسارات
    const javaWinPath = javaPath.replace(/\//g, '\\');
    const qzTrayJarPath = path.join(qzTrayDir, 'qz-tray.jar').replace(/\//g, '\\');
    
    // تشغيل QZ Tray كمسؤول لتوليد وتثبيت الشهادة بصمت، ثم التأكيد بإضافتها للنظام كـ Enterprise Root
    // ثم استخدام icacls لمنح صلاحيات القراءة والكتابة لجميع المستخدمين على مجلدات QZ لتجنب AccessDeniedException
    const qzWinDir = qzTrayDir.replace(/\//g, '\\');
    // QZ only needs read/execute access after this elevated setup. Granting
    // Everyone full control would let an unprivileged user replace keys or
    // executable files.
    const command = `"${javaWinPath}" -jar "${qzTrayJarPath}" certgen --host localhost && certutil -addstore -enterprise -f root "C:\\ProgramData\\qz\\ssl\\root-ca.crt" && icacls "C:\\ProgramData\\qz" /inheritance:e /grant:r "*S-1-5-32-545:(OI)(CI)RX" /T && icacls "${qzWinDir}" /inheritance:e /grant:r "*S-1-5-32-545:(OI)(CI)RX" /T`;
    
    const options = {
      name: 'POS System Installer'
    };

    console.log('[QZ Certs] Requesting elevation for QZ Tray silent SSL install...');
    sudo.exec(command, options, (error, stdout, stderr) => {
      if (error) {
        console.error('[QZ Certs] Failed to install SSL silently (User might have cancelled UAC):', error.message);
        try { fs.writeFileSync(flagPath, 'skipped', 'utf-8'); } catch {}
        resolve(false);
      } else {
        // Record ownership only when POS created the shared QZ trust. An
        // independent QZ installation must survive removal of POS.
        if (createdByThisInstall && fs.existsSync(sharedRootCertificate)) {
          const certificate = new crypto.X509Certificate(fs.readFileSync(sharedRootCertificate));
          fs.writeFileSync(path.join(getCertsDir(), '..', 'qz-shared-install.json'), JSON.stringify({
            rootSha256: certificate.fingerprint256.replace(/:/g, '').toLowerCase(),
            rootCertificateBase64: certificate.raw.toString('base64'),
            files: listQzFiles(sharedDirectory).filter(file => !existingSharedFiles.has(file)).map(file => ({
              path: file,
              sha256: crypto.createHash('sha256').update(fs.readFileSync(path.join(sharedDirectory, file))).digest('hex'),
            })),
          }));
        }
        console.log('[QZ Certs] SSL cert installed silently successfully.');
        fs.writeFileSync(flagPath, 'installed', 'utf-8');
        resolve(true);
      }
    });
  });
}

/**
 * ينسخ الشهادة كـ override.crt بجانب qz-tray.jar
 * حتى يثق بها QZ Tray تلقائياً.
 */
function _copyOverrideCert(certPath, qzTrayDir) {
  try {
    const overridePath = path.join(qzTrayDir, 'override.crt');
    fs.copyFileSync(certPath, overridePath);
    console.log('[QZ Certs] override.crt copied to:', overridePath);
  } catch (err) {
    console.warn('[QZ Certs] Could not copy override.crt:', err.message);
  }
}

/**
 * Publish only the public certificate for browser clients.
 * The private key stays in Electron userData and is passed to PHP through
 * QZ_PRIVATE_KEY_PATH; it must never be copied into an HTTP document root.
 */
function _publishCertificateForBrowser(certPath) {
  const { getBackendDir } = require('../utils/paths');
  const backendDir = getBackendDir();
  // Resolve project root from backend dir
  const projectRoot = path.resolve(backendDir, '..');

  // 1. Copy cert to frontend/dist/digital-certificate.txt
  try {
    const distDir = path.join(projectRoot, 'frontend', 'dist');
    if (fs.existsSync(distDir)) {
      const destCert = path.join(distDir, 'digital-certificate.txt');
      fs.copyFileSync(certPath, destCert);
      console.log('[QZ Certs] Certificate published to:', destCert);
    }
  } catch (err) {
    console.warn('[QZ Certs] Could not publish certificate to frontend/dist:', err.message);
  }

}

/**
 * يُرجع محتوى الشهادة كـ string (PEM).
 * يُستدعى عبر IPC من الـ Frontend.
 */
function getQZCertificate() {
  const certPath = path.join(getCertsDir(), 'digital-certificate.pem');
  if (fs.existsSync(certPath)) {
    return fs.readFileSync(certPath, 'utf-8');
  }
  return null;
}

/**
 * يوقّع رسالة QZ Tray بالمفتاح الخاص.
 * يُستدعى عبر IPC من الـ Frontend.
 *
 * @param {string} toSign - النص المراد توقيعه
 * @returns {string|null} - التوقيع بصيغة Base64، أو null عند الفشل
 */
function normalizeQZDigest(toSign) {
  if (typeof toSign !== 'string') return null;
  const digest = toSign.trim().toLowerCase();
  return /^[a-f0-9]{64}$/.test(digest) ? digest : null;
}

function signQZMessage(toSign) {
  const digest = normalizeQZDigest(toSign);
  if (!digest) {
    console.warn('[QZ Certs] Rejected a non-digest signing payload');
    return null;
  }

  const keyPath = getQZPrivateKeyPath();
  if (!fs.existsSync(keyPath)) return null;

  try {
    const keyPem = fs.readFileSync(keyPath, 'utf-8');
    
    // استخدام مكتبة crypto المدمجة للتوقيع بشكل مطابق لـ openssl_sign في PHP
    const sign = crypto.createSign('SHA512');
    sign.update(digest, 'utf8');
    sign.end();
    
    return sign.sign(keyPem, 'base64');
  } catch (err) {
    console.error('[QZ Certs] Signing failed:', err.message);
    return null;
  }
}

module.exports = {
  ensureQZCerts,
  getQZCertificate,
  getQZPrivateKeyPath,
  normalizeQZDigest,
  signQZMessage,
};
