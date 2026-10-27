#!/usr/bin/env node
/**
 * scripts/publish-setup-release.mjs
 *
 * Hardened GitHub Actions publisher for Setup 0.0.12.
 *
 * Enforces:
 *   1. Restricted strictly to ephemeral GitHub Actions under ABDO-TECK/pos-desktop.
 *   2. Synchronized metadata guard (version 0.0.12, channel stable, release_type setup).
 *   3. Expected source commit SHA binding: GITHUB_SHA required; SOURCE_SHA if present must equal GITHUB_SHA.
 *   4. Reparse/symlink rejection on assetsDir, runnerTemp, and download views.
 *   5. Pre-check: remote tag MUST pre-exist on GitHub and peel to exact expected commit (fail 404 and drift).
 *   6. Pre-check: ANY pre-existing release (draft or published) REFUSES to proceed (fail-closed, 0 DELETE paths).
 *   7. Exact 5-asset set verification in release-assets:
 *      - POS-Desktop-Setup-0.0.12.exe
 *      - POS-Desktop-Setup-0.0.12.exe.blockmap
 *      - latest.yml
 *      - deployment-inventory.json
 *      - SHA256SUMS.txt
 *      Rejects subdirectories, symlinks, unlisted files, case-insensitive collisions.
 *   8. Strict SHA256SUMS.txt validation:
 *      Must cover exactly the 4 payloads once, reject duplicates/paths/self-references.
 *      Never appends or modifies checksums at publish time (verified artifact immutability).
 *   9. Creates fresh owned DRAFT release via GitHub REST API with explicit target_commitish.
 *  10. Uploads all 5 release assets, capturing immutable uploaded snapshots (id, name, size).
 *  11. Downloads all 5 DRAFT asset byte payloads and verifies SHA256 against local hashes.
 *  12. Re-fetches release and peels remote tag immediately before PATCH; validates exact 5 asset identities.
 *  13. Controlled PATCH draft: false, make_latest: "true".
 *  14. Post-publish: verifies public release tag, commitish, draft=false, prerelease=false, exact 5-asset set;
 *      downloads all 5 public browser download assets into unique owned mkdtemp view and verifies SHA256.
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';

function sha256(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex');
}

function assertNoReparse(targetPath) {
  let current = path.resolve(targetPath);
  while (current && path.dirname(current) !== current) {
    if (fs.existsSync(current)) {
      const stat = fs.lstatSync(current);
      if (stat.isSymbolicLink()) {
        throw new Error('Reparse point or symlink rejected: ' + current);
      }
    }
    const parent = path.dirname(current);
    if (parent === current) break;
    current = parent;
  }
}

// 1. Environment & Runner Boundary Guards
assert.equal(
  process.env.GITHUB_ACTIONS,
  'true',
  'publish-setup-release.mjs is strictly restricted to ephemeral GitHub Actions runners'
);
const runnerTemp = process.env.RUNNER_TEMP;
assert.ok(runnerTemp && fs.existsSync(runnerTemp), 'RUNNER_TEMP directory is required and must exist');
assertNoReparse(runnerTemp);

assert.equal(
  process.env.GITHUB_REPOSITORY,
  'ABDO-TECK/pos-desktop',
  `GITHUB_REPOSITORY must be ABDO-TECK/pos-desktop (got: ${process.env.GITHUB_REPOSITORY})`
);
if (process.env.REPOSITORY) {
  assert.equal(
    process.env.REPOSITORY,
    'ABDO-TECK/pos-desktop',
    `REPOSITORY override must match ABDO-TECK/pos-desktop (got: ${process.env.REPOSITORY})`
  );
}
const repository = 'ABDO-TECK/pos-desktop';

const token = process.env.GH_TOKEN || process.env.GITHUB_TOKEN;
assert.ok(token && token.trim().length > 0, 'GH_TOKEN or GITHUB_TOKEN environment variable is required');

// 2. Metadata Guards
const versionMeta = JSON.parse(fs.readFileSync('version.json', 'utf8'));
assert.equal(versionMeta.version, '0.0.12', 'version.json version must be 0.0.12');
assert.equal(versionMeta.channel, 'stable', 'version.json channel must be stable');
assert.equal(versionMeta.release_type, 'setup', 'version.json release_type must be setup');

const tag = process.env.RELEASE_TAG || 'v0.0.12';
assert.equal(tag, 'v0.0.12', `RELEASE_TAG must be v0.0.12 (got: ${tag})`);

const githubSha = process.env.GITHUB_SHA;
assert.ok(
  githubSha && /^[a-f0-9]{40}$/i.test(githubSha),
  `Valid 40-character hex GITHUB_SHA required (got: ${githubSha})`
);

const sourceSha = process.env.SOURCE_SHA;
if (sourceSha) {
  assert.equal(
    sourceSha.toLowerCase(),
    githubSha.toLowerCase(),
    `SOURCE_SHA (${sourceSha}) must equal GITHUB_SHA (${githubSha})`
  );
}
const expectedSha = githubSha;

const isPrerelease = process.env.PRERELEASE === 'true';
assert.equal(isPrerelease, false, 'Setup 0.0.12 is a stable release; PRERELEASE must be false');

const assetsDir = path.resolve(process.argv[2] || 'release-assets');
assert.ok(fs.existsSync(assetsDir), `Assets directory missing: ${assetsDir}`);
assertNoReparse(assetsDir);

// 3. Exact 5-Asset Set & Case-Collision Checks
console.log('--- Hardened Setup Release Publisher for 0.0.12 (Stable) ---');
console.log(`[1/7] Verifying exact 5-asset artifact set in ${assetsDir}...`);

const EXPECTED_PAYLOAD_ASSETS = [
  'POS-Desktop-Setup-0.0.12.exe',
  'POS-Desktop-Setup-0.0.12.exe.blockmap',
  'latest.yml',
  'deployment-inventory.json'
];
const CHECKSUMS_FILE = 'SHA256SUMS.txt';
const EXACT_5_ASSETS = [...EXPECTED_PAYLOAD_ASSETS, CHECKSUMS_FILE].sort();

const dirEntries = fs.readdirSync(assetsDir, { withFileTypes: true });
for (const ent of dirEntries) {
  assert.ok(ent.isFile(), `release-assets must contain only regular files; found directory or symlink: ${ent.name}`);
}

const localFiles = dirEntries.map(e => e.name);
assert.equal(localFiles.length, 5, `release-assets must contain exactly 5 assets, found ${localFiles.length}: ${localFiles.join(', ')}`);

const lowerNames = localFiles.map(f => f.toLowerCase());
assert.equal(new Set(lowerNames).size, 5, 'Case-insensitive filename collision detected in release-assets');

assert.deepEqual(localFiles.slice().sort(), EXACT_5_ASSETS, 'Artifact set does not match exact 5 expected release files');

// Read local file contents and compute hashes
const localHashes = new Map();
const localSizes = new Map();
for (const file of localFiles) {
  const filePath = path.join(assetsDir, file);
  const bytes = fs.readFileSync(filePath);
  assert.ok(bytes.length > 0, `Release asset cannot be 0 bytes: ${file}`);
  localHashes.set(file, sha256(bytes));
  localSizes.set(file, bytes.length);
}

// 4. Strict SHA256SUMS.txt Validation (Verified Artifact Immutability)
console.log(`[2/7] Verifying ${CHECKSUMS_FILE} strictly covers 4 payloads once (no publisher mutation)...`);
const checksumsFilePath = path.join(assetsDir, CHECKSUMS_FILE);
const checksumsContent = fs.readFileSync(checksumsFilePath, 'utf8');

const checksumLines = checksumsContent.trim().split(/\r?\n/).filter(line => line.trim().length > 0);
assert.equal(checksumLines.length, 4, `${CHECKSUMS_FILE} must contain exactly 4 entries, found: ${checksumLines.length}`);

const coveredFiles = new Set();
for (const line of checksumLines) {
  const match = line.match(/^([a-f0-9]{64})\s+(\S+)$/);
  assert.ok(match, `Malformed line in ${CHECKSUMS_FILE}: '${line}'`);
  const [, expectedHash, filename] = match;

  assert.ok(!filename.includes('/') && !filename.includes('\\'), `Path traversal detected in ${CHECKSUMS_FILE}: ${filename}`);
  assert.notEqual(filename, CHECKSUMS_FILE, `${CHECKSUMS_FILE} must not list itself`);
  assert.ok(!coveredFiles.has(filename), `Duplicate entry in ${CHECKSUMS_FILE}: ${filename}`);
  coveredFiles.add(filename);

  assert.ok(EXPECTED_PAYLOAD_ASSETS.includes(filename), `Unexpected file listed in ${CHECKSUMS_FILE}: ${filename}`);
  assert.equal(localHashes.get(filename), expectedHash, `SHA256 mismatch for ${filename} against ${CHECKSUMS_FILE}`);
}
assert.deepEqual([...coveredFiles].sort(), EXPECTED_PAYLOAD_ASSETS.slice().sort(), `${CHECKSUMS_FILE} coverage mismatch`);
console.log('      PASS: Exact 5-asset set and SHA256SUMS.txt verified.');

// Step 5: Read release notes
console.log('[3/7] Loading release notes...');
let releaseNotes = '';
if (fs.existsSync('release-notes.md')) {
  releaseNotes = fs.readFileSync('release-notes.md', 'utf8');
} else {
  releaseNotes = `# POS Desktop ${versionMeta.version}\n\n` +
    `Channel: ${versionMeta.channel}\n\n` +
    (versionMeta.changelog ? versionMeta.changelog.map(c => `- ${c}`).join('\n') : '') +
    '\n\nWindows x64 Setup. Verified on GitHub Actions.\n';
}

// GitHub REST API Helper
const apiBase = 'https://api.github.com';
const defaultHeaders = {
  'Authorization': `Bearer ${token}`,
  'Accept': 'application/vnd.github+json',
  'User-Agent': 'pos-desktop-publisher-setup12',
  'X-GitHub-Api-Version': '2022-11-28'
};

async function apiRequest(endpoint, options = {}) {
  const url = endpoint.startsWith('http') ? endpoint : `${apiBase}${endpoint}`;
  const reqHeaders = {
    ...defaultHeaders,
    ...(options.headers || {})
  };
  if (options.body && typeof options.body === 'string' && !reqHeaders['Content-Type']) {
    reqHeaders['Content-Type'] = 'application/json';
  }
  const res = await fetch(url, {
    ...options,
    headers: reqHeaders
  });
  const text = await res.text();
  let data = null;
  try { data = JSON.parse(text); } catch { data = text; }
  return { status: res.status, ok: res.ok, data };
}

// Step 6: Verify Remote Tag Exists and Peels to Expected Commit (Fail-Closed)
console.log(`[4/7] Verifying remote tag ${tag} exists and peels to expected commit ${expectedSha}...`);
const tagRefRes = await apiRequest(`/repos/${repository}/git/ref/tags/${tag}`);
if (tagRefRes.status === 404) {
  throw new Error(`CRITICAL REFUSAL: Remote tag ${tag} does not exist on remote (HTTP 404). Tag must pre-exist and peel to ${expectedSha}.`);
}
assert.ok(tagRefRes.ok, `Unexpected HTTP status checking remote tag ${tag}: ${tagRefRes.status}`);

let peeledSha = tagRefRes.data.object.sha;
if (tagRefRes.data.object.type === 'tag') {
  const tagObjRes = await apiRequest(`/repos/${repository}/git/tags/${peeledSha}`);
  assert.ok(tagObjRes.ok, `Failed resolving annotated tag object ${peeledSha}`);
  peeledSha = tagObjRes.data.object.sha;
}
assert.equal(
  peeledSha.toLowerCase(),
  expectedSha.toLowerCase(),
  `Remote tag ${tag} points to ${peeledSha}, but expected commit is ${expectedSha}`
);
console.log(`      PASS: Remote tag ${tag} verified to peel to expected commit.`);

// Step 7: Pre-Release Check (ANY existing release REFUSES to proceed - NO DELETE paths)
console.log(`      Checking for existing release for tag ${tag} (fail-closed, no DELETE permitted)...`);
const existingCheck = await apiRequest(`/repos/${repository}/releases/tags/${tag}`);
if (existingCheck.status === 200) {
  throw new Error(
    `CRITICAL REFUSAL: Release for tag ${tag} already exists (ID: ${existingCheck.data?.id}, draft: ${existingCheck.data?.draft}). Overwriting or modifying historical releases is strictly forbidden.`
  );
}
if (existingCheck.status !== 404) {
  throw new Error(`Unexpected HTTP status checking release for tag ${tag}: ${existingCheck.status}`);
}
console.log('      PASS: Confirmed release does not exist.');

// Step 8: Create Fresh Owned DRAFT Release with explicit target_commitish
console.log(`[5/7] Creating fresh owned DRAFT release for ${tag} at ${expectedSha}...`);
const createRes = await apiRequest(`/repos/${repository}/releases`, {
  method: 'POST',
  body: JSON.stringify({
    tag_name: tag,
    target_commitish: expectedSha,
    name: `POS Desktop ${tag}`,
    body: releaseNotes,
    draft: true,
    prerelease: false
  })
});
assert.ok(createRes.ok, `Failed to create draft release: HTTP ${createRes.status} - ${JSON.stringify(createRes.data)}`);

const releaseId = createRes.data.id;
assert.ok(typeof releaseId === 'number' && releaseId > 0, `Expected numeric REST databaseId, got: ${releaseId}`);
assert.equal(createRes.data.tag_name, tag, 'Created release tag_name mismatch');
assert.equal(createRes.data.target_commitish.toLowerCase(), expectedSha.toLowerCase(), 'target_commitish mismatch');
assert.equal(createRes.data.draft, true, 'Created release must be in draft state');
assert.equal(createRes.data.prerelease, false, 'Created release prerelease must be false');
console.log(`      PASS: Draft release created (numeric REST databaseId: ${releaseId}).`);

// Step 9: Upload All 5 Release Assets and Snapshot Uploaded Identities
console.log(`[6/7] Uploading all 5 release assets to draft release ${releaseId}...`);
const uploadUrlTemplate = createRes.data.upload_url.replace(/\{(\?.*)?\}$/, '');
const uploadedSnapshots = new Map();

for (const file of EXACT_5_ASSETS) {
  const filePath = path.join(assetsDir, file);
  const fileBytes = fs.readFileSync(filePath);
  const fileSize = fileBytes.length;

  console.log(`      Uploading ${file} (${fileSize} bytes)...`);
  const uploadUrl = `${uploadUrlTemplate}?name=${encodeURIComponent(file)}`;
  const uploadRes = await fetch(uploadUrl, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/octet-stream',
      'Content-Length': fileSize.toString(),
      'User-Agent': 'pos-desktop-publisher-setup12'
    },
    body: fileBytes
  });
  assert.ok(uploadRes.ok, `Failed to upload ${file}: HTTP ${uploadRes.status} ${uploadRes.statusText}`);
  const assetData = await uploadRes.json();
  assert.ok(typeof assetData.id === 'number' && assetData.id > 0, `Expected numeric asset ID for ${file}`);
  assert.equal(assetData.name, file, `Asset name mismatch for ${file}`);
  assert.equal(assetData.size, fileSize, `Uploaded asset size mismatch for ${file}`);
  uploadedSnapshots.set(file, { id: assetData.id, name: assetData.name, size: assetData.size });
}

// Step 10: Download ALL 5 DRAFT Asset Bytes and Verify Hashes Before PATCH
console.log('      Downloading all 5 DRAFT asset byte payloads to verify SHA256 before publishing...');
const assetsListRes = await apiRequest(`/repos/${repository}/releases/${releaseId}/assets`);
assert.ok(assetsListRes.ok && Array.isArray(assetsListRes.data), 'Failed to list draft release assets');
assert.equal(assetsListRes.data.length, 5, `Expected exactly 5 assets in draft release, found: ${assetsListRes.data.length}`);

const draftAssetNames = assetsListRes.data.map(a => a.name);
const draftLowerNames = draftAssetNames.map(n => n.toLowerCase());
assert.equal(new Set(draftLowerNames).size, 5, 'Duplicate or case collision in remote draft asset list');
assert.deepEqual(draftAssetNames.slice().sort(), EXACT_5_ASSETS, 'Remote draft assets set mismatch');

for (const remoteAsset of assetsListRes.data) {
  assert.ok(typeof remoteAsset.id === 'number' && remoteAsset.id > 0, 'Invalid remote asset ID');
  const snap = uploadedSnapshots.get(remoteAsset.name);
  assert.ok(snap, `Unexpected draft asset ${remoteAsset.name}`);
  assert.equal(remoteAsset.id, snap.id, `Draft asset ID mismatch for ${remoteAsset.name}`);
  assert.equal(remoteAsset.size, snap.size, `Draft asset size mismatch for ${remoteAsset.name}`);

  const downloadRes = await fetch(remoteAsset.url, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/octet-stream',
      'User-Agent': 'pos-desktop-publisher-setup12'
    }
  });
  assert.ok(downloadRes.ok, `Failed downloading draft asset ${remoteAsset.name}: HTTP ${downloadRes.status}`);
  const downloadedBuf = Buffer.from(await downloadRes.arrayBuffer());
  const downloadedHash = sha256(downloadedBuf);
  assert.equal(downloadedHash, localHashes.get(remoteAsset.name), `SHA256 mismatch on draft download for ${remoteAsset.name}`);
}
console.log('      PASS: All 5 draft assets downloaded and verified byte-for-byte.');

// Step 11: Re-fetch Owned Numeric ID & Peel Remote Tag Immediately Before PATCH
console.log('      Re-fetching release and remote tag immediately before PATCH draft=false...');
const refetchRes = await apiRequest(`/repos/${repository}/releases/${releaseId}`);
assert.ok(refetchRes.ok, `Failed to re-fetch release ${releaseId}: HTTP ${refetchRes.status}`);
assert.equal(refetchRes.data.id, releaseId, 'Release databaseId changed unexpectedly');
assert.equal(refetchRes.data.tag_name, tag, 'Release tag_name drifted before PATCH');
assert.equal(refetchRes.data.target_commitish.toLowerCase(), expectedSha.toLowerCase(), 'target_commitish drifted');
assert.equal(refetchRes.data.draft, true, 'Release is not in draft state before PATCH');
assert.equal(refetchRes.data.prerelease, false, 'Release prerelease changed before PATCH');

// Re-check remote tag peel immediately before PATCH
const tagRecheck = await apiRequest(`/repos/${repository}/git/ref/tags/${tag}`);
assert.ok(tagRecheck.ok, `Remote tag ${tag} missing immediately before PATCH`);
let recheckSha = tagRecheck.data.object.sha;
if (tagRecheck.data.object.type === 'tag') {
  const tagObj = await apiRequest(`/repos/${repository}/git/tags/${recheckSha}`);
  assert.ok(tagObj.ok, `Failed resolving annotated tag object on re-check: ${recheckSha}`);
  recheckSha = tagObj.data.object.sha;
}
assert.equal(recheckSha.toLowerCase(), expectedSha.toLowerCase(), 'Remote tag drifted before PATCH');

// Validate exact 5 assets on refetch with identical identities
const refetchAssets = refetchRes.data.assets;
assert.ok(Array.isArray(refetchAssets) && refetchAssets.length === 5, 'Refetched release must have exactly 5 assets');
const refetchNames = refetchAssets.map(a => a.name);
const refetchLower = refetchNames.map(n => n.toLowerCase());
assert.equal(new Set(refetchLower).size, 5, 'Duplicate or case collision in refetched asset list');
assert.deepEqual(refetchNames.slice().sort(), EXACT_5_ASSETS, 'Refetched asset names mismatch');

for (const a of refetchAssets) {
  assert.ok(typeof a.id === 'number' && a.id > 0, 'Invalid refetched asset ID');
  const snap = uploadedSnapshots.get(a.name);
  assert.ok(snap, `Unexpected refetched asset ${a.name}`);
  assert.equal(a.id, snap.id, `Asset ID changed on refetch for ${a.name}`);
  assert.equal(a.size, snap.size, `Asset size changed on refetch for ${a.name}`);
}

// Step 12: Controlled PATCH draft=false (make_latest: "true")
console.log(`[7/7] Publishing release (PATCH draft=false, make_latest: "true")...`);
const patchRes = await apiRequest(`/repos/${repository}/releases/${releaseId}`, {
  method: 'PATCH',
  body: JSON.stringify({
    draft: false,
    make_latest: 'true'
  })
});
assert.ok(patchRes.ok, `Failed to undraft release: HTTP ${patchRes.status}`);
assert.equal(patchRes.data.draft, false, 'Release must no longer be draft');
assert.equal(patchRes.data.id, releaseId, 'Published release ID mismatch');
assert.equal(patchRes.data.prerelease, false, 'Published release prerelease must remain false');

// Step 13: Final Public Download Verification into unique owned mkdtemp view in RUNNER_TEMP
console.log('      Verifying public download URLs for all 5 assets into fresh RUNNER_TEMP view...');
const publicViewDir = fs.mkdtempSync(path.join(runnerTemp, 'setup12-public-view-'));
assertNoReparse(publicViewDir);

const publicCheckRes = await apiRequest(`/repos/${repository}/releases/tags/${tag}`);
assert.ok(publicCheckRes.ok, `Failed fetching public release: HTTP ${publicCheckRes.status}`);
assert.equal(publicCheckRes.data.draft, false, 'Public release must not be draft');
assert.equal(publicCheckRes.data.prerelease, false, 'Public release must not be prerelease');
assert.equal(publicCheckRes.data.id, releaseId, 'Public release ID must match draft ID');
assert.equal(publicCheckRes.data.tag_name, tag, 'Public release tag_name mismatch');
assert.equal(publicCheckRes.data.target_commitish.toLowerCase(), expectedSha.toLowerCase(), 'Public release commitish mismatch');

const publicAssets = publicCheckRes.data.assets;
assert.ok(Array.isArray(publicAssets) && publicAssets.length === 5, `Expected exactly 5 assets in public release, found: ${publicAssets?.length}`);
const publicNames = publicAssets.map(a => a.name);
const publicLower = publicNames.map(n => n.toLowerCase());
assert.equal(new Set(publicLower).size, 5, 'Duplicate or case collision in public release asset list');
assert.deepEqual(publicNames.slice().sort(), EXACT_5_ASSETS, 'Public release asset set mismatch');

for (const publicAsset of publicAssets) {
  assert.ok(typeof publicAsset.id === 'number' && publicAsset.id > 0, 'Invalid public asset ID');
  const snap = uploadedSnapshots.get(publicAsset.name);
  assert.ok(snap, `Unexpected public asset ${publicAsset.name}`);
  assert.equal(publicAsset.id, snap.id, `Public asset ID mismatch for ${publicAsset.name}`);
  assert.equal(publicAsset.size, snap.size, `Public asset size mismatch for ${publicAsset.name}`);
  assert.ok(publicAsset.browser_download_url, `Asset missing browser_download_url: ${publicAsset.name}`);

  const publicDownRes = await fetch(publicAsset.browser_download_url, {
    headers: { 'User-Agent': 'pos-desktop-publisher-setup12' }
  });
  assert.ok(publicDownRes.ok, `Public download failed for ${publicAsset.name}: HTTP ${publicDownRes.status}`);
  const publicBuf = Buffer.from(await publicDownRes.arrayBuffer());
  const publicHash = sha256(publicBuf);
  assert.equal(publicHash, localHashes.get(publicAsset.name), `Public download hash mismatch for ${publicAsset.name}`);

  const downloadedFilePath = path.join(publicViewDir, publicAsset.name);
  fs.writeFileSync(downloadedFilePath, publicBuf);
}

// Clean up public view directory
fs.rmSync(publicViewDir, { recursive: true, force: true });

console.log('\n===============================================================');
console.log(` SETUP 0.0.12 PUBLISHED AND CRYPTOGRAPHICALLY VERIFIED!`);
console.log(` Release URL: ${publicCheckRes.data.html_url}`);
console.log('===============================================================');
