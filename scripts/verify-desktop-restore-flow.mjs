import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const privilegedCli = require('../electron/services/privileged-cli.js');
const restoreManager = require('../electron/services/restore-manager.js');

function safeCleanTempDir(dirPath, expectedPrefix) {
  if (!dirPath || typeof dirPath !== 'string') {
    throw new Error('safeCleanTempDir: directory path must be a non-empty string');
  }
  const realTarget = fs.realpathSync(dirPath);
  const realTmp = fs.realpathSync(os.tmpdir());
  const relative = path.relative(realTmp, realTarget);
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) {
    throw new Error(`safeCleanTempDir: target path "${realTarget}" is not a strict child of temp root "${realTmp}"`);
  }
  const basename = path.basename(realTarget);
  if (!basename.startsWith(expectedPrefix)) {
    throw new Error(`safeCleanTempDir: target folder "${basename}" does not start with expected prefix "${expectedPrefix}"`);
  }
  fs.rmSync(realTarget, { recursive: true, force: true });
}

console.log('--- POS Desktop Restore Flow & Privileged CLI Mock/Simulation Verification ---');

// ── 1. Privileged CLI Allowlist & Security Environment ──
console.log('[1/4] Verifying privileged CLI allowlist and environment isolation...');

const ALLOWED_COMMANDS = [
  'restore-backup',
  'restore-migration-safety',
  'create-restore-safety',
  'verify-database',
];

// Verify exact allowlist membership
assert.deepEqual(
  [...privilegedCli.PRIVILEGED_CLI_ALLOWLIST].sort(),
  [...ALLOWED_COMMANDS].sort(),
  'Privileged CLI allowlist must match exact required restore commands'
);

for (const cmd of ALLOWED_COMMANDS) {
  assert.equal(privilegedCli.isPrivilegedCliCommandAllowed(cmd), true, `Command ${cmd} must be allowed`);
}

// Reject unallowed commands
const FORBIDDEN_COMMANDS = ['migrate', 'seed', 'bootstrap-admin', 'reset-password', 'arbitrary-command'];
for (const cmd of FORBIDDEN_COMMANDS) {
  assert.equal(privilegedCli.isPrivilegedCliCommandAllowed(cmd), false, `Command ${cmd} must be rejected`);
}

// Environment construction and password propagation
const dummyCredentials = {
  user: 'pos_runtime',
  password: 'runtime_secret_pass_123',
  migrationUser: 'pos_migrator',
  migrationPassword: 'migrator_secret_pass_456',
};

const privilegedEnv = privilegedCli.createPrivilegedEnv({
  mysqlPort: 3307,
  dbCredentials: dummyCredentials,
  apiPort: 8080,
});

assert.equal(privilegedEnv.DB_MIGRATION_USER, 'pos_migrator');
assert.equal(privilegedEnv.DB_MIGRATION_PASS, 'migrator_secret_pass_456');

// Password redaction check
const leakedText = 'Error connecting with pos_migrator:migrator_secret_pass_456 to pos_db';
const sanitized = privilegedCli.sanitizeText(leakedText, [
  dummyCredentials.migrationPassword,
  dummyCredentials.password,
]);
assert.ok(!sanitized.includes('migrator_secret_pass_456'));
assert.ok(sanitized.includes('[REDACTED]'));

console.log('      PASS: Privileged CLI isolation and credential redaction verified.');

// ── 2. Privileged CLI Failure Parsing & Backend Reference Handling ──
console.log('[2/4] Verifying backend CLI failure formatting and reference capture...');

// Test 2.1: JSON failure output
const jsonStdout = JSON.stringify({
  ok: false,
  error: 'Database restore execution failed. Reference: fedcba0123456789',
});
const parsedJson = privilegedCli.formatBackendCliFailure(jsonStdout, '');
assert.equal(parsedJson, 'Database restore execution failed. Reference: fedcba0123456789');

// Test 2.2: Plain stderr with Reference
const rawStderr = 'Backup restore failed. Reference: 1a2b3c4d5e6f7a8b\n';
const parsedRaw = privilegedCli.formatBackendCliFailure('', rawStderr);
assert.ok(parsedRaw.includes('1a2b3c4d5e6f7a8b'));

// Test 2.3: Stderr with PHP warning prelude followed by Reference line
const noisyStderr = 'PHP Deprecated: Return type should be compatible\nDatabase verification failed: missing trigger (Reference: deadbeef01234567)\n';
const parsedNoisy = privilegedCli.formatBackendCliFailure('', noisyStderr);
const extractedNoisyRef = restoreManager.extractRestoreReference(parsedNoisy);
assert.equal(extractedNoisyRef, 'deadbeef01234567');

console.log('      PASS: Backend CLI failure formatting and reference capture verified.');

// ── 3. Desktop Restore State Machine Simulation Wired to CLI Dispatch ──
console.log('[3/4] Verifying desktop restore lifecycle unit simulation with CLI dispatch hooks...');

const labDir = fs.mkdtempSync(path.join(os.tmpdir(), 'desktop-restore-flow-'));

try {
  const dummyBackupPath = path.join(labDir, 'valid_user_backup.sql');
  fs.writeFileSync(
    dummyBackupPath,
    '-- POS Desktop User Backup\nCREATE TABLE `orders` (`id` INT PRIMARY KEY);\nINSERT INTO `orders` VALUES (101);\n'
  );

  const dummySnapshotFile = path.join(labDir, 'safety-snapshot.sql');
  const dummySnapshotMeta = path.join(labDir, 'safety-snapshot.sql.json');
  fs.writeFileSync(dummySnapshotFile, '-- Safety snapshot\nCREATE TABLE `orders` (`id` INT);\n');
  fs.writeFileSync(dummySnapshotMeta, JSON.stringify({ recovery_id: 'rec-123', sha256: 'abc' }));

  // Flow 3.1: Successful restore with complete pipeline
  let workerStopped = false;
  let phpStopped = false;
  let snapshotCreated = false;
  let restoreRan = false;
  let migrationsRan = false;
  let dbVerified = false;
  let restarted = false;
  let sessionCleared = false;
  let windowReloaded = false;
  let snapshotDeleted = false;

  const successResult = await restoreManager.executeRestoreStateMachine(dummyBackupPath, {
    stopWorker: async () => { workerStopped = true; },
    stopPhp: async () => { phpStopped = true; },
    createSnapshot: async (recoveryId) => {
      snapshotCreated = true;
      return { ok: true, backupPath: dummySnapshotFile, recoveryId };
    },
    runRestore: async (validatedPath) => {
      assert.equal(validatedPath, path.resolve(dummyBackupPath));
      restoreRan = true;
    },
    runMigrations: async () => { migrationsRan = true; },
    verifyDb: async () => { dbVerified = true; return { ok: true }; },
    restartPhpAndWorker: async () => { restarted = true; },
    clearSession: async () => { sessionCleared = true; },
    reloadWindow: async () => { windowReloaded = true; },
    deleteSnapshot: async (p) => {
      snapshotDeleted = true;
      if (fs.existsSync(p)) fs.unlinkSync(p);
      if (fs.existsSync(p + '.json')) fs.unlinkSync(p + '.json');
    },
  });

  assert.equal(successResult.success, true);
  assert.equal(workerStopped, true, 'Worker must stop before snapshot');
  assert.equal(phpStopped, true, 'PHP must stop before snapshot');
  assert.equal(snapshotCreated, true, 'Safety snapshot must be taken');
  assert.equal(restoreRan, true, 'Restore import must execute');
  assert.equal(migrationsRan, true, 'Migrations must run after restore');
  assert.equal(dbVerified, true, 'Database verification must succeed');
  assert.equal(restarted, true, 'PHP and worker must restart');
  assert.equal(sessionCleared, true, 'Desktop session must be cleared');
  assert.equal(windowReloaded, true, 'Window must be reloaded');
  assert.equal(snapshotDeleted, true, 'Snapshot must be cleaned up on complete success');

  // Flow 3.2: Regression simulation: Import failure triggers automatic rollback from safety snapshot
  fs.writeFileSync(dummySnapshotFile, '-- Safety snapshot recreated\n');
  let rollbackRan = false;
  let restartedAfterRollback = false;

  await assert.rejects(
    async () => {
      await restoreManager.executeRestoreStateMachine(dummyBackupPath, {
        createSnapshot: async (recoveryId) => ({
          ok: true,
          backupPath: dummySnapshotFile,
          recoveryId,
        }),
        runRestore: async () => {
          throw new Error('Database restore failed. Reference: c0ffee0123456789');
        },
        runRollback: async (backupPath, recoveryId) => {
          rollbackRan = true;
          assert.equal(backupPath, dummySnapshotFile);
          return { ok: true };
        },
        restartPhpAndWorker: async () => {
          restartedAfterRollback = true;
        },
      });
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_FAILED_RECOVERED');
      assert.equal(err.details?.stage, 'restore_import');
      assert.equal(err.details?.recovered, true);
      assert.equal(err.details?.reference, 'c0ffee0123456789');

      // Format through renderer contract
      const rendererPayload = restoreManager.formatRestoreFailure(err);
      assert.equal(rendererPayload.success, false);
      assert.equal(rendererPayload.recovered, true);
      assert.equal(rendererPayload.stage, 'restore_import');
      assert.equal(rendererPayload.reference, 'c0ffee0123456789');
      assert.ok(rendererPayload.error.includes('c0ffee0123456789'));
      assert.ok(rendererPayload.error.includes('[restore_import]'));
      assert.ok(rendererPayload.error.includes('The original database was recovered successfully'));
      return true;
    }
  );
  assert.equal(rollbackRan, true, 'Safety rollback must run when import fails');
  assert.equal(restartedAfterRollback, true, 'Services must restart after rollback');
  assert.equal(fs.existsSync(dummySnapshotFile), true, 'Snapshot must be retained after failure');

  // Flow 3.3: Regression simulation: Verification failure triggers automatic rollback
  let rollbackAfterVerifyFail = false;
  await assert.rejects(
    async () => {
      await restoreManager.executeRestoreStateMachine(dummyBackupPath, {
        createSnapshot: async (recoveryId) => ({
          ok: true,
          backupPath: dummySnapshotFile,
          recoveryId,
        }),
        runRestore: async () => {},
        runMigrations: async () => {},
        verifyDb: async () => {
          return { ok: false, error: 'Database verification failed: missing trigger (Reference: abcdef1234567890)' };
        },
        runRollback: async () => {
          rollbackAfterVerifyFail = true;
          return { ok: true };
        },
        restartPhpAndWorker: async () => {},
      });
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_FAILED_RECOVERED');
      assert.equal(err.details?.stage, 'restore_verify');
      assert.equal(err.details?.recovered, true);
      assert.equal(err.details?.reference, 'abcdef1234567890');

      const rendererPayload = restoreManager.formatRestoreFailure(err);
      assert.ok(rendererPayload.error.includes('[restore_verify]'));
      assert.ok(rendererPayload.error.includes('The original database was recovered successfully'));
      return true;
    }
  );
  assert.equal(rollbackAfterVerifyFail, true, 'Rollback must run on verify failure');

  console.log('      PASS: Desktop restore lifecycle unit simulation verified.');
} finally {
  safeCleanTempDir(labDir, 'desktop-restore-flow-');
}

// ── 4. Renderer IPC Contract Structure, Truthfulness, & Native Logging Context ──
console.log('[4/4] Verifying renderer IPC contract structure, truthfulness, and native diagnostics...');

const mockIpcErrors = [
  {
    err: restoreManager.createRestoreError('DATABASE_RESTORE_VALIDATION_FAILED', 'Invalid SQL extension', {
      stage: 'restore_validate',
      reason: 'PREFLIGHT_INVALID_FILE',
      reference: '0000111122223333',
    }),
    expectedStage: 'restore_validate',
    expectedRecovered: false,
    expectedCode: 'DATABASE_RESTORE_VALIDATION_FAILED',
  },
  {
    err: restoreManager.createRestoreError('DATABASE_RESTORE_FAILED_RECOVERED', 'Restore failed during import', {
      stage: 'restore_import',
      recovered: true,
      reference: '1111222233334444',
    }),
    expectedStage: 'restore_import',
    expectedRecovered: true,
    expectedCode: 'DATABASE_RESTORE_FAILED_RECOVERED',
  },
  {
    err: restoreManager.createRestoreError('DATABASE_RESTORE_RECOVERY_FAILED', 'Rollback failed', {
      stage: 'restore_rollback',
      recovered: false,
      recoveryModeEntered: true,
      reference: '2222333344445555',
    }),
    expectedStage: 'restore_rollback',
    expectedRecovered: false,
    expectedCode: 'DATABASE_RESTORE_RECOVERY_FAILED',
  },
];

for (const { err, expectedStage, expectedRecovered, expectedCode } of mockIpcErrors) {
  const result = restoreManager.formatRestoreFailure(err);
  const diagnostics = restoreManager.classifyRestoreError(err);

  // Verify strict contract fields
  assert.equal(result.success, false);
  assert.equal(typeof result.error, 'string');
  assert.equal(result.code, expectedCode);
  assert.equal(result.stage, expectedStage);
  assert.equal(result.recovered, expectedRecovered);
  assert.match(result.reference, /^[a-f0-9]{16}$/);

  // Error message MUST include the reference
  assert.ok(result.error.includes(result.reference));

  // Must NOT expose internal properties to renderer
  assert.equal(result.details, undefined);
  assert.equal(result.stack, undefined);
  assert.equal(result.originalError, undefined);
  assert.equal(result.rollbackError, undefined);

  // Diagnostics for native console logging must be present and safe
  assert.equal(typeof diagnostics.name, 'string');
  assert.equal(diagnostics.stage, expectedStage);
}

console.log('      PASS: Renderer IPC contract structure and native diagnostic logging context verified.');

console.log('PASS desktop restore flow unit simulation: verified privileged CLI isolation, failure formatting, restore lifecycle simulation, and renderer contract');
