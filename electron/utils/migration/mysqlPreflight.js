const fs = require('fs');
const path = require('path');
const { app } = require('electron');
const { execSync } = require('child_process');
const { getMysqlDataDir, getBackupsDir } = require('../paths');
const { recordMigrationEventDirect, writeRuntimeMetadata } = require('./metadata');
const { copyDirectorySafe } = require('./fileOps');

// Configurable limit: default 2GB
const MYSQL_PREFLIGHT_BACKUP_MAX_BYTES = 2 * 1024 * 1024 * 1024; // 2GB

function detectLegacyMysqlDataDirs() {
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const potentialPaths = [
    path.join(legacyAppDataDir, 'mysql_data'),
    path.join(legacyAppDataDir, 'mysql'),
    path.join(unpackedBackendDir, 'mysql_data'),
    path.join(devBackendDir, 'mysql_data')
  ];

  const activeMysqlDir = getMysqlDataDir();
  const normalizedActive = activeMysqlDir ? path.normalize(activeMysqlDir).toLowerCase() : '';

  const found = [];
  for (const p of potentialPaths) {
    if (fs.existsSync(p) && fs.statSync(p).isDirectory()) {
      const normalizedP = path.normalize(p).toLowerCase();
      if (normalizedP === normalizedActive) {
        continue;
      }
      found.push(p);
    }
  }
  return found;
}

/**
 * Validates if a folder looks like a MySQL data directory.
 */
function validateMysqlDataDir(dirPath) {
  if (!fs.existsSync(dirPath) || !fs.statSync(dirPath).isDirectory()) {
    return false;
  }
  
  try {
    const files = fs.readdirSync(dirPath);
    
    const hasIbdata = files.includes('ibdata1');
    const hasAutoCnf = files.includes('auto.cnf');
    const hasMysqlSubdir = files.some(f => f.toLowerCase() === 'mysql' && fs.statSync(path.join(dirPath, f)).isDirectory());
    const hasPerfSchema = files.some(f => f.toLowerCase() === 'performance_schema' && fs.statSync(path.join(dirPath, f)).isDirectory());
    const hasIbLogfile = files.some(f => f.startsWith('ib_logfile'));
    
    let score = 0;
    if (hasIbdata) score++;
    if (hasAutoCnf) score++;
    if (hasMysqlSubdir) score++;
    if (hasPerfSchema) score++;
    if (hasIbLogfile) score++;

    return score >= 2;
  } catch (err) {
    return false;
  }
}

function detectRunningMysqldProcesses() {
  if (process.platform !== 'win32') return [];
  
  const found = [];
  
  // 1. Try PowerShell Get-CimInstance to retrieve full cmdLine
  try {
    const cmd = `powershell -Command "Get-CimInstance Win32_Process -Filter \\"name='mysqld.exe' or name='mariadbd.exe'\\" | Select-Object Name, CommandLine | ConvertTo-Json"`;
    const output = execSync(cmd, { encoding: 'utf8', windowsHide: true, timeout: 3000 }).trim();
    if (output) {
      const parsed = JSON.parse(output);
      const list = Array.isArray(parsed) ? parsed : [parsed];
      for (const item of list) {
        if (item && item.Name) {
          const match = item.CommandLine ? item.CommandLine.match(/--datadir=(?:"([^"]+)"|([^\s]+))/) : null;
          found.push({
            name: item.Name,
            commandLine: item.CommandLine || null,
            dataDir: match ? (match[1] || match[2]) : null
          });
        }
      }
      return found;
    }
  } catch (err) {
    // Ignore and fallback to wmic
  }
 
  // 2. Try wmic fallback
  try {
    const output = execSync('wmic process where "name=\'mysqld.exe\' or name=\'mariadbd.exe\'" get name,commandline /format:list', { encoding: 'utf8', windowsHide: true, timeout: 3000 }).trim();
    if (output) {
      const blocks = output.split(/\r?\n\r?\n/);
      for (const block of blocks) {
        const lines = block.split(/\r?\n/);
        let name = null;
        let commandLine = null;
        for (const line of lines) {
          if (line.startsWith('CommandLine=')) commandLine = line.substring(12);
          if (line.startsWith('Name=')) name = line.substring(5);
        }
        if (name) {
          const match = commandLine ? commandLine.match(/--datadir=(?:"([^"]+)"|([^\s]+))/) : null;
          found.push({
            name,
            commandLine,
            dataDir: match ? (match[1] || match[2]) : null
          });
        }
      }
      if (found.length > 0) return found;
    }
  } catch (err) {
    // Ignore and fallback to tasklist
  }

  // 3. Fallback to tasklist
  try {
    const output = execSync('tasklist', { encoding: 'utf8', windowsHide: true });
    if (output.toLowerCase().includes('mysqld.exe')) {
      found.push({ name: 'mysqld.exe', commandLine: null, dataDir: null });
    }
    if (output.toLowerCase().includes('mariadbd.exe')) {
      found.push({ name: 'mariadbd.exe', commandLine: null, dataDir: null });
    }
  } catch (err) {
    console.error('[RuntimeMigrator] Fallback tasklist failed:', err.message);
  }

  return found;
}

/**
 * Checks safe read locks on critical MySQL files without locking or truncating.
 */
function detectMysqlLockState(dirPath) {
  if (!fs.existsSync(dirPath)) return 'none';

  try {
    const filesToCheck = ['ibdata1', 'auto.cnf', 'ib_logfile0', 'ib_logfile1'];
    let isLocked = false;
    let isAccessDenied = false;
    let isUnknown = false;

    for (const f of filesToCheck) {
      const filePath = path.join(dirPath, f);
      if (fs.existsSync(filePath)) {
        try {
          const fd = fs.openSync(filePath, 'r');
          fs.closeSync(fd);
        } catch (err) {
          if (err.code === 'EBUSY') {
            isLocked = true;
          } else if (err.code === 'EACCES' || err.code === 'EPERM') {
            isAccessDenied = true;
          } else {
            isUnknown = true;
          }
        }
      }
    }

    const runningProcesses = detectRunningMysqldProcesses();
    if (runningProcesses.length > 0) {
      let datadirMatched = false;
      let undeterminedDatadir = false;
      
      const normalizedDir = path.normalize(dirPath).toLowerCase();
      for (const proc of runningProcesses) {
        if (proc.dataDir) {
          const normalizedProcDir = path.normalize(proc.dataDir).toLowerCase();
          if (normalizedProcDir === normalizedDir) {
            datadirMatched = true;
          }
        } else {
          undeterminedDatadir = true;
        }
      }
      
      if (datadirMatched || undeterminedDatadir) {
        return 'process_detected';
      }
    }

    if (isLocked) return 'locked';
    if (isAccessDenied) return 'access_denied';
    if (isUnknown) return 'unknown_lock_state';

    return 'none';
  } catch (err) {
    return 'unknown_lock_state';
  }
}

/**
 * Recursively sums directory size in bytes.
 */
function getDirSizeBytes(dirPath) {
  let total = 0;
  const files = fs.readdirSync(dirPath);
  for (const file of files) {
    const filePath = path.join(dirPath, file);
    const stat = fs.statSync(filePath);
    if (stat.isDirectory()) {
      total += getDirSizeBytes(filePath);
    } else {
      total += stat.size;
    }
  }
  return total;
}

/**
 * Checks if a legacy folder is valid and safe to replicate.
 */
function isMysqlDataSafeToCopy(dirPath, options = {}) {
  if (!validateMysqlDataDir(dirPath)) {
    return { safe: false, reason: 'invalid_mysql_structure' };
  }

  const lockState = detectMysqlLockState(dirPath);
  if (lockState !== 'none') {
    const reasonMap = {
      'locked': 'locked',
      'access_denied': 'access_denied',
      'process_detected': 'process_detected_copy_skipped',
      'unknown_lock_state': 'unknown_lock_state'
    };
    return { safe: false, reason: reasonMap[lockState] || 'unknown_lock_state' };
  }

  const maxBytes = options.MYSQL_PREFLIGHT_BACKUP_MAX_BYTES !== undefined 
    ? options.MYSQL_PREFLIGHT_BACKUP_MAX_BYTES 
    : (process.env.MYSQL_PREFLIGHT_BACKUP_MAX_BYTES 
        ? parseInt(process.env.MYSQL_PREFLIGHT_BACKUP_MAX_BYTES, 10) 
        : MYSQL_PREFLIGHT_BACKUP_MAX_BYTES);

  try {
    const sizeBytes = getDirSizeBytes(dirPath);
    if (sizeBytes > maxBytes) {
      return { safe: false, reason: 'backup_skipped_size_limit', sizeBytes };
    }
    return { safe: true, reason: 'none', sizeBytes };
  } catch (err) {
    return { safe: false, reason: 'failed', message: err.message };
  }
}

/**
 * Copies MySQL database folders to preflight backup location.
 */
function createMysqlPreMigrationBackup(srcPath, backupRoot) {
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const destPath = path.join(backupRoot, 'mysql-preflight', timestamp);

  try {
    fs.mkdirSync(destPath, { recursive: true });
    copyDirectorySafe(srcPath, destPath);
    return destPath;
  } catch (err) {
    console.error(`[RuntimeMigrator] Preflight backup copy failed:`, err.message);
    throw err;
  }
}

/**
 * Verifies copied files count and size.
 */
function verifyMysqlBackupCopy(srcPath, backupPath) {
  if (!fs.existsSync(srcPath) || !fs.existsSync(backupPath)) {
    return false;
  }

  function getFileInfoRecursive(dir) {
    let count = 0;
    let size = 0;
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const entryPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        const info = getFileInfoRecursive(entryPath);
        count += info.count;
        size += info.size;
      } else {
        count++;
        size += fs.statSync(entryPath).size;
      }
    }
    return { count, size };
  }

  const srcInfo = getFileInfoRecursive(srcPath);
  const destInfo = getFileInfoRecursive(backupPath);

  if (srcInfo.count !== destInfo.count || srcInfo.size !== destInfo.size) {
    return false;
  }

  const criticalFiles = ['ibdata1', 'auto.cnf'];
  for (const cf of criticalFiles) {
    const srcCf = path.join(srcPath, cf);
    const destCf = path.join(backupPath, cf);
    if (fs.existsSync(srcCf)) {
      if (!fs.existsSync(destCf)) return false;
      if (fs.statSync(srcCf).size !== fs.statSync(destCf).size) return false;
    }
  }

  return true;
}

/**
 * Safe Preflight check orchestration.
 */
function runMysqlMigrationPreflight(metadata, options = {}) {
  if (!metadata.mysqlMigration) {
    metadata.mysqlMigration = {
      phase: 'preflight',
      status: 'not_started',
      activeMigrationPerformed: false,
      mysqlDataMigrationPerformed: false,
      activeMysqlPathChanged: false,
      candidates: [],
      backup: {
        path: null,
        status: 'skipped',
        timestamp: null,
        sizeBytes: 0
      },
      lastError: null
    };
  }

  const status = metadata.mysqlMigration.status;
  const force = !!options.forceMysqlPreflight;
  
  if ((status === 'backup_verified' || status === 'skipped') && !force) {
    console.log('[RuntimeMigrator] MySQL preflight already verified or skipped. Skipping.');
    return;
  }

  recordMigrationEventDirect(metadata, {
    phase: 'check',
    message: 'mysql_preflight_started: MySQL migration preflight checklist started.',
    details: {}
  });

  try {
    const foundCandidates = detectLegacyMysqlDataDirs();
    metadata.mysqlMigration.candidates = [];

    if (foundCandidates.length === 0) {
      metadata.mysqlMigration.status = 'skipped';
      metadata.mysqlMigration.backup.status = 'skipped';
      metadata.mysqlMigration.backup.timestamp = new Date().toISOString();
      
      recordMigrationEventDirect(metadata, {
        phase: 'check',
        message: 'mysql_candidate_skipped: No legacy MySQL directories detected.',
        details: {}
      });
      recordMigrationEventDirect(metadata, {
        phase: 'check',
        message: 'mysql_preflight_completed: Preflight complete. Skipping backup.',
        details: {}
      });
      writeRuntimeMetadata(metadata);
      return;
    }

    let chosenCandidate = null;
    let chosenSafeResult = null;

    for (const c of foundCandidates) {
      const isValid = validateMysqlDataDir(c);
      const lockState = detectMysqlLockState(c);
      const isLocked = (lockState !== 'none');
      
      let sizeBytes = 0;
      let reason = 'none';
      if (isValid) {
        try { sizeBytes = getDirSizeBytes(c); } catch (e) {}
      } else {
        reason = 'invalid_mysql_structure';
      }

      const candidateObj = {
        path: c,
        valid: isValid,
        locked: isLocked,
        lockState: lockState,
        sizeBytes: sizeBytes,
        reason: reason
      };

      metadata.mysqlMigration.candidates.push(candidateObj);

      recordMigrationEventDirect(metadata, {
        phase: 'check',
        message: isValid ? `mysql_candidate_detected: Found valid candidate at ${path.basename(c)}.` : `mysql_candidate_skipped: Invalid MySQL candidate structure at ${path.basename(c)}.`,
        details: { path: c, lockState, sizeBytes }
      });

      if (isValid && !chosenCandidate) {
        const safeResult = isMysqlDataSafeToCopy(c, options);
        if (safeResult.safe) {
          chosenCandidate = c;
          chosenSafeResult = safeResult;
        } else {
          metadata.mysqlMigration.status = safeResult.reason;
          metadata.mysqlMigration.candidates[metadata.mysqlMigration.candidates.length - 1].reason = safeResult.reason;
          
          if (safeResult.reason === 'backup_skipped_size_limit') {
            recordMigrationEventDirect(metadata, {
              phase: 'failed',
              message: `mysql_backup_skipped_size_limit: Candidate exceeds size limit. Candidate: ${safeResult.sizeBytes} bytes.`,
              details: { path: c, sizeBytes: safeResult.sizeBytes }
            });
          } else if (safeResult.reason === 'process_detected_copy_skipped') {
            recordMigrationEventDirect(metadata, {
              phase: 'failed',
              message: 'mysql_process_detected: Active database process running. Preflight skipped.',
              details: { path: c }
            });
          } else {
            recordMigrationEventDirect(metadata, {
              phase: 'failed',
              message: `mysql_lock_detected: Database files locked or access denied. State: ${safeResult.reason}.`,
              details: { path: c, lockState: safeResult.reason }
            });
          }
        }
      }
    }

    if (chosenCandidate && chosenSafeResult) {
      metadata.mysqlMigration.status = 'candidate_found';
      writeRuntimeMetadata(metadata);

      recordMigrationEventDirect(metadata, {
        phase: 'copy',
        message: `mysql_backup_started: Copying valid candidate database files for preflight verification.`,
        details: { path: chosenCandidate }
      });

      const backupPath = createMysqlPreMigrationBackup(chosenCandidate, getBackupsDir());
      
      metadata.mysqlMigration.status = 'backup_created';
      metadata.mysqlMigration.backup.path = backupPath;
      metadata.mysqlMigration.backup.status = 'created';
      metadata.mysqlMigration.backup.timestamp = new Date().toISOString();
      metadata.mysqlMigration.backup.sizeBytes = chosenSafeResult.sizeBytes;
      recordMigrationEventDirect(metadata, {
        phase: 'copy',
        message: 'mysql_backup_created: Preflight backup copy created successfully.',
        details: { backupPath, sizeBytes: chosenSafeResult.sizeBytes }
      });
      writeRuntimeMetadata(metadata);

      const isVerified = verifyMysqlBackupCopy(chosenCandidate, backupPath);
      if (isVerified) {
        metadata.mysqlMigration.status = 'backup_verified';
        metadata.mysqlMigration.backup.status = 'verified';
        recordMigrationEventDirect(metadata, {
          phase: 'verify',
          message: `mysql_backup_verified: Preflight backup verified successfully. File counts and sizes match exactly.`,
          details: { backupPath }
        });
      } else {
        metadata.mysqlMigration.status = 'failed';
        metadata.mysqlMigration.backup.status = 'failed';
        metadata.mysqlMigration.lastError = 'Backup verification size or count mismatch';
        recordMigrationEventDirect(metadata, {
          phase: 'failed',
          message: `mysql_preflight_failed: Verification mismatch between source and preflight backup.`,
          details: { backupPath }
        });
      }
    } else {
      if (metadata.mysqlMigration.status === 'not_started') {
        metadata.mysqlMigration.status = 'skipped';
      }
    }

    recordMigrationEventDirect(metadata, {
      phase: 'check',
      message: `mysql_preflight_completed: Preflight sequence finished. Status: ${metadata.mysqlMigration.status}.`,
      details: { status: metadata.mysqlMigration.status }
    });
    
    writeRuntimeMetadata(metadata);
  } catch (err) {
    console.error('[RuntimeMigrator] MySQL preflight execution failed:', err.message);
    metadata.mysqlMigration.status = 'failed';
    metadata.mysqlMigration.lastError = err.message;
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `mysql_preflight_failed: Preflight crashed: ${err.message}`,
      details: { error: err.stack }
    });
    writeRuntimeMetadata(metadata);
  }
}

module.exports = {
  detectLegacyMysqlDataDirs,
  validateMysqlDataDir,
  detectRunningMysqldProcesses,
  detectMysqlLockState,
  getDirSizeBytes,
  isMysqlDataSafeToCopy,
  createMysqlPreMigrationBackup,
  verifyMysqlBackupCopy,
  runMysqlMigrationPreflight
};
