import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const SAFE_SEMVER_REGEX = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/;

const DISALLOWED_STORAGE_SEGMENTS = new Set([
  'update_staging',
  'qz-certs',
  'trust',
  'config',
  'ssl',
  'private',
]);

function isValidSafeSemver(version) {
  if (typeof version !== 'string' || !version) {
    return false;
  }
  if (version.length > 64) {
    return false;
  }
  if (/[\/\\\.]{2,}|[\/\\\s\0\r\n]/.test(version)) {
    return false;
  }
  return SAFE_SEMVER_REGEX.test(version);
}

/**
 * Classifies an eligible verified public staged CA certificate bundle.
 *
 * Requirements:
 * 1. Exact path must match: storageRoot/update_staging/<trustedinventory.version>/backend/certs/cacert.pem
 * 2. storageRoot must be the primary ProgramData storage root (no update_staging, qz-certs, ssl, etc.)
 * 3. No symlinks, junctions, or reparse points on the file or any ancestor.
 * 4. File size and SHA-256 must match the exact logical inventory entry 'backend/certs/cacert.pem'
 *    in an explicitly provided trusted inventory fixture.
 * 5. Bytes in memory must contain valid public X.509 PEM certificate content.
 * 6. Explicitly rejects ANY private key marker, even if hash/size matches an input inventory.
 * 7. Fails closed (returns null) on any discrepancy, missing file, or error.
 * 8. Returns public classification metadata only (version, logical_path, relative_staging_path).
 *    Never returns hash, file bytes, or private material.
 *
 * @param {string} filePath - Absolute or relative path to the candidate file.
 * @param {string} storageRoot - The primary ProgramData storage root directory.
 * @param {Array<object>|object} trustedInventories - Explicit already-trusted inventory fixtures.
 * @returns {object|null} Public classification metadata or null if not eligible.
 */
export function classifyVerifiedPublicStagedCa(filePath, storageRoot, trustedInventories) {
  try {
    if (!filePath || typeof filePath !== 'string' || !storageRoot || typeof storageRoot !== 'string') {
      return null;
    }
    if (!trustedInventories) {
      return null;
    }

    const resolvedStorage = path.resolve(storageRoot);
    const resolvedFile = path.resolve(filePath);

    const storageLower = resolvedStorage.toLowerCase();
    const fileLower = resolvedFile.toLowerCase();

    // Sanity check storage root: must not be or contain update_staging, qz-certs, ssl, etc.
    const storageParts = storageLower.split(path.sep);
    for (const part of storageParts) {
      if (DISALLOWED_STORAGE_SEGMENTS.has(part)) {
        return null;
      }
    }

    // Windows canonical containment check: resolvedFile must reside strictly inside resolvedStorage
    const storageWithSep = storageLower.endsWith(path.sep) ? storageLower : storageLower + path.sep;
    if (!fileLower.startsWith(storageWithSep)) {
      return null;
    }

    // Basename and extension check: must be cacert.pem exactly
    if (path.basename(resolvedFile).toLowerCase() !== 'cacert.pem') {
      return null;
    }
    if (path.extname(resolvedFile).toLowerCase() !== '.pem') {
      return null;
    }

    // Relative path check: must have exactly 5 parts:
    // ['update_staging', <version>, 'backend', 'certs', 'cacert.pem']
    const rel = path.relative(resolvedStorage, resolvedFile);
    const relNormalized = rel.split(path.sep).join('/');
    const relParts = relNormalized.split('/');

    if (relParts.length !== 5) {
      return null;
    }
    if (relParts[0] !== 'update_staging') {
      return null;
    }

    const version = relParts[1];
    if (!isValidSafeSemver(version)) {
      return null;
    }

    if (relParts[2] !== 'backend' || relParts[3] !== 'certs' || relParts[4] !== 'cacert.pem') {
      return null;
    }

    // Symlink / Junction / Reparse point check:
    // Inspect storage root and every segment leading down to the target file
    const storageLstat = fs.lstatSync(resolvedStorage);
    if (storageLstat.isSymbolicLink()) {
      return null;
    }

    let currentWalk = resolvedStorage;
    for (let i = 0; i < relParts.length; i++) {
      currentWalk = path.join(currentWalk, relParts[i]);
      if (!fs.existsSync(currentWalk)) {
        return null;
      }
      const lstat = fs.lstatSync(currentWalk);
      if (lstat.isSymbolicLink()) {
        return null;
      }
      if (i < relParts.length - 1) {
        if (!lstat.isDirectory()) {
          return null;
        }
      } else {
        if (!lstat.isFile()) {
          return null;
        }
      }
    }

    // Confirm real native path resolves strictly to resolvedFile (prevents ancestor junction escapes)
    const nativeRealPath = (fs.realpathSync.native ? fs.realpathSync.native(resolvedFile) : fs.realpathSync(resolvedFile))
      .replace(/^\\\\\?\\/, '');
    if (path.resolve(nativeRealPath).toLowerCase() !== fileLower) {
      return null;
    }

    // Normalize trusted inventories list
    let inventories = [];
    if (Array.isArray(trustedInventories)) {
      inventories = trustedInventories;
    } else if (typeof trustedInventories === 'object') {
      if (trustedInventories.files && typeof trustedInventories.files === 'object') {
        inventories = [trustedInventories];
      } else {
        inventories = Object.values(trustedInventories);
      }
    }

    const matchedInventory = inventories.find(inv => inv && typeof inv === 'object' && inv.version === version);
    if (!matchedInventory || !matchedInventory.files || typeof matchedInventory.files !== 'object') {
      return null;
    }

    const logicalPath = 'backend/certs/cacert.pem';
    const inventoryEntry = matchedInventory.files[logicalPath] || matchedInventory.files['backend\\certs\\cacert.pem'];
    if (!inventoryEntry || typeof inventoryEntry !== 'object') {
      return null;
    }

    if (typeof inventoryEntry.size !== 'number' || !Number.isInteger(inventoryEntry.size) || inventoryEntry.size <= 0) {
      return null;
    }
    if (typeof inventoryEntry.sha256 !== 'string' || !/^[0-9a-f]{64}$/i.test(inventoryEntry.sha256)) {
      return null;
    }

    // Check actual file size against trusted inventory
    const fileStat = fs.statSync(resolvedFile);
    if (!fileStat.isFile() || fileStat.size !== inventoryEntry.size || fileStat.size <= 0) {
      return null;
    }

    // Read actual file bytes into memory ONLY
    const fileBytes = fs.readFileSync(resolvedFile);
    if (fileBytes.length !== inventoryEntry.size) {
      return null;
    }

    // Verify SHA-256 matches trusted inventory
    const computedSha256 = crypto.createHash('sha256').update(fileBytes).digest('hex').toLowerCase();
    if (computedSha256 !== inventoryEntry.sha256.toLowerCase()) {
      return null;
    }

    // Verify certificate content: must be public X.509 PEM certificate and NOT contain private keys
    const fileText = fileBytes.toString('utf8');

    // Explicit rejection of ANY private key marker
    if (/private\s*key/i.test(fileText) || /-----BEGIN[ A-Z0-9_-]*PRIVATE KEY-----/i.test(fileText)) {
      return null;
    }

    // Require public X.509 PEM certificate content
    const beginMarker = '-----BEGIN CERTIFICATE-----';
    const endMarker = '-----END CERTIFICATE-----';
    const beginIdx = fileText.indexOf(beginMarker);
    const endIdx = fileText.indexOf(endMarker, beginIdx);
    if (beginIdx === -1 || endIdx === -1) {
      return null;
    }

    // Validate the first public certificate block with crypto.X509Certificate
    const firstCertPem = fileText.slice(beginIdx, endIdx + endMarker.length);
    try {
      new crypto.X509Certificate(firstCertPem);
    } catch {
      return null;
    }

    // Return public classification metadata ONLY
    return {
      version,
      logical_path: logicalPath,
      relative_staging_path: `update_staging/${version}/${logicalPath}`,
    };
  } catch {
    return null;
  }
}

/**
 * Predicate wrapper for classifyVerifiedPublicStagedCa.
 *
 * @param {string} filePath - Absolute or relative path to the candidate file.
 * @param {string} storageRoot - The primary ProgramData storage root directory.
 * @param {Array<object>|object} trustedInventories - Explicit already-trusted inventory fixtures.
 * @returns {boolean} True if the file qualifies as a verified public staged CA bundle.
 */
export function isVerifiedPublicStagedCa(filePath, storageRoot, trustedInventories) {
  return classifyVerifiedPublicStagedCa(filePath, storageRoot, trustedInventories) !== null;
}
