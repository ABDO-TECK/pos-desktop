'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const run = promisify(execFile);

const EXE = 'POS Desktop.exe';
const WORKER = 'shell-update-supervisor.js';
const BACKEND = 'resources/app.asar.unpacked/backend/backend.phar';
const VERSION = 'resources/app.asar.unpacked/version.json';
const MANIFEST = 'shell-manifest.json';
const SIGNATURE = 'shell-manifest.sig';
const PACKAGE = 'shell-delta.zip';
const VALID_OPERATION = /^tx_[0-9a-f]{32}$/;
const VALID_VERSION = /^\d+\.\d+\.\d+(?:-beta\.\d+)?$/;
const validSecret = /^[0-9a-f]{64}$/;
const SOCKET_LIMIT = 65536;

function secret() {
  return crypto.randomBytes(32).toString('hex');
}

function hash(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

function canonical(target, filesystem = fs) {
  const absolute = path.resolve(target);
  const real = filesystem.realpathSync.native(absolute);
  if (real.toLowerCase() !== absolute.toLowerCase() || filesystem.lstatSync(absolute).isSymbolicLink()) {
    throw new Error('An update path is redirected.');
  }
  return real;
}

function plainFile(target, max = Number.MAX_SAFE_INTEGER, filesystem = fs) {
  const stat = filesystem.lstatSync(canonical(target, filesystem));
  if (!stat.isFile() || stat.nlink !== 1 || stat.size > max) {
    throw new Error('An update input is not a bounded regular file.');
  }
  return target;
}

function ensureDirectory(dir) {
  try {
    fs.mkdirSync(dir);
  } catch (error) {
    if (error.code !== 'EEXIST') throw error;
  }
  if (!fs.lstatSync(canonical(dir)).isDirectory()) throw new Error('Expected update directory.');
  return dir;
}

function stateLocation(installRoot, profileRoot) {
  const base = path.join(profileRoot, 'pos-shell-update-v2');
  return path.join(base, hash(installRoot.toLowerCase()));
}

function operationPaths(stateRoot, id) {
  if (!VALID_OPERATION.test(id)) throw new Error('Invalid shell operation identity.');
  const operation = path.join(stateRoot, id);
  return {
    operation,
    worker: path.join(operation, 'worker'),
    runtime: path.join(operation, 'worker', 'runtime'),
    php: path.join(operation, 'worker', 'php'),
    backend: path.join(operation, 'worker', 'backend.phar'),
    transaction: path.join(operation, 'transaction'),
    backup: path.join(operation, 'cold-db'),
    hashes: path.join(operation, 'cold-db.json'),
    gate: path.join(operation, 'release'),
    receipt: path.join(operation, 'receipt.json'),
  };
}

function readRecord(file) {
  try {
    return JSON.parse(fs.readFileSync(plainFile(file, SOCKET_LIMIT), 'utf8'));
  } catch (error) {
    if (error.code === 'ENOENT') return null;
    throw error;
  }
}

function durableFile(file, value) {
  const temp = path.join(path.dirname(file), '.' + path.basename(file) + '-' + secret().slice(0, 20));
  let descriptor;
  try {
    descriptor = fs.openSync(temp, 'wx', 0o600);
    fs.writeFileSync(descriptor, value);
    fs.fsyncSync(descriptor);
    fs.closeSync(descriptor);
    descriptor = undefined;
    fs.renameSync(temp, file);
  } finally {
    if (descriptor !== undefined) fs.closeSync(descriptor);
    try { fs.unlinkSync(temp); } catch (error) { if (error.code !== 'ENOENT') throw error; }
  }
}

function writeRecord(file, value) {
  durableFile(file, JSON.stringify(value) + '\n');
}

function readActive(stateRoot) {
  return readRecord(path.join(stateRoot, 'active.json'));
}

function clearActive(stateRoot, id) {
  if (readActive(stateRoot)?.id !== id) throw new Error('Shell update marker ownership changed.');
  fs.unlinkSync(path.join(stateRoot, 'active.json'));
}

function systemPath(...elements) {
  const windows = process.env.SystemRoot;
  if (!windows || !path.isAbsolute(windows)) throw new Error('Windows system directory is unavailable.');
  return canonical(path.join(windows, 'System32', ...elements));
}

async function powershell(source, variable = {}) {
  const script = '$ErrorActionPreference = "Stop"\n' + source;
  const result = await run(systemPath('WindowsPowerShell', 'v1.0', 'powershell.exe'), [
    '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', Buffer.from(script, 'utf16le').toString('base64'),
  ], { env: { ...process.env, ...variable }, windowsHide: true, timeout: 15000, maxBuffer: 4 * 1024 * 1024 });
  return result.stdout.trim().replace(/^\uFEFF/, '');
}

async function ensurePrivateRoot(stateRoot) {
  ensureDirectory(path.dirname(stateRoot));
  ensureDirectory(stateRoot);
  await powershell([
    '$owner = [System.Security.Principal.WindowsIdentity]::GetCurrent().User',
    '$system = New-Object System.Security.Principal.SecurityIdentifier("S-1-5-18")',
    '$acl = New-Object System.Security.AccessControl.DirectorySecurity',
    '$acl.SetOwner($owner)',
    '$acl.SetAccessRuleProtection($true, $false)',
    'foreach ($sid in @($owner, $system)) {',
    '  $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($sid, "FullControl", "ContainerInherit,ObjectInherit", "None", "Allow")',
    '  $acl.AddAccessRule($rule)',
    '}',
    'Set-Acl -LiteralPath $env:POS_SHELL_PRIVATE -AclObject $acl',
    '$applied = Get-Acl -LiteralPath $env:POS_SHELL_PRIVATE',
    'if (!$applied.AreAccessRulesProtected -or $applied.GetOwner([System.Security.Principal.SecurityIdentifier]) -ne $owner) { throw "Private updater ACL failed." }',
    'foreach ($entry in $applied.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier])) {',
    '  if ($entry.IdentityReference -ne $owner -and $entry.IdentityReference -ne $system) { throw "External updater ACL principal." }',
    '}',
  ].join('\n'), { POS_SHELL_PRIVATE: stateRoot });
}

function regularPackage(dataDir, version) {
  if (!VALID_VERSION.test(version)) throw new Error('Invalid shell update version.');
  const directory = canonical(path.join(dataDir, 'update_staging', version, 'shell-delta'));
  plainFile(path.join(directory, MANIFEST), 16 * 1024 * 1024);
  plainFile(path.join(directory, SIGNATURE), 16384);
  plainFile(path.join(directory, PACKAGE), 500 * 1024 * 1024);
  return directory;
}

function readSignedDescriptor(directory, key) {
  const manifest = fs.readFileSync(plainFile(path.join(directory, MANIFEST), 16 * 1024 * 1024));
  const signature = fs.readFileSync(plainFile(path.join(directory, SIGNATURE), 16384), 'ascii').trim();
  const decoded = Buffer.from(signature, 'base64');
  if (signature === '' || decoded.toString('base64') !== signature ||
      !crypto.verify('sha256', manifest, key, decoded)) {
    throw new Error('The shell update signature could not be verified.');
  }
  return JSON.parse(manifest);
}

async function fileHash(file, filesystem = fs) {
  const digest = crypto.createHash('sha256');
  for await (const chunk of filesystem.createReadStream(plainFile(file, Number.MAX_SAFE_INTEGER, filesystem))) digest.update(chunk);
  return digest.digest('hex');
}

async function copyChecked(source, target, expected) {
  const stat = fs.statSync(plainFile(source), { bigint: true });
  if (stat.size !== BigInt(expected.size)) throw new Error('An updater source image has the wrong size.');
  await fs.promises.copyFile(source, target, fs.constants.COPYFILE_EXCL);
  if (await fileHash(target) !== expected.sha256) {
    throw new Error('An updater source image differs from the signed source.');
  }
  const descriptor = fs.openSync(target, 'r+');
  try { fs.fsyncSync(descriptor); } finally { fs.closeSync(descriptor); }
}

function phpArgs(paths) {
  return [
    '-n', '-d', 'extension_dir=' + path.join(paths.php, 'ext'),
    '-d', 'extension=openssl', '-d', 'extension=zip', '-d', 'extension=mbstring',
    '-d', 'extension=pdo_mysql', paths.backend, 'shell-transaction',
  ];
}

function workerEnv() {
  const win = process.env.SystemRoot;
  return {
    SystemRoot: win, WINDIR: win, PATH: path.join(win, 'System32'),
    USERPROFILE: process.env.USERPROFILE,
    APPDATA: process.env.APPDATA, LOCALAPPDATA: process.env.LOCALAPPDATA,
    PROGRAMDATA: process.env.PROGRAMDATA,
    TEMP: process.env.TEMP, TMP: process.env.TMP,
    HTTP_PROXY: process.env.HTTP_PROXY, HTTPS_PROXY: process.env.HTTPS_PROXY, NO_PROXY: process.env.NO_PROXY,
    POS_LAN_ENABLED: process.env.POS_LAN_ENABLED, DB_NAME: process.env.DB_NAME,
    APP_TIMEZONE: process.env.APP_TIMEZONE,
  };
}

function jsonLine(channel, handler, errorHandler) {
  channel.setEncoding('utf8');
  let buffer = '';
  channel.on('data', (chunk) => {
    try {
      buffer += chunk;
      if (Buffer.byteLength(buffer) > SOCKET_LIMIT) throw new Error('Oversized shell update control frame.');
      let end;
      while ((end = buffer.indexOf('\n')) >= 0) {
        const line = buffer.slice(0, end);
        buffer = buffer.slice(end + 1);
        handler(JSON.parse(line));
      }
    } catch (error) {
      errorHandler(error);
    }
  });
}

function send(channel, value) {
  channel.write(JSON.stringify(value) + '\n');
}

function bytesInTree(root) {
  canonical(root);
  let total = 0n;
  for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
    const item = path.join(root, entry.name);
    if (entry.isDirectory()) {
      total += bytesInTree(item);
    } else {
      total += fs.statSync(plainFile(item), { bigint: true }).size;
    }
  }
  return total;
}

function requireSpace(root, bytes) {
  const space = fs.statfsSync(root, { bigint: true });
  if (space.bavail * space.bsize < bytes) throw new Error('There is insufficient free disk space for a safe shell update.');
}

function inspectOwnedTree(root) {
  const physical = canonical(root);
  for (const entry of fs.readdirSync(physical, { withFileTypes: true })) {
    const item = path.join(physical, entry.name);
    if (entry.isDirectory()) inspectOwnedTree(item);
    else plainFile(item);
  }
}

function pruneCompletedCopies(stateRoot) {
  const active = readActive(stateRoot);
  const entries = fs.readdirSync(canonical(stateRoot), { withFileTypes: true });
  const terminal = [];
  for (const entry of entries) {
    if (!entry.isDirectory() || entry.name === active?.id || !VALID_OPERATION.test(entry.name)) continue;
    const paths = operationPaths(stateRoot, entry.name);
    const receipt = readRecord(paths.receipt);
    if (!receipt || receipt.operation_id !== entry.name || !['completed', 'rolled_back', 'failed'].includes(receipt.outcome)) continue;
    terminal.push({ paths, updated: fs.statSync(paths.receipt).mtimeMs });
    for (const directory of [paths.worker, paths.transaction, paths.backup]) {
      if (!fs.existsSync(directory)) continue;
      inspectOwnedTree(directory);
      // The root and every entry have been verified inside this operation's
      // protected profile directory. A still-running worker is retried later.
      try { fs.rmSync(directory, { recursive: true }); }
      catch (error) { if (!['EPERM', 'EBUSY', 'EACCES'].includes(error.code)) throw error; }
    }
  }
  terminal.sort((left, right) => right.updated - left.updated);
  for (const { paths } of terminal.slice(10)) {
    if ([paths.worker, paths.transaction, paths.backup].some((dir) => fs.existsSync(dir))) continue;
    inspectOwnedTree(paths.operation);
    fs.rmSync(paths.operation, { recursive: true });
  }
}

function sharedUpdateContext(job) {
  return { operation: 'shell_delta', operation_id: job.releaseJob, target_version: job.target, to_version: job.target };
}

module.exports = {
  EXE, WORKER, BACKEND, VERSION, MANIFEST, SIGNATURE, VALID_OPERATION, VALID_VERSION, validSecret,
  secret, hash, canonical, plainFile, ensureDirectory, stateLocation, operationPaths,
  readRecord, durableFile, writeRecord, readActive, clearActive, systemPath, powershell, ensurePrivateRoot,
  regularPackage, readSignedDescriptor, fileHash, copyChecked, phpArgs, workerEnv, jsonLine, send,
  bytesInTree, requireSpace, pruneCompletedCopies, sharedUpdateContext,
};

