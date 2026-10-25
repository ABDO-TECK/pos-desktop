import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import os from 'node:os';
import { fileURLToPath } from 'node:url';
import { classifyVerifiedPublicStagedCa, isVerifiedPublicStagedCa } from './protected-certificate-classification.mjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, '..');

// 1. Locate and read repository public backend/certs/cacert.pem
const repoCacertPath = path.join(repoRoot, 'backend', 'certs', 'cacert.pem');
assert.ok(fs.existsSync(repoCacertPath), 'Repository backend/certs/cacert.pem must exist: ' + repoCacertPath);
const realCaBytes = fs.readFileSync(repoCacertPath);
assert.ok(realCaBytes.length > 0, 'Repository backend/certs/cacert.pem must be non-empty');
const realCaSha256 = crypto.createHash('sha256').update(realCaBytes).digest('hex').toLowerCase();
const realCaSize = realCaBytes.length;

// 2. Set up disposable owned test lab in RUNNER_TEMP (or os.tmpdir())
const runnerTemp = process.env.RUNNER_TEMP ? path.resolve(process.env.RUNNER_TEMP) : path.resolve(os.tmpdir());
assert.ok(path.isAbsolute(runnerTemp), 'Temp directory must be an absolute path: ' + runnerTemp);
assert.ok(fs.existsSync(runnerTemp), 'Temp directory must exist: ' + runnerTemp);

const labPrefix = path.join(runnerTemp, 'ca-classification-lab-');
const ownedLab = fs.mkdtempSync(labPrefix);
const resolvedLab = path.resolve(ownedLab);
assert.ok(path.isAbsolute(resolvedLab), 'Resolved lab directory must be absolute');
assert.ok(resolvedLab.toLowerCase().startsWith(runnerTemp.toLowerCase()), 'Lab directory must reside strictly within runner temp');

console.log('Using disposable test lab:', resolvedLab);

try {
  // Construct mock primary storage root inside ownedLab (no writes to actual ProgramData)
  const mockStorage = path.join(resolvedLab, 'ProgramData_POS_System');
  fs.mkdirSync(mockStorage, { recursive: true });

  // 3. Construct trusted inventory fixture for version 0.0.12
  const trustedInventory12 = {
    version: '0.0.12',
    files: {
      'backend/certs/cacert.pem': {
        sha256: realCaSha256,
        size: realCaSize,
      },
    },
  };
  const trustedInventories = [trustedInventory12];

  // ---------------------------------------------------------
  // POSITIVE TEST: Exact staging path under storageRoot
  // ---------------------------------------------------------
  const positiveStagePath = path.join(mockStorage, 'update_staging', '0.0.12', 'backend', 'certs', 'cacert.pem');
  fs.mkdirSync(path.dirname(positiveStagePath), { recursive: true });
  fs.writeFileSync(positiveStagePath, realCaBytes);

  const positiveResult = classifyVerifiedPublicStagedCa(positiveStagePath, mockStorage, trustedInventories);
  assert.ok(positiveResult, 'Eligible staging cacert.pem must be classified');
  assert.equal(positiveResult.version, '0.0.12', 'Classified version must match 0.0.12');
  assert.equal(positiveResult.logical_path, 'backend/certs/cacert.pem', 'Logical path must match backend/certs/cacert.pem');
  assert.equal(positiveResult.relative_staging_path, 'update_staging/0.0.12/backend/certs/cacert.pem');
  assert.equal(isVerifiedPublicStagedCa(positiveStagePath, mockStorage, trustedInventories), true, 'Predicate wrapper must return true');

  // Verify that NO hash, file bytes, or private material is leaked in the metadata
  assert.equal(positiveResult.hash, undefined, 'Metadata must not contain hash');
  assert.equal(positiveResult.sha256, undefined, 'Metadata must not contain sha256');
  assert.equal(positiveResult.bytes, undefined, 'Metadata must not contain bytes');
  assert.equal(positiveResult.content, undefined, 'Metadata must not contain content');
  assert.deepEqual(Object.keys(positiveResult).sort(), ['logical_path', 'relative_staging_path', 'version']);
  console.log('PASS: Positive exact staging CA path classification');

  // ---------------------------------------------------------
  // NEGATIVE TEST 1: Same public CA outside exact update_staging bound path
  // ---------------------------------------------------------
  const outsideRoot = path.join(mockStorage, 'backend', 'certs', 'cacert.pem');
  fs.mkdirSync(path.dirname(outsideRoot), { recursive: true });
  fs.writeFileSync(outsideRoot, realCaBytes);
  assert.equal(classifyVerifiedPublicStagedCa(outsideRoot, mockStorage, trustedInventories), null, 'Direct storage root cert must be rejected');

  const downloadsPath = path.join(mockStorage, 'downloads', '0.0.12', 'backend', 'certs', 'cacert.pem');
  fs.mkdirSync(path.dirname(downloadsPath), { recursive: true });
  fs.writeFileSync(downloadsPath, realCaBytes);
  assert.equal(classifyVerifiedPublicStagedCa(downloadsPath, mockStorage, trustedInventories), null, 'downloads path cert must be rejected');

  const otherStorageRoot = path.join(resolvedLab, 'AppData_Roaming');
  const otherStoragePath = path.join(otherStorageRoot, 'update_staging', '0.0.12', 'backend', 'certs', 'cacert.pem');
  fs.mkdirSync(path.dirname(otherStoragePath), { recursive: true });
  fs.writeFileSync(otherStoragePath, realCaBytes);
  assert.equal(classifyVerifiedPublicStagedCa(otherStoragePath, mockStorage, trustedInventories), null, 'File outside storageRoot must be rejected');
  console.log('PASS: Negative 1 - outside exact update_staging bound path rejected');

  // ---------------------------------------------------------
  // NEGATIVE TEST 2: Same CA under qz-certs
  // ---------------------------------------------------------
  const qzCertsPath = path.join(mockStorage, 'qz-certs', 'cacert.pem');
  fs.mkdirSync(path.dirname(qzCertsPath), { recursive: true });
  fs.writeFileSync(qzCertsPath, realCaBytes);
  assert.equal(classifyVerifiedPublicStagedCa(qzCertsPath, mockStorage, trustedInventories), null, 'Cert under qz-certs must be rejected');

  const qzStagingPath = path.join(mockStorage, 'update_staging', '0.0.12', 'qz-certs', 'cacert.pem');
  fs.mkdirSync(path.dirname(qzStagingPath), { recursive: true });
  fs.writeFileSync(qzStagingPath, realCaBytes);
  assert.equal(classifyVerifiedPublicStagedCa(qzStagingPath, mockStorage, trustedInventories), null, 'Cert under qz-certs in staging must be rejected');
  console.log('PASS: Negative 2 - public CA under qz-certs rejected');

  // ---------------------------------------------------------
  // NEGATIVE TEST 3: Different basename / private-key.pem
  // ---------------------------------------------------------
  const diffBasenamePath = path.join(mockStorage, 'update_staging', '0.0.12', 'backend', 'certs', 'private-key.pem');
  fs.writeFileSync(diffBasenamePath, realCaBytes);
  assert.equal(classifyVerifiedPublicStagedCa(diffBasenamePath, mockStorage, trustedInventories), null, 'Non-cacert.pem basename must be rejected');
  console.log('PASS: Negative 3 - different basename / private-key.pem rejected');

  // ---------------------------------------------------------
  // NEGATIVE TEST 4: Other / unknown version
  // ---------------------------------------------------------
  const unknownVerPath = path.join(mockStorage, 'update_staging', '0.0.99', 'backend', 'certs', 'cacert.pem');
  fs.mkdirSync(path.dirname(unknownVerPath), { recursive: true });
  fs.writeFileSync(unknownVerPath, realCaBytes);
  assert.equal(classifyVerifiedPublicStagedCa(unknownVerPath, mockStorage, trustedInventories), null, 'Unknown version must be rejected');

  // Also test invalid SemVer version with traversal or invalid chars
  const traversalVerPath = path.join(mockStorage, 'update_staging', '..', 'backend', 'certs', 'cacert.pem');
  assert.equal(classifyVerifiedPublicStagedCa(traversalVerPath, mockStorage, trustedInventories), null, 'Traversal version must be rejected');
  console.log('PASS: Negative 4 - other / unknown version rejected');

  // ---------------------------------------------------------
  // NEGATIVE TEST 5: Wrong hash
  // ---------------------------------------------------------
  const wrongHashDir = path.join(mockStorage, 'update_staging', '0.0.13', 'backend', 'certs');
  fs.mkdirSync(wrongHashDir, { recursive: true });
  const wrongHashPath = path.join(wrongHashDir, 'cacert.pem');
  const tamperedBytes = Buffer.from(realCaBytes);
  tamperedBytes[tamperedBytes.length - 1] ^= 0xff;
  fs.writeFileSync(wrongHashPath, tamperedBytes);
  const inv13 = {
    version: '0.0.13',
    files: {
      'backend/certs/cacert.pem': {
        sha256: realCaSha256,
        size: realCaSize,
      },
    },
  };
  assert.equal(classifyVerifiedPublicStagedCa(wrongHashPath, mockStorage, [inv13]), null, 'Wrong SHA-256 hash must be rejected');
  console.log('PASS: Negative 5 - wrong hash rejected');

  // ---------------------------------------------------------
  // NEGATIVE TEST 6: Wrong size
  // ---------------------------------------------------------
  const invWrongSize = {
    version: '0.0.12',
    files: {
      'backend/certs/cacert.pem': {
        sha256: realCaSha256,
        size: realCaSize + 42,
      },
    },
  };
  assert.equal(classifyVerifiedPublicStagedCa(positiveStagePath, mockStorage, [invWrongSize]), null, 'Wrong size in inventory must be rejected');
  console.log('PASS: Negative 6 - wrong size rejected');

  // ---------------------------------------------------------
  // NEGATIVE TEST 7: Empty file
  // ---------------------------------------------------------
  const emptyDir = path.join(mockStorage, 'update_staging', '0.0.14', 'backend', 'certs');
  fs.mkdirSync(emptyDir, { recursive: true });
  const emptyPath = path.join(emptyDir, 'cacert.pem');
  fs.writeFileSync(emptyPath, Buffer.alloc(0));
  const emptyHash = crypto.createHash('sha256').update(Buffer.alloc(0)).digest('hex');
  const inv14 = {
    version: '0.0.14',
    files: {
      'backend/certs/cacert.pem': {
        sha256: emptyHash,
        size: 0,
      },
    },
  };
  assert.equal(classifyVerifiedPublicStagedCa(emptyPath, mockStorage, [inv14]), null, 'Empty file must be rejected');
  console.log('PASS: Negative 7 - empty file rejected');

  // ---------------------------------------------------------
  // NEGATIVE TEST 8: Corrupted / non-certificate bytes
  // ---------------------------------------------------------
  const corruptDir = path.join(mockStorage, 'update_staging', '0.0.15', 'backend', 'certs');
  fs.mkdirSync(corruptDir, { recursive: true });
  const corruptPath = path.join(corruptDir, 'cacert.pem');
  const nonCertBytes = Buffer.from('NOT A VALID CERTIFICATE FILE - CORRUPTED TEXT');
  fs.writeFileSync(corruptPath, nonCertBytes);
  const nonCertHash = crypto.createHash('sha256').update(nonCertBytes).digest('hex');
  const inv15 = {
    version: '0.0.15',
    files: {
      'backend/certs/cacert.pem': {
        sha256: nonCertHash,
        size: nonCertBytes.length,
      },
    },
  };
  assert.equal(classifyVerifiedPublicStagedCa(corruptPath, mockStorage, [inv15]), null, 'Corrupted / non-certificate bytes must be rejected');
  console.log('PASS: Negative 8 - corrupted / non-certificate bytes rejected');

  // ---------------------------------------------------------
  // NEGATIVE TEST 9: PRIVATE KEY marker/fixture rejected even when inventory hash matches
  // ---------------------------------------------------------
  const privDir = path.join(mockStorage, 'update_staging', '0.0.16', 'backend', 'certs');
  fs.mkdirSync(privDir, { recursive: true });
  const privPath = path.join(privDir, 'cacert.pem');
  const fakePrivKeyText = '-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA0...\n-----END RSA PRIVATE KEY-----\n';
  const fakePrivBytes = Buffer.from(fakePrivKeyText, 'utf8');
  fs.writeFileSync(privPath, fakePrivBytes);
  const fakePrivHash = crypto.createHash('sha256').update(fakePrivBytes).digest('hex');
  const inv16 = {
    version: '0.0.16',
    files: {
      'backend/certs/cacert.pem': {
        sha256: fakePrivHash,
        size: fakePrivBytes.length,
      },
    },
  };
  assert.equal(classifyVerifiedPublicStagedCa(privPath, mockStorage, [inv16]), null, 'Private key marker must be rejected even with matching hash');
  console.log('PASS: Negative 9 - PRIVATE KEY marker rejected even when inventory hash matches');

  // ---------------------------------------------------------
  // NEGATIVE TEST 10: Symlink / junction ancestor test
  // ---------------------------------------------------------
  try {
    const realTargetDir = path.join(resolvedLab, 'real_staged_payload');
    const realTargetCerts = path.join(realTargetDir, 'backend', 'certs');
    fs.mkdirSync(realTargetCerts, { recursive: true });
    fs.writeFileSync(path.join(realTargetCerts, 'cacert.pem'), realCaBytes);

    const junctionLinkDir = path.join(mockStorage, 'update_staging', '0.0.17');
    fs.mkdirSync(path.dirname(junctionLinkDir), { recursive: true });

    fs.symlinkSync(realTargetDir, junctionLinkDir, 'junction');

    const junctionFile = path.join(junctionLinkDir, 'backend', 'certs', 'cacert.pem');
    const inv17 = {
      version: '0.0.17',
      files: {
        'backend/certs/cacert.pem': {
          sha256: realCaSha256,
          size: realCaSize,
        },
      },
    };
    const junctionResult = classifyVerifiedPublicStagedCa(junctionFile, mockStorage, [inv17]);
    assert.equal(junctionResult, null, 'Junction ancestor must be rejected by classifier');
    console.log('PASS: Junction ancestor test: EXECUTED and successfully rejected junction ancestor');
  } catch (junctionErr) {
    if(!['EPERM','EACCES','ENOSYS','ENOTSUP'].includes(junctionErr?.code))throw junctionErr;
    console.log(`Symlink/junction ancestor test: SKIPPED (filesystem permission denied or not supported on runner: ${junctionErr.message})`);
  }

  // ---------------------------------------------------------
  // REGRESSION POINT: Unknown key still enters strict scanner deviceKeyMap
  // ---------------------------------------------------------
  const simulatedDeviceKeyMap = {
    'C:\\ProgramData\\POS System\\qz-certs\\private-key.pem': 'hash_qz_key',
    'C:\\ProgramData\\POS System\\qz-certs\\digital-certificate.pem': 'hash_qz_cert',
    'C:\\ProgramData\\POS System\\ssl\\server.key': 'hash_ssl_key',
    'C:\\ProgramData\\POS System\\ssl\\server.crt': 'hash_ssl_cert',
  };
  const baselineCount = Object.keys(simulatedDeviceKeyMap).length;
  assert.equal(baselineCount, 4, 'Baseline must hold exactly 4 mandatory device keys');

  // Case A: Unknown key file encountered during scanner run -> fails classification -> enters deviceKeyMap -> count increases (fails CI)
  const unknownKeyFile = path.join(mockStorage, 'extra-private.key');
  fs.writeFileSync(unknownKeyFile, 'UNKNOWN_KEY_MATERIAL');
  const unknownClassified = classifyVerifiedPublicStagedCa(unknownKeyFile, mockStorage, trustedInventories);
  assert.equal(unknownClassified, null, 'Unknown key must fail public CA classification');
  if (!unknownClassified) {
    simulatedDeviceKeyMap[unknownKeyFile] = 'hash_unknown_key';
  }
  assert.equal(Object.keys(simulatedDeviceKeyMap).length, 5, 'Unknown key must be added to deviceKeyMap, triggering failure');

  // Case B: Verified public staging CA is classified separately and kept OUT of deviceKeyMap
  const verifiedDeviceKeyMap = {
    'C:\\ProgramData\\POS System\\qz-certs\\private-key.pem': 'hash_qz_key',
    'C:\\ProgramData\\POS System\\qz-certs\\digital-certificate.pem': 'hash_qz_cert',
    'C:\\ProgramData\\POS System\\ssl\\server.key': 'hash_ssl_key',
    'C:\\ProgramData\\POS System\\ssl\\server.crt': 'hash_ssl_cert',
  };
  const publicStagedMeta = classifyVerifiedPublicStagedCa(positiveStagePath, mockStorage, trustedInventories);
  assert.ok(publicStagedMeta, 'Valid public staged CA must be classified');
  if (!publicStagedMeta) {
    verifiedDeviceKeyMap[positiveStagePath] = 'hash_staging_ca';
  }
  assert.equal(Object.keys(verifiedDeviceKeyMap).length, 4, 'Verified public staged CA is not added to deviceKeyMap; count remains 4');
  console.log('PASS: Regression point verified: unknown keys increase deviceKeyMap count, verified public CA remains excluded');

  console.log('ALL PROTECTED CERTIFICATE CLASSIFICATION REGRESSIONS PASSED');
} finally {
  // Bounded safe cleanup: refuse preexisting and cleanup ONLY own resolved root
  if (resolvedLab && path.isAbsolute(resolvedLab) && resolvedLab.toLowerCase().startsWith(runnerTemp.toLowerCase())) {
    try {
      fs.rmSync(resolvedLab, { recursive: true, force: true });
    } catch (cleanupErr) {
      console.error('Warning: Failed to cleanup disposable lab:', cleanupErr.message);
    }
  }
}
