const fs = require('fs');
const path = require('path');

function normalizeChannelPreference(channel) {
  const normalized = String(channel || '').trim().toLowerCase();
  if (normalized === 'beta') return 'beta';
  return 'stable';
}

function readUpdateChannel(storageDir, deployedRoot) {
  for (const [file, key] of [
    [path.join(storageDir, 'update_channel.json'), 'channel'],
    [path.join(deployedRoot, 'version.json'), 'update_channel'],
  ]) {
    try {
      const channel = JSON.parse(fs.readFileSync(file, 'utf8'))[key];
      if (typeof channel === 'string') {
        const lower = channel.trim().toLowerCase();
        if (lower === 'beta') return 'beta';
        if (lower === 'stable' || lower === 'rc') return 'stable';
      }
    } catch { /* use persisted preference, then installed default */ }
  }
  return 'stable';
}

function classifyVersionChannel(version) {
  if (typeof version !== 'string') return null;
  const clean = version.trim().replace(/^v/i, '');
  if (/^\d+\.\d+\.\d+-beta\.\d+$/.test(clean)) return 'beta';
  if (/^\d+\.\d+\.\d+$/.test(clean)) return 'stable';
  return null;
}

function getReleaseChannel(version, prerelease) {
  if (typeof prerelease !== 'boolean') return null;
  const channel = classifyVersionChannel(version);
  if (channel === 'beta' && prerelease === true) {
    return 'beta';
  }
  if (channel === 'stable' && prerelease === false) {
    return 'stable';
  }
  return null;
}

function selectSetupRelease(releases, clientChannel, currentVersion) {
  if (!Array.isArray(releases) || !currentVersion) return null;
  const semver = require('semver');
  const cleanCurrent = String(currentVersion).trim().replace(/^v/i, '');
  if (!semver.valid(cleanCurrent)) return null;

  const normalizedClient = String(clientChannel || '').trim().toLowerCase();
  const effectiveChannel = normalizedClient === 'rc' ? 'stable' : normalizedClient;
  if (!['stable', 'beta'].includes(effectiveChannel)) return null;

  const expectedMetadata = effectiveChannel === 'beta' ? 'beta.yml' : 'latest.yml';

  return releases.filter(release => {
    if (!release || release.draft !== false || !Array.isArray(release.assets)) return false;
    const version = String(release.tag_name || '').trim().replace(/^v/i, '');
    if (!semver.valid(version) || !semver.gt(version, cleanCurrent)) return false;
    if (semver.lt(cleanCurrent, '1.0.0') && semver.gte(version, '1.0.0')) return false;

    const releaseChannel = getReleaseChannel(version, release.prerelease);
    if (releaseChannel !== effectiveChannel) return false;

    return release.assets.some(asset => asset && asset.name === expectedMetadata);
  }).sort((a, b) => semver.rcompare(
    String(a.tag_name || '').trim().replace(/^v/i, ''),
    String(b.tag_name || '').trim().replace(/^v/i, '')
  ))[0] || null;
}

module.exports = {
  classifyVersionChannel,
  readUpdateChannel,
  selectSetupRelease,
  getReleaseChannel,
  normalizeChannelPreference,
};
