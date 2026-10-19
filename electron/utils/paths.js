const path = require('path');
const fs = require('fs');
const { app } = require('electron');
const { execFileSync } = require('child_process');
const { createRuntimeError } = require('./runtime-error');

function isPackaged() {
  return Boolean(app && app.isPackaged);
}

function getAppPath() {
  try {
    return app.getAppPath();
  } catch {
    return path.resolve(__dirname, '..', '..');
  }
}

function getAppUnpackedPath() {
  const appPath = getAppPath();
  return /\.asar$/i.test(appPath) ? `${appPath}.unpacked` : appPath;
}

function uniquePaths(paths) {
  const seen = new Set();
  return paths.filter((candidate) => {
    if (!candidate) return false;
    const normalized = path.normalize(candidate);
    const key = process.platform === 'win32' ? normalized.toLowerCase() : normalized;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function getPortableCandidates() {
  const candidates = [];
  if (!isPackaged() && process.env.POS_PORTABLE_DIR) {
    candidates.push(path.resolve(process.env.POS_PORTABLE_DIR));
  }

  if (process.resourcesPath) {
    candidates.push(path.join(process.resourcesPath, 'portable'));
  }

  if (isPackaged()) {
    // This is equivalent to resources/portable for a normal Electron bundle,
    // but also works when the app is launched from an unpacked directory.
    candidates.push(path.resolve(getAppUnpackedPath(), '..', 'portable'));
  } else {
    candidates.push(path.resolve(__dirname, '..', '..', 'portable'));
    if (process.cwd()) candidates.push(path.join(process.cwd(), 'portable'));
  }

  return uniquePaths(candidates);
}

function getPortableDir() {
  const candidates = getPortableCandidates();
  return candidates.find((candidate) => fs.existsSync(candidate) && fs.statSync(candidate).isDirectory())
    || candidates[0];
}

function describePath(candidate) {
  const result = { path: candidate, exists: false, isFile: false, isDirectory: false };
  try {
    const stat = fs.statSync(candidate);
    result.exists = true;
    result.isFile = stat.isFile();
    result.isDirectory = stat.isDirectory();
  } catch {
    // Missing runtime files are expected to reach the diagnostic screen.
  }
  return result;
}

function createMissingRuntimeError(runtime, expectedFiles, candidates, message) {
  const details = {
    runtime,
    packaged: isPackaged(),
    resourcesPath: process.resourcesPath || null,
    processCwd: process.cwd(),
    portableCandidates: getPortableCandidates(),
    expectedFiles: expectedFiles.map(describePath),
    candidates: candidates.map(describePath),
  };
  const checked = candidates.join(', ');
  const error = createRuntimeError(
    `RUNTIME_${runtime.toUpperCase()}_MISSING`,
    `${message} Checked: ${checked}. Reinstall POS System or rebuild the installer with the verified portable runtime bundle.`,
    details,
  );
  error.runtime = runtime;
  return error;
}

function resolveExecutable(runtime, candidates, expectedFiles, message) {
  const resolved = candidates.find((candidate) => {
    try {
      return fs.existsSync(candidate) && fs.statSync(candidate).isFile();
    } catch {
      return false;
    }
  });
  if (resolved) return path.resolve(resolved);
  throw createMissingRuntimeError(runtime, expectedFiles, candidates, message);
}

function getPhpCandidates() {
  const bundled = getPortableCandidates().map((root) => path.join(root, 'php', 'php.exe'));
  if (isPackaged()) return uniquePaths(bundled);

  return uniquePaths([
    ...bundled,
    process.env.POS_PHP_PATH,
    'C:\\xampp\\php\\php.exe',
  ].filter(Boolean));
}

function getPhpPath() {
  const candidates = getPhpCandidates();
  return resolveExecutable(
    'php',
    candidates,
    candidates,
    'Bundled PHP runtime is missing or incomplete.',
  );
}

function getMysqlCandidateRoots() {
  const bundled = getPortableCandidates().map((root) => path.join(root, 'mysql'));
  if (isPackaged()) return uniquePaths(bundled);

  return uniquePaths([
    ...bundled,
    process.env.POS_MYSQL_DIR,
    'C:\\xampp\\mysql',
  ].filter(Boolean).map((candidate) => path.resolve(candidate)));
}

function getMysqlPaths() {
  const roots = getMysqlCandidateRoots();
  const expectedFiles = roots.flatMap((root) => [
    path.join(root, 'bin', 'mysqld.exe'),
    path.join(root, 'bin', 'mariadbd.exe'),
    path.join(root, 'bin', 'mysql.exe'),
  ]);

  for (const baseDir of roots) {
    const binaryDir = path.join(baseDir, 'bin');
    const serverPath = ['mysqld.exe', 'mariadbd.exe']
      .map((name) => path.join(binaryDir, name))
      .find((candidate) => fs.existsSync(candidate) && fs.statSync(candidate).isFile());
    const mysqlPath = path.join(binaryDir, 'mysql.exe');

    if (!serverPath || !fs.existsSync(mysqlPath) || !fs.statSync(mysqlPath).isFile()) continue;

    const mysqlAdminPath = ['mysqladmin.exe', 'mariadb-admin.exe']
      .map((name) => path.join(binaryDir, name))
      .find((candidate) => fs.existsSync(candidate) && fs.statSync(candidate).isFile()) || null;
    const initializerPath = ['mariadb-install-db.exe', 'mysql_install_db.exe']
      .map((name) => path.join(binaryDir, name))
      .find((candidate) => fs.existsSync(candidate) && fs.statSync(candidate).isFile()) || null;

    return {
      mysqldPath: path.resolve(serverPath),
      mysqlPath: path.resolve(mysqlPath),
      mysqlAdminPath: mysqlAdminPath ? path.resolve(mysqlAdminPath) : null,
      initializerPath: initializerPath ? path.resolve(initializerPath) : null,
      dataDir: getMysqlDataDir(),
      baseDir: path.resolve(baseDir),
      binaryDir: path.resolve(binaryDir),
      variant: path.basename(serverPath, '.exe').toLowerCase() === 'mariadbd' ? 'mariadb' : 'mysql',
    };
  }

  throw createMissingRuntimeError(
    'mysql',
    expectedFiles,
    roots,
    'Bundled MySQL/MariaDB runtime is missing or incomplete.',
  );
}

function getJavaPath() {
  const bundledJavaCandidates = getPortableCandidates()
    .map((root) => path.join(root, 'java', 'bin', 'java.exe'));
  const bundledJava = bundledJavaCandidates.find((candidate) => fs.existsSync(candidate) && fs.statSync(candidate).isFile());
  if (bundledJava) return path.resolve(bundledJava);

  // QZ Tray is optional. Keep a system-Java fallback for development and for
  // installations where the operator intentionally supplies Java on PATH.
  try {
    execFileSync('java', ['-version'], { windowsHide: true, stdio: 'pipe' });
    return 'java';
  } catch {
    throw createMissingRuntimeError(
      'java',
      bundledJavaCandidates,
      bundledJavaCandidates,
      'Java is unavailable; QZ Tray printing will remain disabled.',
    );
  }
}

function getQZTrayPath() {
  const bundledCandidates = getPortableCandidates()
    .map((root) => path.join(root, 'qz-tray', 'qz-tray.jar'));
  const devCandidate = path.resolve(__dirname, '..', '..', 'tray', 'out', 'dist', 'qz-tray.jar');
  const candidates = uniquePaths([...bundledCandidates, devCandidate]);
  return resolveExecutable(
    'qz-tray',
    candidates,
    candidates,
    'qz-tray.jar is not available; printing will remain disabled.',
  );
}

let resolvedDataDir = null;

function getConfigDir() {
  const appDataPath = process.env.APPDATA || app.getPath('appData');
  return path.join(appDataPath, 'POS System');
}

function getPrimaryDataDir() {
  const programDataPath = process.env.PROGRAMDATA || 'C:\\ProgramData';
  return path.join(programDataPath, 'POS System');
}

function getFallbackDataDir() {
  const localAppDataPath = process.env.LOCALAPPDATA || path.join(app.getPath('appData'), '..', 'Local');
  return path.join(localAppDataPath, 'POS System', 'Data');
}

function getDataDir() {
  if (resolvedDataDir) return resolvedDataDir;

  const primary = getPrimaryDataDir();
  const fallback = getFallbackDataDir();

  try {
    if (!fs.existsSync(primary)) fs.mkdirSync(primary, { recursive: true });
    const testFile = path.join(primary, `.write-test-${Math.random().toString(36).substring(7)}`);
    fs.writeFileSync(testFile, 'test');
    fs.unlinkSync(testFile);
    resolvedDataDir = primary;
  } catch (err) {
    console.warn(`[Paths] Primary directory ${primary} is not writable. Falling back to LocalAppData. Error: ${err.message}`);
    try {
      if (!fs.existsSync(fallback)) fs.mkdirSync(fallback, { recursive: true });
      resolvedDataDir = fallback;
    } catch (fallbackErr) {
      console.error(`[Paths] Fallback directory ${fallback} is also not writable:`, fallbackErr);
      resolvedDataDir = app.getPath('userData');
    }
  }

  return resolvedDataDir;
}

function getBackendDir() {
  return isPackaged()
    ? path.join(getAppUnpackedPath(), 'backend')
    : path.resolve(__dirname, '..', '..', 'backend');
}

function getDatabaseDir() {
  return isPackaged()
    ? path.join(getAppUnpackedPath(), 'database')
    : path.resolve(__dirname, '..', '..', 'database');
}

function getRuntimeDiagnostics() {
  const phpCandidates = getPhpCandidates();
  const mysqlRoots = getMysqlCandidateRoots();
  const mysqlCandidates = mysqlRoots.flatMap((root) => [
    path.join(root, 'bin', 'mysqld.exe'),
    path.join(root, 'bin', 'mariadbd.exe'),
    path.join(root, 'bin', 'mysql.exe'),
  ]);

  return {
    packaged: isPackaged(),
    platform: process.platform,
    arch: process.arch,
    appPath: getAppPath(),
    appUnpackedPath: getAppUnpackedPath(),
    resourcesPath: process.resourcesPath || null,
    processCwd: process.cwd(),
    portableCandidates: getPortableCandidates(),
    portableDir: getPortableDir(),
    php: {
      selected: phpCandidates.find((candidate) => describePath(candidate).isFile) || null,
      candidates: phpCandidates.map(describePath),
    },
    mysql: {
      selectedRoots: mysqlRoots.filter((root) => describePath(root).isDirectory),
      candidates: mysqlCandidates.map(describePath),
    },
    backendDir: describePath(getBackendDir()),
    databaseDir: describePath(getDatabaseDir()),
  };
}

function getLogsDir() {
  return path.join(getDataDir(), 'logs');
}

function getTempDir() {
  return path.join(getDataDir(), 'temp');
}

function getBackupsDir() {
  return path.join(getDataDir(), 'backups');
}

function getMysqlDataDir() {
  return path.join(getDataDir(), 'mysql_data');
}

function getRuntimeMetadataPath() {
  return path.join(getConfigDir(), 'runtime_metadata.json');
}

function getRuntimePortsPath() {
  return path.join(getConfigDir(), 'runtime_ports.json');
}

function getMigrationsFlagPath() {
  return path.join(getDataDir(), 'migrations_hash.flag');
}

function getEnvPath() {
  return path.join(getConfigDir(), '.env');
}

function getRecoveryAuthPath() {
  return path.join(getConfigDir(), 'recovery_auth.json');
}

function getCookiesPath() {
  return path.join(getConfigDir(), 'cookies.json');
}

function getDatabaseCredentialsPath() {
  return path.join(getConfigDir(), 'db_credentials.json');
}

function ensureRuntimeDirs() {
  const config = getConfigDir();
  const data = getDataDir();
  const mysql = getMysqlDataDir();
  const backups = getBackupsDir();
  const updates = path.join(data, 'updates');
  const temp = getTempDir();
  const logs = getLogsDir();

  for (const directory of [config, data, mysql, backups, updates, temp, logs]) {
    try {
      if (!fs.existsSync(directory)) fs.mkdirSync(directory, { recursive: true });
    } catch (err) {
      console.error(`[Paths] Failed to create directory: ${directory}`, err);
    }
  }

  const isPrimary = data === getPrimaryDataDir();
  console.log(`[Paths] Config Directory: ${config}`);
  console.log(`[Paths] Data Directory: ${data} (${isPrimary ? 'ProgramData' : 'LocalAppData Fallback'})`);
  console.log('[Paths] All subfolders verified/created.');
}

module.exports = {
  getAppPath,
  getAppUnpackedPath,
  getPhpCandidates,
  getPhpPath,
  getMysqlCandidateRoots,
  getMysqlPaths,
  getRuntimeDiagnostics,
  isPackaged,
  getPortableDir,
  getPortableCandidates,
  getBackendDir,
  getDatabaseDir,
  getJavaPath,
  getQZTrayPath,
  getConfigDir,
  getPrimaryDataDir,
  getFallbackDataDir,
  getDataDir,
  getLogsDir,
  getTempDir,
  getBackupsDir,
  getMysqlDataDir,
  getRuntimeMetadataPath,
  getRuntimePortsPath,
  getMigrationsFlagPath,
  getEnvPath,
  getRecoveryAuthPath,
  getCookiesPath,
  getDatabaseCredentialsPath,
  ensureRuntimeDirs,
};
