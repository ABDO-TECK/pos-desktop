const fs = require('fs');
const path = require('path');

function readUpdateChannel(storageDir, deployedRoot) {
  for (const [file, key] of [[path.join(storageDir, 'update_channel.json'), 'channel'], [path.join(deployedRoot, 'version.json'), 'update_channel']]) {
    try {
      const channel = JSON.parse(fs.readFileSync(file, 'utf8'))[key];
      if (['stable', 'beta', 'rc'].includes(channel)) return channel;
    } catch { /* use persisted preference, then installed default */ }
  }
  return 'stable';
}

function selectSetupRelease(releases, clientChannel, currentVersion) {
  const semver = require('semver');
  return releases.filter(release => {
    if (release.draft) return false;
    const version = String(release.tag_name || '').replace(/^v/, '');
    if (!semver.valid(version) || !semver.gt(version, currentVersion)) return false;
    if (semver.lt(currentVersion, '1.0.0') && semver.gte(version, '1.0.0')) return false;
    const channel = version.includes('-rc.') ? 'rc' : (release.prerelease || version.includes('-beta.')) ? 'beta' : 'stable';
    if (clientChannel === 'stable' && channel !== 'stable') return false;
    if (clientChannel === 'rc' && channel === 'beta') return false;
    return (release.assets || []).some(asset => ['latest.yml', 'beta.yml', 'rc.yml'].includes(asset.name));
  }).sort((a, b) => semver.rcompare(a.tag_name.replace(/^v/, ''), b.tag_name.replace(/^v/, '')))[0] || null;
}

module.exports = { readUpdateChannel, selectSetupRelease };
