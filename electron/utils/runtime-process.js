const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { createRuntimeError } = require('./runtime-error');

function assertWorkingDirectory(cwd, executable) {
  if (!cwd || !fs.existsSync(cwd) || !fs.statSync(cwd).isDirectory()) {
    throw createRuntimeError(
      'RUNTIME_WORKING_DIRECTORY_MISSING',
      `Cannot start ${executable}: working directory does not exist.`,
      { executable, cwd },
    );
  }
}

function assertAbsoluteExecutable(executable, cwd) {
  if (!path.isAbsolute(executable)) return;

  if (!fs.existsSync(executable) || !fs.statSync(executable).isFile()) {
    throw createRuntimeError(
      'RUNTIME_EXECUTABLE_MISSING',
      `Cannot start runtime executable: ${executable} was not found.`,
      { executable, cwd },
    );
  }
}

function prependPath(env, directory) {
  if (!directory) return env;

  const existingPath = env.PATH || env.Path || process.env.PATH || process.env.Path || '';
  const entries = [directory, ...existingPath.split(path.delimiter).filter(Boolean)];
  const seen = new Set();
  const uniqueEntries = entries.filter((entry) => {
    const key = process.platform === 'win32' ? entry.toLowerCase() : entry;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });

  return {
    ...env,
    PATH: uniqueEntries.join(path.delimiter),
  };
}

function getRuntimeSpawnOptions(executable, options = {}) {
  const {
    cwd: requestedCwd,
    env: requestedEnv,
    shell: _ignoredShell,
    ...spawnOptions
  } = options;
  const cwd = requestedCwd || (path.isAbsolute(executable) ? path.dirname(executable) : process.cwd());

  assertWorkingDirectory(cwd, executable);
  assertAbsoluteExecutable(executable, cwd);

  const env = prependPath({ ...process.env, ...(requestedEnv || {}) }, path.isAbsolute(executable)
    ? path.dirname(executable)
    : null);

  return {
    ...spawnOptions,
    cwd,
    env,
    shell: false,
  };
}

function spawnRuntimeProcess(executable, args = [], options = {}) {
  try {
    return spawn(executable, args, getRuntimeSpawnOptions(executable, options));
  } catch (error) {
    const wrapped = createRuntimeError(
      error.code || 'RUNTIME_SPAWN_FAILED',
      `Failed to start ${executable}: ${error.message}`,
      {
        executable,
        args,
        cwd: options.cwd || (path.isAbsolute(executable) ? path.dirname(executable) : process.cwd()),
        originalCode: error.code || null,
      },
    );
    wrapped.cause = error;
    throw wrapped;
  }
}

function formatSpawnError(error, { executable, args = [], cwd } = {}) {
  const code = error && error.code ? ` (${error.code})` : '';
  const command = [executable, ...args].join(' ');
  return `Failed to start runtime process${code}: ${command}. Working directory: ${cwd || 'not set'}. ${error?.message || error}`;
}

module.exports = {
  formatSpawnError,
  getRuntimeSpawnOptions,
  prependPath,
  spawnRuntimeProcess,
};
