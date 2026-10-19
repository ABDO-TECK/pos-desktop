const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const net = require('net');
const crypto = require('crypto');
const os = require('os');

let proxyServer = null;
let proxyListenHost = null;
let proxyListenPort = null;
let lanEnablePromise = null;

function buildProxyHeaders(requestHeaders, remoteAddress, httpsPort) {
  const clientIp = typeof remoteAddress === 'string' && net.isIP(remoteAddress) !== 0
    ? remoteAddress
    : 'unknown';
  const headers = {
    ...requestHeaders,
    'x-forwarded-for': clientIp,
    'x-real-ip': clientIp,
    'x-forwarded-proto': 'https',
    'x-forwarded-port': String(httpsPort),
  };

  // Never propagate a second client-controlled forwarding mechanism.
  delete headers.forwarded;

  return headers;
}

/**
 * Get the SSL directory path. Always use Electron's writable userData
 * directory when running inside the app so development and packaged builds
 * can refresh a LAN certificate without modifying files in the source tree or
 * the read-only app.asar archive.
 */
function getSslDir() {
  try {
    const { app } = require('electron');
    if (app && typeof app.getPath === 'function') {
      return path.join(app.getPath('userData'), 'ssl');
    }
  } catch (e) {
    // Electron is unavailable when this module is exercised in isolation.
  }
  return path.join(__dirname, '..', 'assets', 'ssl');
}

function getCertificateAltNames() {
  const altNames = [
    { type: 2, value: 'localhost' },
    { type: 7, ip: '127.0.0.1' },
  ];
  const seen = new Set(altNames.map((entry) => entry.ip || entry.value));

  for (const addresses of Object.values(os.networkInterfaces())) {
    for (const address of addresses || []) {
      const family = address.family;
      if ((family !== 'IPv4' && family !== 4) || address.internal || !net.isIP(address.address)) {
        continue;
      }
      if (!seen.has(address.address)) {
        seen.add(address.address);
        altNames.push({ type: 7, ip: address.address });
      }
    }
  }

  return altNames;
}

function certificateHasRequiredAltNames(certPath) {
  try {
    const forge = require('node-forge');
    const certificate = forge.pki.certificateFromPem(fs.readFileSync(certPath, 'utf8'));
    const extension = certificate.extensions?.find((item) => item.name === 'subjectAltName');
    if (!extension || !Array.isArray(extension.altNames)) return false;

    const names = new Set(
      extension.altNames.map((entry) => entry.type === 7 ? entry.ip : entry.value).filter(Boolean)
    );
    return getCertificateAltNames().every((entry) => names.has(entry.ip || entry.value));
  } catch {
    return false;
  }
}

function generateCertificate(certPath, keyPath) {
  try {
    const forge = require('node-forge');
    console.log('[HTTPS] Generating new self-signed certificate...');
    
    const keys = forge.pki.rsa.generateKeyPair(2048);
    const cert = forge.pki.createCertificate();
    
    cert.publicKey = keys.publicKey;
    cert.serialNumber = '01';
    cert.validity.notBefore = new Date();
    cert.validity.notAfter = new Date();
    cert.validity.notAfter.setFullYear(cert.validity.notBefore.getFullYear() + 10);
    
    const attrs = [{ name: 'commonName', value: 'POS Local Server' }];
    cert.setSubject(attrs);
    cert.setIssuer(attrs);

    cert.setExtensions([
      { name: 'basicConstraints', cA: true },
      { name: 'keyUsage', keyCertSign: true, digitalSignature: true, keyEncipherment: true },
      { name: 'subjectAltName', altNames: getCertificateAltNames() },
      { name: 'subjectKeyIdentifier' },
    ]);
    
    cert.sign(keys.privateKey);
    
    const pemCert = forge.pki.certificateToPem(cert);
    const pemKey = forge.pki.privateKeyToPem(keys.privateKey);
    
    // Ensure directory exists
    const dir = path.dirname(certPath);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }
    
    fs.writeFileSync(certPath, pemCert);
    fs.writeFileSync(keyPath, pemKey);
    console.log('[HTTPS] Certificate generated successfully at:', dir);
  } catch (err) {
    console.error('[HTTPS] Failed to generate certificate:', err.message);
  }
}

function resolveListenHost(requestedHost) {
  if (requestedHost === '127.0.0.1' || requestedHost === '0.0.0.0') {
    return requestedHost;
  }
  return process.env.POS_LAN_ENABLED === 'true' ? '0.0.0.0' : '127.0.0.1';
}

function getHttpsProxyInfo() {
  return {
    running: Boolean(proxyServer?.listening),
    host: proxyListenHost,
    port: proxyListenPort,
    lanEnabled: proxyListenHost === '0.0.0.0',
  };
}

function closeProxyServer() {
  return new Promise((resolve) => {
    const server = proxyServer;
    proxyServer = null;
    proxyListenHost = null;
    proxyListenPort = null;

    if (!server) {
      resolve();
      return;
    }

    if (!server.listening) {
      resolve();
      return;
    }

    server.close(() => resolve());
  });
}

function startHttpsProxy(phpPort, httpsPort, startOptions = {}) {
  return new Promise((resolve) => {
    const sslDir = getSslDir();
    const keyPath = path.join(sslDir, 'server.key');
    const certPath = path.join(sslDir, 'server.crt');
    
    console.log('[HTTPS] SSL directory:', sslDir);

    // Generate or refresh the certificate when it lacks IP SANs. Browsers
    // reject a LAN HTTPS certificate that only has a common name.
    if (
      !fs.existsSync(keyPath)
      || !fs.existsSync(certPath)
      || !certificateHasRequiredAltNames(certPath)
    ) {
      generateCertificate(certPath, keyPath);
    }

    try {
      const tlsOptions = {
        key: fs.readFileSync(keyPath),
        cert: fs.readFileSync(certPath)
      };

      proxyServer = https.createServer(tlsOptions, (req, res) => {
        // Fix: Preserve the original Host header from the client,
        // and forward the request to the PHP backend with the correct host.
        // This prevents cookie domain mismatches that cause refresh loops.
        const headers = buildProxyHeaders(
          req.headers,
          req.socket.remoteAddress,
          httpsPort
        );
        // Keep original Host header so PHP sees the correct domain

        const proxyReq = http.request({
          hostname: '127.0.0.1',
          port: phpPort,
          path: req.url,
          method: req.method,
          headers: headers
        }, (proxyRes) => {
          // Fix Set-Cookie headers: remove Secure flag (PHP may add it
          // because it sees HTTPS, but the self-signed cert isn't trusted)
          // and ensure SameSite=None for cross-port cookies
          const responseHeaders = { ...proxyRes.headers };
          if (responseHeaders['set-cookie']) {
            responseHeaders['set-cookie'] = (Array.isArray(responseHeaders['set-cookie'])
              ? responseHeaders['set-cookie']
              : [responseHeaders['set-cookie']]
            ).map(cookie => {
              // Remove Secure flag that PHP might add since we're behind HTTPS proxy
              // but the cert is self-signed / not trusted by mobile browsers
              let c = cookie;
              // Ensure SameSite=None so cookies work cross-port
              if (!/samesite/i.test(c)) {
                c = c + '; SameSite=None';
              }
              // Ensure Secure flag is present when SameSite=None (required by browsers)
              if (/samesite=none/i.test(c) && !/;\s*secure/i.test(c)) {
                c = c + '; Secure';
              }
              return c;
            });
          }

          res.writeHead(proxyRes.statusCode, responseHeaders);
          proxyRes.pipe(res, { end: true });
        });

        req.pipe(proxyReq, { end: true });
        
        proxyReq.on('error', (e) => {
          const correlationId = crypto.randomUUID();
          console.error('[HTTPS proxy]', correlationId, e.message);
          if (!res.headersSent) {
            res.writeHead(502, { 'Content-Type': 'text/plain' });
          }
          res.end(`Proxy error (${correlationId})`);
        });
      });

      const listenHost = resolveListenHost(startOptions.listenHost);
      const onError = (error) => {
        proxyServer = null;
        proxyListenHost = null;
        proxyListenPort = null;
        console.error('[HTTPS] Could not bind proxy:', error.message);
        resolve({ running: false, host: listenHost, port: httpsPort, error: error.message });
      };
      proxyServer.once('error', onError);
      proxyServer.listen(httpsPort, listenHost, () => {
        proxyServer.off('error', onError);
        proxyListenHost = listenHost;
        proxyListenPort = httpsPort;
        console.log(`[HTTPS] Proxy running on ${listenHost}:${httpsPort}`);
        resolve(getHttpsProxyInfo());
      });
    } catch (err) {
      console.error('[HTTPS] Could not start proxy:', err.message);
      resolve({ running: false, host: null, port: null, error: err.message }); // resolve anyway so app continues
    }
  });
}

/**
 * Rebind the already-running local proxy to all interfaces after the user
 * explicitly opens the phone/network access section. This keeps the desktop
 * application loopback-only until LAN access is requested.
 */
async function enableLanAccess(phpPort, httpsPort = 8443) {
  if (lanEnablePromise) return lanEnablePromise;

  lanEnablePromise = (async () => {
    const current = getHttpsProxyInfo();
    if (current.running && current.lanEnabled && current.port === httpsPort) {
      return current;
    }

    await closeProxyServer();
    return startHttpsProxy(phpPort, httpsPort, { listenHost: '0.0.0.0' });
  })();

  try {
    return await lanEnablePromise;
  } finally {
    lanEnablePromise = null;
  }
}

function stopHttpsProxy() {
  return closeProxyServer();
}

module.exports = {
  buildProxyHeaders,
  enableLanAccess,
  getHttpsProxyInfo,
  resolveListenHost,
  startHttpsProxy,
  stopHttpsProxy,
};
