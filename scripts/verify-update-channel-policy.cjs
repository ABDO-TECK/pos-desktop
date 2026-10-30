const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { readUpdateChannel, selectSetupRelease, getReleaseChannel, classifyVersionChannel } = require('../electron/utils/update-channel-policy');

const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-channel-'));
fs.writeFileSync(path.join(root, 'version.json'), JSON.stringify({ update_channel: 'stable' }));
assert.equal(readUpdateChannel(root, root), 'stable');

fs.writeFileSync(path.join(root, 'update_channel.json'), JSON.stringify({ channel: 'beta' }));
assert.equal(readUpdateChannel(root, root), 'beta');

fs.writeFileSync(path.join(root, 'update_channel.json'), JSON.stringify({ channel: 'rc' }));
assert.equal(readUpdateChannel(root, root), 'stable', 'Retired RC preference must normalize to stable');

const setup = (version, prerelease = false) => ({
  tag_name: `v${version}`,
  prerelease,
  draft: false,
  assets: [{ name: prerelease ? 'beta.yml' : 'latest.yml' }],
});

const stable = setup('0.0.3');
const beta = setup('0.0.4-beta.1', true);
const delta = { tag_name: 'v0.0.5', prerelease: false, assets: [{ name: 'delta.zip' }] };

assert.equal(selectSetupRelease([beta, stable, delta], 'stable', '0.0.2').tag_name, 'v0.0.3');
assert.equal(selectSetupRelease([stable, delta, beta], 'beta', '0.0.2').tag_name, 'v0.0.4-beta.1');
assert.equal(selectSetupRelease([beta, stable], 'stable', '0.0.4'), null, 'Never offer an older Setup after a delta.');

// Strict channel isolation: newer Stable 13 must NEVER be offered to a Beta client on 0.0.12-beta.1
const stable13 = setup('0.0.13', false);
const beta12 = setup('0.0.12-beta.1', true);
assert.equal(
  selectSetupRelease([stable13, beta12], 'beta', '0.0.12-beta.1'),
  null,
  'Beta client on 0.0.12-beta.1 must not be offered newer Stable 13 Setup'
);

// Only Stable available: Beta client receives NONE (no fallback to Stable)
assert.equal(
  selectSetupRelease([stable13], 'beta', '0.0.10-beta.1'),
  null,
  'Beta client must receive null when only Stable Setup releases exist'
);

// Newer Beta 14-beta.1 + Stable 20: Beta client gets Beta 14, Stable client gets Stable 20
const beta14 = setup('0.0.14-beta.1', true);
const stable20 = setup('0.0.20', false);
assert.equal(
  selectSetupRelease([stable20, beta14], 'beta', '0.0.12-beta.1')?.tag_name,
  'v0.0.14-beta.1',
  'Beta client must receive only Beta 14'
);
assert.equal(
  selectSetupRelease([beta14, stable20], 'stable', '0.0.12')?.tag_name,
  'v0.0.20',
  'Stable client must receive only Stable 20'
);

// Drafts must be excluded
const draftBeta = { ...setup('0.0.15-beta.1', true), draft: true };
assert.equal(
  selectSetupRelease([draftBeta], 'beta', '0.0.12-beta.1'),
  null,
  'Draft releases must be excluded'
);

// RC releases must never be offered to beta or stable
const rcRelease = setup('0.0.14-rc.1', true);
assert.equal(
  selectSetupRelease([rcRelease], 'beta', '0.0.12-beta.1'),
  null,
  'RC releases must not be offered to beta'
);
assert.equal(
  selectSetupRelease([rcRelease], 'stable', '0.0.12'),
  null,
  'RC releases must not be offered to stable'
);

// Inconsistent flags
const stableWithPrereleaseTrue = { tag_name: 'v0.0.15', prerelease: true, draft: false, assets: [{ name: 'latest.yml' }] };
assert.equal(
  selectSetupRelease([stableWithPrereleaseTrue], 'stable', '0.0.12'),
  null,
  'Stable tag with prerelease=true must not be accepted as stable'
);
assert.equal(
  selectSetupRelease([stableWithPrereleaseTrue], 'beta', '0.0.12-beta.1'),
  null,
  'Stable tag with prerelease=true must not be accepted as beta'
);

const betaWithPrereleaseFalse = { tag_name: 'v0.0.15-beta.1', prerelease: false, draft: false, assets: [{ name: 'beta.yml' }] };
assert.equal(
  selectSetupRelease([betaWithPrereleaseFalse], 'beta', '0.0.12-beta.1'),
  null,
  'Beta tag with prerelease=false must not be accepted'
);

// Channel-appropriate metadata enforcement (Setup asset check)
const stableWithBetaYmlOnly = { tag_name: 'v0.0.15', prerelease: false, draft: false, assets: [{ name: 'beta.yml' }] };
assert.equal(
  selectSetupRelease([stableWithBetaYmlOnly], 'stable', '0.0.12'),
  null,
  'Stable release without latest.yml must be excluded'
);

const betaWithLatestYmlOnly = { tag_name: 'v0.0.15-beta.1', prerelease: true, draft: false, assets: [{ name: 'latest.yml' }] };
assert.equal(
  selectSetupRelease([betaWithLatestYmlOnly], 'beta', '0.0.12-beta.1'),
  null,
  'Beta release without beta.yml must be excluded'
);

// Invalid versions
const invalidCandidate = { tag_name: 'vnot-a-semver', prerelease: false, draft: false, assets: [{ name: 'latest.yml' }] };
assert.equal(
  selectSetupRelease([invalidCandidate], 'stable', '0.0.12'),
  null,
  'Invalid semver candidate must be excluded'
);
assert.equal(
  selectSetupRelease([stable20], 'stable', 'invalid-current-ver'),
  null,
  'Invalid currentVersion must return null'
);

// Retired RC preference channel normalization at selection
assert.equal(
  selectSetupRelease([stable], 'rc', '0.0.2')?.tag_name,
  'v0.0.3',
  'Retired RC preference normalizes to stable and receives stable Setup'
);
assert.equal(
  selectSetupRelease([beta], 'rc', '0.0.2'),
  null,
  'Retired RC preference normalizes to stable and does not receive beta'
);

// Fail closed on invalid client channel
assert.equal(
  selectSetupRelease([stable], 'alpha', '0.0.2'),
  null,
  'Unknown channel must fail closed'
);

// Strict helper getReleaseChannel & classifyVersionChannel verification
assert.equal(classifyVersionChannel('0.0.13'), 'stable');
assert.equal(classifyVersionChannel('0.0.12-beta.1'), 'beta');
assert.equal(classifyVersionChannel('0.0.12-rc.1'), null);
assert.equal(classifyVersionChannel('not-a-version'), null);

assert.equal(getReleaseChannel('0.0.13', false), 'stable');
assert.equal(getReleaseChannel('0.0.12-beta.1', true), 'beta');
assert.equal(getReleaseChannel('0.0.13', true), null);
assert.equal(getReleaseChannel('0.0.12-beta.1', false), null);
assert.equal(getReleaseChannel('0.0.12-rc.1', true), null);
assert.equal(getReleaseChannel('0.0.13', 'false'), null, 'String prerelease must fail closed');
assert.equal(getReleaseChannel('0.0.13', null), null, 'Null prerelease must fail closed');
assert.equal(getReleaseChannel('0.0.13', undefined), null, 'Missing prerelease must fail closed');
assert.equal(getReleaseChannel('0.0.13', 0), null, 'Numeric prerelease must fail closed');

// Negative fixtures: malformed release objects, missing/string/null prerelease or draft, non-array assets
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14', draft: false, assets: [{ name: 'latest.yml' }] }], 'stable', '0.0.12'), null, 'Missing prerelease must be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14', prerelease: null, draft: false, assets: [{ name: 'latest.yml' }] }], 'stable', '0.0.12'), null, 'Null prerelease must be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14-beta.1', prerelease: 'false', draft: false, assets: [{ name: 'beta.yml' }] }], 'beta', '0.0.12-beta.1'), null, 'String prerelease must be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14-beta.1', prerelease: 0, draft: false, assets: [{ name: 'beta.yml' }] }], 'beta', '0.0.12-beta.1'), null, 'Numeric 0 prerelease must be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14', prerelease: false, assets: [{ name: 'latest.yml' }] }], 'stable', '0.0.12'), null, 'Missing draft must be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14', prerelease: false, draft: 'false', assets: [{ name: 'latest.yml' }] }], 'stable', '0.0.12'), null, 'String draft must be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14', prerelease: false, draft: null, assets: [{ name: 'latest.yml' }] }], 'stable', '0.0.12'), null, 'Null draft must be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14', prerelease: false, draft: true, assets: [{ name: 'latest.yml' }] }], 'stable', '0.0.12'), null, 'Draft true must be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14', prerelease: false, draft: false, assets: null }], 'stable', '0.0.12'), null, 'Null assets must not throw and be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14', prerelease: false, draft: false, assets: { name: 'latest.yml' } }], 'stable', '0.0.12'), null, 'Non-array object assets must not throw and be excluded');
assert.equal(selectSetupRelease([{ tag_name: 'v0.0.14', prerelease: false, draft: false }], 'stable', '0.0.12'), null, 'Missing assets must not throw and be excluded');

// Clean up temp dir
try { fs.rmSync(root, { recursive: true, force: true }); } catch { /* best effort */ }

console.log('Setup channels verified: persisted preference, delta-only exclusion, strict isolation, and downgrade prevention.');
