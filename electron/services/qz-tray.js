const net = require('net');
const { formatSpawnError, spawnRuntimeProcess } = require('../utils/runtime-process');

let qzProcess = null;

/**
 * تشغيل QZ Tray كـ child process.
 * QZ Tray يستمع على WebSocket port 8182 (insecure) و 8181 (secure).
 * يعمل بوضع headless (بدون System Tray icon) لأنه مدمج مع Electron.
 *
 * @returns {Promise<void>}
 */
function startQZTray() {
  return new Promise((resolve, reject) => {
    const { getJavaPath, getQZTrayPath } = require('../utils/paths');

    let javaPath;
    let qzTrayJar;

    try {
      javaPath = getJavaPath();
      qzTrayJar = getQZTrayPath();
    } catch (err) {
      // QZ Tray اختياري — لا نمنع التطبيق من العمل بدونه
      console.warn('[QZ Tray]', err.message);
      resolve();
      return;
    }

    const path = require('path');
    const qzTrayDir = path.dirname(qzTrayJar);

    console.log('[QZ Tray] Starting...', { javaPath, qzTrayJar, qzTrayDir });

    try {
      qzProcess = spawnRuntimeProcess(javaPath, [
        '-Xms64m',
        '-Xmx256m',
        '-Djna.nosys=true',
        '-jar', qzTrayJar,
        '--headless',    // بدون System Tray icon (لأن Electron له tray خاص)
      ], {
        windowsHide: true,
        cwd: qzTrayDir,
        env: { ...process.env },
        stdio: ['ignore', 'pipe', 'pipe'],
      });
    } catch (err) {
      console.warn('[QZ Tray]', formatSpawnError(err, { executable: javaPath, cwd: qzTrayDir }));
      resolve();
      return;
    }

    qzProcess.stdout.on('data', (data) => {
      console.log('[QZ Tray]', data.toString().trim());
    });

    qzProcess.stderr.on('data', (data) => {
      console.log('[QZ Tray]', data.toString().trim());
    });

    qzProcess.on('error', (err) => {
      console.warn('[QZ Tray] Process error:', formatSpawnError(err, { executable: javaPath, cwd: qzTrayDir }));
      clearInterval(check);
      qzProcess = null;
      resolve();
    });

    qzProcess.on('exit', (code) => {
      console.log('[QZ Tray] Process exited with code:', code);
      qzProcess = null;
    });

    // انتظار جاهزية QZ Tray — نحاول الاتصال بـ WebSocket port
    // QZ Tray يستمع افتراضياً على port 8182 (insecure)
    // نحاول كل 500ms لمدة 15 ثانية (30 محاولة)
    let attempts = 0;
    const maxAttempts = 30;
    const check = setInterval(() => {
      attempts++;
      const sock = new net.Socket();
      sock.setTimeout(500);
      sock.connect(8182, '127.0.0.1', () => {
        sock.destroy();
        clearInterval(check);
        console.log('[QZ Tray] Ready on port 8182');
        resolve();
      });
      sock.on('error', () => { sock.destroy(); });
      sock.on('timeout', () => { sock.destroy(); });

      if (attempts >= maxAttempts) {
        clearInterval(check);
        console.warn('[QZ Tray] Timeout waiting for QZ Tray — continuing without it');
        // لا نستخدم reject — QZ Tray اختياري
        resolve();
      }
    }, 500);
  });
}

/**
 * إيقاف QZ Tray.
 */
function stopQZTray() {
  if (qzProcess) {
    console.log('[QZ Tray] Stopping...');
    qzProcess.kill();
    qzProcess = null;
  }
}

module.exports = { startQZTray, stopQZTray };
