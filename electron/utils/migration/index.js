const metadata = require('./metadata');
const fileOps = require('./fileOps');
const fileMigration = require('./fileMigration');
const mysqlPreflight = require('./mysqlPreflight');
const mysqlMigration = require('./mysqlMigration');
const mysqlRollback = require('./mysqlRollback');
const mysqlFinalSwitch = require('./mysqlFinalSwitch');
const orchestrator = require('./orchestrator');

module.exports = {
  // Metadata & Status
  readAppVersion: metadata.readAppVersion,
  readRuntimeMetadata: metadata.readRuntimeMetadata,
  writeRuntimeMetadata: metadata.writeRuntimeMetadata,
  ensureMetadataInitialized: metadata.ensureMetadataInitialized,
  needsMigration: metadata.needsMigration,
  getMigrationStatus: metadata.getMigrationStatus,
  recordMigrationEvent: metadata.recordMigrationEvent,

  // File Operations
  copyDirectorySafe: fileOps.copyDirectorySafe,
  verifyDirectoryCopy: fileOps.verifyDirectoryCopy,
  archiveOldPath: fileOps.archiveOldPath,
  isPathLocked: fileOps.isPathLocked,

  // Safe file migrations
  migrateEnvFile: fileMigration.migrateEnvFile,
  migrateLogs: fileMigration.migrateLogs,
  migrateBackups: fileMigration.migrateBackups,
  migrateCacheRuntimeFiles: fileMigration.migrateCacheRuntimeFiles,
  runSafeFileMigrations: fileMigration.runSafeFileMigrations,

  // High-level Orchestration
  runRuntimeMigration: orchestrator.runRuntimeMigration,

  // MySQL Preflight & Detect
  detectLegacyMysqlDataDirs: mysqlPreflight.detectLegacyMysqlDataDirs,
  validateMysqlDataDir: mysqlPreflight.validateMysqlDataDir,
  detectMysqlLockState: mysqlPreflight.detectMysqlLockState,
  detectRunningMysqldProcesses: mysqlPreflight.detectRunningMysqldProcesses,
  isMysqlDataSafeToCopy: mysqlPreflight.isMysqlDataSafeToCopy,
  createMysqlPreMigrationBackup: mysqlPreflight.createMysqlPreMigrationBackup,
  verifyMysqlBackupCopy: mysqlPreflight.verifyMysqlBackupCopy,
  runMysqlMigrationPreflight: mysqlPreflight.runMysqlMigrationPreflight,

  // MySQL Migration
  canRunMysqlActiveMigration: mysqlMigration.canRunMysqlActiveMigration,
  stopAppManagedMysqlIfRunning: mysqlMigration.stopAppManagedMysqlIfRunning,
  copyMysqlDataToRuntime: mysqlMigration.copyMysqlDataToRuntime,
  verifyRuntimeMysqlDataCopy: mysqlMigration.verifyRuntimeMysqlDataCopy,
  writeMysqlMigrationCommit: mysqlMigration.writeMysqlMigrationCommit,
  markMysqlMigrationRollbackAvailable: mysqlMigration.markMysqlMigrationRollbackAvailable,
  runControlledMysqlMigration: mysqlMigration.runControlledMysqlMigration,

  // MySQL Rollback
  getRollbackReadiness: mysqlRollback.getRollbackReadiness,
  buildMysqlRollbackPlan: mysqlRollback.buildMysqlRollbackPlan,
  validateMysqlRollbackSources: mysqlRollback.validateMysqlRollbackSources,
  runMysqlRollbackDryRun: mysqlRollback.runMysqlRollbackDryRun,
  createPreRollbackSnapshot: mysqlRollback.createPreRollbackSnapshot,
  executeMysqlRollback: mysqlRollback.executeMysqlRollback,
  recordRollbackEvent: mysqlRollback.recordRollbackEvent,

  // MySQL Rollback Restore
  canExecuteMysqlRollbackRestore: mysqlRollback.canExecuteMysqlRollbackRestore,
  createRollbackRestoreStaging: mysqlRollback.createRollbackRestoreStaging,
  verifyRollbackRestoreStaging: mysqlRollback.verifyRollbackRestoreStaging,
  runAuthorizedMysqlRollbackRestore: mysqlRollback.runAuthorizedMysqlRollbackRestore,

  // Final Switch
  canExecuteFinalRollbackSwitch: mysqlFinalSwitch.canExecuteFinalRollbackSwitch,
  createFinalSwitchSafetySnapshot: mysqlFinalSwitch.createFinalSwitchSafetySnapshot,
  prepareActivePathForSwitch: mysqlFinalSwitch.prepareActivePathForSwitch,
  switchStagingToActivePath: mysqlFinalSwitch.switchStagingToActivePath,
  verifyFinalActivePath: mysqlFinalSwitch.verifyFinalActivePath,
  commitFinalRollbackSwitch: mysqlFinalSwitch.commitFinalRollbackSwitch,
  abortFinalRollbackSwitch: mysqlFinalSwitch.abortFinalRollbackSwitch,
  runFinalMysqlRollbackSwitch: mysqlFinalSwitch.runFinalMysqlRollbackSwitch
};
