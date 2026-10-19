# Release Process

## Reproducible release contract

From a checkout with PHP, Composer, Node.js, and Windows build tools installed:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\release.ps1 -Mode build -NonInteractive
```

This installs locked Composer development dependencies from
`backend/composer.lock` so the quality suite can run, executes `npm ci` from
both package-lock files, and runs all quality checks. It then reinstalls locked
Composer production dependencies with `--no-dev`, builds the frontend and
production PHAR, and creates the NSIS Electron installer. A failed command
stops the pipeline with a non-zero exit code. Secrets are never embedded;
publishing uses only the `GH_TOKEN` environment variable.

`backend/backend.phar` is generated and is not tracked by Git. Publish/store it
with the installer release assets and its trusted SHA-512 value. Verify the
embedded PHAR signature with the build PHP runtime:

```powershell
php --% -r "$p='backend/backend.phar'; $f=new Phar($p); $s=$f->getSignature(); if ($s['hash_type'] !== 'SHA-512') { exit(1); } echo $s['hash'], PHP_EOL;"
```

Compare that output with the value published for the release. SHA-512 verifies
integrity, not publisher authenticity.

Use `scripts/release.ps1` to prepare POS desktop releases. A valid release keeps the Electron runtime version, backend update metadata, PHAR, installer, and GitHub Release artifacts in sync.

## GUI Release Manager

For day-to-day release work, use the developer-only WinForms Release Manager:

```powershell
powershell -NoProfile -STA -ExecutionPolicy Bypass -File .\scripts\release-gui.ps1
```

The GUI is an orchestration layer over `scripts/release.ps1`; the command script remains the source of truth for release actions.

### GUI Tabs

- `Status`: shows package/version sync, package-lock version, Git branch, dirty state, GH_TOKEN, PHAR, dist, latest.yml, installer/blockmap, and whether `POS System.exe` is running.
- `Version`: previews patch/minor/major/custom version changes, syncs `package.json`, `package-lock.json` when present, and `version.json`, and can update `released_at`.
- `Build`: runs frontend, PHAR, Electron, full local build, clean, and artifact verification commands with log output.
- `Commit`: classifies changed files, blocks unsafe paths, requires a commit message, and commits only selected safe or warning files.
- `Publish`: runs repo-only, Electron-only, all, or push flows after confirmation.
- `Rollback`: safely reverts already-pushed commits by creating new `git revert` commits. It does not rewrite shared history.
- `Verify`: verifies local artifacts, PHAR contents, or GitHub Release assets. If `GH_TOKEN` is missing, it prints the manual release URL.
- `Logs`: command logs are shown in the lower pane for all tabs with timestamps, stdout, stderr, and exit codes.

### Commit Classifications

The GUI and release script use `scripts/release-manager.config.json` for commit classification.

- `Safe`: normal source/docs/tooling files. These are selectable.
- `Warning`: selectable, but review first because the file is release metadata, generated, or binary-like.
- `Blocked`: never selectable and never passed to `git add`, even if explicitly requested.

Use `Select Safe` for ordinary commits. Use `Select Safe + Warning` only when intentionally committing release metadata or reviewed generated artifacts.

Default blocked patterns:

- `dist-electron/**`
- `node_modules/**`
- `vendor/**`
- `tmp/**`
- `scratch/**`
- `backend/.phpunit.result.cache`
- `backend/storage/**`
- `backend/logs/**`
- `backend/temp/**`
- `backend/tmp/**`
- `backend/cache/**`
- `backend/backups/**`
- `backend/runtime/**`
- `backend/uploads/**`
- `.env`
- `.env.*`
- `*.log`
- `*.tmp`
- `*.sqlite`
- `*.db`
- `*.bak`
- `*.cache`

Default warning patterns:

- `backend/backend.phar`
- `package-lock.json`
- `package.json`
- `version.json`
- Electron-builder generated artifact patterns
- binary/archive-like patterns such as `*.exe`, `*.dll`, `*.bin`, `*.zip`, `*.7z`, `*.tar`, `*.gz`

Default safe patterns include:

- `frontend/src/**`
- frontend config/test files
- `electron/**`
- backend source folders such as `config`, `middleware`, `cli`, `WebSocket`, services, controllers, routes, repositories, models, and tests
- `backend/services/**`
- `backend/controllers/**`
- `backend/routes/**`
- `backend/tests/**`
- `scripts/**`
- `docs/**`
- `backend/certs/**`
- `README.md`

To add a new blocked pattern, edit `scripts/release-manager.config.json` and add it to `blockedCommitPatterns`. Blocked patterns take precedence over warning and safe patterns.

### Recommended GUI Flows

Commit-only flow:

1. Open the GUI.
2. Refresh `Status`.
3. Open `Commit`.
4. Refresh files.
5. Select safe files, or safe + warning files after review.
6. Enter a commit message.
7. Click `Commit Selected`.

Build-only flow:

1. Open `Build`.
2. Run `Build Frontend`, `Build PHAR`, `Build Electron`, or `Full Local Build`.
3. Run `Verify Artifacts`.

Publish flow:

1. Confirm `Status` shows synced versions and expected artifacts.
2. Set `GH_TOKEN` before Electron or full publish.
3. Use `Repo Only` for commit/tag/push without GitHub Release publishing.
4. Use `Electron Only` for build + GitHub Release publishing.
5. Use `All` for build + commit + tag + push + publish.

Rollback flow:

1. Open `Rollback`.
2. Click `Refresh Commits`.
3. Select the commit that should be undone.
4. Click `Revert Selected`.
5. Confirm the summary.
6. If the revert succeeds, review the new rollback commit.
7. Click `Push Rollback Commit` only after review.

For multiple commits, enter `From` and `To` hashes and click `Revert Range`. The command uses `git revert`, so it creates new commit(s) instead of deleting pushed history.

If conflicts happen:

1. Resolve the conflicts manually in the working tree.
2. Use `Continue Revert` to finish, or `Abort Revert` to cancel.
3. Do not push until the rollback commit is complete and reviewed.

Never commit runtime data, logs, environment files, caches, database files, backups, uploads, `node_modules`, `vendor`, or `dist-electron` artifacts.

## Usage

```powershell
powershell -ExecutionPolicy Bypass -File scripts/release.ps1 -Version X.Y.Z
powershell -ExecutionPolicy Bypass -File scripts/release.ps1 -Version X.Y.Z -Mode build
powershell -ExecutionPolicy Bypass -File scripts/release.ps1 -Version X.Y.Z -Mode repo
powershell -ExecutionPolicy Bypass -File scripts/release.ps1 -Version X.Y.Z -Mode electron
powershell -ExecutionPolicy Bypass -File scripts/release.ps1 -Version X.Y.Z -Mode all
```

## Modes

- `build`: updates version files, builds the frontend, rebuilds `backend/backend.phar`, and builds the NSIS installer. It does not push git changes or publish a GitHub Release.
- `repo`: updates version files, builds the frontend and PHAR, commits release files, tags `vX.Y.Z`, and pushes the commit and tag. It does not publish Electron artifacts.
- `electron`: updates version files, builds the frontend, PHAR, and NSIS installer, then publishes Electron artifacts with `electron-builder`. It does not push source commits, and it refuses to publish if `package.json` or `version.json` has uncommitted changes.
- `all`: runs the full source and Electron release flow in this order: version update, frontend build, PHAR build, Electron installer build, artifact verification, git commit, git tag, push commit, push tag, and GitHub Release publish.

## GitHub Token

Only publishing modes require `GH_TOKEN`: `electron` and `all`.

```powershell
$env:GH_TOKEN = '<github-token-with-release-access>'
```

The script refuses `electron` and `all` modes when `GH_TOKEN` is missing.

## Version Sync

`package.json` is the Electron runtime version source used by `app.getVersion()`. `version.json` is the backend update-check metadata read by `/api/v1/update/check`. Keep both files on the same `X.Y.Z` version so the installed app, GitHub detection, and Electron updater all agree.

The release manager also updates configured text version references from `scripts/release-manager.config.json`, including the `README.md` version badge.

Configured text files are read and written as UTF-8 so Arabic documentation remains readable when version references are updated.

## PHAR Before Packaging

`backend/backend.phar` must be rebuilt before Electron packaging because the packaged app ships the PHAR. The script verifies that the PHAR contains:

- `version.json`
- `certs/cacert.pem`

The CA bundle is required for packaged GitHub HTTPS checks.

## Electron Artifacts

The script verifies the release output contains:

- `dist-electron/latest.yml`
- NSIS installer `.exe` for the requested version
- matching `.blockmap`
- `dist-electron/win-unpacked/resources/app.asar.unpacked/backend/backend.phar`
- `dist-electron/win-unpacked/resources/app.asar.unpacked/backend/certs/cacert.pem`

`electron-updater` needs `latest.yml`, the installer, and the blockmap attached to the GitHub Release.

## Git Hygiene

The script warns if tracked `backend/.phpunit.result.cache` has local modifications. Do not commit that cache file unless the change is intentional; it is usually test-run noise.

## Installed Update Verification

Electron-updater must be verified from an installed previous NSIS version. Do not use `dist-electron/win-unpacked` for final update verification, because win-unpacked does not exercise the installed updater path.

To verify an update:

1. Install the previous version normally from its NSIS installer.
2. Publish the new release with `scripts/release.ps1 -Version X.Y.Z -Mode electron` or `-Mode all`.
3. Open the installed previous version.
4. Go to Settings > System & Maintenance.
5. Click Check Updates and confirm the backend checker sees the new version.
6. Click `تحديث الآن`.
7. Confirm download progress, `ready_to_install`, and the restart confirmation.
8. Approve restart/install.
9. After relaunch, confirm `app.getVersion()` reports the new version and Check Updates reports no update available.
