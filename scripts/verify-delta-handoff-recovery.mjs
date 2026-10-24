import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { createRequire } from 'node:module';

fs.mkdirSync(path.join(process.cwd(), 'storage'), { recursive: true });
const lab = fs.mkdtempSync(path.join(process.cwd(), 'storage', 'test-delta-recovery-'));
process.env.PROGRAMDATA = path.join(lab, 'programdata');
process.env.LOCALAPPDATA = path.join(lab, 'localappdata');
process.env.APPDATA = path.join(lab, 'appdata');

const require = createRequire(import.meta.url);
const handoff = require('../electron/services/delta-update-handoff.js');
const phpServer = require('../electron/services/php-server.js');

function hashOf(content) {
  return crypto.createHash('sha256').update(content).digest('hex');
}

function write(filePath, content) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, content, 'utf8');
}

function createFixtureEnv(name, options = {}) {
  const version = options.version || '0.0.10-beta.8';
  const envRoot = path.join(lab, name);
  const deployed = path.join(envRoot, 'installed');
  const storage = path.join(envRoot, 'runtime');
  const staging = path.join(storage, 'update_staging', version);
  const backups = path.join(storage, 'update-backups', 'patch_0.0.1_to_beta.8');
  const snapshotFiles = path.join(backups, 'files');

  fs.mkdirSync(deployed, { recursive: true });
  fs.mkdirSync(staging, { recursive: true });
  fs.mkdirSync(snapshotFiles, { recursive: true });

  const baselineFiles = {
    'frontend/dist/index.html': 'old frontend v0.0.1',
    'version.json': JSON.stringify({ version: '0.0.1', channel: 'stable' }, null, 2),
    ...(options.baselineExtra || {}),
  };

  for (const [relPath, content] of Object.entries(baselineFiles)) {
    write(path.join(deployed, relPath), content);
    write(path.join(snapshotFiles, relPath), content);
  }

  const stagedFiles = {
    'frontend/dist/index.html': 'new frontend v0.0.10-beta.8',
    'frontend/dist/add.js': 'console.log("new module");',
    'version.json': JSON.stringify({ version: '0.0.10-beta.8', channel: 'beta' }, null, 2),
    ...(options.stagedExtra || {}),
  };

  for (const [relPath, content] of Object.entries(stagedFiles)) {
    write(path.join(staging, relPath), content);
  }

  const manifestBaseline = {};
  for (const [relPath, content] of Object.entries(baselineFiles)) {
    manifestBaseline[relPath] = hashOf(content);
  }

  const manifestFiles = Object.entries(stagedFiles).map(([relPath, content]) => ({
    path: relPath,
    sha256: hashOf(content),
    action: 'replace',
  }));

  const manifest = {
    version,
    channel: 'beta',
    from_version: '0.0.1',
    files: manifestFiles,
    deleted_files: options.deletedFiles || [],
    baseline_files: manifestBaseline,
    migrations: options.migrations || [],
    ...(options.manifestExtra || {}),
  };

  const existingFilesMetadata = Object.keys(baselineFiles).map((p) => ({
    path: p,
    sha256: hashOf(baselineFiles[p]),
    size: Buffer.byteLength(baselineFiles[p]),
  }));

  let dbRecoveryObj = null;
  if (options.dbRecovery) {
    dbRecoveryObj = options.dbRecovery;
  } else if (options.migrations && options.migrations.length > 0) {
    const fakeBackupSql = path.join(storage, 'migration_safety_backup.sql');
    write(fakeBackupSql, '-- POS Auto-Update Backup\nSET FOREIGN_KEY_CHECKS=0;\n');
    dbRecoveryObj = {
      backup_path: fakeBackupSql,
      recovery_id: 'rec_fixture_default',
      sha256: hashOf('-- POS Auto-Update Backup\nSET FOREIGN_KEY_CHECKS=0;\n'),
    };
  }

  const metadata = {
    purpose: 'delta_install_safety',
    from_version: '0.0.1',
    to_version: version,
    timestamp: new Date().toISOString(),
    files: existingFilesMetadata,
    new_files: ['frontend/dist/add.js'],
    deleted_files: [],
    version_json_backup: baselineFiles['version.json'],
    baseline_files: manifestBaseline,
    db_recovery: dbRecoveryObj,
    ...(options.metadataExtra || {}),
  };

  write(path.join(backups, 'metadata.json'), JSON.stringify(metadata, null, 2));

  const plan = {
    version,
    root_dir: deployed,
    storage_dir: storage,
    staging_dir: staging,
    snapshot_path: backups,
    manifest,
    db_recovery: dbRecoveryObj,
  };

  write(path.join(staging, 'desktop-handoff.json'), JSON.stringify(plan, null, 2));

  return { envRoot, deployed, storage, staging, backups, plan, metadata };
}

class FaithfulChildProcess extends EventEmitter {
  constructor({ exitDelayMs = 0, willExit = true } = {}) {
    super();
    this.pid = process.pid;
    this.exitCode = null;
    this.signalCode = null;
    this.killed = false;
    this.willExit = willExit;
    this.exitDelayMs = exitDelayMs;
  }

  kill(signal = 'SIGTERM') {
    this.killed = true;
    if (!this.willExit) return true;
    if (this.exitDelayMs === 0) {
      process.nextTick(() => {
        this.exitCode = signal === 'SIGKILL' ? null : 0;
        this.signalCode = signal === 'SIGKILL' ? 'SIGKILL' : null;
        this.emit('exit', this.exitCode, this.signalCode);
        this.emit('close', this.exitCode, this.signalCode);
      });
    } else {
      setTimeout(() => {
        this.exitCode = 0;
        this.emit('exit', 0, null);
        this.emit('close', 0, null);
      }, this.exitDelayMs);
    }
    return true;
  }
}

try {
  console.log('--- Starting verify-delta-handoff-recovery tests ---');

  // Case 0: Direct phpServer.runDatabaseMigrations call with empty migrations array passes --migrations= to CLI for DEFINER reconciliation, and phpServer.isProcessAlive tests
  {
    assert.equal(typeof phpServer.isProcessAlive, 'function', 'isProcessAlive must be an exported function');
    assert.equal(phpServer.isProcessAlive(process.pid), true, 'Current running process must be reported as alive');
    assert.equal(phpServer.isProcessAlive(2147483647), false, 'Non-existent PID must report false');

    const liveFakeChild = new FaithfulChildProcess();
    assert.equal(phpServer.isProcessAlive(liveFakeChild), true, 'Faithful unexited child process must report alive');
    liveFakeChild.exitCode = 0;
    assert.equal(phpServer.isProcessAlive(liveFakeChild), false, 'Faithful exited child process must report dead');

    let capturedArgs = null;
    const mockSpawn = (bin, args) => {
      capturedArgs = args;
      const fakeChild = new EventEmitter();
      fakeChild.stdout = new EventEmitter();
      fakeChild.stderr = new EventEmitter();
      process.nextTick(() => {
        fakeChild.emit('close', 0, null);
      });
      return fakeChild;
    };
    await phpServer.runDatabaseMigrations({
      mysqlPort: 3306,
      dbCredentials: { user: 'root', password: '' },
      apiPort: 8000,
      migrations: [],
      spawnProcess: mockSpawn,
    });
    assert.ok(capturedArgs, 'spawnProcess must be called');
    assert.ok(
      capturedArgs.includes('--migrations='),
      `Captured args must include '--migrations=', got: ${JSON.stringify(capturedArgs)}`
    );
    console.log('[PASS] Case 0: Direct phpServer.runDatabaseMigrations with empty migrations array passes --migrations= to CLI and isProcessAlive exports correctly');
  }

  // Case 1: success and version last with actual fs.renameSync instrumentation
  {
    const fixture = createFixtureEnv('case-1-success');
    const renames = [];
    const origRenameSync = fs.renameSync;

    fs.renameSync = function patchedRename(oldPath, newPath) {
      if (newPath.startsWith(fixture.deployed)) {
        renames.push({
          rel: path.relative(fixture.deployed, newPath).replace(/\\/g, '/'),
          sha256: hashOf(fs.readFileSync(oldPath)),
        });
      }
      return origRenameSync.call(fs, oldPath, newPath);
    };

    let stopWorkerCalled = false;
    let stopPhpCalled = false;
    let restartCalled = false;

    try {
      const result = await handoff.orchestrateDeltaInstall({
        storageDir: fixture.storage,
        version: '0.0.10-beta.8',
        expectedRoot: fixture.deployed,
        lease: { owner_id: 'test-lease-1' },
        hooks: {
          stopJobWorker: async () => { stopWorkerCalled = true; },
          stopPHP: async () => { stopPhpCalled = true; },
          restartPhpAndWorker: async (opts) => {
            restartCalled = true;
            assert.deepEqual(opts?.startupMigrations, [], 'Must pass empty startupMigrations on update restart');
          },
          writeUpdateState: () => {},
        },
      });

      assert.equal(result.ok, true, `Case 1 failed: ${result.error}`);
      assert.equal(stopWorkerCalled, true);
      assert.equal(stopPhpCalled, true);
      assert.equal(restartCalled, true);
      assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'), 'new frontend v0.0.10-beta.8');
      assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/add.js'), 'utf8'), 'console.log("new module");');

      // Assert version.json was renamed strictly LAST among fixture mutations
      const lastRename = renames[renames.length - 1];
      assert.equal(lastRename.rel, 'version.json', 'version.json must be renamed strictly last');
      const expectedVersionSha = hashOf(fs.readFileSync(path.join(fixture.staging, 'version.json')));
      assert.equal(lastRename.sha256, expectedVersionSha, 'version.json rename must match staged hash');

      const statePath = path.join(fixture.storage, 'update-state.json');
      const stateData = JSON.parse(fs.readFileSync(statePath, 'utf8'));
      assert.equal(stateData.state, 'completed');
      console.log('[PASS] Case 1: success and version last');
    } finally {
      fs.renameSync = origRenameSync;
    }
  }

  // Case 2: all staged hashes checked upfront
  {
    const fixture = createFixtureEnv('case-2-staged-hashes');
    write(path.join(fixture.staging, 'frontend/dist/add.js'), 'tampered javascript content');

    let stopPhpCalled = false;
    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage,
      version: '0.0.10-beta.8',
      expectedRoot: fixture.deployed,
      lease: { owner_id: 'test-lease-2' },
      hooks: {
        stopPHP: async () => { stopPhpCalled = true; },
      },
    });

    assert.equal(result.ok, false);
    assert.equal(result.phase, 'preflight');
    assert.match(result.error, /Staged artifact (hash mismatch|integrity check failed)/);
    assert.equal(stopPhpCalled, false, 'Runtime must NOT be stopped when preflight fails');
    assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'), 'old frontend v0.0.1');
    assert.equal(fs.existsSync(path.join(fixture.deployed, 'frontend/dist/add.js')), false);
    console.log('[PASS] Case 2: all staged hashes checked upfront');
  }

  // Case 3: no baseline unsafe apply
  {
    const fixture = createFixtureEnv('case-3-baseline');
    write(path.join(fixture.deployed, 'frontend/dist/index.html'), 'locally modified html');

    let stopPhpCalled = false;
    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage,
      version: '0.0.10-beta.8',
      expectedRoot: fixture.deployed,
      lease: { owner_id: 'test-lease-3' },
      hooks: {
        stopPHP: async () => { stopPhpCalled = true; },
      },
    });

    assert.equal(result.ok, false);
    assert.equal(result.phase, 'preflight');
    assert.match(result.error, /Baseline file hash mismatch|Installed artifact differs from delta baseline/);
    assert.equal(stopPhpCalled, false);
    assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'), 'locally modified html');
    console.log('[PASS] Case 3: no baseline unsafe apply');
  }

  // Case 4: EPERM copy/rename with stale TMP present + fresh unique TMP rollback
  {
    const fixture = createFixtureEnv('case-4-samepid-temp');
    const staleTmpPath = path.join(fixture.deployed, 'frontend/dist', `.index.html.delta-${process.pid}.tmp`);
    write(staleTmpPath, 'stale leftover tmp from prior run');

    let currentSource = '';
    const origCopy = fs.copyFileSync;
    const origRename = fs.renameSync;

    fs.copyFileSync = function (src, dst, flags) {
      currentSource = src;
      return origCopy.call(fs, src, dst, flags);
    };

    fs.renameSync = function (from, to) {
      if (currentSource.startsWith(fixture.staging) && to.includes('index.html')) {
        const err = new Error('EPERM: operation not permitted, rename');
        err.code = 'EPERM';
        throw err;
      }
      return origRename.call(fs, from, to);
    };

    try {
      const applyRes = await handoff.applyPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
      assert.equal(applyRes.ok, false);
      assert.equal(applyRes.mutations_started, true);
      assert.equal(applyRes.rolled_back, true);
      // Stale TMP was untouched and not collided with
      assert.equal(fs.readFileSync(staleTmpPath, 'utf8'), 'stale leftover tmp from prior run');
      // Target was rolled back safely to old baseline
      assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'), 'old frontend v0.0.1');
      console.log('[PASS] Case 4: EPERM copy/rename + rollback same PID temp not reused');
    } finally {
      fs.copyFileSync = origCopy;
      fs.renameSync = origRename;
      fs.unlinkSync(staleTmpPath);
    }
  }

  // Case 5: real apply failure AND separate rollback failure on one file
  {
    const fixture = createFixtureEnv('case-5-rollback-fail', {
      baselineExtra: {
        'frontend/dist/fileA.js': 'content A baseline',
        'frontend/dist/fileB.js': 'content B baseline',
      },
      stagedExtra: {
        'frontend/dist/fileA.js': 'content A staged',
        'frontend/dist/fileB.js': 'content B staged',
      },
    });

    let currentSource = '';
    const origCopy = fs.copyFileSync;
    const origRename = fs.renameSync;

    fs.copyFileSync = function (src, dst, flags) {
      currentSource = src;
      return origCopy.call(fs, src, dst, flags);
    };

    // Inject rename failure on apply for fileB, and restore failure on rollback for fileA
    fs.renameSync = function (from, to) {
      if (currentSource.startsWith(fixture.staging) && to.includes('fileB.js')) {
        const err = new Error('Simulated apply EPERM on fileB.js');
        err.code = 'EPERM';
        throw err;
      }
      if (currentSource.startsWith(fixture.backups) && to.includes('fileA.js')) {
        const err = new Error('Simulated rollback EACCES on fileA.js');
        err.code = 'EACCES';
        throw err;
      }
      return origRename.call(fs, from, to);
    };

    let restartCalled = false;
    try {
      const res = await handoff.orchestrateDeltaInstall({
        storageDir: fixture.storage,
        version: '0.0.10-beta.8',
        expectedRoot: fixture.deployed,
        lease: { owner_id: 'test-lease-5' },
        hooks: {
          stopJobWorker: async () => {},
          stopPHP: async () => {},
          restartPhpAndWorker: async () => { restartCalled = true; },
        },
      });

      assert.equal(res.ok, false);
      assert.equal(res.reason_code, 'recovery_required');
      // Assert distinct primary apply error and secondary rollback error
      assert.match(res.primary_error, /Simulated apply EPERM on fileB\.js/);
      assert.ok(res.rollback_errors.some((e) => e.error.includes('fileA.js')));
      assert.equal(restartCalled, false, 'Runtime must NOT restart on mixed/unverified files');
      console.log('[PASS] Case 5: original error preserved if rollback also fails');
    } finally {
      fs.copyFileSync = origCopy;
      fs.renameSync = origRename;
    }
  }

  // Case 6: remaining restore entries attempted
  {
    const fixture = createFixtureEnv('case-6-remaining-entries', {
      baselineExtra: {
        'frontend/dist/fileA.js': 'content A baseline',
        'frontend/dist/fileB.js': 'content B baseline',
        'frontend/dist/fileC.js': 'content C baseline',
      },
      stagedExtra: {
        'frontend/dist/fileA.js': 'content A new',
        'frontend/dist/fileB.js': 'content B new',
        'frontend/dist/fileC.js': 'content C new',
      },
    });

    write(path.join(fixture.deployed, 'frontend/dist/fileA.js'), 'content A new');
    write(path.join(fixture.deployed, 'frontend/dist/fileB.js'), 'content B new');
    write(path.join(fixture.deployed, 'frontend/dist/fileC.js'), 'content C new');

    // Remove backup for fileA
    fs.unlinkSync(path.join(fixture.backups, 'files/frontend/dist/fileA.js'));

    const rb = await handoff.rollback(fixture.plan);
    assert.equal(rb.ok, false);
    // Crucial check: files B and C MUST have been restored even though file A failed!
    assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/fileB.js'), 'utf8'), 'content B baseline');
    assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/fileC.js'), 'utf8'), 'content C baseline');
    assert.ok(rb.errors.some((e) => e.path.includes('fileA')));
    console.log('[PASS] Case 6: remaining restore entries attempted');
  }

  // Case 7: structured partial recovery retained backup
  {
    const fixture = createFixtureEnv('case-7-partial-recovery', {
      baselineExtra: {
        'frontend/dist/file1.js': 'baseline 1',
        'frontend/dist/file2.js': 'baseline 2',
      },
    });
    fs.unlinkSync(path.join(fixture.backups, 'files/frontend/dist/file1.js'));

    const rb = await handoff.rollback(fixture.plan);
    assert.equal(rb.ok, false);
    assert.equal(rb.verified, false);
    assert.equal(typeof rb.retained_backup, 'string');
    assert.equal(fs.existsSync(rb.retained_backup), true, 'Backup snapshot must be retained');
    assert.equal(fs.existsSync(rb.retained_staging), true, 'Staging directory must be retained');
    console.log('[PASS] Case 7: structured partial recovery retained backup');
  }

  // Case 8: no DB restore on preflight fail
  {
    const fixture = createFixtureEnv('case-8-no-db-restore', {
      migrations: ['2026_10_migration.sql'],
    });
    // Corrupt staged version.json
    write(path.join(fixture.staging, 'version.json'), 'corrupt version');

    let dbRestoreCalled = false;
    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage,
      version: '0.0.10-beta.8',
      expectedRoot: fixture.deployed,
      lease: { owner_id: 'test-lease-8' },
      hooks: {
        restoreDatabaseSafety: async () => { dbRestoreCalled = true; },
        stopPHP: async () => {},
        restartPhpAndWorker: async () => {},
      },
    });

    assert.equal(result.ok, false);
    assert.equal(result.phase, 'preflight');
    assert.equal(dbRestoreCalled, false, 'Database safety restore must NOT be called on preflight failure');
    console.log('[PASS] Case 8: no DB restore on preflight fail');
  }

  // Case 9: migration failure DB restore then files (with event order check)
  {
    const fixture = createFixtureEnv('case-9-migration-fail', {
      migrations: ['2026_10_migration.sql'],
    });

    const eventOrder = [];
    const origRename = fs.renameSync;
    let dbRestoredFlag = false;

    fs.renameSync = function (from, to) {
      if (to.includes('index.html') && dbRestoredFlag) {
        eventOrder.push('file_rollback_rename');
      }
      return origRename.call(fs, from, to);
    };

    try {
      const result = await handoff.orchestrateDeltaInstall({
        storageDir: fixture.storage,
        version: '0.0.10-beta.8',
        expectedRoot: fixture.deployed,
        lease: { owner_id: 'test-lease-9' },
        hooks: {
          stopJobWorker: async () => {},
          stopPHP: async () => {},
          runDatabaseMigrations: async () => {
            eventOrder.push('migrations_run');
            throw new Error('Database schema migration failed: duplicate column');
          },
          restoreDatabaseSafety: async () => {
            eventOrder.push('db_restore_safety');
            dbRestoredFlag = true;
          },
          restartPhpAndWorker: async () => {
            eventOrder.push('restart_old_runtime');
          },
        },
      });

      assert.equal(result.ok, false);
      assert.equal(result.phase, 'migration');
      assert.equal(result.primary_error, 'Database schema migration failed: duplicate column');
      assert.equal(eventOrder[0], 'migrations_run');
      assert.equal(eventOrder[1], 'db_restore_safety', 'DB restore must happen strictly before file rollback');
      assert.ok(eventOrder.indexOf('file_rollback_rename') > eventOrder.indexOf('db_restore_safety'));
      assert.equal(result.db_restored, true);
      assert.equal(result.rolled_back, true);
      console.log('[PASS] Case 9: migration failure DB restore then files exactly once');
    } finally {
      fs.renameSync = origRename;
    }
  }

  // Case 10: restart failure post-install: stop before DB/files, DB restore, second restart failure
  {
    const fixture = createFixtureEnv('case-10-restart-fail', {
      migrations: ['2026_10_migration.sql'],
    });

    const sequence = [];
    let restartAttempts = 0;

    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage,
      version: '0.0.10-beta.8',
      expectedRoot: fixture.deployed,
      lease: { owner_id: 'test-lease-10' },
      hooks: {
        stopJobWorker: async () => { sequence.push('stop_worker'); },
        stopPHP: async () => { sequence.push('stop_php'); },
        runDatabaseMigrations: async () => { sequence.push('run_migrations'); },
        restoreDatabaseSafety: async () => { sequence.push('restore_db'); },
        restartPhpAndWorker: async () => {
          restartAttempts += 1;
          if (restartAttempts === 1) {
            sequence.push('restart_post_install_fail');
            throw new Error('PHP server failed to bind port 8080: EADDRINUSE');
          } else {
            sequence.push('restart_after_rollback_fail');
            throw new Error('Old PHP server failed to start: corrupt config');
          }
        },
      },
    });

    assert.equal(result.ok, false);
    assert.equal(result.phase, 'restart');
    assert.equal(result.primary_error, 'PHP server failed to bind port 8080: EADDRINUSE');
    assert.ok(result.secondary_errors.some((e) => e.includes('Old PHP server failed to start')));
    const failIdx = sequence.indexOf('restart_post_install_fail');
    const stopIdx = sequence.indexOf('stop_php', failIdx);
    const dbIdx = sequence.indexOf('restore_db', stopIdx);
    assert.ok(stopIdx > failIdx, 'Must confirm runtime stopped before recovery');
    assert.ok(dbIdx > stopIdx, 'Must restore DB after runtime stop');
    console.log('[PASS] Case 10: restart failure doesn\'t overwrite cause and recovers in order');
  }

  // Case 11: stop worker failure aborts BEFORE file mutations; stop PHP timeout retains child
  {
    // 11a: Unit test stopPhpServerAwaitable with faithful child
    const mockChild = new FaithfulChildProcess({ exitDelayMs: 20 });
    const stopRes = await phpServer.stopPhpServerAwaitable({ timeoutMs: 1000, processRef: mockChild });
    assert.equal(stopRes.stopped, true);

    // 11b: Unit test stopPhpServerAwaitable timeout when process refuses to exit
    const mockStubborn = new FaithfulChildProcess({ willExit: false });
    let timeoutCaught = false;
    try {
      await phpServer.stopPhpServerAwaitable({ timeoutMs: 100, processRef: mockStubborn });
    } catch (err) {
      timeoutCaught = true;
      assert.equal(err.code, 'PHP_STOP_TIMEOUT');
    }
    assert.equal(timeoutCaught, true);

    // 11c: Worker stop failure aborts BEFORE file mutation
    const fixture = createFixtureEnv('case-11-worker-stop-fail');
    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage,
      version: '0.0.10-beta.8',
      expectedRoot: fixture.deployed,
      lease: { owner_id: 'test-lease-11' },
      hooks: {
        stopJobWorker: async () => {
          throw new Error('Worker process PID 1234 refused to exit: timeout');
        },
        stopPHP: async () => {},
      },
    });

    assert.equal(result.ok, false);
    assert.match(result.phase, /stop_worker|stop_runtime/);
    assert.match(result.primary_error, /Worker process PID 1234 refused to exit/);
    assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'), 'old frontend v0.0.1');
    console.log('[PASS] Case 11: stop await exit not killed timeout no changes');
  }

  // Case 12: plan path escapes & exact allowlist
  {
    const maliciousPaths = [
      '../outside.txt',
      '../../etc/passwd',
      'C:/windows/system32/cmd.exe',
      '\\unc\\share\\exploit.exe',
      'backend/backend.phar.evil',
      'version.jsonx',
      'backend/storage/business.db',
      'backend/certs/../../.env',
      'frontend/dist/digital-certificate.txt',
    ];

    for (const badPath of maliciousPaths) {
      assert.equal(handoff.isAllowed(badPath), false, `Path "${badPath}" must be rejected by isAllowed`);
      assert.throws(
        () => handoff.resolveSafeTargetPath(lab, badPath),
        /Unsafe delta path rejected|Delta target escapes root directory|Path escapes root boundary/,
        `Path "${badPath}" must throw on resolveSafeTargetPath`
      );
    }
    console.log('[PASS] Case 12: plan paths escapes & exact allowlist rejected');
  }

  // Case 13: Reparse / junction escape check
  {
    const testRoot = path.join(lab, 'reparse-root');
    fs.mkdirSync(testRoot, { recursive: true });

    const linkTarget = path.join(lab, 'link-target');
    fs.mkdirSync(linkTarget, { recursive: true });
    const linkPath = path.join(testRoot, 'frontend');
    let symlinkCreated = false;
    try {
      fs.symlinkSync(linkTarget, linkPath, 'junction');
      symlinkCreated = true;
    } catch (err) {
      throw new Error(`Case 13 failed: NTFS junction creation failed in lab (${err.message}). Must fail non-zero if junctions cannot be created.`);
    }

    try {
      assert.throws(
        () => handoff.resolveSafeTargetPath(testRoot, 'frontend/dist/test.js'),
        /Reparse point or (symbolic link|junction) rejected/
      );
    } finally {
      try {
        fs.rmdirSync(linkPath);
      } catch {
        try {
          fs.unlinkSync(linkPath);
        } catch {}
      }
    }
    console.log('[PASS] Case 13: Reparse / junction escape check');
  }

  // Case 14a: Malformed plan returns structured preflight failure
  {
    const fixture = createFixtureEnv('case-14a-malformed');
    write(path.join(fixture.staging, 'desktop-handoff.json'), '{"invalid_json": true');

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, false);
    assert.equal(preflight.phase, 'preflight');
    assert.match(preflight.error, /Invalid desktop handoff plan JSON/);
    console.log('[PASS] Case 14a: Malformed plan returns structured preflight failure');
  }

  // Case 14b: Manifest missing signed version.json entry rejected upfront
  {
    const fixture = createFixtureEnv('case-14b-missing-version-entry');
    const plan = JSON.parse(fs.readFileSync(path.join(fixture.staging, 'desktop-handoff.json'), 'utf8'));
    plan.manifest.files = plan.manifest.files.filter((f) => f.path !== 'version.json');
    write(path.join(fixture.staging, 'desktop-handoff.json'), JSON.stringify(plan, null, 2));

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, false);
    assert.equal(preflight.phase, 'preflight');
    assert.match(preflight.error, /signed version\.json entry/);
    console.log('[PASS] Case 14b: Manifest missing signed version.json entry rejected upfront');
  }

  // Case 14c: Snapshot metadata is null or non-object returns preflight failure
  {
    const fixture = createFixtureEnv('case-14c-null-metadata');
    write(path.join(fixture.backups, 'metadata.json'), 'null');

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, false);
    assert.equal(preflight.phase, 'preflight');
    assert.match(preflight.error, /Snapshot metadata must be a non-null object/);
    console.log('[PASS] Case 14c: Null snapshot metadata returns preflight failure');
  }

  // Case 14d: Snapshot metadata conflicts (file in both new_files and files)
  {
    const fixture = createFixtureEnv('case-14d-metadata-conflict');
    const meta = JSON.parse(fs.readFileSync(path.join(fixture.backups, 'metadata.json'), 'utf8'));
    meta.new_files.push('frontend/dist/index.html');
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(meta, null, 2));

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, false);
    assert.equal(preflight.phase, 'preflight');
    assert.match(preflight.error, /Snapshot metadata conflict/);
    console.log('[PASS] Case 14d: Conflicting metadata entries rejected upfront');
  }

  // Case 14e: Mutated file missing from snapshot coverage
  {
    const fixture = createFixtureEnv('case-14e-missing-coverage');
    const meta = JSON.parse(fs.readFileSync(path.join(fixture.backups, 'metadata.json'), 'utf8'));
    meta.files = meta.files.filter((f) => f.path !== 'frontend/dist/index.html');
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(meta, null, 2));

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, false);
    assert.equal(preflight.phase, 'preflight');
    assert.match(preflight.error, /not covered by snapshot metadata/);
    console.log('[PASS] Case 14e: Missing snapshot coverage for mutated file rejected upfront');
  }

  // Case 14f: Backup file corrupted in snapshot (hash mismatch)
  {
    const fixture = createFixtureEnv('case-14f-corrupt-backup');
    write(path.join(fixture.backups, 'files/frontend/dist/index.html'), 'corrupt backup bytes');

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, false);
    assert.equal(preflight.phase, 'preflight');
    assert.match(preflight.error, /Backup snapshot artifact integrity check failed/);
    console.log('[PASS] Case 14f: Corrupt backup artifact rejected upfront');
  }

  // Case 14g: Backup artifact missing for deleted file
  {
    const fixture = createFixtureEnv('case-14g-deleted-backup-missing', {
      baselineExtra: { 'frontend/dist/obsolete.js': 'obsolete file baseline' },
    });
    const plan = JSON.parse(fs.readFileSync(path.join(fixture.staging, 'desktop-handoff.json'), 'utf8'));
    plan.manifest.deleted_files = ['frontend/dist/obsolete.js'];
    write(path.join(fixture.staging, 'desktop-handoff.json'), JSON.stringify(plan, null, 2));

    const meta = JSON.parse(fs.readFileSync(path.join(fixture.backups, 'metadata.json'), 'utf8'));
    meta.files = meta.files.filter((f) => (typeof f === 'string' ? f : f?.path) !== 'frontend/dist/obsolete.js');
    meta.deleted_files = [{ path: 'frontend/dist/obsolete.js', sha256: hashOf('obsolete file baseline') }];
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(meta, null, 2));
    if (fs.existsSync(path.join(fixture.backups, 'files/frontend/dist/obsolete.js'))) {
      fs.unlinkSync(path.join(fixture.backups, 'files/frontend/dist/obsolete.js'));
    }

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, false);
    assert.equal(preflight.phase, 'preflight');
    assert.match(preflight.error, /Backup artifact missing for deleted file/);
    console.log('[PASS] Case 14g: Missing backup for deleted file rejected upfront');
  }

  // Case 15: Target ReadOnly attribute restored if rename fails during replaceFile
  {
    const targetFile = path.join(lab, 'test-target-readonly.txt');
    const sourceFile = path.join(lab, 'test-source.txt');
    write(targetFile, 'target content');
    write(sourceFile, 'source content');

    handoff.restoreReadOnly(targetFile);
    assert.equal(handoff.isReadOnly(targetFile), true, 'Target must initially be ReadOnly');

    const origRename = fs.renameSync;
    fs.renameSync = () => {
      const err = new Error('Injected rename failure');
      err.code = 'EPERM';
      throw err;
    };

    try {
      let replaceFailed = false;
      try {
        await handoff.replaceFile(sourceFile, targetFile, { retries: 1 });
      } catch {
        replaceFailed = true;
      }
      assert.equal(replaceFailed, true);
      assert.equal(handoff.isReadOnly(targetFile), true, 'Target must have ReadOnly restored on rename fail');
      assert.equal(fs.readFileSync(targetFile, 'utf8'), 'target content', 'Target content must be untouched');
      console.log('[PASS] Case 15: Target ReadOnly attribute restored on rename failure');
    } finally {
      fs.renameSync = origRename;
      handoff.clearReadOnlyIfSet(targetFile);
      fs.unlinkSync(targetFile);
      fs.unlinkSync(sourceFile);
    }
  }

  // Case 16a: State write failure before mutation aborts safely
  {
    const fixture = createFixtureEnv('case-16a-state-fail-before');
    const origWriteFileSync = fs.writeFileSync;
    let stopPhpCalled = false;

    fs.writeFileSync = function (targetPath, data, encoding) {
      if (typeof targetPath === 'string' && targetPath.includes('update-state.json') && String(data).includes('"applying"')) {
        const err = new Error('Injected disk full error on initial state write');
        err.code = 'ENOSPC';
        throw err;
      }
      return origWriteFileSync.call(fs, targetPath, data, encoding);
    };

    try {
      const result = await handoff.orchestrateDeltaInstall({
        storageDir: fixture.storage,
        version: '0.0.10-beta.8',
        expectedRoot: fixture.deployed,
        lease: { owner_id: 'test-lease-16a' },
        hooks: {
          stopJobWorker: async () => {},
          stopPHP: async () => { stopPhpCalled = true; },
        },
      });

      assert.equal(result.ok, false);
      assert.equal(result.phase, 'state_write');
      assert.match(result.primary_error, /Injected disk full error on initial state write/);
      assert.equal(stopPhpCalled, false, 'Runtime must NOT be stopped if initial state write fails');
      assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'), 'old frontend v0.0.1');
      console.log('[PASS] Case 16a: State write failure before mutation aborts safely with 0 mutations');
    } finally {
      fs.writeFileSync = origWriteFileSync;
    }
  }

  // Case 16b: State write failure after apply stops runtime, recovers DB/files, preserves primary + state errors, returns ok: false
  {
    const fixture = createFixtureEnv('case-16b-state-fail-after', {
      migrations: ['2026_10_migration.sql'],
    });
    const origWriteFileSync = fs.writeFileSync;
    const callOrder = [];

    fs.writeFileSync = function (targetPath, data, encoding) {
      if (typeof targetPath === 'string' && targetPath.includes('update-state.json') && String(data).includes('"completed"')) {
        const err = new Error('Injected disk full error on completed state write');
        err.code = 'ENOSPC';
        throw err;
      }
      return origWriteFileSync.call(fs, targetPath, data, encoding);
    };

    try {
      const result = await handoff.orchestrateDeltaInstall({
        storageDir: fixture.storage,
        version: '0.0.10-beta.8',
        expectedRoot: fixture.deployed,
        lease: { owner_id: 'test-lease-16b' },
        hooks: {
          stopJobWorker: async () => { callOrder.push('stop_worker'); },
          stopPHP: async () => { callOrder.push('stop_php'); },
          runDatabaseMigrations: async () => { callOrder.push('run_migrations'); },
          restartPhpAndWorker: async () => { callOrder.push('restart_installed'); },
          restoreDatabaseSafety: async () => { callOrder.push('restore_db_safety'); },
        },
      });

      assert.equal(result.ok, false);
      assert.equal(result.phase, 'completed');
      assert.match(result.primary_error, /Injected disk full error on completed state write/);
      assert.ok(result.secondary_errors.some((e) => e.includes('State persistence error')));
      assert.equal(result.db_restored, true);
      assert.equal(result.rolled_back, true);
      const restoredVersion = JSON.parse(fs.readFileSync(path.join(fixture.deployed, 'version.json'), 'utf8'));
      assert.equal(restoredVersion.version, '0.0.1');
      assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'), 'old frontend v0.0.1');
      assert.ok(callOrder.includes('restore_db_safety'));
      console.log('[PASS] Case 16b: State write failure after apply stops runtime, recovers DB/files, preserves primary + state errors, returns ok: false');
    } finally {
      fs.writeFileSync = origWriteFileSync;
    }
  }

  // Case 16c: Migrating-state write failure: assert CLI=0, restoreDB=0, file rollback safe
  {
    const fixture = createFixtureEnv('case-16c-migrating-state-fail', {
      migrations: ['2026_10_migration.sql'],
    });
    const origWriteFileSync = fs.writeFileSync;
    let cliCallCount = 0;
    let restoreDbCallCount = 0;

    fs.writeFileSync = function (targetPath, data, encoding) {
      if (
        typeof targetPath === 'string' &&
        targetPath.includes('update-state.json') &&
        String(data).includes('"migrating"')
      ) {
        const err = new Error('Injected disk full error on migrating state write');
        err.code = 'ENOSPC';
        throw err;
      }
      return origWriteFileSync.call(fs, targetPath, data, encoding);
    };

    try {
      const result = await handoff.orchestrateDeltaInstall({
        storageDir: fixture.storage,
        version: '0.0.10-beta.8',
        expectedRoot: fixture.deployed,
        lease: { owner_id: 'test-lease-16c' },
        hooks: {
          stopJobWorker: async () => {},
          stopPHP: async () => {},
          runDatabaseMigrations: async () => {
            cliCallCount += 1;
          },
          restoreDatabaseSafety: async () => {
            restoreDbCallCount += 1;
          },
          restartPhpAndWorker: async () => {},
        },
      });

      assert.equal(result.ok, false);
      assert.equal(result.phase, 'migration');
      assert.equal(cliCallCount, 0, 'CLI migrations must NOT be executed if migrating state write fails');
      assert.equal(restoreDbCallCount, 0, 'Database safety restore must NOT be called if migrations were not attempted');
      assert.equal(result.rolled_back, true, 'File rollback must succeed safely');
      assert.equal(
        fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'),
        'old frontend v0.0.1'
      );
      // Check update-state.json persisted pointers
      const stateContent = JSON.parse(fs.readFileSync(path.join(fixture.storage, 'update-state.json'), 'utf8'));
      assert.equal(stateContent.to_version, '0.0.10-beta.8');
      assert.ok(stateContent.backup_snapshot);
      console.log('[PASS] Case 16c: Migrating-state write failure: assert CLI=0, restoreDB=0, file rollback safe');
    } finally {
      fs.writeFileSync = origWriteFileSync;
    }
  }

  // Case 17: Post-restart health failure with recovery stop failure: fail-closed, NO DB restore, NO rollback, NO restart, retain new bytes
  {
    const fixture = createFixtureEnv('case-17-post-restart-stop-fail', {
      migrations: ['2026_10_migration.sql'],
    });
    let restoreDbCalls = 0;
    let restartCalls = 0;
    let stopPhpCount = 0;

    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage,
      version: '0.0.10-beta.8',
      expectedRoot: fixture.deployed,
      lease: { owner_id: 'test-lease-17' },
      hooks: {
        stopJobWorker: async () => {},
        stopPHP: async () => {
          stopPhpCount += 1;
          if (stopPhpCount > 1) {
            throw new Error('Recovery stop failed: live child unresponsive');
          }
        },
        runDatabaseMigrations: async () => {},
        restartPhpAndWorker: async () => {
          restartCalls += 1;
          throw new Error('Post-restart health check failed: PHP child process unresponsive');
        },
        restoreDatabaseSafety: async () => {
          restoreDbCalls += 1;
        },
      },
    });

    assert.equal(result.ok, false);
    assert.equal(result.reason_code, 'recovery_required');
    assert.equal(result.phase, 'recovery_stop');
    assert.match(result.primary_error, /Post-restart health check failed/);
    assert.ok(result.secondary_errors.some((e) => e.includes('Recovery stop failed')));
    assert.equal(restoreDbCalls, 0, 'Database safety restore must NOT be called when recovery stop fails');
    assert.equal(result.db_restored, false);
    assert.equal(result.rolled_back, false);
    // Assert new bytes retained (no rollback writes occurred)
    assert.equal(
      fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'),
      'new frontend v0.0.10-beta.8'
    );
    console.log('[PASS] Case 17: Fail-closed recovery stop failure returns recovery_required with new bytes retained, 0 DB restore, 0 rollback');
  }

  // Case 18: Zero-mutation runtime recovery in central catch: stopPHP tampers staged hash after first preflight
  {
    const fixture = createFixtureEnv('case-18-tamper-after-preflight');
    const restartedOld = [];

    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage,
      version: '0.0.10-beta.8',
      expectedRoot: fixture.deployed,
      lease: { owner_id: 'test-lease-18' },
      hooks: {
        stopJobWorker: async () => {},
        stopPHP: async () => {
          // Tamper staged file hash AFTER preflight has already succeeded
          write(path.join(fixture.staging, 'frontend/dist/index.html'), 'tampered corrupt staged content');
        },
        restartPhpAndWorker: async (opts) => {
          restartedOld.push(opts);
        },
      },
    });

    assert.equal(result.ok, false);
    assert.equal(result.mutations_started, false);
    assert.equal(result.restarted_old_runtime, true);
    assert.equal(restartedOld.length, 1);
    assert.deepEqual(restartedOld[0], { startupMigrations: [] });
    // Verify deployed files untouched
    assert.equal(
      fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'),
      'old frontend v0.0.1'
    );
    console.log('[PASS] Case 18: Zero-mutation runtime recovery restarts old runtime once with deployed hashes unchanged');
  }

  // Case 14h: Missing intermediate directory in DB recovery backup path rejected by resolveSafeConfinedPath
  {
    const fixture = createFixtureEnv('case-14h-missing-intermediate-db', {
      migrations: ['2026_10_migration.sql'],
    });
    const plan = JSON.parse(fs.readFileSync(path.join(fixture.staging, 'desktop-handoff.json'), 'utf8'));
    plan.db_recovery = {
      backup_path: path.join(fixture.storage, 'nonexistent_intermediate', 'sub', 'backup.sql'),
      recovery_id: 'rec_14h',
      sha256: 'abc123',
    };
    write(path.join(fixture.staging, 'desktop-handoff.json'), JSON.stringify(plan, null, 2));

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, false);
    assert.equal(preflight.phase, 'preflight');
    assert.match(preflight.error, /Database recovery backup file validation failed/);
    console.log('[PASS] Case 14h: Missing intermediate directory in DB backup path rejected by resolveSafeConfinedPath');
  }

  // Case 14i: Nested target directory remains absent on disk after preflight probe (no mkdir during preflight)
  {
    const fixture = createFixtureEnv('case-14i-nested-target-absent');
    const plan = JSON.parse(fs.readFileSync(path.join(fixture.staging, 'desktop-handoff.json'), 'utf8'));
    // Add a file in an allowed prefix with a deep non-existent directory
    const newNestedRel = 'frontend/dist/new_dir/deeper/module.js';
    write(path.join(fixture.staging, newNestedRel), 'console.log("deep");');
    plan.manifest.files.push({
      path: newNestedRel,
      sha256: hashOf('console.log("deep");'),
      action: 'replace',
    });
    write(path.join(fixture.staging, 'desktop-handoff.json'), JSON.stringify(plan, null, 2));

    const meta = JSON.parse(fs.readFileSync(path.join(fixture.backups, 'metadata.json'), 'utf8'));
    meta.new_files.push(newNestedRel);
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(meta, null, 2));

    const targetNestedDir = path.join(fixture.deployed, 'frontend/dist/new_dir');
    assert.equal(fs.existsSync(targetNestedDir), false, 'new_dir must not exist initially');

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, true, `Preflight should succeed: ${preflight.error}`);
    assert.equal(fs.existsSync(targetNestedDir), false, 'new_dir must NOT be created on disk by preflight probe!');
    console.log('[PASS] Case 14i: Nested target directory remains absent on disk after preflight probe');
  }

  // Case 14j: Existing untracked file on disk rejected if declared in metaNew
  {
    const fixture = createFixtureEnv('case-14j-untracked-file-meta-new');
    const untrackedRel = 'frontend/dist/untracked.txt';
    // Create the file on disk in deployed
    write(path.join(fixture.deployed, untrackedRel), 'existing untracked file on disk');

    const plan = JSON.parse(fs.readFileSync(path.join(fixture.staging, 'desktop-handoff.json'), 'utf8'));
    write(path.join(fixture.staging, untrackedRel), 'new content for untracked');
    plan.manifest.files.push({
      path: untrackedRel,
      sha256: hashOf('new content for untracked'),
      action: 'replace',
    });
    write(path.join(fixture.staging, 'desktop-handoff.json'), JSON.stringify(plan, null, 2));

    const meta = JSON.parse(fs.readFileSync(path.join(fixture.backups, 'metadata.json'), 'utf8'));
    meta.new_files.push(untrackedRel);
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(meta, null, 2));

    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, false);
    assert.equal(preflight.phase, 'preflight');
    assert.match(preflight.error, /Existing untracked file cannot be classified as new/);
    console.log('[PASS] Case 14j: Existing untracked file rejected in metaNew');
  }

  // Case 14k: Target directory probe catches junction / reparse point structured (new-only frontend/public junction)
  {
    const fixture = createFixtureEnv('case-14k-target-junction');
    const junctionTarget = path.join(lab, 'junction-target-case14k');
    const junctionLink = path.join(fixture.deployed, 'frontend/public');
    fs.mkdirSync(junctionTarget, { recursive: true });

    let junctionCreated = false;
    try {
      fs.symlinkSync(junctionTarget, junctionLink, 'junction');
      junctionCreated = true;
    } catch {}

    if (junctionCreated) {
      const plan = JSON.parse(fs.readFileSync(path.join(fixture.staging, 'desktop-handoff.json'), 'utf8'));
      const relThroughJunction = 'frontend/public/asset.png';
      write(path.join(fixture.staging, relThroughJunction), 'image bytes');
      plan.manifest.files.push({
        path: relThroughJunction,
        sha256: hashOf('image bytes'),
        action: 'replace',
      });
      write(path.join(fixture.staging, 'desktop-handoff.json'), JSON.stringify(plan, null, 2));

      const meta = JSON.parse(fs.readFileSync(path.join(fixture.backups, 'metadata.json'), 'utf8'));
      meta.new_files.push(relThroughJunction);
      write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(meta, null, 2));

      const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
      assert.equal(preflight.ok, false);
      assert.equal(preflight.phase, 'preflight');
      assert.match(preflight.error, /Reparse point or (?:symbolic link|junction) rejected/);
      console.log('[PASS] Case 14k: Target junction rejected structured in preflight');
    } else {
      console.log('[SKIP] Case 14k: Junction creation skipped due to environment permissions');
    }
  }

  // Case 19a: Backup corrupted after preflight is not copied during rollback
  {
    const fixture = createFixtureEnv('case-19a-corrupt-backup-after-preflight');
    const preflight = handoff.preflightPendingDelta(fixture.storage, '0.0.10-beta.8', fixture.deployed);
    assert.equal(preflight.ok, true);

    // Corrupt the backup file before calling rollback
    write(path.join(fixture.backups, 'files/frontend/dist/index.html'), 'corrupted backup bytes');

    const rbResult = await handoff.rollback(fixture.plan);
    assert.equal(rbResult.ok, false);
    assert.ok(rbResult.errors.some((e) => e.error.includes('Backup source hash mismatch before copy')));
    console.log('[PASS] Case 19a: Backup corrupted after preflight is not copied during rollback');
  }

  // Case 19b: ReadOnly target restored with ReadOnly attribute preserved after apply + migration failure
  {
    const fixture = createFixtureEnv('case-19b-readonly-restore-after-migration-fail', {
      migrations: ['2026_test.sql'],
    });
    const targetFile = path.join(fixture.deployed, 'frontend/dist/index.html');
    handoff.restoreReadOnly(targetFile);
    assert.equal(handoff.isReadOnly(targetFile), true, 'Target must initially be ReadOnly');

    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage,
      version: '0.0.10-beta.8',
      expectedRoot: fixture.deployed,
      lease: { owner_id: 'test-lease-19b' },
      hooks: {
        stopJobWorker: async () => {},
        stopPHP: async () => {},
        runDatabaseMigrations: async () => {
          throw new Error('Injected migration SQL failure');
        },
        restoreDatabaseSafety: async () => {},
        restartPhpAndWorker: async () => {},
      },
    });

    assert.equal(result.ok, false);
    assert.equal(result.rolled_back, true);
    assert.equal(fs.readFileSync(targetFile, 'utf8'), 'old frontend v0.0.1');
    assert.equal(handoff.isReadOnly(targetFile), true, 'Target must have ReadOnly restored after rollback');
    handoff.clearReadOnlyIfSet(targetFile);
    console.log('[PASS] Case 19b: ReadOnly target restored with ReadOnly attribute preserved after apply + migration failure');
  }

  // Case 19c: Direct rollback with malformed metadata returns structured failure without throwing
  {
    const fixture = createFixtureEnv('case-19c-malformed-metadata');
    // Corrupt metadata.json to have invalid non-array files
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify({ purpose: 'test', files: 'invalid-string' }));

    const rbResult = await handoff.rollback(fixture.plan);
    assert.equal(rbResult.ok, false);
    assert.equal(rbResult.verified, false);
    assert.ok(rbResult.errors.some((e) => e.error.includes('Snapshot metadata.files must be an array')));

    // Also test malformed JSON
    write(path.join(fixture.backups, 'metadata.json'), '{ invalid json');
    const rbResultJson = await handoff.rollback(fixture.plan);
    assert.equal(rbResultJson.ok, false);
    assert.ok(rbResultJson.errors.some((e) => e.error.includes('Invalid snapshot metadata JSON')));
    console.log('[PASS] Case 19c: Direct rollback with malformed metadata returns structured failure without throwing');
  }

  // Case 20: Metadata changed AFTER preflight/apply must never permit a second HTTP start.
  for (const kind of ['files', 'deleted_files', 'new_files', 'version_json_backup']) {
    const obsolete = 'frontend/dist/obsolete.js';
    const fixture = createFixtureEnv(`case-20-tampered-${kind}`, {
      baselineExtra: { [obsolete]: 'old obsolete artifact' },
      deletedFiles: [obsolete],
    });
    fixture.metadata.files = fixture.metadata.files.filter((entry) => entry.path !== obsolete);
    fixture.metadata.deleted_files = [{ path: obsolete, sha256: hashOf('old obsolete artifact') }];
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(fixture.metadata));
    let restartCalls = 0;
    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage, version: fixture.plan.version, expectedRoot: fixture.deployed,
      hooks: {
        restartPhpAndWorker: async () => {
          restartCalls += 1;
          const metadata = JSON.parse(fs.readFileSync(path.join(fixture.backups, 'metadata.json'), 'utf8'));
          if (kind === 'files') metadata.files = metadata.files.filter((entry) => entry.path !== 'frontend/dist/index.html');
          else if (kind === 'version_json_backup') metadata.version_json_backup = '{"version":"forged"}';
          else metadata[kind] = [];
          write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(metadata));
          throw new Error('Injected post-install restart failure');
        },
      },
    });
    assert.equal(result.ok, false);
    assert.equal(result.rolled_back, false);
    assert.equal(result.reason_code, 'recovery_required');
    assert.equal(result.rollback.verified, false);
    assert.equal(result.primary_error, 'Injected post-install restart failure');
    assert.ok(result.secondary_errors.some((entry) => entry.includes('metadata changed after preflight')));
    assert.equal(restartCalls, 1, 'No second HTTP start over unverified artifacts');
    assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'), 'new frontend v0.0.10-beta.8');
    assert.equal(fs.readFileSync(path.join(fixture.deployed, 'version.json'), 'utf8'), fs.readFileSync(path.join(fixture.staging, 'version.json'), 'utf8'), 'Old version must not be stamped over unrestored new files');
    assert.equal(fs.existsSync(path.join(fixture.backups, 'metadata.json')), true);
    console.log(`[PASS] Case 20 ${kind}: post-preflight metadata tampering fails closed with primary error retained`);
  }

  // Case 21: Verify signed baseline entries even when absent from the restoration lists.
  {
    const untouched = 'frontend/dist/unchanged.js';
    const fixture = createFixtureEnv('case-21-full-baseline', {
      baselineExtra: { [untouched]: 'original unchanged bytes' }, migrations: ['test.sql'],
    });
    fixture.metadata.files = fixture.metadata.files.filter((entry) => entry.path !== untouched);
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(fixture.metadata));
    let restartCalls = 0;
    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage, version: fixture.plan.version, expectedRoot: fixture.deployed,
      hooks: {
        runDatabaseMigrations: async () => {
          write(path.join(fixture.deployed, untouched), 'external corruption during transaction');
          throw new Error('Injected migration failure with baseline drift');
        },
        restartPhpAndWorker: async () => { restartCalls += 1; },
      },
    });
    assert.equal(result.primary_error, 'Injected migration failure with baseline drift');
    assert.equal(result.rolled_back, false);
    assert.equal(result.reason_code, 'recovery_required');
    assert.ok(result.rollback_errors.some((entry) => entry.path === untouched && /baseline hash mismatch/.test(entry.error)));
    assert.equal(restartCalls, 0);
    assert.equal(JSON.parse(fs.readFileSync(path.join(fixture.deployed, 'version.json'), 'utf8')).version, fixture.plan.version);
    console.log('[PASS] Case 21: Complete signed baseline mismatch blocks old version and HTTP restart');
  }

  // Case 22: Even a zero-mutation abort cannot restart an externally changed baseline.
  {
    const fixture = createFixtureEnv('case-22-zero-mutation-baseline-drift');
    let restartCalls = 0;
    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage, version: fixture.plan.version, expectedRoot: fixture.deployed,
      hooks: {
        stopPHP: async () => write(path.join(fixture.deployed, 'frontend/dist/index.html'), 'externally changed bytes'),
        restartPhpAndWorker: async () => { restartCalls += 1; },
      },
    });
    assert.equal(result.mutations_started, false);
    assert.equal(result.reason_code, 'recovery_required');
    assert.equal(result.restarted_old_runtime, false);
    assert.equal(restartCalls, 0);
    assert.match(result.primary_error, /Baseline file hash mismatch/);
    assert.ok(result.secondary_errors.some((entry) => /baseline hash mismatch/.test(entry)));
    console.log('[PASS] Case 22: Zero-mutation baseline mismatch cannot restart HTTP');
  }

  // Case 23: Successful rollback restores deleted/replaced/version bytes and removes new artifacts.
  {
    const obsolete = 'frontend/dist/obsolete.js';
    const fixture = createFixtureEnv('case-23-complete-recovery', {
      baselineExtra: { [obsolete]: 'obsolete original bytes' }, deletedFiles: [obsolete], migrations: ['test.sql'],
    });
    fixture.metadata.files = fixture.metadata.files.filter((entry) => entry.path !== obsolete);
    fixture.metadata.deleted_files = [{ path: obsolete, sha256: hashOf('obsolete original bytes') }];
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(fixture.metadata));
    let restartCalls = 0;
    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage, version: fixture.plan.version, expectedRoot: fixture.deployed,
      hooks: {
        runDatabaseMigrations: async () => {
          assert.equal(fs.existsSync(path.join(fixture.deployed, obsolete)), false);
          assert.equal(fs.existsSync(path.join(fixture.deployed, 'frontend/dist/add.js')), true);
          throw new Error('Injected migration failure');
        },
        restartPhpAndWorker: async () => {
          restartCalls += 1;
          for (const [relativePath, sha] of Object.entries(fixture.plan.manifest.baseline_files)) {
            assert.equal(hashOf(fs.readFileSync(path.join(fixture.deployed, relativePath))), sha);
          }
          assert.equal(fs.existsSync(path.join(fixture.deployed, 'frontend/dist/add.js')), false);
        },
      },
    });
    assert.equal(result.rolled_back, true);
    assert.equal(result.rollback.verified, true);
    assert.equal(result.restart_failed, false, 'Recovery restart assertions must not be swallowed as a restart failure');
    assert.equal(restartCalls, 1);
    console.log('[PASS] Case 23: Replaced/deleted/new/version baseline verified before recovery restart');
  }

  // Case 25: A successful migration hook cannot silently drift an unchanged artifact before HTTP starts.
  {
    const untouched = 'frontend/dist/unchanged.js';
    const fixture = createFixtureEnv('case-25-drift-before-install-restart', {
      baselineExtra: { [untouched]: 'original unchanged bytes' }, migrations: ['test.sql'],
    });
    fixture.metadata.files = fixture.metadata.files.filter((entry) => entry.path !== untouched);
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(fixture.metadata));
    let restartCalls = 0;
    const result = await handoff.orchestrateDeltaInstall({
      storageDir: fixture.storage, version: fixture.plan.version, expectedRoot: fixture.deployed,
      hooks: {
        runDatabaseMigrations: async () => write(path.join(fixture.deployed, untouched), 'unexpected drift'),
        restartPhpAndWorker: async () => { restartCalls += 1; },
      },
    });
    assert.match(result.primary_error, /Installed artifact verification failed before restart/);
    assert.equal(result.reason_code, 'recovery_required');
    assert.equal(result.rolled_back, false);
    assert.equal(restartCalls, 0, 'Neither new nor old HTTP may start over mixed artifacts');
    console.log('[PASS] Case 25: Full installed artifact verification blocks initial HTTP restart on baseline drift');
  }

  // Case 24: Direct rollback rejects forged hashes, not just missing coverage.
  {
    const fixture = createFixtureEnv('case-24-forged-rollback-hash');
    fixture.metadata.files.find((entry) => entry.path === 'frontend/dist/index.html').sha256 = hashOf('forged bytes');
    write(path.join(fixture.backups, 'files/frontend/dist/index.html'), 'forged bytes');
    write(path.join(fixture.backups, 'metadata.json'), JSON.stringify(fixture.metadata));
    const result = await handoff.rollback(fixture.plan);
    assert.equal(result.ok, false);
    assert.equal(result.verified, false);
    assert.ok(result.errors.some((entry) => /does not match baseline/.test(entry.error)));
    assert.equal(fs.readFileSync(path.join(fixture.deployed, 'frontend/dist/index.html'), 'utf8'), 'old frontend v0.0.1');
    console.log('[PASS] Case 24: Forged rollback metadata cannot override original baseline hashes');
  }

  console.log('--- ALL VERIFY-DELTA-HANDOFF-RECOVERY TESTS PASSED! ---');
} finally {
  if (lab && fs.existsSync(lab)) {
    try {
      fs.rmSync(lab, { recursive: true, force: true });
    } catch {}
  }
}
