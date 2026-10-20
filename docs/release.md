# Publishing POS Desktop updates

All Setup and delta releases use **ABDO-TECK/pos-desktop**. The supported publishers are GitHub Actions workflows `Windows Setup release` and `Signed delta release`. Historical v1 release guides and GUI scripts are not the publisher for this v0 repository.

| Channel | Tag | GitHub release |
| --- | --- | --- |
| stable | v0.0.3 | Normal release |
| beta | v0.0.3-beta.1 | Prerelease |
| rc | v0.0.3-rc.1 | Prerelease |

Stable devices accept stable only. Beta devices accept beta, rc and stable. The device preference is persisted separately in `update_channel.json`, which a release never replaces. Discovery reads Releases on this repository, rather than the version on main.

## Setup baseline

Use Setup for changes to Electron, npm runtime dependencies, packaging or portable engines. Set the same complete version in version.json, root/frontend package.json and their lockfiles. Set `release_type: setup`, the tag's `channel`, and the intended default `update_channel`. Commit, tag that commit, and push the branch and tag.

The Setup workflow builds and smoke-tests the packaged app on a clean Windows runner before publishing. It also publishes `deployment-inventory.json`, generated from actual app.asar.unpacked files and tracked desktop shell inputs.

Install **v0.0.2 Setup** before using this improved delta protocol. The original v0.0.1 has no published inventory and lacks the updated shell/physical version-file layout. Current Windows installers are unsigned Authenticode; RSA delta signing is separate and does not sign the Windows EXE.

## Beta delta

Keep root/frontend package versions and lockfiles at their last Setup version. Change application code and version.json, for example:

```json
{
  "version": "0.0.3-beta.1",
  "application_version": "0.0.3-beta.1",
  "update_engine_version": "1.0.0",
  "channel": "beta",
  "release_type": "delta",
  "delta_baseline_tags": ["v0.0.2"],
  "released_at": "2026-10-20",
  "changelog": ["Describe actual changes"]
}
```

Commit, tag that exact commit v0.0.3-beta.1 and push it. CI downloads each baseline's inventory from its published release, verifies its version and unchanged desktop shell, builds frontend/PHAR, compares deployed SHA-256 hashes, and signs each baseline manifest using the UPDATE_PRIVATE_KEY repository secret. A missing or mismatched key fails the job. The private key never enters source, ZIPs, logs or artifacts.

For each baseline the release contains:

- manifest-0.0.2.json and manifest-0.0.2.sig
- delta-0.0.2-to-0.0.3-beta.1.zip

It also contains the target deployment-inventory.json for future releases. Delta releases do not become GitHub's latest full Setup release. The app selects a delta for its channel and exact installed baseline. The separate full updater selects releases containing installer metadata.

## Promote to stable

For v0.0.3, set matching version fields, `channel: stable`, `release_type: delta`, and support both starting points:

```json
"delta_baseline_tags": ["v0.0.2", "v0.0.3-beta.1"]
```

CI builds distinct signed packages for both. Keep older starting points listed if they should update directly; another baseline's package is never offered instead. Different shell fingerprints require a new Setup.

## File replacement and verification

Only files with different built bytes enter the ZIP. Removed managed files appear explicitly in deleted_files. Unchanged files are omitted; both apply engines also skip already-matching targets. Installed baseline hashes, payload hashes, RSA signature, release version and channel are checked before applying. Databases, device configuration, credentials and portable engines are outside delta targets.

PHP code/migrations ship as one **backend/backend.phar** file. A PHP change replaces that artifact as a unit. This is a file-level delta, not a binary patch within PHAR/EXE files. Electron changes require Setup.

```powershell
$env:PHP_BINARY = 'C:\xampp\php\php.exe' # or PHP on PATH
& $env:PHP_BINARY scripts/verify-release-channels.php
node scripts/verify-update-channel-policy.cjs
node scripts/verify-delta-flow.mjs
```

Isolated acceptance builds/signs real ZIPs for stable, beta and beta-to-stable promotion; stages via PHP; applies via PHP and Electron handoff; checks exact resulting hashes and unchanged artifact/data hashes, timestamps and identity. It tests new/deleted files, rollback, corrupt signature/payload rejection and wrong baselines. It never opens the live app/database.

For a complete build/sign/validation without publishing, run Signed delta release manually against an existing delta tag with `publish` unchecked.
