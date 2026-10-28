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

const RESTORE_ERROR_CODES = Object.freeze({
  VALIDATION_FAILED: 'DATABASE_RESTORE_VALIDATION_FAILED',
  SNAPSHOT_FAILED: 'DATABASE_RESTORE_SNAPSHOT_FAILED',
  FAILED_RECOVERED: 'DATABASE_RESTORE_FAILED_RECOVERED',
  RECOVERY_FAILED: 'DATABASE_RESTORE_RECOVERY_FAILED',
  RESTART_FAILED: 'DATABASE_RESTORE_RESTART_FAILED',
  FINALIZE_FAILED: 'DATABASE_RESTORE_FINALIZE_FAILED',
  RESTORE_FAILED: 'DATABASE_RESTORE_FAILED',
});

const RESTORE_PREFLIGHT_REASONS = Object.freeze({
  LAN_ENABLED: 'PREFLIGHT_LAN_ENABLED',
  PHP_NOT_RUNNING: 'PREFLIGHT_PHP_NOT_RUNNING',
  INVALID_FILE: 'PREFLIGHT_INVALID_FILE',
});

const ALLOWED_RESTORE_STAGES = Object.freeze(Object.values(RESTORE_STAGES));
const ALLOWED_RESTORE_CODES = Object.freeze(Object.values(RESTORE_ERROR_CODES));
const ALLOWED_PREFLIGHT_REASONS = Object.freeze(Object.values(RESTORE_PREFLIGHT_REASONS));

const REFERENCE_PATTERN = /\bReference:\s*([a-f0-9]{16})\b/i;

function extractRestoreReference(errorOrText) {
  if (!errorOrText) return null;

  if (typeof errorOrText === 'string') {
    const match = errorOrText.match(REFERENCE_PATTERN);
    return match ? match[1].toLowerCase() : null;
  }

  if (typeof errorOrText.reference === 'string' && /^[a-f0-9]{16}$/i.test(errorOrText.reference.trim())) {
    return errorOrText.reference.trim().toLowerCase();
  }

  if (typeof errorOrText.details?.reference === 'string' && /^[a-f0-9]{16}$/i.test(errorOrText.details.reference.trim())) {
    return errorOrText.details.reference.trim().toLowerCase();
  }

  if (typeof errorOrText.message === 'string') {
    const match = errorOrText.message.match(REFERENCE_PATTERN);
    if (match) return match[1].toLowerCase();
  }

  if (typeof errorOrText.details?.originalError === 'string') {
    const match = errorOrText.details.originalError.match(REFERENCE_PATTERN);
    if (match) return match[1].toLowerCase();
  }

  if (typeof errorOrText.details?.rollbackError === 'string') {
    const match = errorOrText.details.rollbackError.match(REFERENCE_PATTERN);
    if (match) return match[1].toLowerCase();
  }

  return null;
}

function resolveRestoreReference(errorOrText) {
  return extractRestoreReference(errorOrText) || crypto.randomBytes(8).toString('hex');
}

function classifyRestoreError(error) {
  if (!error) return { name: 'UnknownError', type: 'unknown' };
  const name = error.name || 'Error';
  const code = error.code || error.details?.code || null;
  const stage = error.details?.stage || null;
  const reason = error.details?.reason || null;

  let causeClassification = null;
  if (error.cause) {
    causeClassification = error.cause.code || error.cause.name || 'ErrorCause';
  } else if (error.details?.originalError) {
    const origMatch = String(error.details.originalError).match(/^([A-Za-z0-9_]+Error|[A-Z_]+)/);
    causeClassification = origMatch ? origMatch[1] : 'UnderlyingFailure';
  }

  return {
    name,
    code,
    stage,
    reason,
    causeClassification,
  };
}

function validateBackupFileHeader(filePath) {
  if (typeof filePath !== 'string' || !filePath.trim()) {
    throw new Error('A backup file path must be specified.');
  }

  const resolved = path.resolve(filePath);
  if (!fs.existsSync(resolved) || !fs.statSync(resolved).isFile()) {
    throw new Error('Backup file does not exist or is not a file.');
  }

  if (path.extname(resolved).toLowerCase() !== '.sql') {
    throw new Error('Backup file must have a .sql extension.');
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
  if (details && typeof details.reference === 'string') {
    error.reference = details.reference;
  }
  return error;
}

function formatRestoreFailure(error) {
  const reference = resolveRestoreReference(error);
  const rawCode = error?.code;
  const rawStage = error?.details?.stage;
  const rawReason = error?.details?.reason;
  const recovered = error?.details?.recovered === true || rawCode === RESTORE_ERROR_CODES.FAILED_RECOVERED;

  // Unknown code MUST remain DATABASE_RESTORE_FAILED, never default to VALIDATION_FAILED
  const code = ALLOWED_RESTORE_CODES.includes(rawCode) ? rawCode : RESTORE_ERROR_CODES.RESTORE_FAILED;
  // Unknown stage MUST remain null, never default to restore_validate
  const stage = ALLOWED_RESTORE_STAGES.includes(rawStage) ? rawStage : null;
  const reason = ALLOWED_PREFLIGHT_REASONS.includes(rawReason) ? rawReason : null;

  let humanMessage;
  switch (code) {
    case RESTORE_ERROR_CODES.VALIDATION_FAILED:
      if (reason === RESTORE_PREFLIGHT_REASONS.LAN_ENABLED) {
        humanMessage = `Database restore is available only while the desktop runtime is local. (Reference: ${reference})`;
      } else if (reason === RESTORE_PREFLIGHT_REASONS.PHP_NOT_RUNNING) {
        humanMessage = `The local PHP server is not running. (Reference: ${reference})`;
      } else {
        const stageLabel = stage ? ` during [${stage}]` : '';
        humanMessage = `Database restore validation failed${stageLabel}. The existing database was not modified. (Reference: ${reference})`;
      }
      break;

    case RESTORE_ERROR_CODES.SNAPSHOT_FAILED:
      humanMessage = `Failed to create pre-restore safety snapshot during [${stage || RESTORE_STAGES.SNAPSHOT}]. The existing database was not modified. (Reference: ${reference})`;
      break;

    case RESTORE_ERROR_CODES.FAILED_RECOVERED: {
      const stageLabel = stage ? ` during [${stage}]` : '';
      humanMessage = `Database restore failed${stageLabel}. The original database was recovered successfully. (Reference: ${reference})`;
      break;
    }

    case RESTORE_ERROR_CODES.RECOVERY_FAILED: {
      const stageLabel = stage ? ` during [${stage}]` : '';
      if (error?.details?.recoveryModeEntered === false) {
        humanMessage = `Database restore failed${stageLabel} and automatic recovery could not be completed. Recovery mode is required but could not be launched. (Reference: ${reference})`;
      } else {
        humanMessage = `Database restore failed${stageLabel} and automatic recovery could not be completed. The system has entered Recovery Mode. (Reference: ${reference})`;
      }
      break;
    }

    case RESTORE_ERROR_CODES.RESTART_FAILED:
      humanMessage = `Database restore completed, but restarting application services failed during [${stage || RESTORE_STAGES.RESTART}]. (Reference: ${reference})`;
      break;

    case RESTORE_ERROR_CODES.FINALIZE_FAILED:
      humanMessage = `Database restore completed, but finalizing the session failed during [${stage || RESTORE_STAGES.FINALIZE}]. (Reference: ${reference})`;
      break;

    case RESTORE_ERROR_CODES.RESTORE_FAILED:
    default: {
      // Unknown error / unproven state: NO state-preservation claim!
      const stageLabel = stage ? ` during [${stage}]` : '';
      humanMessage = `Database restore failed${stageLabel}. (Reference: ${reference})`;
      break;
    }
  }

  return {
    success: false,
    error: humanMessage,
    code,
    stage,
    recovered,
    reference,
    ...(reason ? { reason } : {}),
  };
}

async function executeRestoreStateMachine(filePath, hooks) {
  let currentStage = RESTORE_STAGES.VALIDATE;
  let validatedPath;

  try {
    validatedPath = validateBackupFileHeader(filePath);
  } catch (validationError) {
    const reference = resolveRestoreReference(validationError);
    throw createRestoreError(RESTORE_ERROR_CODES.VALIDATION_FAILED, validationError.message, {
      stage: RESTORE_STAGES.VALIDATE,
      reason: RESTORE_PREFLIGHT_REASONS.INVALID_FILE,
      recovered: false,
      reference,
    });
  }

  // 1. Stop background activity before taking snapshot
  // await hooks.stopPhp() supports both sync actual production hook and async simulation/test hook
  currentStage = RESTORE_STAGES.SNAPSHOT;
  try {
    if (hooks.stopWorker) {
      await hooks.stopWorker();
    }
    if (hooks.stopPhp) {
      await hooks.stopPhp();
    }
  } catch (stopError) {
    try {
      if (hooks.restartPhpAndWorker) {
        await hooks.restartPhpAndWorker();
      }
    } catch {}
    const reference = resolveRestoreReference(stopError);
    throw createRestoreError(
      RESTORE_ERROR_CODES.SNAPSHOT_FAILED,
      `Failed to stop background services before safety snapshot: ${stopError.message}`,
      { stage: RESTORE_STAGES.SNAPSHOT, recovered: false, reference }
    );
  }

  // 2. Take pre-restore safety snapshot
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

    const reference = resolveRestoreReference(snapshotError);
    throw createRestoreError(
      RESTORE_ERROR_CODES.SNAPSHOT_FAILED,
      `Failed to create pre-restore safety snapshot: ${snapshotError.message}`,
      { stage: RESTORE_STAGES.SNAPSHOT, recovered: false, reference }
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

    const reference = resolveRestoreReference(rollbackFailure || triggerError);

    if (!rollbackSucceeded) {
      // Severe state: Rollback failed. Attempt to enter Recovery Mode.
      // Do NOT delete safety snapshot or metadata!
      // Do NOT restart normal flow or clear session!
      const initialRecoveryMessage =
        'Database restore failed and automatic recovery could not be completed. Recovery mode is required.';

      const recoveryError = createRestoreError(
        RESTORE_ERROR_CODES.RECOVERY_FAILED,
        initialRecoveryMessage,
        {
          stage: RESTORE_STAGES.ROLLBACK,
          failedStage,
          originalError: triggerError.message,
          rollbackError: rollbackFailure?.message,
          recoveryModeError: null,
          recoveryModeEntered: false,
          safetyBackupPath: snapshot.backupPath,
          recoveryId: snapshot.recoveryId,
          recovered: false,
          reference,
        }
      );

      if (hooks.enterRecoveryMode) {
        try {
          await hooks.enterRecoveryMode(recoveryError);
          recoveryError.details.recoveryModeEntered = true;
          recoveryError.message =
            'Database restore failed and automatic recovery could not be completed. The system has entered Recovery Mode.';
        } catch (modeErr) {
          recoveryError.details.recoveryModeEntered = false;
          recoveryError.details.recoveryModeError = modeErr?.message || String(modeErr);
          recoveryError.message =
            'Database restore failed and automatic recovery could not be completed. Recovery mode is required but could not be launched.';
          console.error('[Restore] CRITICAL: enterRecoveryMode hook threw an error:', modeErr.message);
        }
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
      RESTORE_ERROR_CODES.FAILED_RECOVERED,
      `Restore failed at stage [${failedStage}]: ${triggerError.message}. The original database was recovered successfully.`,
      {
        stage: failedStage,
        recovered: true,
        reference,
        originalError: triggerError.message,
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
  try {
    if (hooks.restartPhpAndWorker) {
      await hooks.restartPhpAndWorker();
    }
  } catch (restartError) {
    const reference = resolveRestoreReference(restartError);
    throw createRestoreError(
      RESTORE_ERROR_CODES.RESTART_FAILED,
      `Database restore completed but restarting services failed: ${restartError.message}`,
      {
        stage: RESTORE_STAGES.RESTART,
        recovered: false,
        databaseRestored: true,
        reference,
      }
    );
  }

  currentStage = RESTORE_STAGES.FINALIZE;
  try {
    if (hooks.clearSession) {
      await hooks.clearSession();
    }
    if (hooks.reloadWindow) {
      await hooks.reloadWindow();
    }
  } catch (finalizeError) {
    const reference = resolveRestoreReference(finalizeError);
    throw createRestoreError(
      RESTORE_ERROR_CODES.FINALIZE_FAILED,
      `Database restore completed but finalizing the session failed: ${finalizeError.message}`,
      {
        stage: RESTORE_STAGES.FINALIZE,
        recovered: false,
        databaseRestored: true,
        reference,
      }
    );
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
};
