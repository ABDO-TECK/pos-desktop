const path = require('path');
const http = require('http');
const net = require('net');
const fs = require('fs');
const { getQZPrivateKeyPath } = require('./qz-certs');
const { getPhpRuntimeArgs, resolveSystemTimeZone } = require('../utils/php-runtime');
const { createRuntimeError } = require('../utils/runtime-error');
const { formatSpawnError, spawnRuntimeProcess } = require('../utils/runtime-process');

let phpProcess = null;
let serverInfo = null;
let lastHealthResponse = null;
let lastPhpError = null;

const STATUS_DLL_NOT_FOUND = 0xC0000135; // 3221225781 unsigned, -1073741515 signed 32-bit int

function isPhpLoaderDllMissing(code) {
  if (code === null || code === undefined) return false;
  const num = Number(code);
  if (Number.isNaN(num)) return false;
  return num === 3221225781 || num === -1073741515 || (num >>> 0) === STATUS_DLL_NOT_FOUND;
}

function findAvailablePort(preferredPort, { maxPort = 65535 } = {}) {
  return new Promise((resolve, reject) => {
    let port = Number(preferredPort) || 8080;

    const check = () => {
      if (port > maxPort) {
        reject(new Error(`No available TCP port found from ${preferredPort}`));
        return;
      }

      const server = net.createServer();
      server.once('error', () => {
        server.close(() => {
          port += 1;
          check();
        });
      });
      server.once('listening', () => {
        server.close(() => resolve(port));
      });
      server.listen(port, '127.0.0.1');
    };

    check();
  });
}

function resolveBackendPharPath() {
  const { getBackendDir } = require('../utils/paths');
  return path.join(getBackendDir(), 'backend.phar');
}

function resolveBackendEntryPath() {
  const { getBackendDir, isPackaged } = require('../utils/paths');
  return isPackaged()
    ? resolveBackendPharPath()
    : path.join(getBackendDir(), 'router.php');
}

function getPhpServerInfo() {
  return serverInfo;
}

function stopPhpServer() {
  const processToStop = phpProcess;
  phpProcess = null;
  serverInfo = null;

  if (!processToStop || processToStop.killed) return;
  try {
    processToStop.kill();
  } catch (error) {
    console.warn('[PHP] Failed to stop PHP process:', error.message);
  }
}

function stopPHP() {
  stopPhpServer();
}

function resolveBackendRuntimeConfig({ packaged, isLanDeployment, environment = process.env }) {
  return {
    ENABLE_AUTO_UPDATE: packaged ? 'true' : (environment.ENABLE_AUTO_UPDATE || 'false'),
    APP_ENV: isLanDeployment ? (environment.APP_ENV || 'production') : (packaged ? 'production' : 'development'),
    APP_DEBUG: packaged ? 'false' : (environment.APP_DEBUG || 'true'),
    DEPLOYMENT_MODE: isLanDeployment ? 'lan' : 'desktop',
  };
}

function createBackendEnv({ mysqlPort, dbCredentials, apiPort }) {
  const {
    getBackupsDir,
    getAppUnpackedPath,
    getDataDir,
    getEnvPath,
    getLogsDir,
    getRuntimeMetadataPath,
    getRuntimePortsPath,
    isPackaged,
  } = require('../utils/paths');

  const packaged = isPackaged();
  const isLanDeployment = process.env.POS_LAN_ENABLED === 'true';

  return {
    ...process.env,
    DB_HOST: '127.0.0.1',
    DB_PORT: String(mysqlPort),
    DB_NAME: process.env.DB_NAME || 'pos_db',
    DB_USER: dbCredentials.user,
    DB_PASS: dbCredentials.password,
    ...resolveBackendRuntimeConfig({ packaged, isLanDeployment }),
    APP_TIMEZONE: resolveSystemTimeZone(),
    ENV_PATH: getEnvPath(),
    APP_STORAGE_DIR: getDataDir(),
    APP_DEPLOY_ROOT: getAppUnpackedPath(),
    QZ_PRIVATE_KEY_PATH: getQZPrivateKeyPath(),
    DB_BACKUP_DIR: getBackupsDir(),
    LOGS_PATH: getLogsDir(),
    API_PORT: String(apiPort),
    PORT: String(apiPort),
    RUNTIME_METADATA_PATH: getRuntimeMetadataPath(),
    RUNTIME_PORTS_PATH: getRuntimePortsPath(),
  };
}

function getEntryArgs(backendDir, isPackaged, args) {
  return isPackaged
    ? [path.join(backendDir, 'backend.phar'), ...args]
    : [path.join(backendDir, 'cli', `${args[0]}.php`), ...args.slice(1)];
}

function getProcessOutputTail(value, maxLength = 8192) {
  return String(value || '').slice(-maxLength);
}

function createPhpProcessError(code, message, details, cause) {
  const error = createRuntimeError(code, message, details);
  if (cause) error.cause = cause;
  lastPhpError = {
    code,
    message,
    details,
  };
  return error;
}

function runDatabaseMigrations({ mysqlPort, dbCredentials, apiPort, migrations = null, force = false }) {
  const {
    getBackendDir,
    getLogsDir,
    getTempDir,
    getPhpPath,
    getDataDir,
    isPackaged,
  } = require('../utils/paths');
  const backendDir = getBackendDir();
  const phpBin = getPhpPath();
  const migrationArgs = [
    ...getPhpRuntimeArgs(phpBin, getTempDir()),
    ...getEntryArgs(backendDir, isPackaged(), ['migrate']),
  ];
  if (force) {
    migrationArgs.push('--force');
    try {
      const flagPath = path.join(getDataDir(), 'migrations_hash.flag');
      if (fs.existsSync(flagPath)) fs.unlinkSync(flagPath);
    } catch {}
  }
  if (Array.isArray(migrations)) {
    if (!migrations.every((migration) => typeof migration === 'string' && /^[A-Za-z0-9][A-Za-z0-9_.-]*\.sql$/.test(migration))) {
      return Promise.reject(new Error('Invalid declared migration list.'));
    }
    migrationArgs.push(`--migrations=${migrations.join(',')}`);
  }
  const env = {
    ...createBackendEnv({ mysqlPort, dbCredentials, apiPort }),
    DB_MIGRATION_USER: dbCredentials.migrationUser || dbCredentials.user,
    DB_MIGRATION_PASS: dbCredentials.migrationPassword || dbCredentials.password,
  };
  const cwd = backendDir;

  return new Promise((resolve, reject) => {
    console.log('[Migration] Applying pending database migrations before starting PHP workers...');
    const logPath = path.join(getLogsDir(), 'database-migrations.log');
    const logStream = fs.createWriteStream(logPath, { flags: 'a' });
    let stdout = '';
    let stderr = '';
    let settled = false;

    const finish = (callback, value) => {
      if (settled) return;
      settled = true;
      logStream.end();
      callback(value);
    };

    let child;
    try {
      child = spawnRuntimeProcess(phpBin, migrationArgs, {
        cwd,
        env,
        windowsHide: true,
        stdio: ['ignore', 'pipe', 'pipe'],
      });
    } catch (error) {
      finish(reject, error);
      return;
    }

    child.stdout.on('data', (data) => {
      logStream.write(data);
      stdout = getProcessOutputTail(`${stdout}${data.toString()}`);
      console.log('[Migration]', data.toString().trim());
    });
    child.stderr.on('data', (data) => {
      logStream.write(data);
      stderr = getProcessOutputTail(`${stderr}${data.toString()}`);
    });
    child.once('error', (error) => {
      finish(reject, createPhpProcessError(
        error.code === 'ENOENT' ? 'RUNTIME_PHP_SPAWN_FAILED' : 'PHP_MIGRATION_SPAWN_FAILED',
        formatSpawnError(error, { executable: phpBin, args: migrationArgs, cwd }),
        { stage: 'database-migrations', stdout, stderr, cwd, executable: phpBin },
        error,
      ));
    });
    child.once('close', (code, signal) => {
      if (code !== 0) {
        if (isPhpLoaderDllMissing(code)) {
          finish(reject, createPhpProcessError(
            'PHP_RUNTIME_DLL_MISSING',
            'PHP runtime failed to initialize: STATUS_DLL_NOT_FOUND (0xC0000135). A required Windows runtime component (Microsoft Visual C++ 2015-2022 Redistributable x64) is missing or not installed.',
            {
              stage: 'database-migrations',
              stdout,
              stderr,
              code,
              signal,
              cwd,
              executable: phpBin,
              ntstatus: 'STATUS_DLL_NOT_FOUND',
              hexCode: '0xC0000135',
            },
          ));
          return;
        }

        const detail = stderr.trim() || `exit code ${code}${signal ? `, signal ${signal}` : ''}`;
        finish(reject, createPhpProcessError(
          'PHP_MIGRATION_FAILED',
          `Database migration failed: ${detail}`,
          { stage: 'database-migrations', stdout, stderr, code, signal, cwd, executable: phpBin },
        ));
        return;
      }

      finish(resolve);
      console.log('[Migration] Database schema is ready.');
    });
  });
}

function waitForPhpReady(port, child, {
  timeoutMs = 30000,
  executable = child.spawnfile,
  args = child.spawnargs,
  cwd = null,
} = {}) {
  const startTime = Date.now();
  const intervalMs = 200;

  return new Promise((resolve, reject) => {
    let timer = null;
    let settled = false;
    let lastError = null;

    const cleanup = () => {
      if (timer) clearTimeout(timer);
      child.removeListener('error', onError);
      child.removeListener('exit', onExit);
    };
    const succeed = () => {
      if (settled) return;
      settled = true;
      cleanup();
      resolve();
    };
    const fail = (error) => {
      if (settled) return;
      settled = true;
      cleanup();
      reject(error);
    };
    const onError = (error) => fail(createPhpProcessError(
      error.code === 'ENOENT' ? 'RUNTIME_PHP_SPAWN_FAILED' : 'PHP_PROCESS_ERROR',
      formatSpawnError(error, { executable, args, cwd }),
      { stage: 'php-server', port, executable, args, cwd, originalCode: error.code || null },
      error,
    ));
    const onExit = (code, signal) => {
      if (isPhpLoaderDllMissing(code)) {
        fail(createPhpProcessError(
          'PHP_RUNTIME_DLL_MISSING',
          'PHP runtime failed to initialize: STATUS_DLL_NOT_FOUND (0xC0000135). A required Windows runtime component (Microsoft Visual C++ 2015-2022 Redistributable x64) is missing or not installed.',
          {
            stage: 'php-server',
            port,
            code,
            signal,
            lastError: lastError ? lastError.message : null,
            ntstatus: 'STATUS_DLL_NOT_FOUND',
            hexCode: '0xC0000135',
          },
        ));
        return;
      }

      fail(createPhpProcessError(
        'PHP_PROCESS_EXITED',
        `PHP server exited before becoming ready (code ${code ?? 'unknown'}${signal ? `, signal ${signal}` : ''}).`,
        { stage: 'php-server', port, code, signal, lastError: lastError ? lastError.message : null },
      ));
    };

    child.once('error', onError);
    child.once('exit', onExit);

    const check = () => {
      if (settled) return;
      const elapsed = Date.now() - startTime;
      if (elapsed >= timeoutMs) {
        fail(createPhpProcessError(
          'PHP_SERVER_NOT_READY',
          `PHP server did not listen on 127.0.0.1:${port} within ${timeoutMs}ms.`,
          { stage: 'php-server', port, timeoutMs, lastError: lastError ? lastError.message : null },
        ));
        return;
      }

      const request = http.get(`http://127.0.0.1:${port}/`, (response) => {
        response.resume();
        response.once('end', succeed);
      });
      request.setTimeout(1000, () => request.destroy(new Error('PHP readiness request timed out')));
      request.once('error', (error) => {
        lastError = error;
        timer = setTimeout(check, intervalMs);
      });
    };

    check();
  });
}

async function startPhpServer(options = {}) {
  const {
    getPhpPath,
    getLogsDir,
    getTempDir,
    getRuntimePortsPath,
    getBackendDir,
    isPackaged,
  } = require('../utils/paths');
  const phpBin = getPhpPath();
  const backendDir = getBackendDir();
  const backendEntryPath = resolveBackendEntryPath();
  const preferredPort = options.preferredPort || 8080;
  const mysqlPort = options.mysqlPort || 3307;
  const dbCredentials = options.dbCredentials;
  const maxStartupAttempts = Math.max(1, Number(options.maxStartupAttempts) || 2);

  if (!dbCredentials?.user || !dbCredentials?.password) {
    throw createPhpProcessError(
      'PHP_DATABASE_CREDENTIALS_MISSING',
      'Dedicated database credentials are required before PHP can start.',
      { stage: 'php-server' },
    );
  }
  if (!fs.existsSync(backendDir) || !fs.statSync(backendDir).isDirectory()) {
    throw createPhpProcessError(
      'RUNTIME_BACKEND_MISSING',
      `Packaged backend directory is missing: ${backendDir}`,
      { stage: 'php-server', backendDir },
    );
  }
  if (!fs.existsSync(backendEntryPath) || !fs.statSync(backendEntryPath).isFile()) {
    throw createPhpProcessError(
      'RUNTIME_BACKEND_ENTRY_MISSING',
      `Packaged backend entry point is missing: ${backendEntryPath}`,
      { stage: 'php-server', backendEntryPath, backendDir },
    );
  }

  let lastError = null;
  for (let attempt = 1; attempt <= maxStartupAttempts; attempt += 1) {
    const selectedPort = await findAvailablePort(preferredPort + attempt - 1);
    try {
      console.log(`[PHP] Startup attempt ${attempt}/${maxStartupAttempts}; selected port ${selectedPort}.`);
      await runDatabaseMigrations({ mysqlPort, dbCredentials, apiPort: selectedPort });

      const env = {
        ...createBackendEnv({ mysqlPort, dbCredentials, apiPort: selectedPort }),
        PHP_CLI_SERVER_WORKERS: '4',
      };
      const sysTempDir = getTempDir();
      const args = [
        ...getPhpRuntimeArgs(phpBin, sysTempDir),
        '-d', `sys_temp_dir=${sysTempDir}`,
        '-S', `127.0.0.1:${selectedPort}`,
        backendEntryPath,
      ];

      const child = spawnRuntimeProcess(phpBin, args, {
        cwd: backendDir,
        env,
        windowsHide: true,
        stdio: ['ignore', 'pipe', 'pipe'],
      });
      phpProcess = child;

      // Keep a listener for the lifetime of the process. Node emits an
      // asynchronous `error` event for missing executables and loader/DLL
      // failures; without a listener Electron treats it as an uncaught main
      // process exception even after the readiness check has completed.
      child.on('error', (error) => {
        lastPhpError = {
          code: error.code || 'PHP_PROCESS_ERROR',
          message: formatSpawnError(error, { executable: phpBin, args, cwd: backendDir }),
          details: { stage: 'php-process', port: selectedPort, executable: phpBin, cwd: backendDir },
        };
        console.error('[PHP] Process error:', lastPhpError.message);
      });
      child.on('exit', (code, signal) => {
        if (phpProcess !== child) return;
        if (code !== 0) {
          lastPhpError = {
            code: isPhpLoaderDllMissing(code) ? 'PHP_RUNTIME_DLL_MISSING' : 'PHP_PROCESS_EXITED',
            message: isPhpLoaderDllMissing(code)
              ? 'PHP runtime failed to initialize: STATUS_DLL_NOT_FOUND (0xC0000135). A required Windows runtime component (Microsoft Visual C++ 2015-2022 Redistributable x64) is missing or not installed.'
              : `PHP server exited (code ${code ?? 'unknown'}${signal ? `, signal ${signal}` : ''}).`,
            details: { stage: 'php-process', port: selectedPort, code, signal, executable: phpBin, cwd: backendDir },
          };
        }
      });

      const logFile = path.join(getLogsDir(), 'php-server.log');
      const logStream = fs.createWriteStream(logFile, { flags: 'a' });
      child.stdout.pipe(logStream, { end: false });
      child.stderr.pipe(logStream, { end: false });
      child.stderr.on('data', (data) => console.log('[PHP]', data.toString().trim()));
      child.once('close', () => logStream.end());

      await waitForPhpReady(selectedPort, child, {
        executable: phpBin,
        args,
        cwd: backendDir,
      });
      serverInfo = {
        pid: child.pid,
        port: selectedPort,
        baseUrl: `http://127.0.0.1:${selectedPort}`,
        backendEntryPath,
        pharPath: isPackaged() ? backendEntryPath : null,
        executablePath: phpBin,
        workingDirectory: backendDir,
      };
      lastPhpError = null;
      console.log(`[PHP] Server is listening on ${serverInfo.baseUrl}.`);

      const portsPath = getRuntimePortsPath();
      const portsData = {
        mysqlPort: Number(mysqlPort),
        apiPort: selectedPort,
        apiBaseUrl: serverInfo.baseUrl,
        updatedAt: new Date().toISOString(),
      };
      try {
        fs.mkdirSync(path.dirname(portsPath), { recursive: true });
        fs.writeFileSync(portsPath, JSON.stringify(portsData, null, 2));
      } catch (error) {
        console.warn('[PHP] Failed to write runtime_ports.json:', error.message);
      }

      return serverInfo;
    } catch (error) {
      lastError = error;
      if (phpProcess && !phpProcess.killed) {
        try { phpProcess.kill(); } catch { /* best effort */ }
      }
      phpProcess = null;
      serverInfo = null;
      if (error.code && !['PHP_PROCESS_EXITED', 'PHP_SERVER_NOT_READY'].includes(error.code)) break;
      if (attempt < maxStartupAttempts) await new Promise((resolve) => setTimeout(resolve, 500));
    }
  }

  throw lastError || createPhpProcessError(
    'PHP_SERVER_START_FAILED',
    'PHP server failed to start.',
    { stage: 'php-server', executable: phpBin, workingDirectory: backendDir },
  );
}

async function startPHP(port, mysqlPort, dbCredentials) {
  return startPhpServer({ preferredPort: port, mysqlPort, dbCredentials });
}

function waitForHealth(baseUrl, options = {}) {
  const maxTime = options.maxTime || 60000;
  const startTime = Date.now();
  let delay = options.initialDelay || 500;

  return new Promise((resolve, reject) => {
    let attempt = 0;
    let settled = false;
    let timer = null;

    const finish = (callback, value) => {
      if (settled) return;
      settled = true;
      if (timer) clearTimeout(timer);
      callback(value);
    };
    const schedule = () => {
      timer = setTimeout(nextCheck, delay);
      delay = Math.min(delay * 1.5, 5000);
    };
    const nextCheck = () => {
      if (settled) return;
      attempt += 1;
      const elapsed = Date.now() - startTime;
      if (elapsed >= maxTime) {
        finish(reject, createPhpProcessError(
          'PHP_HEALTH_TIMEOUT',
          `Backend health check failed after ${elapsed}ms (${attempt} attempts).`,
          { stage: 'health-check', baseUrl, attempts: attempt, lastResponse: lastHealthResponse },
        ));
        return;
      }

      const req = http.get(`${baseUrl}/api/health`, (res) => {
        let rawData = '';
        res.on('data', (chunk) => { rawData += chunk; });
        res.on('end', () => {
          if (res.statusCode === 200 || res.statusCode === 503) {
            try {
              const body = JSON.parse(rawData);
              lastHealthResponse = body;
              if (body.critical_failed === false) {
                finish(resolve, body);
              } else {
                schedule();
              }
            } catch (error) {
              lastHealthResponse = {
                status: 'failed',
                critical_failed: true,
                error: `Parse error: ${error.message}. Raw: ${rawData.substring(0, 200)}`,
                checks: null,
              };
              schedule();
            }
          } else {
            lastHealthResponse = {
              status: 'failed',
              critical_failed: true,
              error: `HTTP status code ${res.statusCode}`,
              checks: null,
            };
            schedule();
          }
        });
      });
      req.setTimeout(2000, () => req.destroy(new Error('Health request timed out')));
      req.on('error', (error) => {
        lastHealthResponse = {
          status: 'failed',
          critical_failed: true,
          error: `Connection error: ${error.message}`,
          checks: null,
        };
        schedule();
      });
    };

    nextCheck();
  });
}

function getLastHealthResponse() {
  return lastHealthResponse;
}

function getLastPhpError() {
  return lastPhpError;
}

module.exports = {
  findAvailablePort,
  resolveBackendPharPath,
  resolveBackendEntryPath,
  createBackendEnv,
  resolveBackendRuntimeConfig,
  runDatabaseMigrations,
  startPhpServer,
  stopPhpServer,
  getPhpServerInfo,
  startPHP,
  stopPHP,
  waitForHealth,
  getLastHealthResponse,
  getLastPhpError,
  isPhpLoaderDllMissing,
  STATUS_DLL_NOT_FOUND,
};
