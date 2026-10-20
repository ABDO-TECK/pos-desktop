# POS release publishing

## Two delivery repositories

- ABDO-TECK/pos-desktop: source and complete Windows Setup releases only.
- ABDO-TECK/pos-desktop-updates: signed per-baseline deltas for stable and beta.

The corrected complete Setup, application metadata and displayed version are all 0.0.1. Full Setup builds reject mismatched application/installer versions. Delta releases retain their own increasing application version while the installed shell/package version remains fixed.

## Full Setup

Run Windows Setup release on the source commit. Leave publish false to build and validate an artifact first. The verified artifact includes the installer, blockmap, latest.yml, SHA256SUMS and deployment-inventory.json. Publishing requires the matching installer tag at that commit. New full Setup versions should exceed deployed application revisions to preserve the full-updater downgrade policy.

The previous v0.0.1 tag and installer assets are archived before replacement. The corrected v0.0.1 tag identifies this complete Setup and its matching baseline inventory lives in baselines/v0.0.1/deployment-inventory.json in the updates repository. Old v0.0.8 shell inventories cannot be used for this rebuilt executable. Build-only metadata stays outside Releases.

## Stable and beta deltas

Keep package versions and all tracked Electron/build/runtime shell inputs unchanged from the supported Setup baseline. Set version and application_version to an increasing revision, release_type to delta, channel to stable or beta, and delta_baseline_tags to explicit source tags. Commit and push the source tag (vX.Y.Z or vX.Y.Z-beta.N).

In pos-desktop-updates run Signed delta release: source_ref is the exact source tag/commit, tag is its application revision tag, publish controls public publication. That workflow checks out public source from pos-desktop, downloads baseline inventories from pos-desktop-updates, builds/signs/verifies each package, and publishes with the updates repository's GITHUB_TOKEN. No cross-repository write credential is required. The encrypted UPDATE_PRIVATE_KEY secret must correspond to the pinned public key.

Stable devices receive stable only; beta devices can receive beta and newer stable. update_channel.json is device state and is excluded from updates. Existing explicit pos/pos-desktop update endpoint settings are normalized in new code to pos-desktop-updates without changing device files. Custom repository settings remain supported.

Previous demonstration delta releases were archived offline and cleared at the owner's request. The update repository retains the current Setup baseline inventory and publishing configuration. Publish future releases only for actual features or repairs; validation runs leave publish false. Use the next unused revision 0.0.10 (or 0.0.10-beta.1), based on the corrected source tag v0.0.1. The rebuilt shell requires this exact baseline; do not advertise it to older demonstration shells.

## Installation and removal

Manual Windows uninstall removes the POS-managed database, settings, device keys, caches and shortcuts. A fresh reinstall creates an empty database and displays a newly generated temporary administrator password. Upgrading via Setup passes --updated to the previous uninstaller and preserves data. Shared Microsoft runtimes and independently installed QZ components are retained. POS-created shared QZ trust is removed only using its recorded certificate fingerprint. Cleanup rejects junctions/reparse points and reports failure rather than claiming complete removal when access is denied.

The Windows runner executes the actual installer, checks the displayed version and temporary-password dialog, upgrades while preserving data, uninstalls, reinstalls with a different temporary password, and uninstalls again. EXE resources and desktop shortcuts are checked against the POS icon. This destructive acceptance script refuses to run outside an ephemeral GitHub runner.

## Integrity

Only changed built artifacts enter the ZIP. Explicit obsolete managed paths enter deleted_files. The installed baseline, payload hashes, release channel and RSA signature are checked. PHP is deployed as one backend/backend.phar artifact; this is file-level delta, not an internal PHAR binary patch. Unchanged files retain their bytes, timestamps and file identities. Business databases, device configuration, credentials and device printing certificates are excluded. Electron or runtime changes require a complete Setup.

Run verify-release-identity.mjs, verify-release-channels.php, verify-update-channel-policy.cjs and verify-delta-flow.mjs before publishing. Acceptance must also exercise actual signed GitHub assets against an isolated packaged copy before removing prior distribution assets.


