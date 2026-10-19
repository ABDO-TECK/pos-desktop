const fs = require('fs');
const path = require('path');
const { app } = require('electron');
const { getConfigDir, getLogsDir, getBackupsDir, getTempDir } = require('../paths');
const { recordMigrationEventDirect, writeRuntimeMetadata } = require('./metadata');

function migrateEnvFile(metadata) {
  const destDir = getConfigDir();
  const destPath = path.join(destDir, '.env');
  
  let sourcePath = null;
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const envSources = [
    path.join(legacyAppDataDir, '.env'),
    path.join(unpackedBackendDir, '.env'),
    path.join(devBackendDir, '.env')
  ];

  for (const src of envSources) {
    if (fs.existsSync(src)) {
      sourcePath = src;
      break;
    }
  }

  if (!sourcePath) {
    metadata.fileMigrations.env = {
      status: 'skipped',
      from: null,
      to: destPath,
      timestamp: new Date().toISOString()
    };
    recordMigrationEventDirect(metadata, {
      phase: 'check',
      message: 'env_skipped: No legacy .env file found to migrate.',
      details: {}
    });
    return;
  }

  try {
    if (!fs.existsSync(destPath)) {
      fs.mkdirSync(destDir, { recursive: true });
      fs.copyFileSync(sourcePath, destPath);
      
      const srcStats = fs.statSync(sourcePath);
      const destStats = fs.statSync(destPath);
      if (srcStats.size !== destStats.size) {
        throw new Error('Verification failed: Size mismatch');
      }

      metadata.fileMigrations.env = {
        status: 'migrated',
        from: sourcePath,
        to: destPath,
        timestamp: new Date().toISOString()
      };
      
      recordMigrationEventDirect(metadata, {
        phase: 'copy',
        message: 'env_migrated: Successfully copied .env file to config folder.',
        details: { from: sourcePath, to: destPath }
      });
    } else {
      const srcStats = fs.statSync(sourcePath);
      const destStats = fs.statSync(destPath);
      if (srcStats.size === destStats.size) {
        metadata.fileMigrations.env = {
          status: 'skipped',
          from: sourcePath,
          to: destPath,
          timestamp: new Date().toISOString()
        };
        recordMigrationEventDirect(metadata, {
          phase: 'check',
          message: 'env_skipped: Destination .env already exists and matches legacy file size.',
          details: { from: sourcePath, to: destPath }
        });
        return;
      }
      
      const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
      const conflictPath = path.join(destDir, `.env.legacy.${timestamp}`);
      fs.copyFileSync(sourcePath, conflictPath);
      
      metadata.fileMigrations.env = {
        status: 'migrated_with_conflict_copy',
        from: sourcePath,
        to: conflictPath,
        timestamp: new Date().toISOString()
      };

      recordMigrationEventDirect(metadata, {
        phase: 'check',
        message: `env_conflict_copy_created: Active .env already exists. Created legacy backup copy at ${path.basename(conflictPath)}.`,
        details: { from: sourcePath, to: conflictPath }
      });
    }
  } catch (err) {
    console.error('[RuntimeMigrator] Failed to migrate .env:', err.message);
    metadata.fileMigrations.env = {
      status: 'failed',
      from: sourcePath,
      to: destPath,
      timestamp: new Date().toISOString()
    };
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `env_failed: Failed to migrate env file: ${err.message}`,
      details: { from: sourcePath, to: destPath }
    });
  }
}

/**
 * Handler 2: Migrate logs from legacy paths.
 */
function migrateLogs(metadata) {
  const destDir = getLogsDir();
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const logSources = [
    path.join(legacyAppDataDir, 'logs'),
    path.join(unpackedBackendDir, 'logs'),
    path.join(devBackendDir, 'logs')
  ];

  let filesCopied = 0;
  let hasConflicts = false;
  let anyFound = false;

  for (const srcDir of logSources) {
    if (fs.existsSync(srcDir) && fs.statSync(srcDir).isDirectory()) {
      anyFound = true;
      const files = fs.readdirSync(srcDir);
      for (const file of files) {
        const srcPath = path.join(srcDir, file);
        if (fs.statSync(srcPath).isFile()) {
          let destPath = path.join(destDir, file);
          try {
            if (fs.existsSync(destPath)) {
              const srcSize = fs.statSync(srcPath).size;
              const destSize = fs.statSync(destPath).size;
              if (srcSize === destSize) {
                continue;
              }
              hasConflicts = true;
              const ext = path.extname(file);
              const base = path.basename(file, ext);
              const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
              destPath = path.join(destDir, `${base}.legacy.${timestamp}${ext}`);
            }

            fs.copyFileSync(srcPath, destPath);
            filesCopied++;
          } catch (err) {
            console.error(`[RuntimeMigrator] Failed to copy log ${file}:`, err.message);
          }
        }
      }
    }
  }

  metadata.fileMigrations.logs = {
    status: !anyFound ? 'skipped' : (hasConflicts ? 'conflict' : 'migrated'),
    filesCopied,
    timestamp: new Date().toISOString()
  };

  recordMigrationEventDirect(metadata, {
    phase: filesCopied > 0 ? 'copy' : 'check',
    message: filesCopied > 0 ? `logs_migrated: Successfully copied ${filesCopied} log files.` : 'logs_skipped: No log files required migration.',
    details: { filesCopied, hasConflicts }
  });
}

/**
 * Handler 3: Migrate backups from legacy paths.
 */
function migrateBackups(metadata) {
  const destDir = getBackupsDir();
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const backupSources = [
    path.join(legacyAppDataDir, 'backups'),
    path.join(unpackedBackendDir, 'backups'),
    path.join(devBackendDir, 'backups')
  ];

  let filesCopied = 0;
  let hasConflicts = false;
  let anyFound = false;

  for (const srcDir of backupSources) {
    if (fs.existsSync(srcDir) && fs.statSync(srcDir).isDirectory()) {
      anyFound = true;
      const files = fs.readdirSync(srcDir);
      for (const file of files) {
        const srcPath = path.join(srcDir, file);
        if (fs.statSync(srcPath).isFile()) {
          let destPath = path.join(destDir, file);
          try {
            if (fs.existsSync(destPath)) {
              const srcSize = fs.statSync(srcPath).size;
              const destSize = fs.statSync(destPath).size;
              if (srcSize === destSize) {
                continue;
              }
              hasConflicts = true;
              const ext = path.extname(file);
              const base = path.basename(file, ext);
              const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
              destPath = path.join(destDir, `${base}.legacy.${timestamp}${ext}`);
            }

            fs.copyFileSync(srcPath, destPath);
            filesCopied++;
          } catch (err) {
            console.error(`[RuntimeMigrator] Failed to copy backup ${file}:`, err.message);
          }
        }
      }
    }
  }

  metadata.fileMigrations.backups = {
    status: !anyFound ? 'skipped' : (hasConflicts ? 'conflict' : 'migrated'),
    filesCopied,
    timestamp: new Date().toISOString()
  };

  recordMigrationEventDirect(metadata, {
    phase: filesCopied > 0 ? 'copy' : 'check',
    message: filesCopied > 0 ? `backups_migrated: Successfully copied ${filesCopied} database backup files.` : 'backups_skipped: No database backups found to migrate.',
    details: { filesCopied, hasConflicts }
  });
}

/**
 * Handler 4: Migrate sqlite/cache files from legacy paths, excluding mysql.
 */
function migrateCacheRuntimeFiles(metadata) {
  const destDir = path.join(getTempDir(), 'runtime-cache');
  const legacyAppDataDir = path.join(app.getPath('appData'), 'pos-desktop');
  const unpackedBackendDir = path.join(app.getAppPath().replace('app.asar', 'app.asar.unpacked'), 'backend');
  const devBackendDir = path.join(app.getAppPath(), 'backend');

  const cacheSources = [
    path.join(legacyAppDataDir, 'storage'),
    path.join(unpackedBackendDir, 'storage'),
    path.join(devBackendDir, 'storage')
  ];

  let filesCopied = 0;
  let anyFound = false;

  for (const srcDir of cacheSources) {
    if (fs.existsSync(srcDir) && fs.statSync(srcDir).isDirectory()) {
      anyFound = true;
      
      function copyCacheFilesRecursive(currentSrc, currentDest) {
        const entries = fs.readdirSync(currentSrc, { withFileTypes: true });
        for (const entry of entries) {
          const entryPath = path.join(currentSrc, entry.name);
          
          if (entry.isDirectory()) {
            const nameLower = entry.name.toLowerCase();
            if (
              nameLower.includes('mysql') || 
              nameLower.includes('database') || 
              nameLower.includes('db_data') || 
              nameLower.includes('data')
            ) {
              continue;
            }
            const nextDest = path.join(currentDest, entry.name);
            copyCacheFilesRecursive(entryPath, nextDest);
          } else {
            const extLower = path.extname(entry.name).toLowerCase();
            if (['.sqlite', '.sqlite3', '.db', '.cache'].includes(extLower) || entry.name.includes('cache') || entry.name.includes('limit')) {
              if (!fs.existsSync(currentDest)) {
                fs.mkdirSync(currentDest, { recursive: true });
              }
              const destFilePath = path.join(currentDest, entry.name);
              
              if (fs.existsSync(destFilePath)) {
                const srcSize = fs.statSync(entryPath).size;
                const destSize = fs.statSync(destFilePath).size;
                if (srcSize === destSize) {
                  continue;
                }
              }
              
              fs.copyFileSync(entryPath, destFilePath);
              filesCopied++;
            }
          }
        }
      }
      
      try {
        copyCacheFilesRecursive(srcDir, destDir);
      } catch (err) {
        console.error(`[RuntimeMigrator] Error traversing cache folder ${srcDir}:`, err.message);
      }
    }
  }

  metadata.fileMigrations.cache = {
    status: !anyFound ? 'skipped' : 'migrated',
    filesCopied,
    timestamp: new Date().toISOString()
  };

  recordMigrationEventDirect(metadata, {
    phase: filesCopied > 0 ? 'copy' : 'check',
    message: filesCopied > 0 ? `cache_migrated: Successfully copied ${filesCopied} cache/sqlite runtime files.` : 'cache_skipped: No cache/sqlite files found to migrate.',
    details: { filesCopied }
  });
}

/**
 * Safe runtime file migration orchestration.
 */
function runSafeFileMigrations(metadata) {
  if (!metadata.fileMigrations) {
    metadata.fileMigrations = {};
  }
  
  recordMigrationEventDirect(metadata, {
    phase: 'init',
    message: 'file_migration_started: Safe file migration sequence started.',
    details: {}
  });

  try {
    migrateEnvFile(metadata);
    migrateLogs(metadata);
    migrateBackups(metadata);
    migrateCacheRuntimeFiles(metadata);

    metadata.safeFileMigrationPerformed = true;
    metadata.mysqlDataMigrationPerformed = false;
    metadata.realDataMigrationPerformed = false;

    recordMigrationEventDirect(metadata, {
      phase: 'commit',
      message: 'file_migration_completed: Safe file migration sequence completed successfully.',
      details: {
        fileMigrations: {
          env: metadata.fileMigrations.env?.status,
          logs: metadata.fileMigrations.logs?.status,
          backups: metadata.fileMigrations.backups?.status,
          cache: metadata.fileMigrations.cache?.status
        }
      }
    });
    
    writeRuntimeMetadata(metadata);
    console.log('[RuntimeMigrator] Safe file migrations completed successfully.');
  } catch (err) {
    console.error('[RuntimeMigrator] Safe file migration failed:', err.message);
    recordMigrationEventDirect(metadata, {
      phase: 'failed',
      message: `file_migration_failed: Safe file migration sequence failed: ${err.message}`,
      details: { error: err.stack }
    });
    writeRuntimeMetadata(metadata);
  }
}

module.exports = {
  migrateEnvFile,
  migrateLogs,
  migrateBackups,
  migrateCacheRuntimeFiles,
  runSafeFileMigrations
};
