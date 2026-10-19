const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { getMysqlDataDir } = require('../paths');
const { recordMigrationEventDirect, writeRuntimeMetadata } = require('./metadata');
const { detectMysqlLockState, detectRunningMysqldProcesses } = require('./mysqlPreflight');

function canRunMysqlActiveMigration(metadata, options = {}) {
  if (!metadata || !metadata.mysqlMigration) return false;
  
  const migrationEnabled = options.enableActiveMysqlMigration === true || process.env.ENABLE_ACTIVE_MYSQL_MIGRATION === 'true';
  if (!migrationEnabled) return false;

  const preflightStatus = metadata.mysqlMigration.status;
  const backupStatus = metadata.mysqlMigration.backup ? metadata.mysqlMigration.backup.status : 'skipped';
  
  if (preflightStatus !== 'backup_verified' || backupStatus !== 'verified') {
    return false;
  }

  if (metadata.mysqlMigration.mysqlDataMigrationPerformed || metadata.mysqlDataMigrationPerformed) {
    return false;
  }

  const validCandidate = metadata.mysqlMigration.candidates.find(c => c.valid === true);
  if (!validCandidate) return false;

  const lockState = detectMysqlLockState(validCandidate.path);
  if (lockState !== 'none') return false;

  const runningProcesses = detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) return false;

  return true;
}

function stopAppManagedMysqlIfRunning(options = {}) {
  // In Phase 11, runRuntimeMigration is called during app initialization (app.whenReady)
  // BEFORE startMySQL is called. Therefore, there is no app-managed MySQL process started yet.
  // We do not stop any external database or run taskkill/net stop commands.
  return true;
}

function copyMysqlDataToRuntime(src, dest, options = {}) {
  if (!fs.existsSync(src)) {
    throw new Error(`Source path does not exist: ${src}`);
  }
  
  const stats = fs.statSync(src);
  if (stats.isDirectory()) {
    if (!fs.existsSync(dest)) {
      fs.mkdirSync(dest, { recursive: true });
    }
    const entries = fs.readdirSync(src);
    for (const entry of entries) {
      const entryLower = entry.toLowerCase();
      if (entryLower.endsWith('.pid') || entryLower.endsWith('.err') || entryLower === 'ibtmp1') {
        continue;
      }
      copyMysqlDataToRuntime(path.join(src, entry), path.join(dest, entry), options);
    }
  } else {
    fs.copyFileSync(src, dest);
  }
}

function verifyRuntimeMysqlDataCopy(srcPath, destPath) {
  if (!fs.existsSync(srcPath) || !fs.existsSync(destPath)) {
    return false;
  }

  function getFileInfo(dir, skipTemp = true) {
    let count = 0;
    let size = 0;
    const filesList = [];
    
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const nameLower = entry.name.toLowerCase();
      if (skipTemp && (nameLower.endsWith('.pid') || nameLower.endsWith('.err') || nameLower === 'ibtmp1')) {
        continue;
      }
      
      const entryPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        const info = getFileInfo(entryPath, skipTemp);
        count += info.count;
        size += info.size;
        filesList.push(...info.filesList);
      } else {
        count++;
        const s = fs.statSync(entryPath).size;
        size += s;
        filesList.push({ name: entry.name, size: s, path: entryPath });
      }
    }
    return { count, size, filesList };
  }

  const srcInfo = getFileInfo(srcPath, true);
  const destInfo = getFileInfo(destPath, false);

  if (srcInfo.count !== destInfo.count || srcInfo.size !== destInfo.size) {
    console.error(`[Verify] Mismatch count/size. Src: ${srcInfo.count}/${srcInfo.size}, Dest: ${destInfo.count}/${destInfo.size}`);
    return false;
  }

  const hasIbdata = fs.existsSync(path.join(destPath, 'ibdata1'));
  const hasAutoCnf = fs.existsSync(path.join(destPath, 'auto.cnf'));
  const hasMysqlDir = fs.existsSync(path.join(destPath, 'mysql')) && fs.statSync(path.join(destPath, 'mysql')).isDirectory();

  if (!hasIbdata || !hasAutoCnf || !hasMysqlDir) {
    console.error('[Verify] Critical files missing in destination');
    return false;
  }

  try {
    const autoCnfSrc = path.join(srcPath, 'auto.cnf');
    const autoCnfDest = path.join(destPath, 'auto.cnf');
    if (fs.existsSync(autoCnfSrc) && fs.existsSync(autoCnfDest)) {
      const srcHash = crypto.createHash('sha256').update(fs.readFileSync(autoCnfSrc)).digest('hex');
      const destHash = crypto.createHash('sha256').update(fs.readFileSync(autoCnfDest)).digest('hex');
      if (srcHash !== destHash) {
        console.error('[Verify] Hash mismatch for auto.cnf');
        return false;
      }
    }
  } catch (hashErr) {
    console.warn('[Verify] Hash verification skipped due to error:', hashErr.message);
  }

  try {
    fs.accessSync(destPath, fs.constants.R_OK);
  } catch (accessErr) {
    console.error('[Verify] Destination is not readable:', accessErr.message);
    return false;
  }

  return true;
}

function writeMysqlMigrationCommit(metadata, result) {
  metadata.mysqlMigration = {
    phase: 'controlled_migration',
    status: 'migration_committed',
    activeMigrationPerformed: true,
    mysqlDataMigrationPerformed: true,
    activeMysqlPathChanged: true,
    sourcePath: result.sourcePath,
    activePath: result.activePath,
    preflightBackupPath: result.preflightBackupPath,
    rollbackAvailable: true,
    legacySourcePreserved: true,
    committedAt: new Date().toISOString(),
    lastError: null
  };
  
  metadata.mysqlDataMigrationPerformed = true;
  metadata.realDataMigrationPerformed = true;
  
  writeRuntimeMetadata(metadata);
}

function markMysqlMigrationRollbackAvailable(metadata, reason) {
  if (metadata.mysqlMigration) {
    metadata.mysqlMigration.rollbackAvailable = true;
    metadata.mysqlMigration.lastError = reason;
    writeRuntimeMetadata(metadata);
  }
}

function runControlledMysqlMigration(metadata, options = {}) {
  if (!metadata.mysqlMigration) {
    metadata.mysqlMigration = {
      phase: 'controlled_migration',
      status: 'not_started',
      activeMigrationPerformed: false,
      mysqlDataMigrationPerformed: false,
      activeMysqlPathChanged: false,
      candidates: [],
      backup: { path: null, status: 'skipped', timestamp: null, sizeBytes: 0 },
      lastError: null
    };
  }

  const migrationEnabled = options.enableActiveMysqlMigration === true || process.env.ENABLE_ACTIVE_MYSQL_MIGRATION === 'true';
  if (!migrationEnabled) {
    metadata.mysqlMigration.phase = 'controlled_migration';
    metadata.mysqlMigration.status = 'active_migration_not_enabled';
    recordMigrationEventDirect(metadata, {
      phase: 'check',
      message: 'mysql_active_migration_skipped: Active migration is not enabled via config or env.',
      details: {}
    });
    writeRuntimeMetadata(metadata);
    return { success: true, message: 'Active MySQL migration is not enabled' };
  }

  const preflightStatus = metadata.mysqlMigration.status;
  const backupStatus = metadata.mysqlMigration.backup ? metadata.mysqlMigration.backup.status : 'skipped';
  
  if (preflightStatus !== 'backup_verified' || backupStatus !== 'verified') {
    console.log('[RuntimeMigrator] Preflight check not verified. Skipping controlled migration.');
    return { success: false, reason: 'preflight_not_verified' };
  }

  if (metadata.mysqlMigration.mysqlDataMigrationPerformed || metadata.mysqlDataMigrationPerformed) {
    console.log('[RuntimeMigrator] MySQL migration already performed.');
    return { success: true, message: 'Already migrated' };
  }

  const validCandidate = metadata.mysqlMigration.candidates.find(c => c.valid === true);
  if (!validCandidate) {
    console.log('[RuntimeMigrator] No valid MySQL candidates found.');
    return { success: false, reason: 'no_valid_candidate' };
  }

  const lockState = detectMysqlLockState(validCandidate.path);
  if (lockState !== 'none') {
    metadata.mysqlMigration.status = (lockState === 'process_detected' ? 'external_mysql_process_detected' : lockState);
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `mysql_active_migration_failed: Candidate is locked or external mysql process detected. lockState: ${lockState}`,
      details: { path: validCandidate.path, lockState }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: lockState === 'process_detected' ? 'external_mysql_process_detected' : lockState };
  }

  const runningProcesses = detectRunningMysqldProcesses();
  if (runningProcesses.length > 0) {
    metadata.mysqlMigration.status = 'external_mysql_process_detected';
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: 'mysql_active_migration_failed: Running external MySQL process detected.',
      details: { runningProcesses }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, reason: 'external_mysql_process_detected' };
  }

  const destDir = getMysqlDataDir();
  if (!fs.existsSync(destDir)) {
    fs.mkdirSync(destDir, { recursive: true });
  } else {
    try {
      const files = fs.readdirSync(destDir);
      if (files.length > 0) {
        metadata.mysqlMigration.status = 'destination_not_empty';
        recordMigrationEventDirect(metadata, {
          phase: 'failed',
          message: 'mysql_active_migration_failed: Destination directory is not empty. Overwrite prevented.',
          details: { destDir, files }
        });
        writeRuntimeMetadata(metadata);
        return { success: false, reason: 'destination_not_empty' };
      }
    } catch (err) {
      console.error('[RuntimeMigrator] Failed to read destination directory:', err.message);
      metadata.mysqlMigration.status = 'failed';
      metadata.mysqlMigration.lastError = `Destination read error: ${err.message}`;
      writeRuntimeMetadata(metadata);
      return { success: false, reason: 'failed' };
    }
  }

  recordMigrationEventDirect(metadata, {
    phase: 'controlled_migration',
    message: 'mysql_active_migration_started: Controlled MySQL active migration execution started.',
    details: { from: validCandidate.path, to: destDir }
  });

  try {
    stopAppManagedMysqlIfRunning(options);

    copyMysqlDataToRuntime(validCandidate.path, destDir, options);

    const isCopyOk = verifyRuntimeMysqlDataCopy(validCandidate.path, destDir);
    if (!isCopyOk) {
      metadata.mysqlMigration.status = 'verify_failed';
      recordMigrationEventDirect(metadata, {
        phase: 'failed',
        message: 'mysql_active_migration_failed: Copy verification failed. Size or count mismatch.',
        details: { from: validCandidate.path, to: destDir }
      });
      writeRuntimeMetadata(metadata);
      return { success: false, reason: 'verify_failed' };
    }

    const commitResult = {
      sourcePath: validCandidate.path,
      activePath: destDir,
      preflightBackupPath: metadata.mysqlMigration.backup.path
    };

    writeMysqlMigrationCommit(metadata, commitResult);

    recordMigrationEventDirect(metadata, {
      phase: 'controlled_migration',
      message: 'mysql_active_migration_completed: Controlled MySQL active migration completed successfully.',
      details: commitResult
    });

    console.log('[RuntimeMigrator] Controlled MySQL active migration completed successfully.');
    return { success: true };

  } catch (err) {
    console.error('[RuntimeMigrator] Controlled MySQL active migration crashed:', err.message);
    metadata.mysqlMigration.status = 'failed';
    metadata.mysqlMigration.lastError = err.message;
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `mysql_active_migration_failed: Migration crashed: ${err.message}`,
      details: { error: err.stack }
    });
    writeRuntimeMetadata(metadata);
    return { success: false, error: err.message };
  }
}

module.exports = {
  canRunMysqlActiveMigration,
  stopAppManagedMysqlIfRunning,
  copyMysqlDataToRuntime,
  verifyRuntimeMysqlDataCopy,
  writeMysqlMigrationCommit,
  markMysqlMigrationRollbackAvailable,
  runControlledMysqlMigration
};
