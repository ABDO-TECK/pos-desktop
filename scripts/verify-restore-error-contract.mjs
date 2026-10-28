import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const {
  RESTORE_STAGES,
  RESTORE_ERROR_CODES,
  RESTORE_PREFLIGHT_REASONS,
  ALLOWED_RESTORE_STAGES,
  ALLOWED_RESTORE_CODES,
  ALLOWED_PREFLIGHT_REASONS,
  validateBackupFileHeader,
  createRestoreError,
  extractRestoreReference,
  resolveRestoreReference,
  classifyRestoreError,
  formatRestoreFailure,
  executeRestoreStateMachine,
} = require('../electron/services/restore-manager.js');

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

console.log('--- POS Desktop Restore Error Contract & State Machine Unit Simulation ---');

// ── 1. Reference Extraction & Generation Tests ──
console.log('[1/5] Verifying backend reference extraction and correlation generation...');

// Direct Reference pattern in string
const ref1 = extractRestoreReference('Database restore failed. Reference: 0123456789abcdef');
assert.equal(ref1, '0123456789abcdef', 'Should extract 16-hex reference from string');

// In parentheses pattern (e.g. from verify-database)
const ref2 = extractRestoreReference('Database verification failed: missing trigger (Reference: abcdef0123456789)');
assert.equal(ref2, 'abcdef0123456789', 'Should extract reference from parenthesized pattern');

// Case insensitivity
const ref3 = extractRestoreReference('Pre-restore safety snapshot failed. reference: ABCDEF1234567890');
assert.equal(ref3, 'abcdef1234567890', 'Reference extraction must normalize to lowercase');

// From Error object with reference property
const errWithRef = new Error('Generic error');
errWithRef.reference = 'fedcba9876543210';
assert.equal(extractRestoreReference(errWithRef), 'fedcba9876543210');

// From Error details object
const errWithDetails = new Error('Generic error');
errWithDetails.details = { reference: '1122334455667788' };
assert.equal(extractRestoreReference(errWithDetails), '1122334455667788');

// From Error details.originalError
const errWithNested = new Error('Rollback succeeded');
errWithNested.details = { originalError: 'Database restore failed. Reference: 9988776655443322' };
assert.equal(extractRestoreReference(errWithNested), '9988776655443322');

// From Error details.rollbackError
const errWithRollback = new Error('Recovery mode entered');
errWithRollback.details = { rollbackError: 'Rollback failed. Reference: aabbccddeeff0011' };
assert.equal(extractRestoreReference(errWithRollback), 'aabbccddeeff0011');

// When no reference is present: resolveRestoreReference generates a 16-hex string
const generatedRef = resolveRestoreReference(new Error('Unknown generic error without ref'));
assert.match(generatedRef, /^[a-f0-9]{16}$/, 'Generated correlation reference must be 16 lowercase hex characters');

// Consistent when reference is present
const preservedRef = resolveRestoreReference(new Error('Failed. Reference: 1234567890abcdef'));
assert.equal(preservedRef, '1234567890abcdef', 'Must preserve existing backend reference');

console.log('      PASS: Reference extraction and correlation generation verified.');

// ── 2. Allowlisted Bounded Codes, Truthful States, & Actionable Preflight ──
console.log('[2/5] Verifying bounded error codes, stages, preflight reasons, and truthful human messages...');

// Verify code and stage allowlists
assert.ok(ALLOWED_RESTORE_CODES.includes('DATABASE_RESTORE_VALIDATION_FAILED'));
assert.ok(ALLOWED_RESTORE_CODES.includes('DATABASE_RESTORE_SNAPSHOT_FAILED'));
assert.ok(ALLOWED_RESTORE_CODES.includes('DATABASE_RESTORE_FAILED_RECOVERED'));
assert.ok(ALLOWED_RESTORE_CODES.includes('DATABASE_RESTORE_RECOVERY_FAILED'));
assert.ok(ALLOWED_RESTORE_CODES.includes('DATABASE_RESTORE_RESTART_FAILED'));
assert.ok(ALLOWED_RESTORE_CODES.includes('DATABASE_RESTORE_FINALIZE_FAILED'));
assert.ok(ALLOWED_RESTORE_CODES.includes('DATABASE_RESTORE_FAILED'));

assert.ok(ALLOWED_RESTORE_STAGES.includes('restore_validate'));
assert.ok(ALLOWED_RESTORE_STAGES.includes('restore_snapshot'));
assert.ok(ALLOWED_RESTORE_STAGES.includes('restore_import'));
assert.ok(ALLOWED_RESTORE_STAGES.includes('restore_migrations'));
assert.ok(ALLOWED_RESTORE_STAGES.includes('restore_verify'));
assert.ok(ALLOWED_RESTORE_STAGES.includes('restore_rollback'));
assert.ok(ALLOWED_RESTORE_STAGES.includes('restore_restart'));
assert.ok(ALLOWED_RESTORE_STAGES.includes('restore_finalize'));

assert.ok(ALLOWED_PREFLIGHT_REASONS.includes('PREFLIGHT_LAN_ENABLED'));
assert.ok(ALLOWED_PREFLIGHT_REASONS.includes('PREFLIGHT_PHP_NOT_RUNNING'));
assert.ok(ALLOWED_PREFLIGHT_REASONS.includes('PREFLIGHT_INVALID_FILE'));

// Test preflight reasons for LAN and PHP not running
const lanPreflightErr = createRestoreError(
  RESTORE_ERROR_CODES.VALIDATION_FAILED,
  'Database restore is available only while the desktop runtime is local',
  {
    stage: RESTORE_STAGES.VALIDATE,
    reason: RESTORE_PREFLIGHT_REASONS.LAN_ENABLED,
    reference: 'a1b2c3d4e5f60718',
  }
);
const lanFormatted = formatRestoreFailure(lanPreflightErr);
assert.equal(lanFormatted.code, 'DATABASE_RESTORE_VALIDATION_FAILED');
assert.equal(lanFormatted.reason, 'PREFLIGHT_LAN_ENABLED');
assert.ok(lanFormatted.error.includes('available only while the desktop runtime is local'));
assert.ok(lanFormatted.error.includes('a1b2c3d4e5f60718'));

const phpPreflightErr = createRestoreError(
  RESTORE_ERROR_CODES.VALIDATION_FAILED,
  'The local PHP server is not running',
  {
    stage: RESTORE_STAGES.VALIDATE,
    reason: RESTORE_PREFLIGHT_REASONS.PHP_NOT_RUNNING,
    reference: 'b2c3d4e5f60718a1',
  }
);
const phpFormatted = formatRestoreFailure(phpPreflightErr);
assert.equal(phpFormatted.code, 'DATABASE_RESTORE_VALIDATION_FAILED');
assert.equal(phpFormatted.reason, 'PREFLIGHT_PHP_NOT_RUNNING');
assert.ok(phpFormatted.error.includes('The local PHP server is not running'));
assert.ok(phpFormatted.error.includes('b2c3d4e5f60718a1'));

// Test truthful messages for each code with known stage
const testCases = [
  {
    input: createRestoreError('DATABASE_RESTORE_VALIDATION_FAILED', 'Invalid file header', {
      stage: RESTORE_STAGES.VALIDATE,
      reference: '1111222233334444',
    }),
    expectedCode: 'DATABASE_RESTORE_VALIDATION_FAILED',
    expectedStage: 'restore_validate',
    expectedRecovered: false,
    expectedInMsg: ['The existing database was not modified', '[restore_validate]', '1111222233334444'],
  },
  {
    input: createRestoreError('DATABASE_RESTORE_SNAPSHOT_FAILED', 'Snapshot disk full', {
      stage: RESTORE_STAGES.SNAPSHOT,
      reference: '2222333344445555',
    }),
    expectedCode: 'DATABASE_RESTORE_SNAPSHOT_FAILED',
    expectedStage: 'restore_snapshot',
    expectedRecovered: false,
    expectedInMsg: ['The existing database was not modified', '[restore_snapshot]', '2222333344445555'],
  },
  {
    input: createRestoreError('DATABASE_RESTORE_FAILED_RECOVERED', 'SQL syntax error', {
      stage: RESTORE_STAGES.IMPORT,
      recovered: true,
      reference: '3333444455556666',
    }),
    expectedCode: 'DATABASE_RESTORE_FAILED_RECOVERED',
    expectedStage: 'restore_import',
    expectedRecovered: true,
    expectedInMsg: ['[restore_import]', 'The original database was recovered successfully', '3333444455556666'],
  },
  {
    input: createRestoreError('DATABASE_RESTORE_RECOVERY_FAILED', 'Rollback corrupted', {
      stage: RESTORE_STAGES.ROLLBACK,
      recovered: false,
      recoveryModeEntered: true,
      reference: '4444555566667777',
    }),
    expectedCode: 'DATABASE_RESTORE_RECOVERY_FAILED',
    expectedStage: 'restore_rollback',
    expectedRecovered: false,
    expectedInMsg: ['Recovery Mode', '[restore_rollback]', '4444555566667777'],
  },
  {
    input: createRestoreError('DATABASE_RESTORE_RECOVERY_FAILED', 'Rollback failed and recovery mode failed', {
      stage: RESTORE_STAGES.ROLLBACK,
      recovered: false,
      recoveryModeEntered: false,
      reference: '4444555566667778',
    }),
    expectedCode: 'DATABASE_RESTORE_RECOVERY_FAILED',
    expectedStage: 'restore_rollback',
    expectedRecovered: false,
    expectedInMsg: ['Recovery mode is required but could not be launched', '[restore_rollback]', '4444555566667778'],
  },
  {
    input: createRestoreError('DATABASE_RESTORE_RESTART_FAILED', 'PHP process failed', {
      stage: RESTORE_STAGES.RESTART,
      reference: '5555666677778888',
    }),
    expectedCode: 'DATABASE_RESTORE_RESTART_FAILED',
    expectedStage: 'restore_restart',
    expectedRecovered: false,
    expectedInMsg: ['restarting application services failed', '[restore_restart]', '5555666677778888'],
  },
  {
    input: createRestoreError('DATABASE_RESTORE_FINALIZE_FAILED', 'Window reload failed', {
      stage: RESTORE_STAGES.FINALIZE,
      reference: '6666777788889999',
    }),
    expectedCode: 'DATABASE_RESTORE_FINALIZE_FAILED',
    expectedStage: 'restore_finalize',
    expectedRecovered: false,
    expectedInMsg: ['finalizing the session failed', '[restore_finalize]', '6666777788889999'],
  },
];

for (const tc of testCases) {
  const formatted = formatRestoreFailure(tc.input);
  assert.equal(formatted.success, false);
  assert.equal(formatted.code, tc.expectedCode, `Code mismatch for ${tc.expectedCode}`);
  assert.equal(formatted.stage, tc.expectedStage, `Stage mismatch for ${tc.expectedCode}`);
  assert.equal(formatted.recovered, tc.expectedRecovered, `Recovered mismatch for ${tc.expectedCode}`);
  for (const snippet of tc.expectedInMsg) {
    assert.ok(
      formatted.error.includes(snippet),
      `Expected message to contain "${snippet}", got: "${formatted.error}"`
    );
  }
}

// Unknown/unmapped error fallback check: MUST NOT claim database was not modified or recovered
const unknownErr = new Error('Some unexpected internal error');
const fallbackFormatted = formatRestoreFailure(unknownErr);
assert.equal(fallbackFormatted.success, false);
assert.equal(fallbackFormatted.code, 'DATABASE_RESTORE_FAILED', 'Unknown error must remain DATABASE_RESTORE_FAILED');
assert.equal(fallbackFormatted.stage, null, 'Unknown error stage must remain null/unclaimed');
assert.equal(fallbackFormatted.recovered, false, 'Unknown error recovered must be false');
assert.match(fallbackFormatted.reference, /^[a-f0-9]{16}$/);
assert.equal(fallbackFormatted.error, `Database restore failed. (Reference: ${fallbackFormatted.reference})`);
assert.ok(!fallbackFormatted.error.includes('not modified'), 'Unknown error must not claim database was not modified');
assert.ok(!fallbackFormatted.error.includes('recovered successfully'), 'Unknown error must not claim database was recovered');

console.log('      PASS: Bounded codes, stages, and truthful states verified.');

// ── 3. Zero-Leakage Guarantee (No SQL/Paths/Credentials/Stack in UI Error) ──
console.log('[3/5] Verifying zero leakage of SQL, paths, credentials, and stack traces to renderer...');

const sensitiveError = new Error(
  'Fatal error executing SQL: INSERT INTO `users` (`id`, `password_hash`, `email`) ' +
  'VALUES (1, "$2y$10$secretHash123", "admin@store.local") at C:\\xampp\\htdocs\\pos\\storage\\backups\\dump.sql:42. ' +
  'Reference: fedcba0123456789'
);
sensitiveError.stack = 'Error: at C:\\xampp\\htdocs\\pos\\electron\\services\\restore-manager.js:150:10\n at async run()';

const formattedSensitive = formatRestoreFailure(sensitiveError);

// Verify error does NOT contain raw SQL keywords or values
assert.ok(!formattedSensitive.error.includes('INSERT INTO'));
assert.ok(!formattedSensitive.error.includes('users'));
assert.ok(!formattedSensitive.error.includes('password_hash'));
assert.ok(!formattedSensitive.error.includes('secretHash123'));
assert.ok(!formattedSensitive.error.includes('admin@store.local'));

// Verify error does NOT contain filesystem paths
assert.ok(!formattedSensitive.error.includes('C:\\xampp'));
assert.ok(!formattedSensitive.error.includes('storage'));
assert.ok(!formattedSensitive.error.includes('dump.sql'));
assert.ok(!formattedSensitive.error.includes('restore-manager.js'));

// Verify error does NOT contain stack trace elements
assert.ok(!formattedSensitive.error.includes('at async run'));
assert.ok(!formattedSensitive.error.includes('at C:\\'));

// Verify it DOES contain the safe reference
assert.equal(formattedSensitive.reference, 'fedcba0123456789');
assert.ok(formattedSensitive.error.includes('fedcba0123456789'));

// Native classification test for structured main console logging
const classified = classifyRestoreError(sensitiveError);
assert.equal(classified.name, 'Error');
assert.equal(classified.code, null);

console.log('      PASS: Zero leakage verified.');

// ── 4. Async Stop PHP & Service Recovery Simulation ──
console.log('[4/5] Verifying async stopPhp rejection, service recovery attempt, and snapshot prevention...');

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'restore-contract-test-'));

try {
  const validBackup = path.join(tempDir, 'valid_backup.sql');
  fs.writeFileSync(validBackup, '-- POS Desktop Backup\nCREATE TABLE `sample` (`id` INT);\nINSERT INTO `sample` VALUES (1);\n');

  let asyncStopPhpRecoverAttempted = false;
  let snapshotRanAfterAsyncStopFail = false;
  let importRanAfterAsyncStopFail = false;

  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(validBackup, {
        stopPhp: async () => {
          throw new Error('Async php stop rejected. Reference: ffeeddccbbaa0011');
        },
        restartPhpAndWorker: async () => {
          asyncStopPhpRecoverAttempted = true;
        },
        createSnapshot: async () => {
          snapshotRanAfterAsyncStopFail = true;
        },
        runRestore: async () => {
          importRanAfterAsyncStopFail = true;
        },
      });
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_SNAPSHOT_FAILED');
      assert.equal(err.details?.stage, RESTORE_STAGES.SNAPSHOT);
      assert.equal(err.details?.recovered, false);
      assert.equal(err.details?.reference, 'ffeeddccbbaa0011');
      return true;
    }
  );
  assert.equal(asyncStopPhpRecoverAttempted, true, 'Services restart recovery must be attempted after async stopPhp rejection');
  assert.equal(snapshotRanAfterAsyncStopFail, false, 'Snapshot must never run if stopPhp fails');
  assert.equal(importRanAfterAsyncStopFail, false, 'Import must never run if stopPhp fails');

  console.log('      PASS: Async stopPhp rejection and safe service recovery simulation verified.');

  // ── 5. State Machine Execution, Rollback, & Recovery Mode Throw Transitions ──
  console.log('[5/5] Verifying state machine execution, rollback failure, and enterRecoveryMode rejection transitions...');

  // Test 5.1: Validation failure - file does not exist
  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(path.join(tempDir, 'nonexistent.sql'), {});
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_VALIDATION_FAILED');
      assert.equal(err.details?.stage, RESTORE_STAGES.VALIDATE);
      assert.equal(err.details?.recovered, false);
      assert.match(err.details?.reference, /^[a-f0-9]{16}$/);
      return true;
    }
  );

  // Test 5.2: Validation failure - wrong extension
  const txtFile = path.join(tempDir, 'backup.txt');
  fs.writeFileSync(txtFile, 'Some plain text content not sql at all');
  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(txtFile, {});
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_VALIDATION_FAILED');
      assert.equal(err.details?.stage, RESTORE_STAGES.VALIDATE);
      return true;
    }
  );

  // Test 5.3: Validation failure - unauthorized command (DROP DATABASE)
  const maliciousFile = path.join(tempDir, 'malicious.sql');
  fs.writeFileSync(maliciousFile, '-- Valid header\nDROP DATABASE pos_db;\nCREATE TABLE t1 (id int);');
  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(maliciousFile, {});
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_VALIDATION_FAILED');
      assert.equal(err.details?.stage, RESTORE_STAGES.VALIDATE);
      return true;
    }
  );

  const dummySnapshotPath = path.join(tempDir, 'snapshot.sql');
  fs.writeFileSync(dummySnapshotPath, '-- Safety Snapshot\nCREATE TABLE `pos_db` (`id` INT);');

  // Test 5.4: Import failure with successful rollback -> recovered: true, original database restored
  let rollbackExecuted = false;
  let servicesRestartedAfterRollback = false;

  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(validBackup, {
        createSnapshot: async (recoveryId) => ({
          ok: true,
          backupPath: dummySnapshotPath,
          recoveryId,
        }),
        runRestore: async () => {
          throw new Error('Database restore failed. Reference: feedface12345678');
        },
        runRollback: async (backupPath, recoveryId) => {
          rollbackExecuted = true;
          return { ok: true };
        },
        restartPhpAndWorker: async () => {
          servicesRestartedAfterRollback = true;
        },
      });
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_FAILED_RECOVERED');
      assert.equal(err.details?.stage, RESTORE_STAGES.IMPORT);
      assert.equal(err.details?.recovered, true);
      assert.equal(err.details?.reference, 'feedface12345678');
      return true;
    }
  );
  assert.equal(rollbackExecuted, true, 'Rollback must be triggered on import failure');
  assert.equal(servicesRestartedAfterRollback, true, 'Services must restart after successful rollback');
  assert.equal(fs.existsSync(dummySnapshotPath), true, 'Safety snapshot must be preserved after rollback');

  // Test 5.5a: Import failure + Rollback failure + enterRecoveryMode hook SUCCEEDS
  // Verifies: single typed Error constructed BEFORE hook, passed to hook as Error instance,
  // updated on success, identical Error object identity thrown, snapshot retained, no session clear/reload.
  let hookSuccessArgReceived = null;
  let hookSuccessCalled = false;

  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(validBackup, {
        createSnapshot: async (recoveryId) => ({
          ok: true,
          backupPath: dummySnapshotPath,
          recoveryId,
        }),
        runRestore: async () => {
          throw new Error('Import execution crashed');
        },
        runRollback: async () => {
          throw new Error('Rollback failed. Reference: a1b2c3d4e5f60718');
        },
        enterRecoveryMode: async (recoveryErr) => {
          hookSuccessCalled = true;
          hookSuccessArgReceived = recoveryErr;
          assert.ok(recoveryErr instanceof Error, 'enterRecoveryMode hook argument must be instanceof Error');
          assert.ok(recoveryErr.message && recoveryErr.message.length > 0, 'Hook argument must have non-empty .message');
          assert.equal(recoveryErr.code, 'DATABASE_RESTORE_RECOVERY_FAILED');
          assert.equal(recoveryErr.reference, 'a1b2c3d4e5f60718');
          assert.equal(recoveryErr.details?.safetyBackupPath, dummySnapshotPath);
        },
      });
    },
    (err) => {
      assert.ok(err instanceof Error, 'Thrown error must be instanceof Error');
      assert.equal(err, hookSuccessArgReceived, 'Thrown error must be exact same object identity passed to hook');
      assert.equal(err.code, 'DATABASE_RESTORE_RECOVERY_FAILED');
      assert.equal(err.reference, 'a1b2c3d4e5f60718');
      assert.equal(err.details?.safetyBackupPath, dummySnapshotPath);
      assert.equal(err.details?.recoveryModeEntered, true);
      assert.ok(err.message.includes('The system has entered Recovery Mode'));
      return true;
    }
  );
  assert.equal(hookSuccessCalled, true, 'enterRecoveryMode hook must have been called');
  assert.equal(fs.existsSync(dummySnapshotPath), true, 'Safety snapshot must be preserved');

  // Test 5.5b: Import failure + Rollback failure + enterRecoveryMode hook THROWS
  // Verifies: single typed Error constructed BEFORE hook, passed to hook,
  // catch updates SAME Error instance with recoveryModeError / truthful message,
  // exact same Error identity thrown, code/reference/snapshot preserved, no session clear/reload.
  let hookThrowArgReceived = null;
  let enterRecoveryModeThrew = false;
  let successClearSessionExecuted = false;
  let successReloadExecuted = false;

  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(validBackup, {
        createSnapshot: async (recoveryId) => ({
          ok: true,
          backupPath: dummySnapshotPath,
          recoveryId,
        }),
        runRestore: async () => {
          throw new Error('Import execution crashed');
        },
        runRollback: async () => {
          throw new Error('Rollback failed. Reference: badc0ffee1234567');
        },
        enterRecoveryMode: async (recoveryErr) => {
          enterRecoveryModeThrew = true;
          hookThrowArgReceived = recoveryErr;
          assert.ok(recoveryErr instanceof Error, 'enterRecoveryMode hook argument must be instanceof Error');
          assert.ok(recoveryErr.message && recoveryErr.message.length > 0, 'Hook argument must have non-empty .message');
          assert.equal(recoveryErr.code, 'DATABASE_RESTORE_RECOVERY_FAILED');
          assert.equal(recoveryErr.reference, 'badc0ffee1234567');
          assert.equal(recoveryErr.details?.safetyBackupPath, dummySnapshotPath);
          throw new Error('enterRecoveryMode window presentation failed');
        },
        clearSession: async () => {
          successClearSessionExecuted = true;
        },
        reloadWindow: async () => {
          successReloadExecuted = true;
        },
      });
    },
    (err) => {
      assert.ok(err instanceof Error, 'Thrown error must be instanceof Error');
      assert.equal(err, hookThrowArgReceived, 'Thrown error must be exact same object identity passed to hook');
      assert.equal(err.code, 'DATABASE_RESTORE_RECOVERY_FAILED');
      assert.equal(err.details?.stage, RESTORE_STAGES.ROLLBACK);
      assert.equal(err.details?.recovered, false);
      assert.equal(err.details?.reference, 'badc0ffee1234567');
      assert.equal(err.details?.recoveryModeEntered, false);
      assert.equal(err.details?.safetyBackupPath, dummySnapshotPath);
      assert.ok(err.details?.recoveryModeError.includes('window presentation failed'));

      // Check formatted renderer message
      const formatted = formatRestoreFailure(err);
      assert.equal(formatted.code, 'DATABASE_RESTORE_RECOVERY_FAILED');
      assert.equal(formatted.recovered, false);
      assert.ok(formatted.error.includes('Recovery mode is required but could not be launched'));
      assert.ok(!formatted.error.includes('The system has entered Recovery Mode'));
      assert.ok(!formatted.error.includes('not modified'));
      assert.ok(formatted.error.includes('badc0ffee1234567'));
      return true;
    }
  );
  assert.equal(enterRecoveryModeThrew, true, 'enterRecoveryMode hook was called and threw');
  assert.equal(fs.existsSync(dummySnapshotPath), true, 'Safety snapshot must be retained even if enterRecoveryMode throws');
  assert.equal(successClearSessionExecuted, false, 'Session must not be cleared on recovery failure');
  assert.equal(successReloadExecuted, false, 'Window reload must not be called on recovery failure');

  // Test 5.6: Migration failure -> triggers rollback and reports recovered
  let rollbackForMigration = false;
  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(validBackup, {
        createSnapshot: async (recoveryId) => ({
          ok: true,
          backupPath: dummySnapshotPath,
          recoveryId,
        }),
        runRestore: async () => {},
        runMigrations: async () => {
          throw new Error('Post-restore migrations failed. Reference: cafebeef98765432');
        },
        runRollback: async () => {
          rollbackForMigration = true;
          return { ok: true };
        },
        restartPhpAndWorker: async () => {},
      });
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_FAILED_RECOVERED');
      assert.equal(err.details?.stage, RESTORE_STAGES.MIGRATIONS);
      assert.equal(err.details?.recovered, true);
      assert.equal(err.details?.reference, 'cafebeef98765432');
      return true;
    }
  );
  assert.equal(rollbackForMigration, true, 'Rollback must run on migration failure');

  // Test 5.7: Verification failure -> triggers rollback and reports recovered
  let rollbackForVerify = false;
  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(validBackup, {
        createSnapshot: async (recoveryId) => ({
          ok: true,
          backupPath: dummySnapshotPath,
          recoveryId,
        }),
        runRestore: async () => {},
        runMigrations: async () => {},
        verifyDb: async () => {
          return { ok: false, error: 'Database verification failed: missing trigger (Reference: 1234abcd5678ef90)' };
        },
        runRollback: async () => {
          rollbackForVerify = true;
          return { ok: true };
        },
        restartPhpAndWorker: async () => {},
      });
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_FAILED_RECOVERED');
      assert.equal(err.details?.stage, RESTORE_STAGES.VERIFY);
      assert.equal(err.details?.recovered, true);
      assert.equal(err.details?.reference, '1234abcd5678ef90');
      return true;
    }
  );
  assert.equal(rollbackForVerify, true, 'Rollback must run on verify failure');

  // Test 5.8: Restart failure -> reports DATABASE_RESTORE_RESTART_FAILED, snapshot retained
  await assert.rejects(
    async () => {
      await executeRestoreStateMachine(validBackup, {
        createSnapshot: async (recoveryId) => ({
          ok: true,
          backupPath: dummySnapshotPath,
          recoveryId,
        }),
        runRestore: async () => {},
        runMigrations: async () => {},
        verifyDb: async () => ({ ok: true }),
        restartPhpAndWorker: async () => {
          throw new Error('PHP worker port conflict');
        },
      });
    },
    (err) => {
      assert.equal(err.code, 'DATABASE_RESTORE_RESTART_FAILED');
      assert.equal(err.details?.stage, RESTORE_STAGES.RESTART);
      assert.equal(err.details?.recovered, false);
      return true;
    }
  );
  assert.equal(fs.existsSync(dummySnapshotPath), true, 'Snapshot retained on restart failure');

  // Test 5.9: Complete success lifecycle -> deletes safety snapshot, returns success: true
  let snapshotDeleted = false;
  let sessionCleared = false;
  let windowReloaded = false;
  const result = await executeRestoreStateMachine(validBackup, {
    createSnapshot: async (recoveryId) => ({
      ok: true,
      backupPath: dummySnapshotPath,
      recoveryId,
    }),
    runRestore: async () => {},
    runMigrations: async () => {},
    verifyDb: async () => ({ ok: true }),
    restartPhpAndWorker: async () => {},
    clearSession: async () => {
      sessionCleared = true;
    },
    reloadWindow: async () => {
      windowReloaded = true;
    },
    deleteSnapshot: async (snapPath) => {
      snapshotDeleted = true;
      if (fs.existsSync(snapPath)) fs.unlinkSync(snapPath);
    },
  });

  assert.equal(result.success, true);
  assert.equal(sessionCleared, true, 'Session must be cleared on success');
  assert.equal(windowReloaded, true, 'Window must reload on success');
  assert.equal(snapshotDeleted, true, 'Snapshot must be deleted on success');

  console.log('      PASS: Complete restore state machine unit simulation lifecycle verified.');
} finally {
  safeCleanTempDir(tempDir, 'restore-contract-test-');
}

console.log('PASS restore error contract unit simulation: verified bounded stages/codes, reference extraction, zero-leakage, and full state machine transitions');
