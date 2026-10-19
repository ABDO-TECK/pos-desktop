const { execFile, execFileSync } = require('child_process');
const path = require('path');
const net = require('net');
const crypto = require('crypto');
const fs = require('fs');
const { getMysqlPaths } = require('../utils/paths');
const { createRuntimeError } = require('../utils/runtime-error');
const { formatSpawnError, getRuntimeSpawnOptions, spawnRuntimeProcess } = require('../utils/runtime-process');

let mysqlProcess = null;
let mysqlPort = null;
let lastMysqlError = null;

function findAvailablePort(preferredPort, { maxPort = 65535 } = {}) {
  return new Promise((resolve, reject) => {
    let port = Number(preferredPort) || 3307;

    const check = () => {
      if (port > maxPort) {
        reject(new Error(`No available MySQL TCP port found from ${preferredPort}`));
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

function getOutputTail(value, maxLength = 12000) {
  return String(value || '').slice(-maxLength);
}

function setLastMysqlError(code, message, details, cause) {
  lastMysqlError = { code, message, details };
  const error = createRuntimeError(code, message, details);
  if (cause) error.cause = cause;
  return error;
}

function isMysqlDataDirectoryInitialized(dataDir) {
  if (!fs.existsSync(dataDir) || !fs.statSync(dataDir).isDirectory()) return false;

  const systemDatabase = path.join(dataDir, 'mysql');
  const dataFiles = [
    path.join(dataDir, 'ibdata1'),
    path.join(dataDir, 'aria_log_control'),
    path.join(dataDir, 'ib_logfile0'),
  ];
  return fs.existsSync(systemDatabase) && dataFiles.some((candidate) => fs.existsSync(candidate));
}

function runMysqlExecutable(executable, args, { cwd, input, encoding = 'utf8', maxBuffer = 16 * 1024 * 1024 } = {}) {
  const options = getRuntimeSpawnOptions(executable, {
    cwd,
    windowsHide: true,
    stdio: input === undefined ? ['ignore', 'pipe', 'pipe'] : ['pipe', 'pipe', 'pipe'],
  });
  const commandOptions = {
    ...options,
    encoding,
    maxBuffer,
  };
  if (input !== undefined) commandOptions.input = input;
  return execFileSync(executable, args, commandOptions);
}

function initializeMysqlDataDirectory(runtimePaths) {
  const { dataDir, binaryDir, baseDir, initializerPath, mysqldPath } = runtimePaths;
  if (isMysqlDataDirectoryInitialized(dataDir)) return { initialized: false };

  fs.mkdirSync(dataDir, { recursive: true });
  if (fs.readdirSync(dataDir).length > 0) {
    throw setLastMysqlError(
      'MYSQL_DATA_DIRECTORY_INVALID',
      `The MySQL data directory is incomplete and was not changed: ${dataDir}`,
      { stage: 'mysql-initialize', dataDir, binaryDir },
    );
  }

  const initAttempts = [];
  const attemptResults = [];
  if (initializerPath) {
    initAttempts.push({
      executable: initializerPath,
      args: [`--datadir=${dataDir}`],
    });
  }
  initAttempts.push({
    executable: mysqldPath,
    args: ['--initialize-insecure', `--datadir=${dataDir}`, `--basedir=${baseDir}`],
  });

  for (const attempt of initAttempts) {
    try {
      console.log(`[MySQL] Initializing data directory with ${path.basename(attempt.executable)}.`);
      runMysqlExecutable(attempt.executable, attempt.args, { cwd: binaryDir });
      if (isMysqlDataDirectoryInitialized(dataDir)) return { initialized: true };
      attemptResults.push({
        ...attempt,
        result: 'completed_without_initialized_marker',
      });
    } catch (error) {
      attemptResults.push({ error: error.message, executable: attempt.executable });
    }
  }

  throw setLastMysqlError(
    'MYSQL_INITIALIZATION_FAILED',
    `MySQL could not initialize its data directory: ${dataDir}`,
    { stage: 'mysql-initialize', dataDir, binaryDir, attempts: attemptResults },
  );
}

function waitForMysqlReady(port, child, {
  timeoutMs = 30000,
  executable = child.spawnfile,
  args = child.spawnargs,
  cwd = null,
} = {}) {
  const startTime = Date.now();

  return new Promise((resolve, reject) => {
    let timer = null;
    let settled = false;
    let lastSocketError = null;

    const cleanup = () => {
      if (timer) clearTimeout(timer);
      child.removeListener('error', onError);
      child.removeListener('exit', onExit);
    };
    const finish = (callback, value) => {
      if (settled) return;
      settled = true;
      cleanup();
      callback(value);
    };
    const onError = (error) => finish(reject, setLastMysqlError(
      error.code === 'ENOENT' ? 'RUNTIME_MYSQL_SPAWN_FAILED' : 'MYSQL_PROCESS_ERROR',
      formatSpawnError(error, { executable, args, cwd }),
      { stage: 'mysql-start', port, executable, args, cwd, originalCode: error.code || null },
      error,
    ));
    const onExit = (code, signal) => finish(reject, setLastMysqlError(
      'MYSQL_PROCESS_EXITED',
      `MySQL exited before becoming ready (code ${code ?? 'unknown'}${signal ? `, signal ${signal}` : ''}).`,
      { stage: 'mysql-start', port, code, signal, lastSocketError: lastSocketError?.message || null },
    ));

    child.once('error', onError);
    child.once('exit', onExit);

    const check = () => {
      if (settled) return;
      const elapsed = Date.now() - startTime;
      if (elapsed >= timeoutMs) {
        finish(reject, setLastMysqlError(
          'MYSQL_START_TIMEOUT',
          `MySQL did not listen on 127.0.0.1:${port} within ${timeoutMs}ms.`,
          { stage: 'mysql-start', port, timeoutMs, lastSocketError: lastSocketError?.message || null },
        ));
        return;
      }

      const socket = new net.Socket();
      socket.setTimeout(750);
      socket.once('connect', () => {
        socket.destroy();
        finish(resolve);
      });
      socket.once('error', (error) => {
        lastSocketError = error;
        socket.destroy();
        timer = setTimeout(check, 250);
      });
      socket.once('timeout', () => {
        lastSocketError = new Error('MySQL readiness socket timed out');
        socket.destroy();
        timer = setTimeout(check, 250);
      });
      socket.connect(port, '127.0.0.1');
    };

    check();
  });
}

function sqlLiteral(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

function loadStoredDatabaseCredentials(credentialsPath) {
  try {
    if (!fs.existsSync(credentialsPath)) return null;
    const data = JSON.parse(fs.readFileSync(credentialsPath, 'utf8'));
    if (data && typeof data.password === 'string' && data.password.length > 0) {
      return {
        user: data.user || 'pos_app',
        password: data.password,
        migrationUser: data.migrationUser || 'pos_migration',
        migrationPassword: typeof data.migrationPassword === 'string' && data.migrationPassword.length > 0
          ? data.migrationPassword
          : null,
      };
    }
  } catch (error) {
    console.warn('[MySQL Init] Failed to read database credentials file:', error.message);
  }
  return null;
}

function saveDatabaseCredentials(credentialsPath, credentials) {
  const dir = path.dirname(credentialsPath);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  const tempPath = `${credentialsPath}.tmp.${crypto.randomBytes(8).toString('hex')}`;
  const payload = JSON.stringify(credentials, null, 2);
  fs.writeFileSync(tempPath, payload, { mode: 0o600, encoding: 'utf8' });
  fs.renameSync(tempPath, credentialsPath);
}

function buildUserProvisioningStatements(appPassword, migrationPassword) {
  const appUserLit = sqlLiteral('pos_app');
  const migrationUserLit = sqlLiteral('pos_migration');
  const hostLit = sqlLiteral('127.0.0.1');
  const appPassLit = sqlLiteral(appPassword);
  const migPassLit = sqlLiteral(migrationPassword);

  return [
    `CREATE USER IF NOT EXISTS ${appUserLit}@${hostLit} IDENTIFIED BY ${appPassLit}; ` +
    `ALTER USER ${appUserLit}@${hostLit} IDENTIFIED BY ${appPassLit}; ` +
    `REVOKE ALL PRIVILEGES, GRANT OPTION FROM ${appUserLit}@${hostLit}; ` +
    `GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE ON pos_db.* TO ${appUserLit}@${hostLit};`,

    `CREATE USER IF NOT EXISTS ${migrationUserLit}@${hostLit} IDENTIFIED BY ${migPassLit}; ` +
    `ALTER USER ${migrationUserLit}@${hostLit} IDENTIFIED BY ${migPassLit}; ` +
    `GRANT ALL PRIVILEGES ON pos_db.* TO ${migrationUserLit}@${hostLit};`,

    'FLUSH PRIVILEGES;',
  ];
}

function initDatabase(port, runtimePaths = getMysqlPaths()) {
  const { mysqlPath, binaryDir } = runtimePaths;
  const { getDatabaseDir, getDatabaseCredentialsPath } = require('../utils/paths');
  const schemaFile = path.join(getDatabaseDir(), 'pos_schema.sql');
  if (!fs.existsSync(schemaFile) || !fs.statSync(schemaFile).isFile()) {
    throw setLastMysqlError(
      'RUNTIME_SCHEMA_MISSING',
      `The packaged database schema is missing: ${schemaFile}`,
      { stage: 'mysql-database-init', schemaFile },
    );
  }

  try {
    runMysqlExecutable(mysqlPath, [
      '-h', '127.0.0.1',
      '-u', 'root',
      `--port=${port}`,
      '--default-character-set=utf8mb4',
      '-e',
      'CREATE DATABASE IF NOT EXISTS pos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    ], { cwd: binaryDir });

    const result = runMysqlExecutable(mysqlPath, [
      '-h', '127.0.0.1',
      '-u', 'root',
      `--port=${port}`,
      'pos_db',
      '-e',
      'SHOW TABLES',
    ], { cwd: binaryDir });
    const freshInstall = !String(result).includes('users');

    if (freshInstall) {
      runMysqlExecutable(mysqlPath, [
        '-h', '127.0.0.1',
        '-u', 'root',
        `--port=${port}`,
        '--default-character-set=utf8mb4',
        'pos_db',
      ], { cwd: binaryDir, input: fs.readFileSync(schemaFile) });
    } else {
      repairCorruptedTables(mysqlPath, binaryDir, port);
    }

    const credentialsPath = getDatabaseCredentialsPath();
    const stored = loadStoredDatabaseCredentials(credentialsPath);

    let appPassword = stored?.password || null;
    let migrationPassword = stored?.migrationPassword || null;
    let credentialsChanged = false;

    if (!appPassword) {
      appPassword = crypto.randomBytes(32).toString('hex');
      credentialsChanged = true;
    }
    if (!migrationPassword) {
      migrationPassword = crypto.randomBytes(32).toString('hex');
      credentialsChanged = true;
    }

    const sqlStatements = buildUserProvisioningStatements(appPassword, migrationPassword);
    runMysqlExecutable(mysqlPath, [
      '-h', '127.0.0.1',
      '-u', 'root',
      `--port=${port}`,
      '-e',
      sqlStatements.join(' '),
    ], { cwd: binaryDir });

    if (credentialsChanged || !stored) {
      saveDatabaseCredentials(credentialsPath, {
        user: 'pos_app',
        password: appPassword,
        migrationUser: 'pos_migration',
        migrationPassword,
      });
    }

    return {
      user: 'pos_app',
      password: appPassword,
      migrationUser: 'pos_migration',
      migrationPassword,
      freshInstall,
    };
  } catch (error) {
    console.error('[MySQL Init]', error.message);
    if (error.code && error.details) throw error;
    throw setLastMysqlError(
      'MYSQL_DATABASE_INIT_FAILED',
      `MySQL database initialization failed: ${error.message}`,
      { stage: 'mysql-database-init', port, mysqlPath, binaryDir },
      error,
    );
  }
}

async function startMySQL(preferredPort = 3307, options = {}) {
  const runtimePaths = getMysqlPaths();
  const maxStartupAttempts = Math.max(1, Number(options.maxStartupAttempts) || 3);
  const startupTimeoutMs = Math.max(5000, Number(options.startupTimeoutMs) || 30000);
  initializeMysqlDataDirectory(runtimePaths);

  let lastError = null;
  for (let attempt = 1; attempt <= maxStartupAttempts; attempt += 1) {
    const selectedPort = await findAvailablePort(Number(preferredPort) + attempt - 1);
    const args = [
      `--port=${selectedPort}`,
      `--datadir=${runtimePaths.dataDir}`,
      `--basedir=${runtimePaths.baseDir}`,
      '--standalone',
      '--console',
      '--skip-networking=0',
      '--bind-address=127.0.0.1',
      '--skip-name-resolve',
    ];
    let child;

    try {
      console.log(`[MySQL] Startup attempt ${attempt}/${maxStartupAttempts}; selected port ${selectedPort}.`);
      child = spawnRuntimeProcess(runtimePaths.mysqldPath, args, {
        cwd: runtimePaths.binaryDir,
        windowsHide: true,
        stdio: ['ignore', 'pipe', 'pipe'],
      });
      mysqlProcess = child;
      mysqlPort = selectedPort;

      const { getLogsDir } = require('../utils/paths');
      const logStream = fs.createWriteStream(path.join(getLogsDir(), 'mysql-server.log'), { flags: 'a' });
      let stdout = '';
      let stderr = '';
      child.stdout.on('data', (data) => {
        stdout = getOutputTail(`${stdout}${data.toString()}`);
        logStream.write(data);
      });
      child.stderr.on('data', (data) => {
        stderr = getOutputTail(`${stderr}${data.toString()}`);
        logStream.write(data);
      });
      child.once('close', () => logStream.end());
      // Keep an error listener after readiness too; an async ENOENT or a
      // runtime DLL failure must never become an uncaught Electron exception.
      child.on('error', (error) => {
        lastMysqlError = {
          code: error.code || 'MYSQL_PROCESS_ERROR',
          message: formatSpawnError(error, { executable: runtimePaths.mysqldPath, args, cwd: runtimePaths.binaryDir }),
          details: { stage: 'mysql-process', port: selectedPort, stdout, stderr },
        };
        console.error('[MySQL] Process error:', lastMysqlError.message);
      });

      await waitForMysqlReady(selectedPort, child, {
        timeoutMs: startupTimeoutMs,
        executable: runtimePaths.mysqldPath,
        args,
        cwd: runtimePaths.binaryDir,
      });
      const credentials = initDatabase(selectedPort, runtimePaths);
      lastMysqlError = null;
      return {
        ...credentials,
        port: selectedPort,
        runtime: {
          serverPath: runtimePaths.mysqldPath,
          clientPath: runtimePaths.mysqlPath,
          workingDirectory: runtimePaths.binaryDir,
          dataDir: runtimePaths.dataDir,
        },
      };
    } catch (error) {
      lastError = error;
      if (child && !child.killed) {
        try { child.kill(); } catch { /* best effort */ }
      }
      if (mysqlProcess === child) mysqlProcess = null;
      if (error.code && !['MYSQL_PROCESS_EXITED', 'MYSQL_START_TIMEOUT', 'MYSQL_PROCESS_ERROR'].includes(error.code)) break;
      if (attempt < maxStartupAttempts) await new Promise((resolve) => setTimeout(resolve, 500));
    }
  }

  throw lastError || setLastMysqlError(
    'MYSQL_START_FAILED',
    'MySQL/MariaDB failed to start after all startup attempts.',
    { stage: 'mysql-start', preferredPort, runtime: runtimePaths },
  );
}

/**
 * Recreate only the application database while keeping the bundled MySQL
 * system tables and process intact. The caller must stop PHP and workers
 * first so no application connection can write during the reset.
 */
async function resetDatabase(port) {
  const runtimePaths = getMysqlPaths();
  const { mysqlPath, binaryDir } = runtimePaths;
  const { getDatabaseDir } = require('../utils/paths');
  const schemaFile = path.join(getDatabaseDir(), 'pos_schema.sql');

  if (!fs.existsSync(schemaFile) || !fs.statSync(schemaFile).isFile()) {
    throw new Error('The packaged database schema is missing');
  }

  runMysqlExecutable(mysqlPath, [
    '-h', '127.0.0.1',
    '-u', 'root',
    `--port=${port}`,
    '-e',
    'DROP DATABASE IF EXISTS pos_db; CREATE DATABASE pos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
  ], { cwd: binaryDir });
  runMysqlExecutable(mysqlPath, [
    '-h', '127.0.0.1',
    '-u', 'root',
    `--port=${port}`,
    '--default-character-set=utf8mb4',
    'pos_db',
  ], { cwd: binaryDir, input: fs.readFileSync(schemaFile) });

  return { ...initDatabase(port, runtimePaths), port };
}

function repairCorruptedTables(mysqlPath, binaryDir, port) {
  try {
    runMysqlExecutable(mysqlPath, [
      '-h', '127.0.0.1',
      '-u', 'root',
      `--port=${port}`,
      'pos_db',
      '-e',
      'SELECT id FROM users LIMIT 1',
    ], { cwd: binaryDir });
  } catch (error) {
    if (error.message && error.message.includes("doesn't exist in engine")) {
      console.log('[MySQL Repair] Database corruption detected. Repairing schema.');
      const { getDatabaseDir } = require('../utils/paths');
      const schemaFile = path.join(getDatabaseDir(), 'pos_schema.sql');
      try {
        runMysqlExecutable(mysqlPath, [
          '-h', '127.0.0.1',
          '-u', 'root',
          `--port=${port}`,
          '-e',
          'DROP DATABASE IF EXISTS pos_db; CREATE DATABASE pos_db;',
        ], { cwd: binaryDir });
        runMysqlExecutable(mysqlPath, [
          '-h', '127.0.0.1',
          '-u', 'root',
          `--port=${port}`,
          'pos_db',
        ], { cwd: binaryDir, input: fs.readFileSync(schemaFile) });
        console.log('[MySQL Repair] Schema reloaded successfully.');
      } catch (repairError) {
        console.error('[MySQL Repair] Schema reload failed:', repairError.message);
        throw repairError;
      }
    } else {
      throw error;
    }
  }
}

function stopMySQL() {
  const processToStop = mysqlProcess;
  const portToStop = mysqlPort;
  mysqlProcess = null;
  mysqlPort = null;

  if (!processToStop) return Promise.resolve();

  const runtimePaths = (() => {
    try { return getMysqlPaths(); } catch { return null; }
  })();
  if (!runtimePaths?.mysqlAdminPath || !portToStop) {
    try { processToStop.kill(); } catch { /* best effort */ }
    return Promise.resolve();
  }

  return new Promise((resolve) => {
    let settled = false;
    const finish = () => {
      if (settled) return;
      settled = true;
      resolve();
    };
    const killFallback = () => {
      try {
        if (!processToStop.killed) processToStop.kill();
      } catch { /* best effort */ }
      finish();
    };

    processToStop.once('exit', finish);
    execFile(runtimePaths.mysqlAdminPath, ['-h', '127.0.0.1', '-u', 'root', `--port=${portToStop}`, 'shutdown'], {
      cwd: runtimePaths.binaryDir,
      windowsHide: true,
      timeout: 5000,
    }, (error) => {
      if (error) {
        console.warn('[MySQL] mysqladmin shutdown failed; terminating process:', error.message);
        killFallback();
        return;
      }
      setTimeout(killFallback, 1000);
    });
    setTimeout(killFallback, 2500);
  });
}

function getLastMysqlError() {
  return lastMysqlError;
}

module.exports = {
  findAvailablePort,
  initDatabase,
  initializeMysqlDataDirectory,
  isMysqlDataDirectoryInitialized,
  startMySQL,
  stopMySQL,
  resetDatabase,
  getLastMysqlError,
  waitForMysqlReady,
  loadStoredDatabaseCredentials,
  saveDatabaseCredentials,
  buildUserProvisioningStatements,
};
