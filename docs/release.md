# POS release publishing

## Two delivery repositories

- ABDO-TECK/pos-desktop: source and complete Windows Setup releases only.
- ABDO-TECK/pos-desktop-updates: signed per-baseline deltas for stable and beta.

Setup 0.0.1 embeds application update revision 0.0.8. package.json and both lockfiles identify the shell/installer; version.json identifies the monotonic application revision and declares installer_version. Never decrement application revisions to rename an installer.

## Full Setup

Run Windows Setup release on the source commit. Leave publish false to build and validate an artifact first. The verified artifact includes the installer, blockmap, latest.yml, SHA256SUMS and deployment-inventory.json. Publishing requires the matching installer tag at that commit. New full Setup versions should exceed deployed application revisions to preserve the full-updater downgrade policy.

The initial replacement v0.0.1 tag is archived before reuse. Its immutable source baseline alias v0.0.8 identifies the actual revision used by delta builds. The updates repository stores this inventory in baselines/v0.0.8/deployment-inventory.json, outside Releases. Build-only metadata never appears in client discovery; ordinary delta inventories remain attached to their own version releases.

## Stable and beta deltas

Keep package versions and all tracked Electron/build/runtime shell inputs unchanged from the supported Setup baseline. Set version and application_version to an increasing revision, release_type to delta, channel to stable or beta, and delta_baseline_tags to explicit source tags. Commit and push the source tag (vX.Y.Z or vX.Y.Z-beta.N).

In pos-desktop-updates run Signed delta release: source_ref is the exact source tag/commit, tag is its application revision tag, publish controls public publication. That workflow checks out public source from pos-desktop, downloads baseline inventories from pos-desktop-updates, builds/signs/verifies each package, and publishes with the updates repository's GITHUB_TOKEN. No cross-repository write credential is required. The encrypted UPDATE_PRIVATE_KEY secret must correspond to the pinned public key.

Stable devices receive stable only; beta devices can receive beta and newer stable. update_channel.json is device state and is excluded from updates. Existing explicit pos/pos-desktop update endpoint settings are normalized in new code to pos-desktop-updates without changing device files. Custom repository settings remain supported.

Previous demonstration delta releases were archived offline and cleared at the owner's request after validating the complete Setup. The update repository retains only the current Setup baseline inventory and publishing configuration. Publish future releases for actual features or repairs; validation runs should leave publish false. Application revisions must remain monotonic: the next unused revision after the retired demonstration tags is 0.0.10 (or 0.0.10-beta.1), based on source tag v0.0.8.

## Integrity

Only changed built artifacts enter the ZIP. Explicit obsolete managed paths enter deleted_files. The installed baseline, payload hashes, release channel and RSA signature are checked. PHP is deployed as one backend/backend.phar artifact; this is file-level delta, not an internal PHAR binary patch. Unchanged files retain their bytes, timestamps and file identities. Business databases, device configuration, credentials and device printing certificates are excluded. Electron or runtime changes require a complete Setup.

Run verify-release-identity.mjs, verify-release-channels.php, verify-update-channel-policy.cjs and verify-delta-flow.mjs before publishing. Acceptance must also exercise actual signed GitHub assets against an isolated packaged copy before removing prior distribution assets.


