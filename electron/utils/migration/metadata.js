const fs = require('fs');
const path = require('path');
const { app } = require('electron');
const { getRuntimeMetadataPath, getConfigDir, getDataDir } = require('../paths');

/**
 * Resolves the current application version from version.json or app.getVersion() fallback.
 */
function readAppVersion() {
  try {
    const versionPath = path.join(app.getAppPath(), 'version.json');
    if (fs.existsSync(versionPath)) {
      const data = fs.readFileSync(versionPath, 'utf8');
      const json = JSON.parse(data);
      if (json && json.version) {
        return json.version;
      }
    }
  } catch (err) {
    console.error('[RuntimeMigrator] Failed to read version.json:', err.message);
  }
  return app.getVersion();
}

/**
 * Writes the runtime metadata registry.
 */
function writeRuntimeMetadata(metadata) {
  try {
    const metadataPath = getRuntimeMetadataPath();
    fs.mkdirSync(path.dirname(metadataPath), { recursive: true });
    fs.writeFileSync(metadataPath, JSON.stringify(metadata, null, 2), 'utf8');
  } catch (err) {
    console.error('[RuntimeMigrator] Failed to write metadata file:', err.message);
    throw err;
  }
}

/**
 * Initializes the default runtime metadata structure.
 */
function ensureMetadataInitialized(currentVersion) {
  const metadataPath = getRuntimeMetadataPath();
  
  if (fs.existsSync(metadataPath)) {
    try {
      const data = fs.readFileSync(metadataPath, 'utf8');
      return JSON.parse(data);
    } catch (e) {
      // If corrupt, readRuntimeMetadata will handle backup and reinit
    }
  }

  const initialMetadata = {
    appVersion: currentVersion,
    lastSuccessfulVersion: currentVersion,
    migrationState: 'idle',
    dataDir: getDataDir(),
    configDir: getConfigDir(),
    archivedPaths: [],
    foundationSafeModeOnly: true,
    realDataMigrationPerformed: false,
    safeFileMigrationPerformed: false,
    mysqlDataMigrationPerformed: false,
    fileMigrations: {
      env: { status: 'skipped', from: null, to: null, timestamp: null },
      logs: { status: 'skipped', filesCopied: 0, timestamp: null },
      backups: { status: 'skipped', filesCopied: 0, timestamp: null },
      cache: { status: 'skipped', filesCopied: 0, timestamp: null }
    },
    events: [
      {
        timestamp: new Date().toISOString(),
        phase: 'init',
        message: 'Initial deployment completed successfully.',
        details: { mode: 'foundation-only' }
      }
    ]
  };

  try {
    writeRuntimeMetadata(initialMetadata);
  } catch (err) {
    console.error('[RuntimeMigrator] Failed to write initial metadata:', err.message);
  }

  return initialMetadata;
}

/**
 * Helper to record event on a metadata object direct.
 */
function recordMigrationEventDirect(metadata, event) {
  metadata.events.push({
    timestamp: event.timestamp || new Date().toISOString(),
    phase: event.phase || 'info',
    message: event.message || '',
    details: event.details || {}
  });
}

/**
 * Reads the runtime metadata file. Handles corruption recovery by making a backup.
 */
function readRuntimeMetadata() {
  const metadataPath = getRuntimeMetadataPath();
  if (!fs.existsSync(metadataPath)) {
    return null;
  }
  try {
    const data = fs.readFileSync(metadataPath, 'utf8');
    return JSON.parse(data);
  } catch (err) {
    console.error('[RuntimeMigrator] Metadata file is corrupt:', err.message);
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const corruptPath = path.join(getConfigDir(), `runtime_metadata.corrupt.${timestamp}.json`);
    try {
      fs.copyFileSync(metadataPath, corruptPath);
      fs.unlinkSync(metadataPath);
      console.log(`[RuntimeMigrator] Backed up corrupt metadata to: ${corruptPath}`);
    } catch (copyErr) {
      console.error('[RuntimeMigrator] Failed to backup corrupt metadata:', copyErr.message);
    }
    
    // Reinitialize metadata
    const currentVersion = readAppVersion();
    const cleanMetadata = ensureMetadataInitialized(currentVersion);
    
    recordMigrationEventDirect(cleanMetadata, {
      phase: 'failed',
      message: `Recovered from corrupt metadata. Backup saved as ${path.basename(corruptPath)}`,
      details: { error: err.message }
    });
    
    writeRuntimeMetadata(cleanMetadata);
    return cleanMetadata;
  }
}

/**
 * Records migration events in the metadata registry.
 */
function recordMigrationEvent(event) {
  const metadata = readRuntimeMetadata() || ensureMetadataInitialized(readAppVersion());
  
  // Idempotency: Do not duplicate consecutive identical events
  const lastEvent = metadata.events[metadata.events.length - 1];
  if (lastEvent && lastEvent.phase === event.phase && lastEvent.message === event.message) {
    return;
  }

  metadata.events.push({
    timestamp: event.timestamp || new Date().toISOString(),
    phase: event.phase || 'info',
    message: event.message || '',
    details: event.details || {}
  });

  writeRuntimeMetadata(metadata);
}

/**
 * Checks if a migration is required.
 */
function needsMigration(currentVersion, metadata) {
  if (!metadata) return true;
  return metadata.lastSuccessfulVersion !== currentVersion || metadata.appVersion !== currentVersion;
}

/**
 * Returns migration state status for APIs.
 */
function getMigrationStatus() {
  const metadata = readRuntimeMetadata();
  if (!metadata) {
    return {
      status: 'pending',
      state: 'pending',
      needsMigration: true,
      lastSuccessfulVersion: null,
      appVersion: readAppVersion()
    };
  }
  const currentVersion = readAppVersion();
  return {
    status: metadata.migrationState === 'committed' || metadata.migrationState === 'idle' ? 'ok' : 'pending',
    state: metadata.migrationState,
    needsMigration: needsMigration(currentVersion, metadata),
    lastSuccessfulVersion: metadata.lastSuccessfulVersion,
    appVersion: metadata.appVersion
  };
}

module.exports = {
  readAppVersion,
  writeRuntimeMetadata,
  ensureMetadataInitialized,
  recordMigrationEventDirect,
  readRuntimeMetadata,
  recordMigrationEvent,
  needsMigration,
  getMigrationStatus
};
