import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const args = parseArguments(process.argv.slice(2));
const root = path.resolve(args.root || process.cwd());
const mode = args.mode || 'working-tree';

function parseArguments(argv) {
  const parsed = {};
  for (let index = 0; index < argv.length; index += 1) {
    const token = argv[index];
    if (!token.startsWith('--')) {
      fail(`Unexpected argument: ${token}`);
    }
    const withoutPrefix = token.slice(2);
    const equalsIndex = withoutPrefix.indexOf('=');
    if (equalsIndex >= 0) {
      parsed[withoutPrefix.slice(0, equalsIndex)] = withoutPrefix.slice(equalsIndex + 1);
      continue;
    }
    const next = argv[index + 1];
    if (next && !next.startsWith('--')) {
      parsed[withoutPrefix] = next;
      index += 1;
    } else {
      parsed[withoutPrefix] = true;
    }
  }
  return parsed;
}

function fail(message) {
  console.error(`Release source validation failed: ${message}`);
  process.exit(1);
}

function requireArgument(name) {
  const value = args[name];
  if (typeof value !== 'string' || value.trim() === '') {
    fail(`--${name} is required`);
  }
  return value.trim();
}

function readJson(relativePath) {
  const absolutePath = path.join(root, relativePath);
  if (!fs.existsSync(absolutePath)) {
    fail(`Missing ${relativePath}`);
  }
  try {
    return JSON.parse(fs.readFileSync(absolutePath, 'utf8'));
  } catch (error) {
    fail(`Invalid JSON in ${relativePath}: ${error.message}`);
  }
}

function readJsonFile(absolutePath, label) {
  if (!fs.existsSync(absolutePath)) {
    fail(`Missing ${label}`);
  }
  try {
    return JSON.parse(fs.readFileSync(absolutePath, 'utf8'));
  } catch (error) {
    fail(`Invalid JSON in ${label}: ${error.message}`);
  }
}

function parseVersion(value, label) {
  if (typeof value !== 'string' || !/^\d+\.\d+\.\d+(?:-beta\.\d+)?$/.test(value)) {
    fail(`${label} must be a semantic version in X.Y.Z form`);
  }
  const [main, prerelease] = value.split('-');
  const [prereleaseLabel, number] = (prerelease || '').split('.');
  return [...main.split('.').map(Number), prereleaseLabel === 'beta' ? 0 : prereleaseLabel === 'rc' ? 1 : 2, Number(number || 0)];
}

function compareVersions(left, right) {
  const leftParts = parseVersion(left, 'version');
  const rightParts = parseVersion(right, 'version');
  for (let index = 0; index < leftParts.length; index += 1) {
    if (leftParts[index] !== rightParts[index]) {
      return leftParts[index] > rightParts[index] ? 1 : -1;
    }
  }
  return 0;
}

function resolveGitRef(ref, label) {
  try {
    return execFileSync('git', ['rev-parse', '--verify', `${ref}^{commit}`], {
      cwd: root,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
  } catch (error) {
    const detail = String(error.stderr || '').trim();
    fail(`could not resolve ${label} '${ref}'${detail ? `: ${detail}` : ''}`);
  }
}

function readVersionAtCommit(commit) {
  try {
    return JSON.parse(execFileSync('git', ['show', `${commit}:version.json`], {
      cwd: root,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    }));
  } catch (error) {
    fail(`baseline ${commit} does not contain valid version.json`);
  }
}

function ensureVersionFields(versionData) {
  const version = versionData.version || versionData.application_version;
  if (!version) {
    fail('version.json must contain version or application_version');
  }
  parseVersion(version, 'version.json version');
  if (versionData.version && versionData.application_version && versionData.version !== versionData.application_version) {
    fail('version.json version and application_version differ');
  }
  return version;
}

function validateManifest(manifestPath, targetVersion, baselineVersion, versionData, mode) {
  const rootRelativePath = path.resolve(root, manifestPath);
  const workingDirectoryPath = path.resolve(process.cwd(), manifestPath);
  const absoluteManifestPath = fs.existsSync(rootRelativePath) ? rootRelativePath : workingDirectoryPath;
  const manifest = readJsonFile(absoluteManifestPath, manifestPath);
  if (manifest.version !== targetVersion) {
    fail(`manifest version '${manifest.version}' does not match target '${targetVersion}'`);
  }
  if (mode === 'delta' && manifest.minimum_version !== baselineVersion) {
    fail(`manifest minimum_version '${manifest.minimum_version}' does not match baseline '${baselineVersion}'`);
  }
  if (manifest.type !== mode) {
    fail(`manifest type '${manifest.type}' does not match release mode '${mode}'`);
  }
  if (manifest.update_engine_version !== (versionData.update_engine_version || '1.0.0')) {
    fail('manifest update_engine_version does not match version.json');
  }
}

const versionData = readJson('version.json');
const rootPackage = readJson('package.json');
const frontendPackage = readJson('frontend/package.json');
const targetVersion = ensureVersionFields(versionData);
if (mode !== 'delta' && (versionData.release_type || 'setup') === 'setup' && targetVersion !== rootPackage.version) {
  fail('Full Setup application and installer versions must agree');
}

if (rootPackage.version !== frontendPackage.version) {
  fail(`package.json (${rootPackage.version}) and frontend/package.json (${frontendPackage.version}) versions differ`);
}
parseVersion(rootPackage.version, 'package.json version');

if (mode === 'working-tree') {
  console.log(`Source versions valid: update=${targetVersion}, desktop-runtime=${rootPackage.version}`);
  process.exit(0);
}

if (!['delta', 'full'].includes(mode)) {
  fail(`unsupported mode '${mode}'`);
}

const tag = requireArgument('tag');
const tagMatch = /^v(\d+\.\d+\.\d+(?:-beta\.\d+)?)$/.exec(tag);
if (!tagMatch) {
  fail(`tag '${tag}' is not a supported vX.Y.Z tag`);
}
if (tagMatch[1] !== (mode === 'full' ? (versionData.installer_version || targetVersion) : targetVersion)) {
  fail(`tag version '${tagMatch[1]}' does not match version.json '${targetVersion}'`);
}

const releaseChannel = requireArgument('release-channel');
if (!['prerelease', 'stable', 'beta'].includes(releaseChannel)) {
  fail(`release channel '${releaseChannel}' is invalid`);
}
const expectedChannel = targetVersion.includes('-beta.') ? 'beta' : 'stable';
if (releaseChannel !== 'prerelease' && releaseChannel !== expectedChannel) fail('Release channel does not match tag.');

const headCommit = resolveGitRef('HEAD', 'checked-out HEAD');
const tagCommit = resolveGitRef(tag, 'release tag');
if (headCommit !== tagCommit) {
  fail(`checked-out HEAD ${headCommit} does not match tag ${tag} at ${tagCommit}`);
}

let baselineVersion = null;
if (mode === 'delta') {
  baselineVersion = requireArgument('baseline-version');
  parseVersion(baselineVersion, 'baseline version');
  const baselineRef = requireArgument('baseline-ref');
  const baselineCommit = resolveGitRef(baselineRef, 'baseline ref');
  const baselineData = readVersionAtCommit(baselineCommit);
  const actualBaselineVersion = ensureVersionFields(baselineData);
  if (actualBaselineVersion !== baselineVersion) {
    fail(`baseline ref '${baselineRef}' contains version '${actualBaselineVersion}', not '${baselineVersion}'`);
  }
  if (compareVersions(targetVersion, baselineVersion) <= 0) {
    fail(`target version '${targetVersion}' must be greater than baseline '${baselineVersion}'`);
  }
  // The runtime package remains at its last Setup version. A beta baseline may
  // already carry newer delta artifacts; the inventory verifies the shell.
} else if (rootPackage.version !== (versionData.installer_version || targetVersion)) {
  fail(`full release package version '${rootPackage.version}' must match target '${targetVersion}'`);
}

if (args.manifest) {
  validateManifest(args.manifest, targetVersion, baselineVersion, versionData, mode);
}

console.log(`Release source valid: tag=${tag}, target=${targetVersion}, mode=${mode}${baselineVersion ? `, baseline=${baselineVersion}` : ''}, channel=${releaseChannel}`);
