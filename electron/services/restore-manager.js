const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const RESTORE_STAGES = Object.freeze({
  VALIDATE: 'restore_validate',
  SNAPSHOT: 'restore_snapshot',
  IMPORT: 'restore_import',
  MIGRATIONS: 'restore_migrations',
  VERIFY: 'restore_verify',
  ROLLBACK: 'restore_rollback',
  RESTART: 'restore_restart',
  FINALIZE: 'restore_finalize',
});

function validateBackupFileHeader(filePath) {
  if (typeof filePath !== 'string' || !filePath.trim()) {
    throw new Error('A backup file path must be specified.');
  }

  const resolved = path.resolve(filePath);
  if (!fs.existsSync(resolved) || !fs.statSync(resolved).isFile()) {
    throw new Error('Backup file does not exist or is not a file.');
  }

  const size = fs.statSync(resolved).size;
  if (size < 30) {
    throw new Error('Backup file is empty or too small to be a valid backup.');
  }

  if (size > 50 * 1024 * 1024) {
    throw new Error('Backup file exceeds maximum allowed size (50 MB).');
  }

  // Inspect first 8KB for basic format and sanity checks
  const buffer = Buffer.alloc(Math.min(8192, size));
  const fd = fs.openSync(resolved, 'r');
  try {
    fs.readSync(fd, buffer, 0, buffer.length, 0);
  } finally {
    fs.closeSync(fd);
  }

  const header = buffer.toString('utf8');
  if (/\b(DROP\s+DATABASE|GRANT|SHUTDOWN)\b/i.test(header)) {
    throw new Error('Backup file contains unauthorized database administrative commands.');
  }

  return resolved;
}

function createRestoreError(code, message, details = {}) {
  const error = new Error(message);
  error.code = code;
  error.details = details;
  return error;
}

async function executeRestoreStateMachine(filePath, hooks) {
  let currentStage = RESTORE_STAGES.VALIDATE;
  let validatedPath;

  try {
    validatedPath = validateBackupFileHeader(filePath);
  } catch (validationError) {
    throw createRestoreError('DATABASE_RESTORE_VALIDATION_FAILED', validationError.message, {
      stage: RESTORE_STAGES.VALIDATE,
    });
  }

  // 1. Stop background activity before taking snapshot
  if (hooks.stopWorker) {
    await hooks.stopWorker();
  }
  if (hooks.stopPhp) {
    hooks.stopPhp();
  }

  // 2. Take pre-restore safety snapshot
  currentStage = RESTORE_STAGES.SNAPSHOT;
  let snapshot = null;
  const recoveryId = `restore-safety-${Date.now()}-${crypto.randomBytes(4).toString('hex')}`;

  try {
    const snapshotResult = await hooks.createSnapshot(recoveryId);
    if (!snapshotResult || !snapshotResult.ok || !snapshotResult.backupPath) {
      throw new Error(snapshotResult?.error || 'Safety snapshot creation returned an incomplete result.');
    }
    snapshot = snapshotResult;
  } catch (snapshotError) {
    // Snapshot failed: DO NOT proceed with restore. Restart PHP/worker and keep session intact.
    console.error(`[Restore] [${RESTORE_STAGES.SNAPSHOT}] Failed to create safety snapshot:`, snapshotError.message);
    try {
      if (hooks.restartPhpAndWorker) {
        await hooks.restartPhpAndWorker();
      }
    } catch (restartErr) {
      console.error('[Restore] Failed to restart services after snapshot failure:', restartErr.message);
    }

    throw createRestoreError(
      'DATABASE_RESTORE_SNAPSHOT_FAILED',
      `Failed to create pre-restore safety snapshot: ${snapshotError.message}`,
      { stage: RESTORE_STAGES.SNAPSHOT }
    );
  }

  // Helper for rollback on subsequent failure
  const performRollback = async (triggerError, failedStage) => {
    console.warn(`[Restore] Triggering automatic rollback from safety snapshot due to failure at stage [${failedStage}]:`, triggerError.message);
    currentStage = RESTORE_STAGES.ROLLBACK;

    let rollbackSucceeded = false;
    let rollbackFailure = null;

    try {
      const rollbackResult = await hooks.runRollback(snapshot.backupPath, snapshot.recoveryId);
      if (rollbackResult && rollbackResult.ok === false) {
        throw new Error(rollbackResult.error || 'Rollback operation returned failure.');
      }
      rollbackSucceeded = true;
    } catch (rollbackErr) {
      rollbackFailure = rollbackErr;
      console.error(`[Restore] [${RESTORE_STAGES.ROLLBACK}] CRITICAL: Safety rollback failed:`, rollbackErr.message);
    }

    if (!rollbackSucceeded) {
      // Severe state: Rollback failed. Enter Recovery Mode.
      // Do NOT delete safety snapshot or metadata!
      // Do NOT restart normal flow!
      const recoveryError = createRestoreError(
        'DATABASE_RESTORE_RECOVERY_FAILED',
        'Database restore failed and the safety snapshot could not be restored automatically. The system has entered Recovery Mode.',
        {
          stage: RESTORE_STAGES.ROLLBACK,
          originalError: triggerError.message,
          rollbackError: rollbackFailure?.message,
          safetyBackupPath: snapshot.backupPath,
          recoveryId: snapshot.recoveryId,
        }
      );

      if (hooks.enterRecoveryMode) {
        await hooks.enterRecoveryMode(recoveryError);
      }

      throw recoveryError;
    }

    // Rollback succeeded: restart runtime and preserve user session
    console.log(`[Restore] [${RESTORE_STAGES.ROLLBACK}] Rollback succeeded. Restoring runtime services and preserving session.`);
    try {
      if (hooks.restartPhpAndWorker) {
        await hooks.restartPhpAndWorker();
      }
    } catch (restartErr) {
      console.error('[Restore] Failed to restart services after successful rollback:', restartErr.message);
    }

    // Report restore failure with note that DB was recovered safely
    throw createRestoreError(
      'DATABASE_RESTORE_FAILED_RECOVERED',
      `Restore failed at stage [${failedStage}]: ${triggerError.message}. The original database was recovered successfully.`,
      {
        stage: failedStage,
        recovered: true,
      }
    );
  };

  // 3. Run privileged restore import
  currentStage = RESTORE_STAGES.IMPORT;
  try {
    await hooks.runRestore(validatedPath);
  } catch (importError) {
    return await performRollback(importError, RESTORE_STAGES.IMPORT);
  }

  // 4. Run post-restore migrations
  currentStage = RESTORE_STAGES.MIGRATIONS;
  try {
    await hooks.runMigrations();
  } catch (migrationError) {
    return await performRollback(migrationError, RESTORE_STAGES.MIGRATIONS);
  }

  // 5. Verify restored database state
  currentStage = RESTORE_STAGES.VERIFY;
  try {
    const verifyResult = await hooks.verifyDb();
    if (verifyResult && verifyResult.ok === false) {
      throw new Error(verifyResult.error || 'Database verification failed.');
    }
  } catch (verifyError) {
    return await performRollback(verifyError, RESTORE_STAGES.VERIFY);
  }

  // 6. Restart runtime, clear auth, and reload
  currentStage = RESTORE_STAGES.RESTART;
  if (hooks.restartPhpAndWorker) {
    await hooks.restartPhpAndWorker();
  }

  currentStage = RESTORE_STAGES.FINALIZE;
  if (hooks.clearSession) {
    await hooks.clearSession();
  }
  if (hooks.reloadWindow) {
    await hooks.reloadWindow();
  }

  // 7. Clean up temporary safety snapshot after complete success
  if (hooks.deleteSnapshot && snapshot?.backupPath) {
    try {
      await hooks.deleteSnapshot(snapshot.backupPath);
    } catch (cleanErr) {
      console.warn('[Restore] Failed to clean up safety snapshot after success:', cleanErr.message);
    }
  }

  return { success: true };
}

module.exports = {
  RESTORE_STAGES,
  validateBackupFileHeader,
  createRestoreError,
  executeRestoreStateMachine,
};
