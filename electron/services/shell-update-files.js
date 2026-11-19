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
  return path.join(profileRoot, 'POS-d2', 'i', hash(installRoot.toLowerCase()));
}

function stateVault(stateRoot) {
  const installations = path.dirname(stateRoot);
  const vault = path.dirname(installations);
  if (path.basename(vault).toLowerCase() !== 'pos-d2' || path.basename(installations) !== 'i' ||
      !/^[0-9a-f]{64}$/.test(path.basename(stateRoot))) {
    throw new Error('Invalid private shell update vault.');
  }
  return vault;
}

function operationPaths(stateRoot, id) {
  if (!VALID_OPERATION.test(id)) throw new Error('Invalid shell operation identity.');
  const vault = stateVault(stateRoot);
  const operation = path.join(stateRoot, id);
  const scratch = path.join(vault, 'o', id);
  const worker = path.join(scratch, 'worker');
  const transaction = path.join(vault, 't');
  return {
    operation, scratch, worker, transaction,
    scratchOwner: path.join(scratch, 'owner.json'),
    runtime: path.join(worker, 'runtime'),
    php: path.join(worker, 'php'),
    backend: path.join(worker, 'backend.phar'),
    transactionWorkspace: path.join(transaction, 'workspaces', id),
    backup: path.join(scratch, 'cold-db'),
    hashes: path.join(operation, 'cold-db.json'),
    gate: path.join(operation, 'release'),
    receipt: path.join(operation, 'receipt.json'),
  };
}

// The PHP transaction core checks absolute paths in UTF-8 bytes and uses
// fixed full SHA-256 leaf names. Keep the budget tied to its longest paths.
function transactionPathExamples(paths) {
  const digest = '0'.repeat(64);
  const nonce = '0'.repeat(16);
  const workspace = paths.transactionWorkspace;
  return [
    path.join(workspace, 'sealed', MANIFEST),
    path.join(workspace, 'sealed', SIGNATURE),
    path.join(workspace, 'sealed', PACKAGE),
    path.join(workspace, 'staged', digest + '.staged'),
    path.join(workspace, 'backups', digest + '.bak'),
    path.join(workspace, 'temp', 'pub_' + nonce + '.tmp'),
    path.join(workspace, 'temp', 'rst_' + nonce + '.tmp'),
    path.join(workspace, '.tmp_' + nonce),
    path.join(workspace, 'journal.json'),
    path.join(paths.transaction, 'locks', digest + '.lock'),
    path.join(paths.transaction, 'active_roots', digest + '.json'),
  ];
}

function assertOperationPathBudget(paths) {
  const files = [
    ...transactionPathExamples(paths), paths.gate, paths.receipt,
    path.join(paths.runtime, EXE), path.join(paths.php, 'ext', 'php_pdo_mysql.dll'),
    paths.backend, path.join(paths.worker, 'OwnedChildProcess.cs'), path.join(paths.backup, 'ibdata1'),
  ];
  if (files.some((file) => Buffer.byteLength(file, 'utf8') > 260)) {
    throw new Error('The Windows profile path is too long for an atomic shell update.');
  }
}

function scratchOwned(paths, stateRoot, id) {
  const owner = readRecord(paths.scratchOwner);
  return owner?.id === id && owner.installation === path.basename(stateRoot);
}

function assertScratchOwnership(paths, stateRoot, id) {
  if (!scratchOwned(paths, stateRoot, id)) throw new Error('Private shell worker ownership could not be verified.');
}

function allocateScratch(paths, stateRoot, id) {
  fs.mkdirSync(paths.scratch);
  canonical(paths.scratch);
  writeRecord(paths.scratchOwner, { id, installation: path.basename(stateRoot) });
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

async function powershell(source, variable = {}, operation = 'Windows supervisor query') {
  const script = '$ErrorActionPreference = "Stop"\n$ProgressPreference = "SilentlyContinue"\n' + source;
  const executable = systemPath('WindowsPowerShell', 'v1.0', 'powershell.exe');
  const timeout = 45000;
  try {
    const result = await run(executable, [
      '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', Buffer.from(script, 'utf16le').toString('base64'),
    ], {
      env: { ...workerEnv(), ...variable, PSModulePath: path.join(path.dirname(executable), 'Modules') },
      windowsHide: true, timeout, maxBuffer: 4 * 1024 * 1024,
    });
    return result.stdout.trim().replace(/^\uFEFF/, '');
  } catch (error) {
    const result = error.killed ? 'timed out after ' + (timeout / 1000) + ' seconds' : 'failed';
    const diagnostic = [error.stdout, error.stderr].filter(Boolean).join('\n').trim().slice(0, 2000);
    throw new Error(operation + ' ' + result + (diagnostic ? '\n' + diagnostic : ' before returning diagnostics.'));
  }
}

async function ensurePrivateRoot(stateRoot) {
  const vault = stateVault(stateRoot);
  ensureDirectory(vault);
  await powershell([
    '[Console]::WriteLine("Reading updater Windows identity.")',
    '$owner = [System.Security.Principal.WindowsIdentity]::GetCurrent().User',
    '$system = New-Object System.Security.Principal.SecurityIdentifier("S-1-5-18")',
    '$acl = New-Object System.Security.AccessControl.DirectorySecurity',
    '$acl.SetOwner($owner)',
    '$acl.SetAccessRuleProtection($true, $false)',
    'foreach ($sid in @($owner, $system)) {',
    '  $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($sid, "FullControl", "ContainerInherit,ObjectInherit", "None", "Allow")',
    '  $acl.AddAccessRule($rule)',
    '}',
    '[Console]::WriteLine("Applying private updater directory ACL.")',
    '[System.IO.Directory]::SetAccessControl($env:POS_SHELL_PRIVATE, $acl)',
    '[Console]::WriteLine("Verifying private updater directory ACL.")',
    '$applied = [System.IO.Directory]::GetAccessControl($env:POS_SHELL_PRIVATE)',
    'if (!$applied.AreAccessRulesProtected -or $applied.GetOwner([System.Security.Principal.SecurityIdentifier]) -ne $owner) { throw "Private updater ACL failed." }',
    'foreach ($entry in $applied.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier])) {',
    '  if ($entry.IdentityReference -ne $owner -and $entry.IdentityReference -ne $system) { throw "External updater ACL principal." }',
    '}',
  ].join('\n'), { POS_SHELL_PRIVATE: vault }, 'Private updater ACL provisioning');
  ensureDirectory(path.dirname(stateRoot));
  ensureDirectory(stateRoot);
  ensureDirectory(path.join(vault, 'o'));
  ensureDirectory(path.join(vault, 't'));
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

function discardOwnedDirectory(directory) {
  if (!fs.existsSync(directory)) return;
  inspectOwnedTree(directory);
  try { fs.rmSync(directory, { recursive: true }); }
  catch (error) { if (!['EPERM', 'EBUSY', 'EACCES'].includes(error.code)) throw error; }
}

function pruneCompletedCopies(stateRoot, retainReleaseFor = null) {
  const active = readActive(stateRoot);
  const entries = fs.readdirSync(canonical(stateRoot), { withFileTypes: true });
  const terminal = [];
  for (const entry of entries) {
    if (!entry.isDirectory() || entry.name === active?.id || !VALID_OPERATION.test(entry.name)) continue;
    const id = entry.name;
    const paths = operationPaths(stateRoot, id);
    const receipt = readRecord(paths.receipt);
    if (!receipt || receipt.operation_id !== id || !['completed', 'rolled_back', 'failed'].includes(receipt.outcome)) continue;
    terminal.push({ id, paths, updated: fs.statSync(paths.receipt).mtimeMs });
    if (scratchOwned(paths, stateRoot, id)) {
      discardOwnedDirectory(paths.worker);
      discardOwnedDirectory(paths.backup);
      if (fs.readdirSync(canonical(paths.scratch)).every((name) => name === 'owner.json')) {
        fs.unlinkSync(paths.scratchOwner);
        fs.rmdirSync(paths.scratch);
      }
    }
    const workspaceOwner = readRecord(path.join(paths.transactionWorkspace, '.pos_transaction_owner'));
    if (workspaceOwner?.operation_id === id && typeof workspaceOwner.root === 'string' &&
        hash(path.resolve(workspaceOwner.root).toLowerCase()) === path.basename(stateRoot)) {
      discardOwnedDirectory(paths.transactionWorkspace);
    }
  }
  terminal.sort((left, right) => right.updated - left.updated);
  for (const { id, paths } of terminal.slice(10)) {
    if (id === retainReleaseFor || [paths.scratch, paths.transactionWorkspace].some((dir) => fs.existsSync(dir))) continue;
    discardOwnedDirectory(paths.operation);
  }
}

function waitForSourceShutdown(nextFrame) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Source shutdown proof timed out.')), 75_000);
    nextFrame((frame) => {
      clearTimeout(timer);
      if (frame?.event === 'stopped' || frame?.event === 'drain-failed-source-exiting') {
        resolve(frame.event);
      } else {
        reject(new Error('The source did not authorize an updater-managed restart.'));
      }
    });
  });
}

function sharedUpdateContext(job) {
  return { operation: 'shell_delta', operation_id: job.releaseJob, target_version: job.target, to_version: job.target };
}

module.exports = {
  EXE, WORKER, BACKEND, VERSION, MANIFEST, SIGNATURE, VALID_OPERATION, VALID_VERSION, validSecret,
  secret, hash, canonical, plainFile, ensureDirectory, stateLocation, operationPaths,
  allocateScratch, assertScratchOwnership, assertOperationPathBudget, transactionPathExamples,
  readRecord, durableFile, writeRecord, readActive, clearActive, systemPath, powershell, ensurePrivateRoot,
  regularPackage, readSignedDescriptor, fileHash, copyChecked, phpArgs, workerEnv, jsonLine, send,
  bytesInTree, requireSpace, pruneCompletedCopies, waitForSourceShutdown, sharedUpdateContext,
};

