const { app, BrowserWindow, Tray, Menu, nativeImage, ipcMain, protocol, safeStorage, dialog } = require('electron');
const path = require('path');
const fs = require('fs');
const { configureReceiptPrintWindows } = require('./utils/receipt-print-window');

// Register app scheme as privileged before app ready
protocol.registerSchemesAsPrivileged([
  {
    scheme: 'app',
    privileges: {
      standard: true,
      secure: true,
      supportFetchAPI: true,
      corsEnabled: true
    }
  }
]);

const { startPHP, stopPHP, runDatabaseMigrations } = require('./services/php-server');
const { startMySQL, stopMySQL, resetDatabase } = require('./services/mysql-server');
const { setupAutoUpdater } = require('./services/auto-updater');
const { assessReleaseCompatibility } = require('./utils/release-version-policy');
const {
  enableLanAccess,
  startHttpsProxy,
  stopHttpsProxy,
} = require('./services/https-proxy');
const { startQZTray, stopQZTray } = require('./services/qz-tray');
const { ensureQZCerts, getQZCertificate, signQZMessage } = require('./services/qz-certs');
const { configureFirewall, removeFirewall } = require('./services/firewall');
const { getPhpRuntimeArgs, resolveSystemTimeZone } = require('./utils/php-runtime');
const { serializeRuntimeError } = require('./utils/runtime-error');
const { formatSpawnError, spawnRuntimeProcess } = require('./utils/runtime-process');
const {
  getCookieHeader,
  isTrustedBackendRequest,
  COOKIE_PATHS,
} = require('./utils/cookie-proxy-policy');
const {
  acquireUpdateLock,
  isActiveUpdateState,
  readUpdateState,
  releaseUpdateLock,
  writeUpdateState,
} = require('./utils/update-coordination');

// Disable code signing auto-discovery to prevent build issues
process.env.CSC_IDENTITY_AUTO_DISCOVERY = 'false';

let mainWindow = null;
let tray = null;
let phpPort = 8080;
let mysqlPort = 3307; // Bundled MySQL port
let dbCredentials = null;
let lastStartupError = null;
let splash = null;
let jobWorkerProcess = null;
let firstRunAdminCredentials = null;
let sessionCookies = {};
const PERSISTED_COOKIE_NAMES = new Set(Object.keys(COOKIE_PATHS));

function assertTrustedAppRenderer(event) {
  const senderUrl = event.senderFrame?.url || '';
  if (!senderUrl.startsWith('app://pos-app/')) {
    throw new Error('Untrusted renderer');
  }
}

function assertRecoveryRenderer(event) {
  const senderUrl = event.senderFrame?.url || '';
  if (!senderUrl.startsWith('file://') || !senderUrl.endsWith('/recovery.html')) {
    throw new Error('Untrusted recovery renderer');
  }
}

async function stopJobWorker(options = {}) {
  const worker = jobWorkerProcess;
  if (!worker) {
    return;
  }
  const { stopPhpServerAwaitable, isProcessAlive } = require('./services/php-server');
  if (!isProcessAlive(worker)) {
    jobWorkerProcess = null;
    return;
  }
  const timeoutMs = options.timeoutMs || 3000;
  await stopPhpServerAwaitable({ timeoutMs, processRef: worker });
  jobWorkerProcess = null;
}

/**
 * Start the worker as an Electron-owned child. Keeping this in one helper is
 * important because database restore temporarily stops both PHP and the
 * worker, then must bring the exact same runtime back online.
 */
function startJobWorker() {
  const { isProcessAlive } = require('./services/php-server');
  if (jobWorkerProcess && isProcessAlive(jobWorkerProcess)) {
    return;
  }
  if (!dbCredentials) {
    throw new Error('Database credentials are not available for the job worker');
  }

  const {
    getPhpPath,
    getBackendDir,
    getDataDir,
    getLogsDir,
    getTempDir,
    getEnvPath,
    isPackaged,
  } = require('./utils/paths');
  const { createBackendEnv } = require('./services/php-server');
  const phpPath = getPhpPath();
  const backendDir = getBackendDir();
  const phpRuntimeArgs = getPhpRuntimeArgs(phpPath, getTempDir());
  const workerArgs = isPackaged()
    ? [...phpRuntimeArgs, path.join(backendDir, 'backend.phar'), 'process-jobs', '--daemon']
    : [...phpRuntimeArgs, path.join(backendDir, 'cli', 'process-jobs.php'), '--daemon'];
  const workerEnv = {
    ...createBackendEnv({ mysqlPort, dbCredentials, apiPort: phpPort }),
    APP_TIMEZONE: resolveSystemTimeZone(),
    APP_STORAGE_DIR: getDataDir(),
    ENV_PATH: getEnvPath(),
    LOGS_PATH: getLogsDir(),
  };

  const worker = spawnRuntimeProcess(phpPath, workerArgs, {
    cwd: backendDir,
    stdio: 'ignore',
    detached: false,
    windowsHide: true,
    env: workerEnv,
  });
  jobWorkerProcess = worker;
  worker.on('error', (err) => console.warn('[JobWorker] Failed to start:', formatSpawnError(err, {
    executable: phpPath,
    args: workerArgs,
    cwd: backendDir,
  })));
  worker.on('exit', () => {
    if (jobWorkerProcess === worker) jobWorkerProcess = null;
  });
}

function runBackendCli(args, input = null) {
  const {
    getPhpPath,
    getBackendDir,
    getTempDir,
    isPackaged,
  } = require('./utils/paths');
  const { createBackendEnv } = require('./services/php-server');
  if (!dbCredentials) {
    return Promise.reject(new Error('Database credentials are not available'));
  }

  const phpPath = getPhpPath();
  const backendDir = getBackendDir();
  const runtimeArgs = getPhpRuntimeArgs(phpPath, getTempDir());
  const entryArgs = isPackaged()
    ? [path.join(backendDir, 'backend.phar'), ...args]
    : [path.join(backendDir, 'cli', `${args[0]}.php`), ...args.slice(1)];
  const commandArgs = [...runtimeArgs, ...entryArgs];
  const env = createBackendEnv({ mysqlPort, dbCredentials, apiPort: phpPort });

  return new Promise((resolve, reject) => {
    let child;
    try {
      child = spawnRuntimeProcess(phpPath, commandArgs, {
        cwd: backendDir,
        env,
        windowsHide: true,
        stdio: ['pipe', 'pipe', 'pipe'],
      });
    } catch (error) {
      reject(error);
      return;
    }
    let stdout = '';
    let stderr = '';
    const maxOutput = 12 * 1024;
    child.stdout.on('data', (data) => {
      stdout = (stdout + data.toString()).slice(-maxOutput);
    });
    child.stderr.on('data', (data) => {
      stderr = (stderr + data.toString()).slice(-maxOutput);
    });
    child.once('error', (error) => reject(new Error(formatSpawnError(error, {
      executable: phpPath,
      args: commandArgs,
      cwd: backendDir,
    }))));
    child.once('close', (code, signal) => {
      if (code === 0) {
        resolve({ stdout, stderr });
        return;
      }
      const detail = formatBackendCliFailure(stdout, stderr)
        || `exit code ${code}${signal ? `, signal ${signal}` : ''}`;
      reject(new Error(detail.slice(-2000)));
    });
    if (input !== null) {
      child.stdin.end(input);
    } else {
      child.stdin.end();
    }
  });
}

function runPrivilegedBackendCli(args, input = null) {
  const { runPrivilegedBackendCli: runPrivilegedCli } = require('./services/privileged-cli');
  return runPrivilegedCli(args, {
    mysqlPort,
    dbCredentials,
    phpPort,
    input,
  });
}

function validateDesktopSqlPath(filePath) {
  if (typeof filePath !== 'string' || filePath.trim() === '') {
    throw new Error('No backup file was selected');
  }
  const resolvedPath = fs.realpathSync(path.resolve(filePath));
  if (path.extname(resolvedPath).toLowerCase() !== '.sql') {
    throw new Error('The selected file must have a .sql extension');
  }
  const stat = fs.statSync(resolvedPath);
  try {
    fs.accessSync(resolvedPath, fs.constants.R_OK);
  } catch {
    throw new Error('The selected backup file cannot be read');
  }
  if (!stat.isFile()) {
    throw new Error('The selected backup file cannot be read');
  }
  if (stat.size > 50 * 1024 * 1024) {
    throw new Error('The backup file exceeds the 50 MB limit');
  }
  return resolvedPath;
}

async function restartPhpAndWorker(options = {}) {
  const { startWorker = true, startupMigrations } = options;
  const phpServer = require('./services/php-server');
  const phpServerInfo = await phpServer.startPhpServer({
    preferredPort: phpPort,
    mysqlPort,
    dbCredentials,
    startupMigrations,
  });
  phpPort = phpServerInfo.port;
  await phpServer.waitForHealth(phpServerInfo.baseUrl, { maxTime: 30000 });
  if (startWorker) startJobWorker();
  return phpServerInfo;
}

function parseBackendJson(stdout) {
  const lines = String(stdout || '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .reverse();
  for (const line of lines) {
    try {
      const parsed = JSON.parse(line);
      if (parsed && typeof parsed === 'object') return parsed;
    } catch {
      // CLI bootstrap output may include harmless PHP startup notices. Only
      // accept the final valid JSON object.
    }
  }
  throw new Error('The backend bootstrap returned an invalid response');
}

function formatBackendCliFailure(stdout, stderr) {
  const output = [stderr, stdout]
    .map((value) => String(value || '').trim())
    .filter(Boolean)
    .join('\n');
  const lines = output
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .reverse();

  for (const line of lines) {
    try {
      const parsed = JSON.parse(line);
      if (parsed && typeof parsed.error === 'string' && parsed.error.trim() !== '') {
        return parsed.error.trim();
      }
      if (parsed && typeof parsed.message === 'string' && parsed.message.trim() !== '') {
        return parsed.message.trim();
      }
    } catch {
      // PHP startup notices may surround the structured CLI error.
    }
  }

  return output;
}

async function initializeFreshRuntime({ seed = false } = {}) {
  if (seed) {
    await runBackendCli(['seed']);
  }

  const { stdout } = await runBackendCli(['initialize-admin']);
  const result = parseBackendJson(stdout);
  if (result.created === true) {
    if (typeof result.email !== 'string' || typeof result.password !== 'string' || !result.email || !result.password) {
      throw new Error('The backend returned incomplete administrator credentials');
    }
    firstRunAdminCredentials = {
      email: result.email,
      name: String(result.name || 'Administrator'),
      password: result.password,
      forcePasswordChange: result.force_password_change === true,
    };
  }
  return result;
}

function isPathInside(childPath, parentPath) {
  const child = path.resolve(childPath);
  const parent = path.resolve(parentPath);
  const relative = path.relative(parent, child);
  return relative !== '' && !relative.startsWith('..') && !path.isAbsolute(relative);
}

function clearDirectoryContents(directory, parentDirectory) {
  if (!isPathInside(directory, parentDirectory)) {
    throw new Error('Refusing to clear an unmanaged directory');
  }
  if (fs.existsSync(directory) && fs.lstatSync(directory).isSymbolicLink()) {
    throw new Error('Refusing to clear a symbolic-link directory');
  }
  fs.mkdirSync(directory, { recursive: true });
  for (const entry of fs.readdirSync(directory)) {
    const target = path.join(directory, entry);
    fs.rmSync(target, { recursive: true, force: true });
  }
}

function removeManagedFile(filePath, parentDirectory) {
  if (!isPathInside(filePath, parentDirectory)) {
    throw new Error('Refusing to remove an unmanaged file');
  }
  if (fs.existsSync(filePath)) {
    if (fs.lstatSync(filePath).isSymbolicLink()) {
      throw new Error('Refusing to remove a symbolic-link file');
    }
    fs.rmSync(filePath, { force: true });
  }
}

function clearFactoryResetRuntimeState() {
  const {
    getDataDir,
    getConfigDir,
    getLogsDir,
    getTempDir,
    getRuntimeMetadataPath,
    getRuntimePortsPath,
    getMigrationsFlagPath,
    getRecoveryAuthPath,
    getCookiesPath,
  } = require('./utils/paths');
  const dataDir = getDataDir();
  const configDir = getConfigDir();

  // Keep the directory itself and only remove app-managed runtime contents.
  // User-created backups are intentionally preserved for recovery.
  clearDirectoryContents(getLogsDir(), dataDir);
  clearDirectoryContents(getTempDir(), dataDir);
  removeManagedFile(getRuntimeMetadataPath(), configDir);
  removeManagedFile(getRuntimePortsPath(), configDir);
  removeManagedFile(getMigrationsFlagPath(), dataDir);
  removeManagedFile(getRecoveryAuthPath(), configDir);
  removeManagedFile(getCookiesPath(), configDir);
}

async function factoryResetDesktop() {
  if (process.env.POS_LAN_ENABLED === 'true') {
    throw new Error('Factory reset is available only while the desktop runtime is local');
  }

  const phpServer = require('./services/php-server');
  firstRunAdminCredentials = null;
  sessionCookies = {};
  await stopJobWorker();
  phpServer.stopPhpServer();
  await stopHttpsProxy();

  try {
    const resetInfo = await resetDatabase(mysqlPort);
    applyMysqlStartupInfo(resetInfo);
    clearFactoryResetRuntimeState();

    // Start PHP only while the schema is migrated and default data/admin are
    // recreated. The worker must not observe a half-reset database.
    await restartPhpAndWorker({ startWorker: false });
    await initializeFreshRuntime({ seed: true });
    startJobWorker();
    await startHttpsProxy(phpPort, 8443);
    await clearDesktopSession();
    await clearDesktopRendererStorage();

    if (mainWindow && !mainWindow.isDestroyed()) {
      await mainWindow.loadURL('app://pos-app/index.html');
    }
    return { success: true, adminSetupRequired: firstRunAdminCredentials !== null };
  } catch (error) {
    try {
      if (!phpServer.getPhpServerInfo()) {
        await restartPhpAndWorker();
      } else {
        startJobWorker();
      }
    } catch (restartError) {
      console.error('[Factory Reset] Failed to restart PHP after reset error:', restartError.message);
    }
    throw error;
  }
}

async function clearDesktopSession() {
  const { session } = require('electron');
  const { getCookiesPath } = require('./utils/paths');
  const cookieNames = ['pos_token', 'pos_refresh_token', 'XSRF-TOKEN'];
  for (const host of ['127.0.0.1', 'localhost']) {
    for (const name of cookieNames) {
      try {
        await session.defaultSession.cookies.remove(`http://${host}:${phpPort}`, name);
      } catch {
        // A cookie may not exist on one of the two local host aliases.
      }
    }
  }
  try {
    if (fs.existsSync(getCookiesPath())) fs.unlinkSync(getCookiesPath());
  } catch (error) {
    console.warn('[Session] Failed to remove persisted session cookies:', error.message);
  }
  sessionCookies = {};
}

async function clearDesktopRendererStorage() {
  const { session } = require('electron');
  // The renderer keeps the offline catalog and service-worker cache outside
  // the SQL database. Clear those stores so a factory reset cannot show
  // products or pending operations from the previous installation.
  await session.defaultSession.clearStorageData({
    storages: [
      'cookies',
      'indexdb',
      'localstorage',
      'serviceworkers',
      'cachestorage',
      'filesystem',
      'websql',
      'appcache',
    ],
  });
}

async function restoreDesktopBackup(filePath) {
  const {
    createRestoreError,
    RESTORE_STAGES,
    RESTORE_ERROR_CODES,
    RESTORE_PREFLIGHT_REASONS,
    executeRestoreStateMachine,
  } = require('./services/restore-manager');

  if (process.env.POS_LAN_ENABLED === 'true') {
    const crypto = require('node:crypto');
    throw createRestoreError(
      RESTORE_ERROR_CODES.VALIDATION_FAILED,
      'Database restore is available only while the desktop runtime is local',
      {
        stage: RESTORE_STAGES.VALIDATE,
        reason: RESTORE_PREFLIGHT_REASONS.LAN_ENABLED,
        recovered: false,
        reference: crypto.randomBytes(8).toString('hex'),
      }
    );
  }

  const phpServer = require('./services/php-server');
  if (!phpServer.getPhpServerInfo()) {
    const crypto = require('node:crypto');
    throw createRestoreError(
      RESTORE_ERROR_CODES.VALIDATION_FAILED,
      'The local PHP server is not running',
      {
        stage: RESTORE_STAGES.VALIDATE,
        reason: RESTORE_PREFLIGHT_REASONS.PHP_NOT_RUNNING,
        recovered: false,
        reference: crypto.randomBytes(8).toString('hex'),
      }
    );
  }

  const hooks = {
    stopWorker: async () => {
      await stopJobWorker();
    },
    stopPhp: () => {
      phpServer.stopPhpServer();
    },
    createSnapshot: async (recoveryId) => {
      const { stdout } = await runPrivilegedBackendCli(['create-restore-safety', recoveryId]);
      const parsed = parseBackendJson(stdout);
      return {
        ok: parsed.ok === true,
        backupPath: parsed.backup_path,
        recoveryId: parsed.recovery_id,
        error: parsed.error,
      };
    },
    runRestore: async (validatedPath) => {
      await runPrivilegedBackendCli(['restore-backup', validatedPath, '--skip-migrations']);
    },
    runRollback: async (backupPath, recoveryId) => {
      await runPrivilegedBackendCli(['restore-migration-safety', backupPath, recoveryId]);
      return { ok: true };
    },
    runMigrations: async () => {
      await runDatabaseMigrations({
        mysqlPort,
        dbCredentials,
        apiPort: phpPort,
        force: true,
      });
    },
    verifyDb: async () => {
      const { stdout } = await runPrivilegedBackendCli(['verify-database']);
      const parsed = parseBackendJson(stdout);
      return { ok: parsed.ok === true };
    },
    restartPhpAndWorker: async (options) => {
      if (phpServer.getPhpServerInfo()) phpServer.stopPhpServer();
      await restartPhpAndWorker(options);
    },
    clearSession: async () => {
      firstRunAdminCredentials = null;
      sessionCookies = {};
      await clearDesktopSession();
      await clearDesktopRendererStorage();
    },
    reloadWindow: async () => {
      if (mainWindow && !mainWindow.isDestroyed()) {
        await mainWindow.loadURL('app://pos-app/index.html');
      }
    },
    deleteSnapshot: async (backupPath) => {
      try {
        if (fs.existsSync(backupPath)) fs.unlinkSync(backupPath);
        const metaPath = backupPath + '.json';
        if (fs.existsSync(metaPath)) fs.unlinkSync(metaPath);
      } catch (cleanErr) {
        console.warn('[Restore] Failed to clean up safety snapshot file:', cleanErr.message);
      }
    },
    enterRecoveryMode: async (recoveryError) => {
      console.error('[Recovery] Entering Recovery Mode due to restore rollback failure:', recoveryError.message);
      captureStartupError(recoveryError, {
        stage: 'restore_rollback',
        attempts: 1,
      });
      await enterRecoveryMode(recoveryError);
    },
  };

  return await executeRestoreStateMachine(filePath, hooks);
}

function saveSessionCookies(cookiesPath, cookies) {
  if (!safeStorage.isEncryptionAvailable()) {
    throw new Error('Secure credential storage is unavailable');
  }

  const filtered = Object.fromEntries(
    Object.entries(cookies).filter(([name]) => PERSISTED_COOKIE_NAMES.has(name))
  );
  fs.writeFileSync(cookiesPath, safeStorage.encryptString(JSON.stringify(filtered)));
}

function loadSessionCookies(cookiesPath) {
  if (!fs.existsSync(cookiesPath)) return {};
  return JSON.parse(safeStorage.decryptString(fs.readFileSync(cookiesPath)));
}

function setSplashStatus(message) {
  if (!splash || splash.isDestroyed()) return;
  try {
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = ${JSON.stringify(message)}`
    ).catch(() => {});
  } catch {
    // The splash can be closing while a retry is cleaning up the runtime.
  }
}

function applyMysqlStartupInfo(info) {
  if (!info || typeof info !== 'object') {
    throw new Error('MySQL startup returned no runtime information');
  }
  const reportedPort = Number(info.port);
  if (!Number.isInteger(reportedPort) || reportedPort < 1 || reportedPort > 65535) {
    throw new Error('MySQL startup returned an invalid TCP port');
  }
  mysqlPort = reportedPort;
  dbCredentials = info;
  return info;
}

function getStartupUserMessage(error) {
  const code = error?.code;
  if (code === 'DATABASE_RESTORE_RECOVERY_FAILED') {
    return 'فشلت استعادة قاعدة البيانات وفشل التراجع التلقائي إلى النسخة السابقة. تم الحفاظ على نسخة الأمان للتدخل اليدوي.';
  }
  if (code === 'PHP_RUNTIME_DLL_MISSING') {
    return 'تعذر تشغيل مكوّن PHP لأن أحد مكونات تشغيل Windows المطلوبة غير مثبت أو مفقود. STATUS_DLL_NOT_FOUND (0xC0000135)';
  }
  if (code === 'RUNTIME_PHP_MISSING' || code === 'RUNTIME_EXECUTABLE_MISSING' || code === 'RUNTIME_PHP_SPAWN_FAILED') {
    return 'ملفات تشغيل PHP المضمنة مفقودة أو تعذر تشغيلها. أعد تثبيت نسخة POS الموثوقة؛ يجب أن تحتوي الحزمة على portable/php/php.exe.';
  }
  if (code === 'RUNTIME_MYSQL_MISSING' || code === 'RUNTIME_MYSQL_SPAWN_FAILED') {
    return 'ملفات تشغيل MariaDB المضمنة مفقودة أو تعذر تشغيلها. أعد تثبيت نسخة POS الموثوقة؛ يجب أن تحتوي الحزمة على portable/mysql/bin/mysqld.exe وmysql.exe.';
  }
  if (code === 'RUNTIME_BACKEND_MISSING' || code === 'RUNTIME_BACKEND_ENTRY_MISSING') {
    return 'حزمة النظام الخلفية غير مكتملة. أعد تثبيت نسخة POS الموثوقة.';
  }
  if (code === 'MYSQL_START_TIMEOUT' || code === 'MYSQL_PROCESS_EXITED' || code === 'MYSQL_PROCESS_ERROR') {
    return 'لم تصبح MariaDB جاهزة. أعد المحاولة مرة واحدة؛ وإذا استمرت المشكلة افتح التشخيص وراجع سجل mysql-server.log.';
  }
  if (code === 'PHP_SERVER_NOT_READY' || code === 'PHP_PROCESS_EXITED' || code === 'PHP_PROCESS_ERROR') {
    return 'لم يصبح خادم PHP جاهزًا. أعد المحاولة مرة واحدة؛ وإذا استمرت المشكلة افتح التشخيص وراجع سجل php-server.log.';
  }
  return error?.message || 'تعذر تشغيل مكونات نظام POS. افتح التشخيص لمعرفة التفاصيل.';
}

function captureStartupError(error, { stage = null, attempts = 0 } = {}) {
  const phpServer = require('./services/php-server');
  const mysqlServer = require('./services/mysql-server');
  let runtimeDiagnostics = null;
  try {
    runtimeDiagnostics = require('./utils/paths').getRuntimeDiagnostics();
  } catch (diagnosticError) {
    runtimeDiagnostics = { error: diagnosticError.message };
  }

  const serialized = serializeRuntimeError(error);
  const lastResponse = phpServer.getLastHealthResponse();
  lastStartupError = {
    ...serialized,
    userMessage: getStartupUserMessage(error),
    timestamp: new Date().toISOString(),
    startupStage: stage || error?.details?.stage || 'startup',
    apiBaseUrl: `http://127.0.0.1:${phpPort}`,
    mysqlPort,
    phpPort,
    attempts,
    failedChecks: lastResponse ? lastResponse.checks : null,
    lastHealthResult: lastResponse,
    lastPhpError: phpServer.getLastPhpError(),
    lastMysqlError: mysqlServer.getLastMysqlError(),
    runtimeDiagnostics,
  };
  return lastStartupError;
}

const NON_RETRYABLE_STARTUP_CODES = new Set([
  'DATABASE_RESTORE_RECOVERY_FAILED',
  'PHP_RUNTIME_DLL_MISSING',
  'RUNTIME_PHP_MISSING',
  'RUNTIME_MYSQL_MISSING',
  'RUNTIME_EXECUTABLE_MISSING',
  'RUNTIME_WORKING_DIRECTORY_MISSING',
  'RUNTIME_PHP_SPAWN_FAILED',
  'RUNTIME_MYSQL_SPAWN_FAILED',
  'RUNTIME_BACKEND_MISSING',
  'RUNTIME_BACKEND_ENTRY_MISSING',
  'RUNTIME_SCHEMA_MISSING',
  'PHP_DATABASE_CREDENTIALS_MISSING',
  'PHP_MIGRATION_FAILED',
  'MYSQL_DATA_DIRECTORY_INVALID',
  'MYSQL_INITIALIZATION_FAILED',
  'MYSQL_DATABASE_INIT_FAILED',
]);

async function stopRuntimeServices() {
  await stopJobWorker();
  stopPHP();
  stopQZTray();
  try {
    await stopHttpsProxy();
  } catch (error) {
    console.warn('[Runtime] Failed to stop HTTPS proxy during recovery:', error.message);
  }
  await stopMySQL();
}

async function startCoreRuntime({ maxAttempts = 2, healthTimeout = 60000, resetBeforeStart = false } = {}) {
  if (resetBeforeStart) await stopRuntimeServices();

  let lastError = null;
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    if (attempt > 1) await stopRuntimeServices();

    try {
      setSplashStatus('جاري تشغيل قاعدة البيانات...');
      const mysqlInfo = await startMySQL(mysqlPort, { maxStartupAttempts: 3 });
      applyMysqlStartupInfo(mysqlInfo);
      console.log(`[Main] MariaDB started successfully on port ${mysqlPort}`);

      setSplashStatus('جاري تشغيل الخادم...');
      const phpServerInfo = await startPHP(phpPort, mysqlPort, dbCredentials);
      phpPort = phpServerInfo.port;
      console.log(`[Main] PHP Server started successfully on port ${phpPort}`);

      setSplashStatus('التحقق من جاهزية النظام...');
      const { waitForHealth } = require('./services/php-server');
      await waitForHealth(phpServerInfo.baseUrl, { maxTime: healthTimeout });
      return { mysqlInfo, phpServerInfo };
    } catch (error) {
      lastError = error;
      captureStartupError(error, {
        stage: error?.details?.stage || 'startup',
        attempts: attempt,
      });
      console.error(`[Runtime] Startup attempt ${attempt}/${maxAttempts} failed:`, error.message);

      try {
        await stopRuntimeServices();
      } catch (cleanupError) {
        console.warn('[Runtime] Failed to clean up after startup failure:', cleanupError.message);
      }

      if (attempt >= maxAttempts || NON_RETRYABLE_STARTUP_CODES.has(error.code)) throw error;
      await new Promise((resolve) => setTimeout(resolve, 750 * attempt));
    }
  }

  throw lastError || new Error('POS runtime failed to start');
}

function startLogCleanup() {
  const {
    getPhpPath,
    getBackendDir,
    getDataDir,
    getEnvPath,
    getLogsDir,
    getTempDir,
    isPackaged,
  } = require('./utils/paths');
  const phpPath = getPhpPath();
  const backendDir = getBackendDir();
  const { createBackendEnv } = require('./services/php-server');
  const maintenanceEnv = {
    ...createBackendEnv({ mysqlPort, dbCredentials, apiPort: phpPort }),
    APP_TIMEZONE: resolveSystemTimeZone(),
    APP_STORAGE_DIR: getDataDir(),
    ENV_PATH: getEnvPath(),
    LOGS_PATH: getLogsDir(),
  };
  const phpRuntimeArgs = getPhpRuntimeArgs(phpPath, getTempDir());
  const cleanupArgs = isPackaged()
    ? [...phpRuntimeArgs, path.join(backendDir, 'backend.phar'), 'cleanup-logs']
    : [...phpRuntimeArgs, path.join(backendDir, 'cli', 'cleanup-logs.php')];

  try {
    const cleanupProcess = spawnRuntimeProcess(phpPath, cleanupArgs, {
      cwd: backendDir,
      stdio: 'ignore',
      detached: true,
      windowsHide: true,
      env: maintenanceEnv,
    });
    cleanupProcess.on('error', (error) => console.warn('[LogCleanup] Failed to start:', formatSpawnError(error, {
      executable: phpPath,
      args: cleanupArgs,
      cwd: backendDir,
    })));
    cleanupProcess.unref();
  } catch (error) {
    console.warn('[LogCleanup] Failed to start:', formatSpawnError(error, {
      executable: phpPath,
      args: cleanupArgs,
      cwd: backendDir,
    }));
  }
}

function startQZBackgroundService() {
  (async () => {
    try {
      setSplashStatus('جاري تشغيل خدمة الطباعة...');
      const { getJavaPath, getQZTrayPath } = require('./utils/paths');
      const qzTrayDir = path.dirname(getQZTrayPath());
      await ensureQZCerts(qzTrayDir, getJavaPath());
      await startQZTray();
    } catch (error) {
      console.warn('[QZ Service] Background startup warning:', error.message);
    }
  })();
}

async function startPostStartupServices() {
  setSplashStatus('جاري تهيئة قاعدة البيانات...');
  await initializeFreshRuntime({ seed: dbCredentials.freshInstall === true });
  await startHttpsProxy(phpPort, 8443);
  startLogCleanup();
  startJobWorker();
  startQZBackgroundService();
}

function createMainWindow() {
  const window = new BrowserWindow({
    width: 1280, height: 800,
    minWidth: 1024, minHeight: 600,
    icon: path.join(__dirname, 'assets', 'icon.png'),
    titleBarStyle: 'hidden',
    titleBarOverlay: {
      color: '#1a1d2e',
      symbolColor: '#f3f4f6',
      height: 36,
    },
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      webSecurity: true,
      sandbox: true,
    },
  });

  configureReceiptPrintWindows(window.webContents);
  window.webContents.on('will-navigate', (event, url) => {
    if (!url.startsWith('app://pos-app/')) event.preventDefault();
  });
  window.setMenu(null);
  window.maximize();
  window.webContents.on('before-input-event', (event, input) => {
    if (input.key === 'F5' || (input.control && input.key.toLowerCase() === 'r')) {
      event.preventDefault();
      window.webContents.reload();
    }
    if (input.control && ['+', '=', '-', '0'].includes(input.key)) {
      event.preventDefault();
      const currentZoom = window.webContents.getZoomFactor();
      if (input.key === '+' || input.key === '=') window.webContents.setZoomFactor(currentZoom + 0.1);
      else if (input.key === '-') window.webContents.setZoomFactor(currentZoom - 0.1);
      else window.webContents.setZoomFactor(1.0);
    }
  });
  window.on('close', (event) => {
    if (!forceQuit) {
      event.preventDefault();
      window.hide();
    }
  });
  return window;
}

async function showMainApplication() {
  if (mainWindow && !mainWindow.isDestroyed()) mainWindow.destroy();
  mainWindow = createMainWindow();
  await mainWindow.loadURL('app://pos-app/index.html');
  if (splash && !splash.isDestroyed()) splash.close();
  if (!tray || tray.isDestroyed?.()) {
    tray = null;
    createTray();
  }
  setupAutoUpdater(mainWindow);
}

// منع تشغيل أكثر من نسخة
const gotTheLock = app.requestSingleInstanceLock();
if (!gotTheLock) { app.quit(); }

app.on('second-instance', () => {
  if (!mainWindow || mainWindow.isDestroyed()) return;
  if (mainWindow.isMinimized()) mainWindow.restore();
  // Closing the window normally hides it in the tray. A second launch from
  // the desktop shortcut must wake that hidden window before focusing it.
  if (!mainWindow.isVisible()) mainWindow.show();
  mainWindow.focus();
});

app.on('window-all-closed', () => {
  if (forceQuit || process.platform !== 'win32') {
    app.quit();
  }
});

app.whenReady().then(async () => {
  // ── Session Cookie Proxy ──
  const { session } = require('electron');
  const { getCookiesPath } = require('./utils/paths');

  // Restore session cookies from disk at startup
  try {
    const cookiesPath = getCookiesPath();
    if (fs.existsSync(cookiesPath)) {
      sessionCookies = loadSessionCookies(cookiesPath);
      console.log('[CookieProxy] Restored cookies from disk');
    }
  } catch (err) {
    console.error('[CookieProxy] Failed to restore cookies from disk:', err.message);
    try {
      const cookiesPath = getCookiesPath();
      if (fs.existsSync(cookiesPath) && fs.readFileSync(cookiesPath, 'utf8').trim().startsWith('{')) {
        fs.unlinkSync(cookiesPath);
        console.warn('[CookieProxy] Removed legacy plaintext cookie storage; sign-in is required.');
      }
    } catch {
      // A failed cleanup must not prevent application startup.
    }
  }

  session.defaultSession.webRequest.onHeadersReceived((details, callback) => {
    const responseHeaders = { ...details.responseHeaders };
    try {
      // 5. Origin guard
      const initiator = details.initiator || '';
      const trustedWebContentsId = mainWindow && !mainWindow.isDestroyed()
        ? mainWindow.webContents.id
        : null;
      if (isTrustedBackendRequest({
        url: details.url,
        phpPort,
        initiator,
        webContentsId: details.webContentsId,
        trustedWebContentsId,
        frameUrl: details.frame?.url,
      })) {
        // 1. Cookie capture scope (Only capture Set-Cookie from the local PHP backend runtime URL)
        let parsedUrl;
        try {
          parsedUrl = new URL(details.url);
        } catch (e) {
          parsedUrl = null;
        }

        if (parsedUrl) {
          const setCookieKey = Object.keys(responseHeaders).find(k => k.toLowerCase() === 'set-cookie');
          if (setCookieKey) {
            const setCookieHeaders = responseHeaders[setCookieKey];
            const cookies = Array.isArray(setCookieHeaders) ? setCookieHeaders : [setCookieHeaders];
            let hasChanged = false;
            cookies.forEach(c => {
              const parts = c.split(';')[0].split('=');
              if (parts.length === 2) {
                const name = parts[0].trim();
                const value = parts[1].trim();
                if (!PERSISTED_COOKIE_NAMES.has(name)) return;
                const isDelete = value === '' ||
                                 /expires=Thu, 01 Jan 1970/i.test(c) ||
                                 /Max-Age=0/i.test(c) ||
                                 /Max-Age=-/i.test(c);
                if (isDelete) {
                  if (sessionCookies[name] !== undefined) {
                    delete sessionCookies[name];
                    hasChanged = true;
                  }
                } else {
                  if (sessionCookies[name] !== value) {
                    sessionCookies[name] = value;
                    hasChanged = true;
                  }
                }
              }
            });
            if (hasChanged) {
              try {
                saveSessionCookies(getCookiesPath(), sessionCookies);
                console.log('[CookieProxy] Saved updated cookies to disk');
              } catch (writeErr) {
                console.error('[CookieProxy] Failed to write cookies to disk:', writeErr.message);
              }
            }
          }
        }
      }
    } catch (err) {
      // 7. Failure behavior: do not reload or throw, fallback gracefully
      // 4. Cookie logging: do not log raw cookies or session IDs
      console.error('[CookieProxy] Error capturing headers safely');
    }
    callback({ responseHeaders });
  });

  session.defaultSession.webRequest.onBeforeSendHeaders((details, callback) => {
    const requestHeaders = { ...details.requestHeaders };
    try {
      // 5. Origin guard
      const initiator = details.initiator || '';
      const trustedWebContentsId = mainWindow && !mainWindow.isDestroyed()
        ? mainWindow.webContents.id
        : null;
      if (isTrustedBackendRequest({
        url: details.url,
        phpPort,
        initiator,
        webContentsId: details.webContentsId,
        trustedWebContentsId,
        frameUrl: details.frame?.url,
      })) {
        // 2. Cookie injection scope (Only inject into outgoing requests targeting same local PHP backend URL)
        let parsedUrl;
        try {
          parsedUrl = new URL(details.url);
        } catch (e) {
          parsedUrl = null;
        }

        if (parsedUrl) {
            const cookieHeader = getCookieHeader(sessionCookies, parsedUrl.pathname);
            // Clear any existing cookie headers case-insensitively to avoid duplicates
            for (const key of Object.keys(requestHeaders)) {
              if (key.toLowerCase() === 'cookie') {
                delete requestHeaders[key];
              }
            }
            if (cookieHeader !== '') {
              requestHeaders['Cookie'] = cookieHeader;
            }
        }
      }
    } catch (err) {
      // 7. Failure behavior: fallback gracefully
      // 4. Cookie logging: do not log headers
      console.error('[CookieProxy] Error injecting headers safely');
    }
    callback({ requestHeaders });
  });

  // ── Register app:// protocol handler ──
  const { net } = require('electron');
  const url = require('url');

  protocol.handle('app', async (request) => {
    const requestUrl = new URL(request.url);
    let filePath = decodeURIComponent(requestUrl.pathname);
    
    // Standardize separators for Windows compatibility
    filePath = filePath.replace(/\\/g, '/');

    // Prevent path traversal
    if (filePath.includes('..') || filePath.includes('%2e%2e') || filePath.includes('\\')) {
      return new Response('Access Denied', { status: 403 });
    }

    const distDir = app.isPackaged
      ? path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'frontend', 'dist')
      : path.join(app.getAppPath(), 'frontend', 'dist');
    let targetFile = path.join(distDir, filePath);

    // Resolve and verify normalized target is within dist directory
    const normalizedTarget = path.normalize(targetFile);
    const normalizedDist = path.normalize(distDir);
    if (!normalizedTarget.startsWith(normalizedDist)) {
      return new Response('Access Denied', { status: 403 });
    }

    try {
      if (fs.existsSync(targetFile)) {
        const stat = fs.statSync(targetFile);
        if (stat.isFile()) {
          return net.fetch(url.pathToFileURL(targetFile).toString());
        }
      }
    } catch (err) {
      console.error('[Protocol] Error resolving file:', err.message);
    }

    // SPA fallback to index.html for unknown routes
    const indexHtmlPath = path.join(distDir, 'index.html');
    return net.fetch(url.pathToFileURL(indexHtmlPath).toString());
  });

  // ── Prepare Runtime Directories ──
  try {
    const { ensureRuntimeDirs } = require('./utils/paths');
    ensureRuntimeDirs();
  } catch (err) {
    console.error('[Paths] Failed to prepare runtime directories:', err);
  }

  // ── Run Runtime Migration ──
  try {
    const { runRuntimeMigration } = require('./utils/runtimeMigrator');
    const migrationResult = runRuntimeMigration({ dryRun: false, safeMode: true });
    if (migrationResult && migrationResult.success === false && migrationResult.error) {
      if (migrationResult.error.includes('ENOENT') || migrationResult.error.includes('EACCES') || migrationResult.error.includes('EPERM')) {
        throw new Error(`Critical migration foundation error: ${migrationResult.error}`);
      }
    }
  } catch (err) {
    console.error('[Migration] Critical failure during runtime migration check:', err.message);
    throw err;
  }

  // ── Configure Windows Firewall rules (production) ──
  if (process.env.POS_LAN_ENABLED === 'true') {
    configureFirewall().catch(err => console.error('[Firewall] Failed:', err));
  }

  ipcMain.handle('get-version', (event) => {
    assertTrustedAppRenderer(event);
    return app.getVersion();
  });
  ipcMain.handle('get-api-base-url', async (event) => {
    assertTrustedAppRenderer(event);
    try {
      const { getRuntimePortsPath } = require('./utils/paths');
      const fs = require('fs');
      const data = fs.readFileSync(getRuntimePortsPath(), 'utf8');
      const ports = JSON.parse(data);
      return ports.apiBaseUrl;
    } catch (err) {
      console.error('[IPC] Failed to read API base URL:', err.message);
      return null;
    }
  });
  ipcMain.handle('delta:get-capability', (event) => {
    assertTrustedAppRenderer(event);
    return { capable: true, protocol: 1 };
  });
  ipcMain.handle('delta:apply-staged', async (event, version) => {
    assertTrustedAppRenderer(event);
    if (typeof version !== 'string' || !/^[A-Za-z0-9._-]+$/.test(version)) {
      throw new Error('Invalid staged delta version');
    }
    const compatibility = assessReleaseCompatibility(app.getVersion(), version);
    if (!compatibility.compatible) {
      throw new Error(`Staged delta rejected: ${compatibility.reason}`);
    }

    const { getAppUnpackedPath, getDataDir } = require('./utils/paths');
    const { orchestrateDeltaInstall } = require('./services/delta-update-handoff');
    const { stopPhpServerAwaitable } = require('./services/php-server');
    const storageDir = getDataDir();
    const currentState = readUpdateState(storageDir);
    if (isActiveUpdateState(currentState) && currentState.state !== 'desktop_handoff_pending') {
      return {
        ok: false,
        reason_code: 'update_in_progress',
        error: 'Another update or sale operation is already in progress.',
        owner: currentState.owner_id || currentState.operation
          ? {
            owner_id: currentState.owner_id || null,
            operation: currentState.operation || 'unknown',
            phase: currentState.phase || null,
            updated_at: currentState.updated_at || null,
          }
          : null,
      };
    }
    const lease = acquireUpdateLock(storageDir, 'electron_delta_install', {
      target_version: version,
    });
    if (!lease.acquired) {
      return {
        ok: false,
        reason_code: lease.reason_code || 'update_in_progress',
        error: lease.message || 'Another update or sale operation is already in progress.',
        owner: lease.owner || null,
      };
    }
    try {
      const result = await orchestrateDeltaInstall({
        storageDir,
        version,
        expectedRoot: getAppUnpackedPath(),
        lease,
        hooks: {
          stopJobWorker,
          stopPHP: () => stopPhpServerAwaitable({ timeoutMs: 5000 }),
          runDatabaseMigrations: (migrations) => runDatabaseMigrations({
            mysqlPort,
            dbCredentials,
            apiPort: phpPort,
            migrations,
          }),
          restoreDatabaseSafety: (backupPath, recoveryId) => runPrivilegedBackendCli([
            'restore-migration-safety',
            backupPath,
            recoveryId,
          ]),
          restartPhpAndWorker: (opts = {}) => restartPhpAndWorker({
            startupMigrations: [],
            ...opts,
          }),
          writeUpdateState: (state, ctx) => writeUpdateState(storageDir, state, ctx),
          onSuccess: async () => {
            if (mainWindow && !mainWindow.isDestroyed()) {
              await mainWindow.loadURL('app://pos-app/index.html');
            }
          },
        },
      });
      return result;
    } finally {
      releaseUpdateLock(lease);
    }
  });
  ipcMain.handle('network:enable-lan', async (event) => {
    assertTrustedAppRenderer(event);
    try {
      const proxyInfo = await enableLanAccess(phpPort, 8443);
      if (!proxyInfo.running) {
        return {
          enabled: false,
          port: 8443,
          protocol: 'https',
          error: proxyInfo.error || 'Unable to bind the LAN HTTPS service',
        };
      }

      // Keep subsequent recovery/restart workers aligned with the explicit
      // user choice made from the network settings section.
      process.env.POS_LAN_ENABLED = 'true';
      const firewallConfigured = await configureFirewall();
      return {
        enabled: true,
        port: proxyInfo.port || 8443,
        protocol: 'https',
        firewallConfigured,
        firewallRequired: process.platform === 'win32' && !firewallConfigured,
      };
    } catch (err) {
      console.error('[LAN] Failed to enable phone access:', err.message);
      return {
        enabled: false,
        port: 8443,
        protocol: 'https',
        error: 'LAN access could not be enabled. Check the local firewall and try again.',
      };
    }
  });
  ipcMain.handle('network:disable-lan', async (event) => {
    assertTrustedAppRenderer(event);
    try {
      await stopHttpsProxy();
      process.env.POS_LAN_ENABLED = 'false';
      const firewallRemoved = await removeFirewall();
      return {
        enabled: false,
        port: 8443,
        protocol: 'https',
        firewallConfigured: !firewallRemoved,
        firewallRequired: !firewallRemoved,
      };
    } catch (err) {
      console.error('[LAN] Failed to disable phone access:', err.message);
      return {
        enabled: true,
        port: 8443,
        protocol: 'https',
        error: 'LAN access could not be disabled. Check the local firewall settings.',
      };
    }
  });
  ipcMain.handle('qz-get-cert', (event) => {
    assertTrustedAppRenderer(event);
    return getQZCertificate();
  });
  ipcMain.handle('qz-sign', (event, data) => {
    assertTrustedAppRenderer(event);
    if (
      typeof data !== 'string'
      || Buffer.byteLength(data, 'utf8') > 128
      || !/^[a-f0-9]{64}$/i.test(data.trim())
    ) {
      throw new TypeError('Invalid QZ signing payload');
    }
    return signQZMessage(data.trim().toLowerCase());
  });

  ipcMain.handle('setup:get-initial-admin', (event) => {
    assertTrustedAppRenderer(event);
    return firstRunAdminCredentials;
  });

  ipcMain.handle('setup:acknowledge-initial-admin', (event) => {
    assertTrustedAppRenderer(event);
    firstRunAdminCredentials = null;
    return { success: true };
  });

  ipcMain.handle('system:factory-reset', async (event, options) => {
    assertTrustedAppRenderer(event);
    if (!options || options.confirmationToken !== 'RESET_POS_DATA') {
      return { success: false, error: 'Invalid factory reset confirmation.' };
    }
    const confirmation = await dialog.showMessageBox(mainWindow, {
      type: 'warning',
      title: 'Factory reset',
      message: 'This permanently deletes the POS database and local session/cache data.',
      detail: 'Backup files are preserved. Continue only if you intend to start from a clean system.',
      buttons: ['Cancel', 'Reset everything'],
      defaultId: 0,
      cancelId: 0,
      noLink: true,
    });
    if (confirmation.response !== 1) {
      return { success: false, cancelled: true };
    }
    try {
      return await factoryResetDesktop();
    } catch (error) {
      console.error('[Factory Reset] Failed:', error.message);
      return {
        success: false,
        error: 'Factory reset failed. Check the error log for the reference.',
      };
    }
  });

  ipcMain.handle('backup:restore', async (event) => {
    assertTrustedAppRenderer(event);
    const selection = await dialog.showOpenDialog(mainWindow, {
      title: 'Select POS SQL backup',
      properties: ['openFile'],
      filters: [{ name: 'SQL backup', extensions: ['sql'] }],
    });
    if (selection.canceled || selection.filePaths.length === 0) {
      return { success: false, cancelled: true };
    }

    try {
      return await restoreDesktopBackup(selection.filePaths[0]);
    } catch (error) {
      const { formatRestoreFailure, classifyRestoreError } = require('./services/restore-manager');
      const failure = formatRestoreFailure(error);
      const diagnostics = classifyRestoreError(error);
      console.error(
        `[Backup] Desktop restore failed [${failure.reference}]: ` +
        `stage=${failure.stage || 'unknown'}, code=${failure.code}, recovered=${failure.recovered}, ` +
        `errorName=${diagnostics.name}, cause=${diagnostics.causeClassification || 'none'}` +
        (diagnostics.reason ? `, reason=${diagnostics.reason}` : '')
      );
      return failure;
    }
  });

  ipcMain.handle('auth:clear-session', async (event) => {
    assertTrustedAppRenderer(event);
    try {
      await clearDesktopSession();
      return { success: true };
    } catch (error) {
      console.error('[Auth] Failed to clear the local desktop session:', error.message);
      return { success: false, error: 'Unable to clear the local desktop session.' };
    }
  });

  ipcMain.handle('auth:recover-password', async (event, payload) => {
    assertTrustedAppRenderer(event);
    if (process.env.POS_LAN_ENABLED === 'true') {
      return {
        success: false,
        error: 'Local password recovery is disabled while LAN access is enabled. Ask an administrator to reset the account.',
      };
    }
    if (!payload || typeof payload !== 'object') {
      return { success: false, error: 'Invalid recovery details.' };
    }

    const email = typeof payload.email === 'string' ? payload.email.trim() : '';
    const password = typeof payload.password === 'string' ? payload.password : '';
    if (email.length > 254 || password.length > 256) {
      return { success: false, error: 'Invalid recovery details.' };
    }

    const phpServer = require('./services/php-server');
    sessionCookies = {};
    await stopJobWorker();
    phpServer.stopPhpServer();
    try {
      await runBackendCli(['reset-password'], JSON.stringify({ email, password }));
      await restartPhpAndWorker();
      return { success: true };
    } catch (error) {
      try {
        if (phpServer.getPhpServerInfo()) phpServer.stopPhpServer();
        await restartPhpAndWorker();
      } catch (restartError) {
        console.error('[Auth] Failed to restart PHP after password recovery error:', restartError.message);
      }
      console.error('[Auth] Local password recovery failed:', error.message);
      return { success: false, error: error.message || 'Password recovery failed.' };
    }
  });

  // ── Recovery Mode Handlers ──
  ipcMain.handle('recovery:get-last-error', (event) => {
    assertRecoveryRenderer(event);
    return lastStartupError;
  });
  ipcMain.handle('recovery:get-diagnostics', (event) => {
    assertRecoveryRenderer(event);
    const { getDataDir, getConfigDir, getLogsDir } = require('./utils/paths');
    const phpServer = require('./services/php-server');
    let runtimePorts = null;
    try {
      const { getRuntimePortsPath } = require('./utils/paths');
      const fs = require('fs');
      const data = fs.readFileSync(getRuntimePortsPath(), 'utf8');
      runtimePorts = JSON.parse(data);
    } catch (err) {
      console.warn('[Recovery Diagnostics] Failed to read runtime ports:', err.message);
    }

    let runtimeMetadata = null;
    let migrationState = 'unknown';
    let lastMigrationEvent = null;
    try {
      const { readRuntimeMetadata } = require('./utils/runtimeMigrator');
      const meta = readRuntimeMetadata();
      if (meta) {
        runtimeMetadata = {
          appVersion: meta.appVersion,
          lastSuccessfulVersion: meta.lastSuccessfulVersion,
          migrationState: meta.migrationState,
          dataDir: meta.dataDir,
          configDir: meta.configDir,
          archivedPaths: meta.archivedPaths,
          foundationSafeModeOnly: meta.foundationSafeModeOnly,
          realDataMigrationPerformed: meta.realDataMigrationPerformed,
          safeFileMigrationPerformed: meta.safeFileMigrationPerformed,
          fileMigrations: meta.fileMigrations,
          mysqlMigration: meta.mysqlMigration ? {
            phase: meta.mysqlMigration.phase,
            status: meta.mysqlMigration.status,
            activeMigrationPerformed: meta.mysqlMigration.activeMigrationPerformed,
            mysqlDataMigrationPerformed: meta.mysqlMigration.mysqlDataMigrationPerformed,
            activeMysqlPathChanged: meta.mysqlMigration.activeMysqlPathChanged,
            sourcePath: meta.mysqlMigration.sourcePath,
            activePath: meta.mysqlMigration.activePath,
            preflightBackupPath: meta.mysqlMigration.preflightBackupPath,
            rollbackAvailable: meta.mysqlMigration.rollbackAvailable,
            legacySourcePreserved: meta.mysqlMigration.legacySourcePreserved,
            candidates: meta.mysqlMigration.candidates ? meta.mysqlMigration.candidates.map(c => ({
              path: c.path,
              valid: c.valid,
              locked: c.locked,
              lockState: c.lockState,
              sizeBytes: c.sizeBytes,
              reason: c.reason
            })) : [],
            backup: meta.mysqlMigration.backup ? {
              path: meta.mysqlMigration.backup.path,
              status: meta.mysqlMigration.backup.status,
              timestamp: meta.mysqlMigration.backup.timestamp,
              sizeBytes: meta.mysqlMigration.backup.sizeBytes
            } : null,
            lastError: meta.mysqlMigration.lastError
          } : null
        };
        migrationState = meta.migrationState || 'idle';
        if (meta.events && meta.events.length > 0) {
          lastMigrationEvent = meta.events[meta.events.length - 1];
        }
      }
    } catch (err) {
      console.warn('[Recovery Diagnostics] Failed to read runtime metadata:', err.message);
    }

    let fileMigrations = runtimeMetadata ? runtimeMetadata.fileMigrations : null;
    let mysqlMigration = runtimeMetadata ? runtimeMetadata.mysqlMigration : null;
    let conflicts = [];
    if (fileMigrations) {
      for (const [key, val] of Object.entries(fileMigrations)) {
        if (val && (val.status === 'conflict' || val.status === 'migrated_with_conflict_copy')) {
          conflicts.push({ key, ...val });
        }
      }
    }

    let runtimeDiagnostics = null;
    try {
      runtimeDiagnostics = require('./utils/paths').getRuntimeDiagnostics();
    } catch (error) {
      runtimeDiagnostics = { error: error.message };
    }

    return {
      selectedDataDir: getDataDir(),
      configDir: getConfigDir(),
      logsDir: getLogsDir(),
      runtimePorts,
      phpServerInfo: phpServer.getPhpServerInfo(),
      lastHealthResult: phpServer.getLastHealthResponse(),
      runtimeMetadata,
      migrationState,
      lastMigrationEvent,
      fileMigrations,
      mysqlMigration,
      conflicts,
      runtimeDiagnostics,
      startupError: lastStartupError,
      lastPhpError: phpServer.getLastPhpError(),
      lastMysqlError: require('./services/mysql-server').getLastMysqlError(),
    };
  });
  ipcMain.handle('recovery:retry-startup', async (event) => {
    assertRecoveryRenderer(event);
    console.log('[Recovery] Retry startup requested by user.');
    try {
      console.log('[Recovery] Stopping both runtime services before retry.');
      await startCoreRuntime({ maxAttempts: 2, healthTimeout: 30000, resetBeforeStart: true });
      await startPostStartupServices();
      console.log(`[Recovery] Runtime restarted with MariaDB on ${mysqlPort} and PHP on ${phpPort}.`);
      await showMainApplication();
      lastStartupError = null;
      return { success: true };
    } catch (err) {
      console.error('[Recovery] Retry startup failed:', err.message);
      try {
        await stopRuntimeServices();
      } catch (cleanupError) {
        console.warn('[Recovery] Failed to clean up after retry:', cleanupError.message);
      }
      const errorRecord = captureStartupError(err, {
        stage: err?.details?.stage || 'recovery-retry',
        attempts: 2,
      });
      return { success: false, error: errorRecord.userMessage, code: errorRecord.code };
    }
  });

  ipcMain.handle('recovery:get-rollback-readiness', (event) => {
    assertRecoveryRenderer(event);
    try {
      const { readRuntimeMetadata, getRollbackReadiness } = require('./utils/runtimeMigrator');
      const meta = readRuntimeMetadata();
      return getRollbackReadiness(meta);
    } catch (err) {
      console.error('[IPC] Failed to get rollback readiness:', err.message);
      return { available: false, reason: err.message };
    }
  });

  ipcMain.handle('recovery:run-rollback-dry-run', (event) => {
    assertRecoveryRenderer(event);
    try {
      const { runMysqlRollbackDryRun } = require('./utils/runtimeMigrator');
      return runMysqlRollbackDryRun();
    } catch (err) {
      console.error('[IPC] Failed to run rollback dry-run:', err.message);
      return { status: 'failed', reason: err.message };
    }
  });

  ipcMain.handle('recovery:execute-mysql-rollback', (event, options) => {
    try {
      assertRecoveryRenderer(event);
      if (!options || options.confirmationToken !== 'CONFIRM_MYSQL_ROLLBACK') {
        throw new Error('Forbidden: Invalid confirmation token.');
      }
      const { executeMysqlRollback } = require('./utils/runtimeMigrator');
      return executeMysqlRollback(null, options);
    } catch (err) {
      console.error('[IPC] Failed to execute mysql rollback:', err.message);
      return { success: false, reason: err.message };
    }
  });

  ipcMain.handle('recovery:prepare-rollback-restore-staging', (event, options) => {
    try {
      assertRecoveryRenderer(event);
      if (!options || options.confirmationToken !== 'CONFIRM_MYSQL_ROLLBACK_RESTORE' || options.enableRollbackRestore !== true || options.dryRun === true) {
        throw new Error('Forbidden: Invalid confirmation token or rollback restore disabled.');
      }
      const { runAuthorizedMysqlRollbackRestore } = require('./utils/runtimeMigrator');
      return runAuthorizedMysqlRollbackRestore(null, options);
    } catch (err) {
      console.error('[IPC] Failed to prepare rollback restore staging:', err.message);
      return { success: false, reason: err.message };
    }
  });

  ipcMain.handle('recovery:run-final-rollback-switch', (event, options) => {
    try {
      assertRecoveryRenderer(event);
      if (!options || options.confirmationToken !== 'CONFIRM_FINAL_MYSQL_ROLLBACK_SWITCH' || options.enableFinalRollbackSwitch !== true || options.dryRun === true) {
        throw new Error('Forbidden: Invalid confirmation token or final rollback switch disabled.');
      }
      const { runFinalMysqlRollbackSwitch } = require('./utils/runtimeMigrator');
      return runFinalMysqlRollbackSwitch(null, options);
    } catch (err) {
      console.error('[IPC] Failed to execute final rollback switch:', err.message);
      return { success: false, reason: err.message };
    }
  });

  // 1. شاشة تحميل (Splash)
  splash = new BrowserWindow({
    width: 400, height: 300,
    frame: false, transparent: true, alwaysOnTop: true,
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true,
    }
  });
  splash.loadFile(path.join(__dirname, 'assets', 'splash.html'));

  try {
    // 2. تشغيل MySQL المدمج
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'جاري تشغيل قاعدة البيانات...'`
    );
    await startCoreRuntime({ maxAttempts: 2 });

    // 3. تشغيل PHP
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'جاري تشغيل الخادم...'`
    );

    // ── Wait for Health Check ──
    splash.webContents.executeJavaScript(
      `document.getElementById('status').textContent = 'التحقق من جاهزية النظام...'`
    );
    // startCoreRuntime completed the health check using the actual selected ports.

    // Fresh desktop installs contain no interactive user by design. Seed the
    // packaged defaults and create a one-time administrator credential only
    // when the database is empty; existing upgrades retain their data.
    await startPostStartupServices();

    // Run log maintenance and the job worker without delaying startup.

    // Keep the worker owned by Electron. A detached worker survives app
    // restarts and can continue running an older backend indefinitely.

    // 4. فتح النافذة الرئيسية
    await showMainApplication();


  } catch (err) {
    console.error('[Recovery] Entering Recovery Mode due to startup failure:', err.message);
    captureStartupError(err, {
      stage: err?.details?.stage || 'startup',
      attempts: err?.details?.attempts || 1,
    });
    await enterRecoveryMode(err);
  }
});

async function enterRecoveryMode(err) {
  if (splash) {
    try {
      splash.close();
    } catch (e) {}
  }

  try {
    await stopRuntimeServices();
  } catch (cleanupError) {
    console.warn('[Recovery] Failed to stop runtime services:', cleanupError.message);
  }
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.destroy();
    mainWindow = null;
  }
  if (tray && !tray.isDestroyed?.()) {
    tray.destroy();
  }
  tray = null;

  {
    mainWindow = new BrowserWindow({
      width: 1280, height: 800,
      minWidth: 1024, minHeight: 600,
      icon: path.join(__dirname, 'assets', 'icon.png'),
      titleBarStyle: 'hidden',
      titleBarOverlay: {
        color: '#1a1d2e',
        symbolColor: '#f3f4f6',
        height: 36
      },
      webPreferences: {
        preload: path.join(__dirname, 'recovery-preload.js'),
        contextIsolation: true,
        nodeIntegration: false,
        webSecurity: true,
        sandbox: true
      }
    });
    mainWindow.setMenu(null);
    mainWindow.maximize();
  }

  await mainWindow.loadFile(path.join(__dirname, 'assets', 'recovery.html'));
}

function createTray() {
  const icon = nativeImage.createFromPath(path.join(__dirname, 'assets', 'icon.png'));
  tray = new Tray(icon.resize({ width: 16, height: 16 }));
  tray.setContextMenu(Menu.buildFromTemplate([
    { label: 'فتح النظام', click: () => mainWindow.show() },
    { type: 'separator' },
    { label: 'إغلاق', click: () => { forceQuit = true; app.quit(); } }
  ]));
  tray.on('double-click', () => mainWindow.show());
}

let forceQuit = false;
app.on('before-quit', async () => {
  forceQuit = true;
  stopJobWorker();
  stopHttpsProxy();
  stopQZTray();
  stopPHP();
  await stopMySQL();
});
