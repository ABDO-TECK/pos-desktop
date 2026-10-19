const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { getBackupsDir } = require('../paths');
const { writeRuntimeMetadata } = require('./metadata');
const { validateMysqlDataDir, detectRunningMysqldProcesses } = require('./mysqlPreflight');
const { copyMysqlDataToRuntime, verifyRuntimeMysqlDataCopy } = require('./mysqlMigration');
const { recordRollbackEvent } = require('./mysqlRollback');

function createStagingManifest(stagingPath) {
  let fileCount = 0;
  let totalSize = 0;
  const criticalFiles = {};
  const sampleHashes = {};

  const filesToCheck = ['ibdata1', 'auto.cnf'];
  for (const file of filesToCheck) {
    const filePath = path.join(stagingPath, file);
    if (fs.existsSync(filePath)) {
      criticalFiles[file] = true;
      try {
        const content = fs.readFileSync(filePath);
        const hash = crypto.createHash('sha256').update(content).digest('hex');
        sampleHashes[file] = hash;
      } catch (err) {
        console.warn(`[Manifest] Could not calculate hash for ${file}:`, err.message);
      }
    } else {
      criticalFiles[file] = false;
    }
  }

  criticalFiles['mysql'] = fs.existsSync(path.join(stagingPath, 'mysql')) && fs.statSync(path.join(stagingPath, 'mysql')).isDirectory();

  function walk(dir) {
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const entryPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        walk(entryPath);
      } else {
        fileCount++;
        totalSize += fs.statSync(entryPath).size;
      }
    }
  }
  walk(stagingPath);

  return {
    fileCount,
    totalSize,
    criticalFiles,
    sampleHashes
  };
}

function canExecuteFinalRollbackSwitch(metadata, options = {}) {
  if (!options.enableFinalRollbackSwitch) {
    return { canExecute: false, reason: 'enableFinalRollbackSwitch_not_enabled' };
  }
  if (options.confirmationToken !== 'CONFIRM_FINAL_MYSQL_ROLLBACK_SWITCH') {
    return { canExecute: false, reason: 'invalid_confirmation_token' };
  }
  if (options.dryRun === true) {
    return { canExecute: false, reason: 'dry_run_not_supported_for_final_switch' };
  }
  if (!metadata) {
    return { canExecute: false, reason: 'no_metadata' };
  }

  const mm = metadata.mysqlMigration;
  const mr = metadata.mysqlRollback;

  if (!mm || !mr) {
    return { canExecute: false, reason: 'missing_migration_or_rollback_metadata' };
  }

  if (mr.status !== 'rollback_restore_staged_verified') {
    return { canExecute: false, reason: 'rollback_not_staged_verified' };
  }

  if (mr.restoreReadyForFinalSwitch !== true) {
    return { canExecute: false, reason: 'restore_not_ready_for_final_switch' };
  }

  if (mr.finalSwitchPerformed === true) {
    return { canExecute: false, reason: 'final_switch_already_performed' };
  }

  if (!mr.stagingPath || !fs.existsSync(mr.stagingPath) || !validateMysqlDataDir(mr.stagingPath)) {
    return { canExecute: false, reason: 'staging_path_missing_or_invalid' };
  }

  if (!mm.activePath || !fs.existsSync(mm.activePath)) {
    return { canExecute: false, reason: 'active_path_missing_or_invalid' };
  }

  if (!mr.lastSnapshot || mr.lastSnapshot.status !== 'verified') {
    return { canExecute: false, reason: 'last_snapshot_not_verified' };
  }

  // Check running MySQL processes
  const runningProcesses = detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) {
    return { canExecute: false, reason: 'external_mysql_process_detected' };
  }

  return { canExecute: true };
}

function createFinalSwitchSafetySnapshot(activePath, backupsDir) {
  try {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const destDir = path.join(backupsDir, 'mysql-final-switch-snapshot', timestamp);
    
    fs.mkdirSync(destDir, { recursive: true });
    copyMysqlDataToRuntime(activePath, destDir);

    const isCopyOk = verifyRuntimeMysqlDataCopy(activePath, destDir);
    if (!isCopyOk) {
      throw new Error('Verification failed: Size or file count mismatch in final safety snapshot');
    }

    return { success: true, path: destDir, timestamp };
  } catch (err) {
    console.error('[RuntimeMigrator] Final switch safety snapshot failed:', err.message);
    return { success: false, error: err.message };
  }
}

function prepareActivePathForSwitch(activePath, options = {}) {
  try {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const backupPath = `${activePath}.pre-final-rollback.${timestamp}`;
    
    fs.renameSync(activePath, backupPath);
    return { success: true, backupPath };
  } catch (err) {
    console.error('[RuntimeMigrator] Prepare active path rename failed:', err.message);
    return { success: false, error: err.message };
  }
}

function switchStagingToActivePath(stagingPath, activePath, options = {}) {
  try {
    fs.renameSync(stagingPath, activePath);
    return { success: true };
  } catch (err) {
    console.error('[RuntimeMigrator] Switch staging rename to active failed:', err.message);
    return { success: false, error: err.message };
  }
}

function verifyFinalActivePath(metadata, activePath, manifest) {
  if (!fs.existsSync(activePath)) {
    return false;
  }

  if (!validateMysqlDataDir(activePath)) {
    return false;
  }

  // Compare against manifest
  const newManifest = createStagingManifest(activePath);
  if (newManifest.fileCount !== manifest.fileCount || newManifest.totalSize !== manifest.totalSize) {
    console.error(`[VerifyFinal] Mismatch count/size. Expected: ${manifest.fileCount}/${manifest.totalSize}, Got: ${newManifest.fileCount}/${newManifest.totalSize}`);
    return false;
  }

  // Check critical files
  for (const file in manifest.criticalFiles) {
    if (manifest.criticalFiles[file] !== newManifest.criticalFiles[file]) {
      console.error(`[VerifyFinal] Critical file existence mismatch: ${file}`);
      return false;
    }
  }

  // Check sample hashes
  for (const file in manifest.sampleHashes) {
    if (manifest.sampleHashes[file] !== newManifest.sampleHashes[file]) {
      console.error(`[VerifyFinal] Sample hash mismatch: ${file}`);
      return false;
    }
  }

  try {
    fs.accessSync(activePath, fs.constants.R_OK);
  } catch (err) {
    console.error('[VerifyFinal] Active path is not readable:', err.message);
    return false;
  }

  return true;
}

function commitFinalRollbackSwitch(metadata, result) {
  metadata.mysqlRollback.status = 'rollback_completed';
  metadata.mysqlRollback.finalSwitchPerformed = true;
  metadata.mysqlRollback.activePathSwitched = true;
  metadata.mysqlRollback.activePathOverwritten = false;
  metadata.mysqlRollback.restoreReadyForFinalSwitch = false;
  metadata.mysqlRollback.completedAt = new Date().toISOString();
  metadata.mysqlRollback.finalActivePath = result.finalActivePath;
  metadata.mysqlRollback.previousActivePathBackup = result.previousActivePathBackup;
  metadata.mysqlRollback.finalSwitchSnapshotPath = result.finalSwitchSnapshotPath;
  metadata.mysqlRollback.stagingPathUsed = result.stagingPathUsed;
  metadata.mysqlRollback.lastError = null;

  writeRuntimeMetadata(metadata);
}

function abortFinalRollbackSwitch(metadata, status, reason) {
  metadata.mysqlRollback.status = status;
  metadata.mysqlRollback.lastError = reason;
  recordRollbackEvent(metadata, {
    phase: 'failed',
    message: `mysql_final_switch_failed: ${reason}`,
    details: {}
  });
  writeRuntimeMetadata(metadata);
}

function runFinalMysqlRollbackSwitch(metadata, options = {}) {
  if (!metadata) {
    metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  }

  if (metadata.mysqlRollback) {
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

  const checkResult = canExecuteFinalRollbackSwitch(metadata, options);
  if (!checkResult.canExecute) {
    const reason = checkResult.reason;
    if (reason === 'invalid_confirmation_token' || reason === 'enableFinalRollbackSwitch_not_enabled') {
      abortFinalRollbackSwitch(metadata, 'failed', `Unauthorized final switch: ${reason}`);
      throw new Error(`Unauthorized final switch: ${reason}`);
    } else {
      abortFinalRollbackSwitch(metadata, 'final_switch_blocked', reason);
      return { success: false, reason };
    }
  }

  const mr = metadata.mysqlRollback;
  const mm = metadata.mysqlMigration;
  const activePath = mm.activePath;
  const stagingPath = mr.stagingPath;

  recordRollbackEvent(metadata, {
    phase: 'final_switch_started',
    message: 'mysql_final_switch_started: Creating safety snapshot of active database before switch.',
    details: { activePath }
  });

  // 1. Create final safety snapshot
  const snapshotResult = createFinalSwitchSafetySnapshot(activePath, getBackupsDir());
  if (!snapshotResult.success) {
    const reason = `Final switch safety snapshot failed: ${snapshotResult.error}`;
    abortFinalRollbackSwitch(metadata, 'final_switch_snapshot_failed', reason);
    return { success: false, reason: 'final_switch_snapshot_failed', error: snapshotResult.error };
  }

  const snapshotPath = snapshotResult.path;

  recordRollbackEvent(metadata, {
    phase: 'final_switch_snapshot_created',
    message: 'mysql_final_switch_snapshot_created: Safety snapshot verified successfully.',
    details: { snapshotPath }
  });

  // Create staging manifest before renaming stagingPath
  const stagingManifest = createStagingManifest(stagingPath);

  // 2. Rename activePath to pre-final-rollback
  recordRollbackEvent(metadata, {
    phase: 'final_switch_rename_started',
    message: 'mysql_final_switch_rename_started: Renaming active database to previous backup.',
    details: { activePath }
  });

  const prepareResult = prepareActivePathForSwitch(activePath, options);
  if (!prepareResult.success) {
    const reason = `atomic_rename_unavailable: ${prepareResult.error}`;
    abortFinalRollbackSwitch(metadata, 'final_switch_rename_failed', reason);
    return { success: false, reason: 'final_switch_rename_failed', error: prepareResult.error };
  }

  const backupPath = prepareResult.backupPath;

  // 3. Rename stagingPath to activePath
  const switchResult = switchStagingToActivePath(stagingPath, activePath, options);
  if (!switchResult.success) {
    // Attempt best-effort restore rename of pre-final-rollback back to activePath
    try {
      fs.renameSync(backupPath, activePath);
      console.log('[RuntimeMigrator] Successfully restored activePath backup on staging rename failure.');
    } catch (restoreErr) {
      console.error('[RuntimeMigrator] Failed to restore activePath backup on staging rename failure:', restoreErr.message);
    }

    const reason = `atomic_rename_unavailable: ${switchResult.error}`;
    abortFinalRollbackSwitch(metadata, 'final_switch_rename_failed', reason);
    return { success: false, reason: 'final_switch_rename_failed', error: switchResult.error };
  }

  // 4. Verify new activePath against staging manifest
  const verifyOk = verifyFinalActivePath(metadata, activePath, stagingManifest);
  if (!verifyOk) {
    const reason = 'mismatch_count_or_size_in_final_active_path';
    metadata.mysqlRollback.status = 'final_switch_verify_failed';
    metadata.mysqlRollback.stagingPathUsed = stagingPath;
    metadata.mysqlRollback.previousActivePathBackup = backupPath;
    metadata.mysqlRollback.finalSwitchSnapshotPath = snapshotPath;
    metadata.mysqlRollback.finalActivePath = activePath;
    metadata.mysqlRollback.lastError = reason;
    recordRollbackEvent(metadata, {
      phase: 'failed',
      message: `mysql_final_switch_failed: Staging manifest verification failed. Path: ${activePath}`,
      details: { activePath }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'final_switch_verify_failed', activePath };
  }

  // 5. Commit metadata on success
  const commitResult = {
    finalActivePath: activePath,
    previousActivePathBackup: backupPath,
    finalSwitchSnapshotPath: snapshotPath,
    stagingPathUsed: stagingPath
  };

  commitFinalRollbackSwitch(metadata, commitResult);

  recordRollbackEvent(metadata, {
    phase: 'rollback_completed',
    message: 'mysql_rollback_completed: Final database switch verified and committed successfully.',
    details: commitResult
  });

  return {
    success: true,
    status: 'rollback_completed',
    finalActivePath: activePath,
    previousActivePathBackup: backupPath,
    finalSwitchSnapshotPath: snapshotPath,
    stagingPathUsed: stagingPath
  };
}

module.exports = {
  createStagingManifest,
  canExecuteFinalRollbackSwitch,
  createFinalSwitchSafetySnapshot,
  prepareActivePathForSwitch,
  switchStagingToActivePath,
  verifyFinalActivePath,
  commitFinalRollbackSwitch,
  abortFinalRollbackSwitch,
  runFinalMysqlRollbackSwitch
};
