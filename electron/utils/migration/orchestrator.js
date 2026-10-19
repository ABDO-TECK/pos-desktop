const fs = require('fs');
const path = require('path');
const { getTempDir } = require('../paths');
const { 
  readAppVersion, 
  readRuntimeMetadata, 
  ensureMetadataInitialized, 
  needsMigration, 
  recordMigrationEvent, 
  writeRuntimeMetadata 
} = require('./metadata');
const { 
  copyDirectorySafe, 
  verifyDirectoryCopy, 
  archiveOldPath, 
  isPathLocked 
} = require('./fileOps');
const { runSafeFileMigrations } = require('./fileMigration');
const { runMysqlMigrationPreflight } = require('./mysqlPreflight');
const { runControlledMysqlMigration } = require('./mysqlMigration');

/**
 * Core runtime migration function. Handles checks and safe dummy testing.
 */
function runRuntimeMigration(options = {}) {
  const safeMode = options.safeMode !== false;
  const dryRun = !!options.dryRun;
  const verbose = !!options.verbose;

  const currentVersion = readAppVersion();
  let metadata = readRuntimeMetadata();

  if (!metadata) {
    console.log('[RuntimeMigrator] First run detected. Initializing metadata.');
    metadata = ensureMetadataInitialized(currentVersion);
    runSafeFileMigrations(metadata);
    
    // Reload and run preflight on first boot as well
    metadata = readRuntimeMetadata();
    runMysqlMigrationPreflight(metadata, { ...options, backupOnly: true });
    return { success: true, message: 'Initialized metadata, performed file and mysql preflight checks' };
  }

  const needsMigrate = needsMigration(currentVersion, metadata);
  const safeFileNotRun = !metadata.safeFileMigrationPerformed;
  
  const forcePreflight = !!options.forceMysqlPreflight;
  const noMysqlStatus = !metadata.mysqlMigration || !metadata.mysqlMigration.status;
  const runPreflight = needsMigrate || noMysqlStatus || forcePreflight;

  if (!needsMigrate && !safeFileNotRun && !runPreflight) {
    if (verbose) {
      console.log(`[RuntimeMigrator] No migration needed. App version is ${currentVersion}.`);
      recordMigrationEvent({
        phase: 'check',
        message: `Version check passed. No migration required. App version: ${currentVersion}`,
        details: { verbose: true }
      });
    }
    return { success: true, message: 'Same version, no action taken' };
  }

  console.log(`[RuntimeMigrator] Migration needed. Registry version: ${metadata.appVersion}, Current version: ${currentVersion}, safeFileNotRun: ${safeFileNotRun}, runPreflight: ${runPreflight}`);
  
  if (dryRun) {
    console.log('[RuntimeMigrator] Dry-run enabled. Skipping migrations.');
    recordMigrationEvent({
      phase: 'check',
      message: `Dry run check: Migration needed.`,
      details: { dryRun: true }
    });
    return { success: true, message: 'Dry run completed' };
  }

  // Set state to pending
  metadata.migrationState = 'pending';
  metadata.appVersion = currentVersion;
  writeRuntimeMetadata(metadata);
  
  recordMigrationEvent({
    phase: 'check',
    message: `Migration initiated to version ${currentVersion}. State: pending.`,
    details: { currentVersion, previousVersion: metadata.lastSuccessfulVersion }
  });

  try {
    // Run safe file migrations first
    runSafeFileMigrations(metadata);

    // Reload metadata
    metadata = readRuntimeMetadata();

    // Run MySQL preflight foundation
    if (runPreflight) {
      runMysqlMigrationPreflight(metadata, { ...options, backupOnly: true });
      metadata = readRuntimeMetadata();
    }

    // Run controlled MySQL active migration if enabled (opt-in)
    const enableActive = options.enableActiveMysqlMigration === true || process.env.ENABLE_ACTIVE_MYSQL_MIGRATION === 'true';
    if (enableActive) {
      runControlledMysqlMigration(metadata, options);
      metadata = readRuntimeMetadata();
    }

    if (safeMode) {
      console.log('[RuntimeMigrator] Running safeMode dummy migration...');
      
      const tempDir = getTempDir();
      const dummySrc = path.join(tempDir, 'dummy_migrate_src');
      const dummyDest = path.join(tempDir, 'dummy_migrate_dest');

      // Clear any pre-existing dummy test data
      if (fs.existsSync(dummySrc)) {
        try { fs.rmSync(dummySrc, { recursive: true, force: true }); } catch (e) {}
      }
      if (fs.existsSync(dummyDest)) {
        try { fs.rmSync(dummyDest, { recursive: true, force: true }); } catch (e) {}
      }

      // Create test dummy structure
      fs.mkdirSync(dummySrc, { recursive: true });
      fs.writeFileSync(path.join(dummySrc, 'test1.txt'), 'content1', 'utf8');
      fs.mkdirSync(path.join(dummySrc, 'subdir'), { recursive: true });
      fs.writeFileSync(path.join(dummySrc, 'subdir', 'test2.txt'), 'content2', 'utf8');

      // Transition state: copying
      metadata.migrationState = 'copying';
      writeRuntimeMetadata(metadata);
      
      recordMigrationEvent({
        phase: 'copy',
        message: 'Copying files to destination (dummy check)...',
        details: { dummySrc, dummyDest }
      });

      // Execute copy
      copyDirectorySafe(dummySrc, dummyDest);

      // Transition state: verified
      metadata = readRuntimeMetadata();
      metadata.migrationState = 'verified';
      writeRuntimeMetadata(metadata);
      
      recordMigrationEvent({
        phase: 'verify',
        message: 'Verifying copied files (dummy check)...',
        details: { dummySrc, dummyDest }
      });

      // Execute verification
      const isCopyOk = verifyDirectoryCopy(dummySrc, dummyDest);
      if (!isCopyOk) {
        throw new Error('Dummy directory verification failed. File mismatch.');
      }

      // Check path lock check on dummy
      const isLocked = isPathLocked(dummyDest);
      console.log(`[RuntimeMigrator] Dummy destination locked check: ${isLocked}`);

      // Archive dummy source
      const archivedSrc = archiveOldPath(dummySrc);
      console.log(`[RuntimeMigrator] Dummy source archived to: ${archivedSrc}`);

      // Clean up dummyDest
      try { fs.rmSync(dummyDest, { recursive: true, force: true }); } catch (e) {}

      // Transition state: committed
      metadata = readRuntimeMetadata();
      metadata.migrationState = 'committed';
      metadata.lastSuccessfulVersion = currentVersion;
      metadata.foundationSafeModeOnly = true;
      metadata.realDataMigrationPerformed = false;
      metadata.safeFileMigrationPerformed = true;
      writeRuntimeMetadata(metadata);
      
      recordMigrationEvent({
        phase: 'commit',
        message: `Migration to version ${currentVersion} committed successfully (safe foundation dummy check). No production data was moved or deleted.`,
        details: {
          foundationSafeModeOnly: true,
          realDataMigrationPerformed: false,
          safeFileMigrationPerformed: true
        }
      });

      console.log(`[RuntimeMigrator] Safe migration dummy check completed successfully for version ${currentVersion}.`);
      return { success: true, message: 'Safe dummy migration committed' };
    } else {
      console.warn('[RuntimeMigrator] Real data migration requested but not supported in Phase 10.');
      metadata = readRuntimeMetadata();
      metadata.migrationState = 'failed';
      writeRuntimeMetadata(metadata);
      
      recordMigrationEvent({
        phase: 'failed',
        message: 'Real data migration skipped: not supported in Phase 10.',
        details: { safeMode }
      });
      return { success: false, message: 'Real data migration not supported in Phase 10' };
    }
  } catch (err) {
    console.error('[RuntimeMigrator] Migration process failed:', err.message);
    metadata = readRuntimeMetadata();
    metadata.migrationState = 'failed';
    writeRuntimeMetadata(metadata);
    
    recordMigrationEvent({
      phase: 'failed',
      message: `Migration failed: ${err.message}`,
      details: { error: err.stack }
    });
    return { success: false, error: err.message };
  }
}

module.exports = {
  runRuntimeMigration
};
