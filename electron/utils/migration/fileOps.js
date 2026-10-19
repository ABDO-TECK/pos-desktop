const fs = require('fs');
const path = require('path');
const { readRuntimeMetadata, writeRuntimeMetadata } = require('./metadata');

function copyDirectorySafe(src, dest) {
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
      copyDirectorySafe(path.join(src, entry), path.join(dest, entry));
    }
  } else {
    if (fs.existsSync(dest)) {
      const srcStats = fs.statSync(src);
      const destStats = fs.statSync(dest);
      if (srcStats.size === destStats.size) {
        return; // Already copied
      }
    }
    fs.copyFileSync(src, dest);
  }
}

/**
 * Compares structure and file sizes of two directories.
 */
function verifyDirectoryCopy(src, dest) {
  if (!fs.existsSync(src) || !fs.existsSync(dest)) {
    return false;
  }
  
  const srcStats = fs.statSync(src);
  const destStats = fs.statSync(dest);
  
  if (srcStats.isDirectory() !== destStats.isDirectory()) {
    return false;
  }
  
  if (!srcStats.isDirectory()) {
    return srcStats.size === destStats.size;
  }
  
  const srcEntries = fs.readdirSync(src);
  const destEntries = fs.readdirSync(dest);
  
  for (const entry of srcEntries) {
    if (!destEntries.includes(entry)) {
      return false;
    }
    const entryVerified = verifyDirectoryCopy(
      path.join(src, entry),
      path.join(dest, entry)
    );
    if (!entryVerified) {
      return false;
    }
  }
  
  return true;
}

/**
 * Moves file/directory to a timestamped archive path.
 */
function archiveOldPath(src) {
  if (!fs.existsSync(src)) return null;
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const archivePath = `${src}.archive.${timestamp}`;
  try {
    fs.renameSync(src, archivePath);
    
    const metadata = readRuntimeMetadata();
    if (metadata) {
      if (!metadata.archivedPaths) {
        metadata.archivedPaths = [];
      }
      metadata.archivedPaths.push(archivePath);
      writeRuntimeMetadata(metadata);
    }
    return archivePath;
  } catch (err) {
    console.error(`[RuntimeMigrator] Failed to archive path: ${src}`, err.message);
    throw err;
  }
}

/**
 * Checks if path is locked by trying to open files.
 */
function isPathLocked(pathStr) {
  if (!fs.existsSync(pathStr)) return false;
  
  try {
    const stats = fs.statSync(pathStr);
    if (stats.isDirectory()) {
      const entries = fs.readdirSync(pathStr);
      for (const entry of entries) {
        if (isPathLocked(path.join(pathStr, entry))) {
          return true;
        }
      }
      return false;
    } else {
      const fd = fs.openSync(pathStr, 'r+');
      fs.closeSync(fd);
      return false;
    }
  } catch (err) {
    if (err.code === 'EBUSY' || err.code === 'EPERM' || err.code === 'EACCES') {
      return true;
    }
    return false;
  }
}

module.exports = {
  copyDirectorySafe,
  verifyDirectoryCopy,
  archiveOldPath,
  isPathLocked
};
