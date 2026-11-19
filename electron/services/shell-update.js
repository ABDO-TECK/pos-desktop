'use strict';

const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const { spawn, execFile } = require('node:child_process');
const { app } = require('electron');
const { readUpdateChannel, classifyVersionChannel } = require('../utils/update-channel-policy');
const {
  EXE, WORKER, BACKEND, VERSION, VALID_VERSION, VALID_OPERATION, validSecret,
  secret, canonical, plainFile, ensureDirectory, stateLocation, operationPaths, ensurePrivateRoot,
  readActive, regularPackage, readSignedDescriptor, fileHash, copyChecked, phpArgs, workerEnv, jsonLine, send,
  bytesInTree, requireSpace, pruneCompletedCopies, writeRecord,
} = require('./shell-update-files');

const keyFile = path.join(__dirname, '..', 'assets', 'shell-update-public-key.pem');
const rootFiles = /^(?:POS Desktop\.exe|icudtl\.dat|v8_context_snapshot\.bin|snapshot_blob\.bin|resources\.pak|chrome_(?:100|200)_percent\.pak|d3dcompiler_47\.dll|ffmpeg\.dll|libEGL\.dll|libGLESv2\.dll|vk_swiftshader\.dll|vk_swiftshader_icd\.json|vulkan-1\.dll|dxil\.dll|dxcompiler\.dll)$/;
const isRuntimeImage = (image) => rootFiles.test(image) || /^resources\/portable\/php\/(?:(?:ext\/)?[A-Za-z0-9_.-]+\.dll|php\.exe)$/.test(image) || image === BACKEND;

function isShellUpdateCapable() {
  return Boolean(app?.isPackaged) && process.platform === 'win32' && process.arch === 'x64' &&
    path.basename(process.execPath) === EXE && fs.existsSync(keyFile);
}

function locations() {
  const installRoot = canonical(path.dirname(process.execPath));
  const localProfile = canonical(process.env.LOCALAPPDATA);
  return { installRoot, stateRoot: stateLocation(installRoot, localProfile) };
}

const backendToken = secret();

function shellBackendEnvironment() {
  const capable = isShellUpdateCapable();
  return {
    POS_SHELL_DELTA_HANDOFF: capable ? '1' : '',
    POS_SHELL_DELTA_HANDOFF_TOKEN: capable ? backendToken : '',
    POS_SHELL_DELTA_NATIVE_VERSION: capable ? app.getVersion() : '',
    POS_SHELL_SOURCE_GATE: capable ? path.join(locations().stateRoot, 'active.json') : '',
  };
}

function workerFor(paths, mode, installRoot, id, stdio) {
  const program = plainFile(path.join(paths.runtime, EXE));
  const script = plainFile(path.join(paths.worker, WORKER));
  return spawn(program, [script, mode, installRoot, id], {
    cwd: canonical(paths.worker), detached: true, windowsHide: true, stdio,
    env: { ...workerEnv(), ELECTRON_RUN_AS_NODE: '1' },
  });
}

async function copyTrustedRuntime(paths, installRoot, descriptor) {
  const originalFs = require('original-fs');
  const source = descriptor.source_inventory?.files;
  const expectedAsar = source?.['resources/app.asar'];
  if (!expectedAsar || await fileHash(path.join(installRoot, 'resources', 'app.asar'), originalFs) !== expectedAsar.sha256) {
    throw new Error('The running application does not match the signed source.');
  }
  for (const directory of [paths.operation, paths.worker, paths.runtime, paths.php, path.join(paths.php, 'ext'), paths.transaction]) {
    ensureDirectory(directory);
  }
  const images = {};
  for (const relative of Object.keys(source).filter(isRuntimeImage)) {
    const original = path.join(installRoot, relative);
    let target;
    if (relative === BACKEND) {
      target = paths.backend;
    } else if (relative.startsWith('resources/portable/php/')) {
      target = path.join(paths.php, relative.slice('resources/portable/php/'.length));
    } else {
      target = path.join(paths.runtime, relative);
    }
    await copyChecked(original, target, source[relative]);
    images[relative] = target;
  }
  for (const required of [EXE, 'icudtl.dat', 'v8_context_snapshot.bin', BACKEND, 'resources/portable/php/php.exe',
    ...['zip', 'openssl', 'mbstring', 'pdo_mysql'].map((module) => 'resources/portable/php/ext/php_' + module + '.dll')]) {
    if (!images[required]) throw new Error('The signed source does not include the required supervisor runtime.');
  }
  for (const [target, original] of Object.entries({
    [WORKER]: path.join(__dirname, WORKER),
    'shell-update-files.js': path.join(__dirname, 'shell-update-files.js'),
    'update-coordination.js': path.join(__dirname, '..', 'utils', 'update-coordination.js'),
    'OwnedChildProcess.cs': path.join(__dirname, '..', 'assets', 'OwnedChildProcess.cs'),
    'shell-update-process.ps1': path.join(__dirname, 'shell-update-process.ps1'),
  })) {
    fs.writeFileSync(path.join(paths.worker, target), fs.readFileSync(original), { flag: 'wx' });
  }
  if (await fileHash(path.join(installRoot, 'resources', 'app.asar'), originalFs) !== expectedAsar.sha256) {
    throw new Error('The source package changed while the supervisor was being staged.');
  }
  return images;
}

function measureDatabase(paths, database) {
  return new Promise((resolve, reject) => {
    const child = execFile(plainFile(path.join(paths.php, 'php.exe')), phpArgs(paths), {
      cwd: paths.php, env: { ...workerEnv(), ...database }, windowsHide: true,
      timeout: 20_000, maxBuffer: 32768,
    }, (error, stdout) => {
      if (error) {
        reject(new Error('The live POS database contract could not be measured.'));
      } else {
        try { resolve(JSON.parse(stdout).data_compatibility); } catch (failure) { reject(failure); }
      }
    });
    child.stdin.on('error', reject);
    child.stdin.end(JSON.stringify({ command: 'data-proof' }));
  });
}

async function prepareSupervisor({ version, storageDir, pending, lease, database }) {
  if (!isShellUpdateCapable()) throw new Error('This desktop installation does not support native shell updates.');
  const { installRoot, stateRoot } = locations();
  const dataDir = canonical(storageDir);
  await ensurePrivateRoot(stateRoot);
  if (readActive(stateRoot)) throw new Error('An unfinished shell update requires recovery.');

  const packageDir = regularPackage(dataDir, version);
  const descriptor = readSignedDescriptor(packageDir, fs.readFileSync(keyFile));
  const installedVersion = JSON.parse(fs.readFileSync(plainFile(path.join(installRoot, VERSION), 65536), 'utf8')).version;
  const nativeVersion = app.getVersion();
  const channel = readUpdateChannel(dataDir, path.join(installRoot, 'resources', 'app.asar.unpacked'));
  if (!VALID_VERSION.test(installedVersion) || descriptor.format !== 2 || descriptor.operation !== 'shell_delta' ||
      descriptor.purpose !== 'regular' || descriptor.platform !== 'win32' || descriptor.arch !== 'x64' ||
      descriptor.from_version !== installedVersion || descriptor.from_native_version !== nativeVersion ||
      (pending.from_version && pending.from_version !== installedVersion) ||
      descriptor.from_channel !== channel || descriptor.channel !== channel ||
      classifyVersionChannel(version) !== channel || descriptor.version !== version || !require('semver').valid(descriptor.native_version)) {
    throw new Error('The signed shell package does not match this installed source and update channel.');
  }
  if (!require('semver').gt(version, installedVersion)) throw new Error('The shell update is not newer than the installed version.');

  const databaseBytes = bytesInTree(path.join(dataDir, 'mysql_data'));
  const runtimeBytes = Object.entries(descriptor.source_inventory.files)
    .filter(([name]) => isRuntimeImage(name))
    .reduce((total, [, file]) => total + BigInt(file.size), 0n);
  const transactionBytes = fs.statSync(path.join(packageDir, 'shell-delta.zip'), { bigint: true }).size +
    [...descriptor.files.map((file) => file.path), ...descriptor.deleted_files]
      .reduce((total, name) => total + BigInt(descriptor.source_inventory.files[name]?.size ?? 0) +
        BigInt(descriptor.target_inventory.files[name]?.size ?? 0), 0n);
  const margin = 64n * 1024n * 1024n;
  const sameVolume = path.parse(dataDir).root.toLowerCase() === path.parse(stateRoot).root.toLowerCase();
  requireSpace(stateRoot, runtimeBytes + transactionBytes + databaseBytes * (sameVolume ? 2n : 1n) + margin);
  if (!sameVolume) requireSpace(dataDir, databaseBytes + margin);
  const largestChange = descriptor.files.reduce((largest, entry) => Math.max(largest, entry.size), 0);
  requireSpace(installRoot, BigInt(largestChange) + margin);

  const id = 'tx_' + secret().slice(0, 32);
  const paths = operationPaths(stateRoot, id);
  let images;
  let dataCompatibility;
  try {
    images = await copyTrustedRuntime(paths, installRoot, descriptor);
    dataCompatibility = await measureDatabase(paths, database);
  } catch (error) {
    if (fs.existsSync(paths.operation)) {
      writeRecord(paths.receipt, { operation_id: id, outcome: 'failed', phase: 'preparing_worker' });
      cleanup(stateRoot);
    }
    throw error;
  }
  const job = {
    id, installRoot, dataDir, target: version, nativeTarget: descriptor.native_version, sourcePid: process.pid,
    releaseJob: pending.operation_id ?? null, lease,
    context: { version: installedVersion, native_version: nativeVersion, channel, data_compatibility: dataCompatibility },
  };
  const worker = workerFor(paths, 'begin', installRoot, id, ['pipe', 'pipe', 'ignore']);
  let timer;
  try {
    await new Promise((resolve, reject) => {
      timer = setTimeout(() => reject(new Error('The independent shell updater did not prepare the package in time.')), 190_000);
      worker.once('error', reject);
      worker.once('exit', () => reject(new Error('The independent shell updater exited before preparation.')));
      worker.stdin.on('error', reject);
      jsonLine(worker.stdout, (frame) => {
        if (frame.event === 'prepared') resolve();
        else if (frame.event === 'rejected') reject(new Error(frame.error));
      }, reject);
      send(worker.stdin, { event: 'begin', job, package: packageDir, images });
    });
  } catch (error) {
    worker.stdin.destroy();
    worker.stdout.destroy();
    worker.unref();
    throw error;
  } finally {
    clearTimeout(timer);
  }

  return {
    id,
    async confirmShutdown() {
      await new Promise((resolve, reject) => {
        worker.stdin.once('error', reject);
        worker.stdin.end('{"event":"stopped"}\n', resolve);
      });
      worker.stdout.destroy();
      worker.unref();
    },
    abort() {
      worker.stdin.end('{"event":"abort"}\n');
      worker.stdout.destroy();
      worker.unref();
    },
  };
}


async function connectProbation(record, stateRoot, probation, onAbandoned) {
  if (!validSecret.test(probation.token) || !VALID_OPERATION.test(probation.id) || record.id !== probation.id ||
      !Number.isInteger(probation.endpoint) || probation.endpoint < 1 || probation.endpoint > 65535) {
    throw new Error('Invalid supervised startup identity.');
  }
  const paths = operationPaths(stateRoot, record.id);
  const socket = net.createConnection({ host: '127.0.0.1', port: probation.endpoint });
  const report = (event, details = {}) => send(socket, { event, pid: process.pid, id: record.id, token: probation.token, ...details });
  let admitted = false;
  let timer;
  let onAdmitted;
  const admittedSignal = new Promise((resolve) => { onAdmitted = resolve; });
  socket.on('error', () => {});
  try {
    const expected = await new Promise((resolve, reject) => {
      timer = setTimeout(() => reject(new Error('The shell updater could not authenticate this startup.')), 5000);
      socket.once('error', reject);
      socket.once('connect', () => report('starting'));
      jsonLine(socket, (frame) => {
        if (frame.command === 'probe') {
          resolve(frame.nativeVersion);
        } else if (frame.command === 'admit') {
          admitted = true;
          onAdmitted();
        }
      }, reject);
    });
    if (app.getVersion() !== expected) throw new Error('The restarted native version is incorrect.');
  } catch (error) {
    socket.destroy();
    throw error;
  } finally {
    clearTimeout(timer);
  }
  socket.on('close', () => {
    if (!admitted) onAbandoned();
  });
  return {
    token: probation.token, gate: paths.gate, report,
    async waitForAdmission() {
      await admittedSignal;
    },
  };
}

async function checkStartup(onAbandoned) {
  const raw = process.env.POS_SHELL_PROBATION;
  delete process.env.POS_SHELL_PROBATION;
  if (!app.isPackaged || process.platform !== 'win32') return { mode: 'normal' };
  const { installRoot, stateRoot } = locations();
  const record = readActive(stateRoot);
  if (!record) return { mode: raw ? 'blocked' : 'normal', cleanup: () => cleanup(stateRoot) };
  if (raw) {
    const probation = await connectProbation(record, stateRoot, JSON.parse(raw), onAbandoned);
    return { mode: 'probation', probation, cleanup: () => cleanup(stateRoot) };
  }
  if (record.phase !== 'recovery_required') {
    const paths = operationPaths(stateRoot, record.id);
    const worker = workerFor(paths, 'recover', installRoot, record.id, 'ignore');
    worker.on('error', (error) => console.error('[Shell Update] Recovery supervisor unavailable:', error.message));
    worker.unref();
  }
  return { mode: 'blocked' };
}

function cleanup(stateRoot) {
  if (!fs.existsSync(stateRoot)) return;
  try { pruneCompletedCopies(stateRoot); }
  catch (error) { console.warn('[Shell Update] Completed update cleanup deferred:', error.message); }
}

module.exports = { isShellUpdateCapable, prepareSupervisor, checkStartup, shellBackendEnvironment, backendToken };

