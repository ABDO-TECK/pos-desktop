const path = require('node:path');
const { createBackendEnv } = require('./php-server');
const { getPhpRuntimeArgs } = require('../utils/php-runtime');
const {
  getPhpPath: defaultGetPhpPath,
  getBackendDir: defaultGetBackendDir,
  getTempDir: defaultGetTempDir,
  isPackaged: defaultIsPackaged,
} = require('../utils/paths');
const {
  spawnRuntimeProcess: defaultSpawnRuntimeProcess,
  formatSpawnError,
} = require('../utils/runtime-process');

const PRIVILEGED_CLI_ALLOWLIST = Object.freeze([
  'restore-backup',
  'restore-migration-safety',
  'create-restore-safety',
  'verify-database',
]);

function isPrivilegedCliCommandAllowed(command) {
  if (typeof command !== 'string' || !command) return false;
  return PRIVILEGED_CLI_ALLOWLIST.includes(command);
}

function createPrivilegedEnv({ mysqlPort, dbCredentials, apiPort }) {
  if (!dbCredentials || !dbCredentials.migrationUser || !dbCredentials.migrationPassword) {
    throw new Error('Database migration credentials are not available for privileged execution');
  }

  const baseEnv = createBackendEnv({ mysqlPort, dbCredentials, apiPort });
  return {
    ...baseEnv,
    DB_MIGRATION_USER: dbCredentials.migrationUser,
    DB_MIGRATION_PASS: dbCredentials.migrationPassword,
  };
}

function sanitizeText(text, secrets = []) {
  if (typeof text !== 'string') return '';
  let result = text;
  for (const secret of secrets) {
    if (secret && typeof secret === 'string' && secret.length > 0) {
      result = result.split(secret).join('[REDACTED]');
    }
  }
  return result;
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
      // Non-JSON output line
    }
  }

  return output;
}

function runPrivilegedBackendCli(args, options = {}) {
  const command = Array.isArray(args) ? args[0] : null;
  if (!isPrivilegedCliCommandAllowed(command)) {
    return Promise.reject(
      new Error(`CLI command "${command}" is not permitted to run with migration credentials`)
    );
  }

  const dbCredentials = options.dbCredentials;
  if (!dbCredentials) {
    return Promise.reject(new Error('Database credentials are not available'));
  }

  const secrets = [
    dbCredentials.migrationPassword,
    dbCredentials.password,
  ].filter(Boolean);

  let env;
  try {
    env = createPrivilegedEnv({
      mysqlPort: options.mysqlPort,
      dbCredentials,
      apiPort: options.phpPort,
    });
  } catch (envError) {
    return Promise.reject(envError);
  }

  const getPhp = options.getPhpPath || defaultGetPhpPath;
  const getBackend = options.getBackendDir || defaultGetBackendDir;
  const getTemp = options.getTempDir || defaultGetTempDir;
  const checkPackaged = options.isPackaged || defaultIsPackaged;
  const spawnProcess = options.spawnProcess || defaultSpawnRuntimeProcess;

  const phpPath = getPhp();
  const backendDir = getBackend();
  const runtimeArgs = getPhpRuntimeArgs(phpPath, getTemp());
  const entryArgs = checkPackaged()
    ? [path.join(backendDir, 'backend.phar'), ...args]
    : [path.join(backendDir, 'cli', `${args[0]}.php`), ...args.slice(1)];
  const commandArgs = [...runtimeArgs, ...entryArgs];

  return new Promise((resolve, reject) => {
    let child;
    try {
      child = spawnProcess(phpPath, commandArgs, {
        cwd: backendDir,
        env,
        windowsHide: true,
        stdio: options.input === undefined ? ['pipe', 'pipe', 'pipe'] : ['pipe', 'pipe', 'pipe'],
      });
    } catch (spawnError) {
      const sanitizedMessage = sanitizeText(
        formatSpawnError(spawnError, { executable: phpPath, args: commandArgs, cwd: backendDir }),
        secrets
      );
      reject(new Error(sanitizedMessage));
      return;
    }

    if (options.input !== undefined && child.stdin) {
      try {
        child.stdin.end(options.input);
      } catch {
        // Child closed stdin early
      }
    }

    let stdout = '';
    let stderr = '';
    const maxOutput = 12 * 1024;
    child.stdout?.on('data', (data) => {
      stdout = (stdout + data.toString()).slice(-maxOutput);
    });
    child.stderr?.on('data', (data) => {
      stderr = (stderr + data.toString()).slice(-maxOutput);
    });

    child.once('error', (error) => {
      const raw = formatSpawnError(error, {
        executable: phpPath,
        args: commandArgs,
        cwd: backendDir,
      });
      reject(new Error(sanitizeText(raw, secrets)));
    });

    child.once('close', (code, signal) => {
      const cleanStdout = sanitizeText(stdout, secrets);
      const cleanStderr = sanitizeText(stderr, secrets);

      if (code === 0) {
        resolve({ stdout: cleanStdout, stderr: cleanStderr });
        return;
      }

      const detail = formatBackendCliFailure(cleanStdout, cleanStderr)
        || `exit code ${code}${signal ? `, signal ${signal}` : ''}`;
      reject(new Error(detail.slice(-2000)));
    });
  });
}

module.exports = {
  PRIVILEGED_CLI_ALLOWLIST,
  isPrivilegedCliCommandAllowed,
  createPrivilegedEnv,
  runPrivilegedBackendCli,
  sanitizeText,
  formatBackendCliFailure,
};
