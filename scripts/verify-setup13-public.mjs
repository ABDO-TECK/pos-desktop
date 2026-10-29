#!/usr/bin/env node
/**
 * scripts/verify-setup13-public.mjs
 *
 * Postpublication read-only verifier for Setup 0.0.13 & channel carrier releases.
 * Reuses pure validators from release source. Executes strictly in GitHub Actions.
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const REQUIRED_REPOSITORY = 'ABDO-TECK/pos-desktop';
export const UPDATES_REPOSITORY = 'ABDO-TECK/pos-desktop-updates';
export const EXPECTED_VERSION = '0.0.13';
export const EXPECTED_TAG = 'v0.0.13';

export const FROZEN_REFS = Object.freeze({
  source_repo: REQUIRED_REPOSITORY,
  source_main_sha: 'a00dd84dda56ef50d0bd9880cd0eb24802c88ed7',
  source_v12_sha: 'd0f8a2968c817ce77c238c623b7c8111c52d1e5c',
  updates_repo: UPDATES_REPOSITORY,
  updates_main_sha: 'f67c564a8ec24dbe2fe522d622dad13437c376a3'
});

export const FROZEN_OLD_ASSETS = Object.freeze([
  { repo: REQUIRED_REPOSITORY, tag: 'v0.0.12', id: 683207579, name: 'deployment-inventory.json', sha256: 'b1265fe0e0cab3d2a91d482a44a9ba5aa4759bb971bb640a9241331bbb060132' },
  { repo: REQUIRED_REPOSITORY, tag: 'v0.0.12', id: 683207594, name: 'latest.yml', sha256: '0aa3b1203de429efd9ac8d675978b00217b1b04fe0c40109fdb6c6c5c26f7b16' },
  { repo: REQUIRED_REPOSITORY, tag: 'v0.0.12', id: 683207307, name: 'POS-Desktop-Setup-0.0.12.exe', sha256: 'cdfa1d797e726280d48d7f7c64e9ccbe5717ba64831b455cf59de47fab4c6ef8', size: 322556752 },
  { repo: REQUIRED_REPOSITORY, tag: 'v0.0.12', id: 683207555, name: 'POS-Desktop-Setup-0.0.12.exe.blockmap', sha256: 'eda51b25668f71f51d08ea2c43c59055b15a2012608bf8f9b717646fbc42fb74' },
  { repo: REQUIRED_REPOSITORY, tag: 'v0.0.12', id: 683207565, name: 'SHA256SUMS.txt', sha256: 'afaa1ca25d7b81904eec3321f00978414abf8ee2a7662a9cb8322a026e0252df' },
  { repo: UPDATES_REPOSITORY, tag: 'v0.0.12', id: 683156785, name: 'deployment-inventory.json', sha256: '219a04064436abdeb3fb58087964ef23d908e7c49b3e62eb4ca1314ca3a6d817' },
  { repo: UPDATES_REPOSITORY, tag: 'v0.0.12-beta.1', id: 676316273, name: 'deployment-inventory.json', sha256: '104cc1b55d9ebae69aac8d56bbb024afa178e4208fd73a41a1d4d89d504b1fb3' },
  { repo: UPDATES_REPOSITORY, tag: 'v0.0.12-beta.1', id: 676316239, name: 'channel-transitions.json', sha256: '4109ad3cdfb06201219a8939015cc1c723863e27f200d2257e408c7b8cd98f27' },
  { repo: UPDATES_REPOSITORY, tag: 'v0.0.12-beta.1', id: 676316233, name: 'channel-transitions.sig', sha256: '599742f4c95f842944b8705458b5f5bc7dfea4cf5d23110b04464014764b77c6' }
]);

export function assertNoReparse(targetPath) {
  let cur = path.resolve(targetPath);
  while (cur && path.dirname(cur) !== cur) {
    if (fs.existsSync(cur) && fs.lstatSync(cur).isSymbolicLink()) {
      throw new Error(`Symlink or reparse point rejected: ${cur}`);
    }
    const parent = path.dirname(cur);
    if (parent === cur) break;
    cur = parent;
  }
}

export function cleanupOwnedStaging(dir, runnerTemp) {
  if (!dir || !fs.existsSync(dir)) return;
  assertNoReparse(runnerTemp);
  const rel = path.relative(runnerTemp, dir);
  assert.ok(!rel.startsWith('..') && !path.isAbsolute(rel) && rel.length > 0, `Staging outside RUNNER_TEMP: ${dir}`);
  assert.ok(path.basename(dir).startsWith('setup13-public-verify-'), `Refusing unowned staging cleanup: ${dir}`);
  assertNoReparse(dir);
  assert.ok(fs.lstatSync(dir).isDirectory() && !fs.lstatSync(dir).isSymbolicLink(), `Not directory: ${dir}`);
  fs.rmSync(dir, { recursive: true, force: true });
}

export async function githubApi(endpoint, token) {
  const url = `https://api.github.com${endpoint}`;
  const headers = { 'User-Agent': 'VerifySetup13Public', 'Accept': 'application/vnd.github+json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;
  const res = await fetch(url, { headers });
  if (res.status < 200 || res.status >= 300) {
    throw new Error(`GitHub API fail-closed non-2xx: ${res.status} on ${endpoint}`);
  }
  return res.json();
}

export async function downloadAndHash(downloadUrl, savePath = null) {
  const u = new URL(downloadUrl);
  assert.equal(u.protocol, 'https:', 'Download URL must be HTTPS');
  assert.ok(u.hostname === 'github.com' || u.hostname.endsWith('.githubusercontent.com'), `Untrusted host: ${u.hostname}`);
  const res = await fetch(downloadUrl, { redirect: 'follow' });
  if (res.status !== 200) {
    throw new Error(`Public download failed: HTTP ${res.status} from ${downloadUrl}`);
  }
  const hash = crypto.createHash('sha256');
  let size = 0;
  let out = savePath ? fs.createWriteStream(savePath) : null;
  for await (const chunk of res.body) {
    hash.update(chunk);
    size += chunk.length;
    if (out) out.write(chunk);
  }
  if (out) {
    await new Promise((resolve, reject) => {
      out.end(resolve);
      out.on('error', reject);
    });
  }
  return { hash: hash.digest('hex').toLowerCase(), size };
}

export async function getPeeledTagSha(repo, tag, token) {
  const refData = await githubApi(`/repos/${repo}/git/ref/tags/${tag}`, token);
  assert.ok(refData?.object?.sha, `Missing object SHA for tag ${tag}`);
  let sha = refData.object.sha;
  if (refData.object.type === 'tag') {
    const tagObj = await githubApi(`/repos/${repo}/git/tags/${sha}`, token);
    assert.ok(tagObj?.object?.sha, `Failed to peel tag ${tag}`);
    sha = tagObj.object.sha;
  }
  return sha.toLowerCase();
}

export function validateRun(run, expectedId, expectedPath, expectedSha, label) {
  assert.ok(run && typeof run === 'object', `${label} data missing`);
  assert.equal(String(run.id), String(expectedId), `${label} ID mismatch`);
  assert.equal(run.status, 'completed', `${label} not completed`);
  assert.equal(run.conclusion, 'success', `${label} not success`);
  assert.equal(run.path, expectedPath, `${label} path mismatch: ${run.path}`);
  assert.equal(run.event, 'workflow_dispatch', `${label} event mismatch: ${run.event}`);
  assert.equal(run.repository?.full_name, REQUIRED_REPOSITORY, `${label} repo mismatch`);
  assert.equal(run.head_repository?.full_name, REQUIRED_REPOSITORY, `${label} head_repo mismatch`);
  assert.equal(run.head_sha?.toLowerCase(), expectedSha, `${label} head_sha mismatch`);
}

export async function precheckRunsAndRefs(opts) {
  const { sourceSha, setupRunId, acceptanceRunId, token, releaseSourceDir } = opts;
  assert.match(sourceSha, /^[a-f0-9]{40}$/, `source_sha must be 40 lower hex: ${sourceSha}`);
  assert.match(setupRunId, /^[1-9]\d*$/, `setup_run_id must be numeric: ${setupRunId}`);
  assert.match(acceptanceRunId, /^[1-9]\d*$/, `acceptance_run_id must be numeric: ${acceptanceRunId}`);

  const [setupRun, accRun] = await Promise.all([
    githubApi(`/repos/${REQUIRED_REPOSITORY}/actions/runs/${setupRunId}`, token),
    githubApi(`/repos/${REQUIRED_REPOSITORY}/actions/runs/${acceptanceRunId}`, token)
  ]);
  validateRun(setupRun, setupRunId, '.github/workflows/windows-setup.yml', sourceSha, 'setup_run');
  validateRun(accRun, acceptanceRunId, '.github/workflows/setup13-release-acceptance.yml', sourceSha, 'acceptance_run');

  const peeledTagSha = await getPeeledTagSha(REQUIRED_REPOSITORY, EXPECTED_TAG, token);
  assert.equal(peeledTagSha, sourceSha, `Tag ${EXPECTED_TAG} peeled SHA (${peeledTagSha}) != source_sha (${sourceSha})`);

  if (releaseSourceDir && fs.existsSync(releaseSourceDir)) {
    const gitHead = execFileSync('git', ['-C', releaseSourceDir, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim().toLowerCase();
    assert.equal(gitHead, sourceSha, `releaseSource git HEAD (${gitHead}) != expected source_sha (${sourceSha})`);
  }

  const [srcMain, srcTag12, updMain] = await Promise.all([
    githubApi(`/repos/${FROZEN_REFS.source_repo}/git/ref/heads/main`, token),
    getPeeledTagSha(FROZEN_REFS.source_repo, 'v0.0.12', token),
    githubApi(`/repos/${FROZEN_REFS.updates_repo}/git/ref/heads/main`, token)
  ]);
  assert.equal(srcMain.object?.sha?.toLowerCase(), FROZEN_REFS.source_main_sha, 'Frozen source main ref mutated');
  assert.equal(srcTag12, FROZEN_REFS.source_v12_sha, 'Frozen source v0.0.12 tag ref mutated');
  assert.equal(updMain.object?.sha?.toLowerCase(), FROZEN_REFS.updates_main_sha, 'Frozen updates main ref mutated');

  return { setupRun, accRun, peeledTagSha };
}

export async function verifyFrozenOldAssets(token) {
  const relCache = new Map();
  async function getRelease(repo, tag) {
    const k = `${repo}@${tag}`;
    if (!relCache.has(k)) relCache.set(k, await githubApi(`/repos/${repo}/releases/tags/${tag}`, token));
    return relCache.get(k);
  }

  for (const item of FROZEN_OLD_ASSETS) {
    const rel = await getRelease(item.repo, item.tag);
    const asset = rel.assets?.find((a) => a.id === item.id && a.name === item.name);
    assert.ok(asset, `Frozen asset ${item.name} (${item.id}) missing in ${item.repo} ${item.tag}`);
    if (item.size !== undefined) {
      assert.equal(asset.size, item.size, `Frozen asset ${item.name} size mismatch`);
    }
    if (asset.digest && typeof asset.digest === 'string' && asset.digest.toLowerCase().includes(item.sha256)) {
      continue;
    }
    const { hash } = await downloadAndHash(asset.browser_download_url);
    assert.equal(hash, item.sha256, `Frozen asset ${item.name} hash mismatch: got ${hash}, expected ${item.sha256}`);
  }
}

export async function verifyPublicReleases(opts) {
  const { sourceSha, setupRunId, acceptanceRunId, token, releaseSourceDir, evidenceDir, runnerTemp } = opts;
  assertNoReparse(runnerTemp);
  const stagingRoot = fs.mkdtempSync(path.join(runnerTemp, 'setup13-public-verify-'));
  assertNoReparse(stagingRoot);

  try {
    const stagingSetupDir = path.join(stagingRoot, 'setup');
    const stagingCarrierDir = path.join(stagingRoot, 'carrier');
    fs.mkdirSync(stagingSetupDir);
    fs.mkdirSync(stagingCarrierDir);

    const promotePath = path.resolve(releaseSourceDir, 'scripts/promote-setup13.mjs');
    const finalizePath = path.resolve(releaseSourceDir, 'scripts/finalize-setup13-release-evidence.mjs');
    assert.ok(fs.existsSync(promotePath), `Missing promote-setup13.mjs at ${promotePath}`);
    assert.ok(fs.existsSync(finalizePath), `Missing finalize-setup13-release-evidence.mjs at ${finalizePath}`);
    const promoteModule = await import(pathToFileURL(promotePath).href);
    const finalizeModule = await import(pathToFileURL(finalizePath).href);

    const [setupRel, carrierRel] = await Promise.all([
      githubApi(`/repos/${REQUIRED_REPOSITORY}/releases/tags/${EXPECTED_TAG}`, token),
      githubApi(`/repos/${UPDATES_REPOSITORY}/releases/tags/${EXPECTED_TAG}`, token)
    ]);
    assert.equal(setupRel.draft, false, 'Setup release must not be draft');
    assert.equal(setupRel.prerelease, false, 'Setup release must not be prerelease');
    assert.equal(carrierRel.draft, false, 'Carrier release must not be draft');
    assert.equal(carrierRel.prerelease, false, 'Carrier release must not be prerelease');

    assert.equal(setupRel.assets?.length, 5, `Setup release must contain exact 5 assets, got ${setupRel.assets?.length}`);
    for (const a of setupRel.assets) {
      assert.ok(promoteModule.EXACT_5_SETUP_ASSETS.includes(a.name), `Unexpected setup asset: ${a.name}`);
      const expectedUrl = `https://github.com/${REQUIRED_REPOSITORY}/releases/download/${EXPECTED_TAG}/${a.name}`;
      assert.equal(a.browser_download_url, expectedUrl, `Setup asset download URL mismatch: ${a.browser_download_url}`);
      await downloadAndHash(a.browser_download_url, path.join(stagingSetupDir, a.name));
    }

    assert.equal(carrierRel.assets?.length, 18, `Carrier release must contain exact 18 assets, got ${carrierRel.assets?.length}`);
    for (const a of carrierRel.assets) {
      assert.ok(promoteModule.EXACT_18_CARRIER_FILES.includes(a.name), `Unexpected carrier asset: ${a.name}`);
      const expectedUrl = `https://github.com/${UPDATES_REPOSITORY}/releases/download/${EXPECTED_TAG}/${a.name}`;
      assert.equal(a.browser_download_url, expectedUrl, `Carrier asset download URL mismatch: ${a.browser_download_url}`);
      await downloadAndHash(a.browser_download_url, path.join(stagingCarrierDir, a.name));
    }

    const { setupHashes, setupSizes, inventoryInfo } = promoteModule.validateSetupDirectory(stagingSetupDir);
    const provenance = promoteModule.validateEvidenceDirectory(evidenceDir, {
      expectedSetupRunId: setupRunId,
      expectedAcceptanceRunId: acceptanceRunId,
      expectedSha: sourceSha,
      setupHashes,
      setupSizes
    });
    promoteModule.validateCarrierDirectory(stagingCarrierDir, provenance, inventoryInfo);

    const pubKeyPath = path.join(releaseSourceDir, 'backend/certs/update_public_key.pem');
    assert.ok(fs.existsSync(pubKeyPath), `Public key missing: ${pubKeyPath}`);
    const pubKeyPem = fs.readFileSync(pubKeyPath, 'utf8');

    const rawIndex = fs.readFileSync(path.join(stagingCarrierDir, 'channel-transitions.json'), 'utf8');
    const rawIndexSig = fs.readFileSync(path.join(stagingCarrierDir, 'channel-transitions.sig'), 'utf8');
    finalizeModule.validateTransitionIndex(JSON.parse(rawIndex), pubKeyPem, rawIndex, rawIndexSig);

    for (const t of promoteModule.EXPECTED_TRANSITIONS) {
      const rawDesc = fs.readFileSync(path.join(stagingCarrierDir, `${t.stem}.json`), 'utf8');
      const rawDescSig = fs.readFileSync(path.join(stagingCarrierDir, `${t.stem}.sig`), 'utf8');
      assert.ok(finalizeModule.verifyRsaSignature(rawDesc, rawDescSig, pubKeyPem), `RSA signature failed: ${t.stem}.json`);
    }

    return { setupHashes, provenance };
  } finally {
    cleanupOwnedStaging(stagingRoot, runnerTemp);
  }
}

export async function main() {
  if (process.env.GITHUB_ACTIONS !== 'true') {
    throw new Error('scripts/verify-setup13-public.mjs is strictly restricted to ephemeral GitHub Actions runners');
  }

  const mode = process.argv.includes('--precheck') ? 'precheck' : 'verify';
  const sourceSha = (process.env.INPUT_SOURCE_SHA || '').trim().toLowerCase();
  const setupRunId = (process.env.INPUT_SETUP_RUN_ID || '').trim();
  const acceptanceRunId = (process.env.INPUT_ACCEPTANCE_RUN_ID || '').trim();
  const token = (process.env.GH_TOKEN || process.env.GITHUB_TOKEN || '').trim();
  const releaseSourceDir = path.resolve(process.env.RELEASE_SOURCE_DIR || 'release-source');
  const evidenceDir = path.resolve(process.env.EVIDENCE_DIR || 'evidence');
  const runnerTemp = process.env.RUNNER_TEMP ? path.resolve(process.env.RUNNER_TEMP) : null;

  const report = {
    verdict: 'PENDING',
    mode,
    timestamp: new Date().toISOString(),
    verification_tool_sha: process.env.GITHUB_SHA || null,
    expected_source_sha: sourceSha,
    setup_run_id: setupRunId,
    acceptance_run_id: acceptanceRunId
  };

  try {
    await precheckRunsAndRefs({ sourceSha, setupRunId, acceptanceRunId, token, releaseSourceDir });
    report.runs_and_refs_verified = true;

    if (mode === 'verify') {
      assert.ok(runnerTemp && fs.existsSync(runnerTemp), 'RUNNER_TEMP is required and must exist');
      await verifyFrozenOldAssets(token);
      report.frozen_old_assets_verified = true;

      await verifyPublicReleases({
        sourceSha,
        setupRunId,
        acceptanceRunId,
        token,
        releaseSourceDir,
        evidenceDir,
        runnerTemp
      });
      report.public_assets_verified = true;
      report.rsa_signatures_verified = true;
    }

    report.verdict = mode === 'verify' ? 'PASSED' : 'PRECHECK_PASSED';
    fs.writeFileSync('public-integrity.json', JSON.stringify(report, null, 2), 'utf8');
    console.log(`[PASS] Verification completed successfully (${mode} mode)`);
  } catch (err) {
    report.verdict = 'FAILED';
    report.error = err.message;
    try {
      fs.writeFileSync('public-integrity.json', JSON.stringify(report, null, 2), 'utf8');
    } catch {
      // Ignore secondary write error
    }
    console.error(`[CRITICAL FAIL] Verification failed: ${err.message}`);
    process.exit(1);
  }
}

const isMain = process.argv[1] && path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url));
if (isMain) {
  main();
}
