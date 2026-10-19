const fs = require('fs');
const path = require('path');
const { getBackupsDir } = require('../paths');
const { readRuntimeMetadata, writeRuntimeMetadata, ensureMetadataInitialized, readAppVersion } = require('./metadata');
const { validateMysqlDataDir, detectRunningMysqldProcesses } = require('./mysqlPreflight');
const { copyMysqlDataToRuntime, verifyRuntimeMysqlDataCopy } = require('./mysqlMigration');

function getRollbackReadiness(metadata) {
  if (!metadata || !metadata.mysqlMigration) {
    return { available: false, reason: 'No mysqlMigration metadata found' };
  }

  const mm = metadata.mysqlMigration;
  if (mm.rollbackAvailable !== true) {
    return { available: false, reason: 'Rollback is not marked as available in metadata' };
  }

  if (!mm.sourcePath) {
    return { available: false, reason: 'sourcePath is missing from metadata' };
  }

  if (!mm.activePath) {
    return { available: false, reason: 'activePath is missing from metadata' };
  }

  if (!mm.preflightBackupPath) {
    return { available: false, reason: 'preflightBackupPath is missing from metadata' };
  }

  if (mm.legacySourcePreserved !== true) {
    return { available: false, reason: 'legacySourcePreserved is not marked as true in metadata' };
  }

  if (!fs.existsSync(mm.sourcePath)) {
    return { available: false, reason: `sourcePath directory does not exist physically: ${mm.sourcePath}` };
  }

  if (!fs.existsSync(mm.activePath)) {
    return { available: false, reason: `activePath directory does not exist physically: ${mm.activePath}` };
  }

  return {
    available: true,
    reason: 'rollback metadata available',
    sourcePath: mm.sourcePath,
    activePath: mm.activePath,
    preflightBackupPath: mm.preflightBackupPath,
    legacySourcePreserved: true,
    activeMysqlPathChanged: mm.activeMysqlPathChanged === true,
    mysqlDataMigrationPerformed: mm.mysqlDataMigrationPerformed === true
  };
}

function buildMysqlRollbackPlan(metadata) {
  if (!metadata || !metadata.mysqlMigration) {
    return null;
  }
  const mm = metadata.mysqlMigration;
  const snapshotPathPlaceholder = path.join(getBackupsDir(), 'mysql-rollback-snapshot', 'YYYY-MM-DD-HH-MM-SS');
  return {
    currentActivePath: mm.activePath || '',
    rollbackSourceCandidate: mm.sourcePath || '',
    preflightBackupPath: mm.preflightBackupPath || '',
    snapshotPath: snapshotPathPlaceholder,
    actions: [
      'verify rollback source',
      'verify active path',
      'create pre-rollback snapshot',
      'copy rollback source to active path or restore strategy',
      'verify restored active path',
      'update metadata'
    ]
  };
}

function validateMysqlRollbackSources(metadata) {
  if (!metadata || !metadata.mysqlMigration) {
    return { valid: false, reason: 'No mysqlMigration metadata found' };
  }
  const mm = metadata.mysqlMigration;
  const src = mm.sourcePath;
  const active = mm.activePath;

  if (!src || !fs.existsSync(src)) {
    return { valid: false, reason: `sourcePath is invalid or does not exist: ${src}` };
  }
  if (!active || !fs.existsSync(active)) {
    return { valid: false, reason: `activePath is invalid or does not exist: ${active}` };
  }

  // Validate directory structure
  if (!validateMysqlDataDir(src)) {
    return { valid: false, reason: `sourcePath does not look like a valid MySQL directory: ${src}` };
  }
  if (!validateMysqlDataDir(active)) {
    return { valid: false, reason: `activePath does not look like a valid MySQL directory: ${active}` };
  }

  return { valid: true };
}

function runMysqlRollbackDryRun(metadata) {
  if (!metadata) {
    metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  }

  if (!metadata.mysqlRollback) {
    metadata.mysqlRollback = {
      available: false,
      dryRun: { status: 'not_started', timestamp: null, plan: null, reason: null },
      lastSnapshot: { path: null, status: 'not_started', timestamp: null },
      status: 'not_started',
      lastError: null
    };
  }

  const readiness = getRollbackReadiness(metadata);
  metadata.mysqlRollback.available = readiness.available;

  if (!readiness.available) {
    metadata.mysqlRollback.dryRun = {
      status: 'blocked',
      timestamp: new Date().toISOString(),
      plan: buildMysqlRollbackPlan(metadata),
      reason: readiness.reason
    };
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = readiness.reason;
    writeRuntimeMetadata(metadata);
    return metadata.mysqlRollback;
  }

  const plan = buildMysqlRollbackPlan(metadata);
  const validation = validateMysqlRollbackSources(metadata);

  if (!validation.valid) {
    metadata.mysqlRollback.dryRun = {
      status: 'blocked',
      timestamp: new Date().toISOString(),
      plan: plan,
      reason: validation.reason
    };
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = validation.reason;
    writeRuntimeMetadata(metadata);
    return metadata.mysqlRollback;
  }

  metadata.mysqlRollback.dryRun = {
    status: 'ready',
    timestamp: new Date().toISOString(),
    plan: plan,
    reason: null
  };
  metadata.mysqlRollback.status = 'dry_run_ready';
  metadata.mysqlRollback.lastError = null;

  writeRuntimeMetadata(metadata);
  return metadata.mysqlRollback;
}

function createPreRollbackSnapshot(activePath, backupsDir) {
  if (!fs.existsSync(activePath)) {
    throw new Error(`Active path does not exist for snapshot: ${activePath}`);
  }

  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const destDir = path.join(backupsDir, 'mysql-rollback-snapshot', timestamp);

  try {
    fs.mkdirSync(destDir, { recursive: true });
    copyMysqlDataToRuntime(activePath, destDir);

    const isCopyOk = verifyRuntimeMysqlDataCopy(activePath, destDir);
    if (!isCopyOk) {
      throw new Error('Verification failed: Size or file count mismatch in snapshot');
    }

    return { success: true, path: destDir, timestamp };
  } catch (err) {
    console.error('[RuntimeMigrator] Pre-rollback snapshot failed:', err.message);
    return { success: false, status: 'rollback_snapshot_failed', error: err.message };
  }
}

function executeMysqlRollback(metadata, options = {}) {
  if (!metadata) {
    metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  }

  if (!metadata.mysqlRollback) {
    metadata.mysqlRollback = {
      available: false,
      dryRun: { status: 'not_started', timestamp: null, plan: null, reason: null },
      lastSnapshot: { path: null, status: 'not_started', timestamp: null },
      status: 'not_started',
      lastError: null
    };
  }

  const enableRollback = options.enableRollback === true;
  const confirmationToken = options.confirmationToken === 'CONFIRM_MYSQL_ROLLBACK';
  const dryRun = options.dryRun !== false;

  if (!enableRollback || !confirmationToken || dryRun) {
    metadata.mysqlRollback.status = 'failed';
    metadata.mysqlRollback.lastError = 'Unauthorized rollback request or missing confirmation token';
    writeRuntimeMetadata(metadata);
    throw new Error('Unauthorized rollback execution: confirmation token is missing or invalid.');
  }

  const readiness = getRollbackReadiness(metadata);
  if (!readiness.available) {
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = `Readiness check failed: ${readiness.reason}`;
    writeRuntimeMetadata(metadata);
    return { success: false, reason: readiness.reason };
  }

  const runningProcesses = detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) {
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = 'external_mysql_process_detected';
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: 'mysql_rollback_failed: Running external MySQL process detected.',
      details: { runningProcesses }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'external_mysql_process_detected' };
  }

  const mm = metadata.mysqlMigration;
  const activePath = mm.activePath;
  const sourcePath = mm.sourcePath;

  if (!fs.existsSync(activePath) || !fs.existsSync(sourcePath)) {
    metadata.mysqlRollback.status = 'rollback_blocked';
    metadata.mysqlRollback.lastError = 'Active or legacy path does not exist physically';
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'Active or legacy path does not exist physically' };
  }

  recordRollbackEvent(metadata, {
    phase: 'rollback_snapshot_started',
    message: 'mysql_rollback_snapshot_started: Creating safety snapshot of active database.',
    details: { activePath }
  });

  const snapshotResult = createPreRollbackSnapshot(activePath, getBackupsDir());
  
  if (!snapshotResult.success) {
    metadata.mysqlRollback.status = 'failed';
    metadata.mysqlRollback.lastSnapshot = {
      path: null,
      status: 'failed',
      timestamp: new Date().toISOString()
    };
    metadata.mysqlRollback.lastError = `Snapshot creation failed: ${snapshotResult.error}`;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_rollback_snapshot_failed: Snapshot creation failed: ${snapshotResult.error}`,
      details: {}
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'rollback_snapshot_failed', error: snapshotResult.error };
  }

  metadata.mysqlRollback.lastSnapshot = {
    path: snapshotResult.path,
    status: 'verified',
    timestamp: new Date().toISOString()
  };
  metadata.mysqlRollback.status = 'rollback_ready_for_restore';
  metadata.mysqlRollback.lastError = 'rollback_restore_requires_next_phase';

  recordRollbackEvent(metadata, {
    phase: 'rollback_snapshot_created',
    message: 'mysql_rollback_snapshot_created: Safety snapshot verified successfully.',
    details: { path: snapshotResult.path }
  });

  recordRollbackEvent(metadata, {
    phase: 'rollback_ready',
    message: 'mysql_rollback_ready: System is ready for legacy restore. actual restore requires next phase.',
    details: { reason: 'rollback_restore_requires_requires_next_phase' }
  });

  writeRuntimeMetadata(metadata);
  console.log('[RuntimeMigrator] Pre-rollback snapshot verified. Staged rollback status is rollback_ready_for_restore.');
  return { success: true, status: 'rollback_ready_for_restore', snapshotPath: snapshotResult.path };
}

function recordRollbackEvent(metadata, event) {
  if (!metadata.events) {
    metadata.events = [];
  }
  metadata.events.push({
    timestamp: event.timestamp || new Date().toISOString(),
    phase: event.phase || 'info',
    message: event.message || '',
    details: event.details || {}
  });
}

function canExecuteMysqlRollbackRestore(metadata, options = {}) {
  if (!options.enableRollbackRestore) {
    return { canExecute: false, reason: 'enableRollbackRestore_not_enabled' };
  }
  if (options.confirmationToken !== 'CONFIRM_MYSQL_ROLLBACK_RESTORE') {
    return { canExecute: false, reason: 'invalid_confirmation_token' };
  }
  if (options.dryRun === true) {
    return { canExecute: false, reason: 'dry_run_not_supported_for_restore' };
  }
  if (!metadata) {
    return { canExecute: false, reason: 'no_metadata' };
  }
  
  const mm = metadata.mysqlMigration;
  const mr = metadata.mysqlRollback;
  if (!mm) {
    return { canExecute: false, reason: 'no_mysqlMigration_metadata' };
  }
  if (!mr) {
    return { canExecute: false, reason: 'no_mysqlRollback_metadata' };
  }
  if (mm.status !== 'migration_committed') {
    return { canExecute: false, reason: 'migration_not_committed' };
  }
  if (mr.status !== 'rollback_ready_for_restore') {
    return { canExecute: false, reason: 'rollback_not_ready_for_restore' };
  }
  if (!mr.lastSnapshot || mr.lastSnapshot.status !== 'verified') {
    return { canExecute: false, reason: 'last_snapshot_not_verified' };
  }
  if (mm.rollbackAvailable !== true) {
    return { canExecute: false, reason: 'rollback_not_available' };
  }
  if (mm.legacySourcePreserved !== true) {
    return { canExecute: false, reason: 'legacy_source_not_preserved' };
  }
  if (!mm.activePath || !fs.existsSync(mm.activePath)) {
    return { canExecute: false, reason: 'active_path_missing_or_invalid' };
  }

  // Check running MySQL processes
  const runningProcesses = detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) {
    return { canExecute: false, reason: 'external_mysql_process_detected' };
  }

  return { canExecute: true };
}

function createRollbackRestoreStaging(restoreSourcePath) {
  try {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const stagingPath = path.join(getBackupsDir(), 'mysql-rollback-restore-staging', timestamp);
    
    fs.mkdirSync(stagingPath, { recursive: true });
    copyMysqlDataToRuntime(restoreSourcePath, stagingPath);

    return { success: true, path: stagingPath };
  } catch (err) {
    console.error('[RuntimeMigrator] Create rollback restore staging failed:', err.message);
    return { success: false, error: err.message };
  }
}

function verifyRollbackRestoreStaging(metadata, stagingPath) {
  const mm = metadata.mysqlMigration;
  let restoreSourcePath = null;
  if (mm.sourcePath && fs.existsSync(mm.sourcePath) && validateMysqlDataDir(mm.sourcePath)) {
    restoreSourcePath = mm.sourcePath;
  } else if (mm.preflightBackupPath && fs.existsSync(mm.preflightBackupPath) && validateMysqlDataDir(mm.preflightBackupPath)) {
    restoreSourcePath = mm.preflightBackupPath;
  }

  if (!restoreSourcePath) {
    return false;
  }

  return verifyRuntimeMysqlDataCopy(restoreSourcePath, stagingPath);
}

function runAuthorizedMysqlRollbackRestore(metadata, options = {}) {
  if (!metadata) {
    metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  }

  if (!metadata.mysqlRollback) {
    metadata.mysqlRollback = {
      available: false,
      dryRun: { status: 'not_started', timestamp: null, plan: null, reason: null },
      lastSnapshot: { path: null, status: 'not_started', timestamp: null },
      status: 'not_started',
      finalSwitchPerformed: false,
      activePathSwitched: false,
      activePathOverwritten: false,
      lastError: null
    };
  } else {
    if (metadata.mysqlRollback.finalSwitchPerformed === undefined) {
      metadata.mysqlRollback.finalSwitchPerformed = false;
    }
    if (metadata.mysqlRollback.activePathSwitched === undefined) {
      metadata.mysqlRollback.activePathSwitched = false;
    }
    if (metadata.mysqlRollback.activePathOverwritten === undefined) {
      metadata.mysqlRollback.activePathOverwritten = false;
    }
  }

  const checkResult = canExecuteMysqlRollbackRestore(metadata, options);
  if (!checkResult.canExecute) {
    const reason = checkResult.reason;
    if (reason === 'invalid_confirmation_token' || reason === 'enableRollbackRestore_not_enabled') {
      metadata.mysqlRollback.status = 'failed';
      metadata.mysqlRollback.lastError = `Unauthorized restore: ${reason}`;
      writeRuntimeMetadata(metadata);
      throw new Error(`Unauthorized restore: ${reason}`);
    } else {
      metadata.mysqlRollback.status = 'rollback_restore_blocked';
      metadata.mysqlRollback.lastError = reason;
      recordRollbackEvent(metadata, {
        phase: 'failed',
        message: `mysql_rollback_restore_blocked: ${reason}`,
        details: {}
      });
      writeRuntimeMetadata(metadata);
      return { success: false, reason };
    }
  }

  const mm = metadata.mysqlMigration;
  let restoreSourcePath = null;
  let restoreSourceName = null;

  if (mm.sourcePath && fs.existsSync(mm.sourcePath) && validateMysqlDataDir(mm.sourcePath)) {
    restoreSourcePath = mm.sourcePath;
    restoreSourceName = 'legacy_source';
  } else if (mm.preflightBackupPath && fs.existsSync(mm.preflightBackupPath) && validateMysqlDataDir(mm.preflightBackupPath)) {
    restoreSourcePath = mm.preflightBackupPath;
    restoreSourceName = 'preflight_backup';
  }

  if (!restoreSourcePath) {
    const reason = 'no_valid_restore_source_directory';
    metadata.mysqlRollback.status = 'rollback_restore_blocked';
    metadata.mysqlRollback.lastError = reason;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_rollback_restore_blocked: ${reason}`,
      details: {}
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason };
  }

  recordRollbackEvent(metadata, {
    phase: 'rollback_restore_started',
    message: `mysql_rollback_restore_started: Staging database restore from ${restoreSourceName}.`,
    details: { restoreSourcePath }
  });

  const stagingResult = createRollbackRestoreStaging(restoreSourcePath);
  if (!stagingResult.success) {
    metadata.mysqlRollback.status = 'failed';
    metadata.mysqlRollback.lastError = `Staging creation failed: ${stagingResult.error}`;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_rollback_restore_failed: Staging creation failed: ${stagingResult.error}`,
      details: {}
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'staging_failed', error: stagingResult.error };
  }

  const stagingPath = stagingResult.path;

  const verifyOk = verifyRollbackRestoreStaging(metadata, stagingPath);
  if (!verifyOk) {
    const reason = 'mismatch_count_or_size_in_staging';
    metadata.mysqlRollback.status = 'rollback_restore_verify_failed';
    metadata.mysqlRollback.stagingPath = stagingPath;
    metadata.mysqlRollback.restoreSource = restoreSourceName;
    metadata.mysqlRollback.lastError = reason;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_rollback_restore_failed: Staging verification failed. Path: ${stagingPath}`,
      details: { stagingPath }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'rollback_restore_verify_failed', stagingPath };
  }

  metadata.mysqlRollback.status = 'rollback_restore_staged_verified';
  metadata.mysqlRollback.restoreSource = restoreSourceName;
  metadata.mysqlRollback.stagingPath = stagingPath;
  metadata.mysqlRollback.restoreReadyForFinalSwitch = true;
  metadata.mysqlRollback.finalSwitchPerformed = false;
  metadata.mysqlRollback.activePathOverwritten = false;
  metadata.mysqlRollback.legacySourcePreserved = true;
  metadata.mysqlRollback.lastError = null;

  recordRollbackEvent(metadata, {
    phase: 'rollback_restore_staged_verified',
    message: 'mysql_rollback_restore_staged_verified: Restore staging verified. final switch not performed.',
    details: { stagingPath, restoreSource: restoreSourceName }
  });

  writeRuntimeMetadata(metadata);
  return { 
    success: true, 
    status: 'rollback_restore_staged_verified', 
    stagingPath, 
    restoreSource: restoreSourceName 
  };
}

module.exports = {
  getRollbackReadiness,
  buildMysqlRollbackPlan,
  validateMysqlRollbackSources,
  runMysqlRollbackDryRun,
  createPreRollbackSnapshot,
  executeMysqlRollback,
  recordRollbackEvent,
  canExecuteMysqlRollbackRestore,
  createRollbackRestoreStaging,
  verifyRollbackRestoreStaging,
  runAuthorizedMysqlRollbackRestore
};
