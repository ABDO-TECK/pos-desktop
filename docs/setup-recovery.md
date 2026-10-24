# Windows Setup Recovery Guide (v0.0.11)

## 1. Executive Summary & Artifact Identity

This document defines the delivery, installation, and recovery procedure for the Windows Setup installer delivering Electron delta handoff hardening, MariaDB catalog trigger DEFINER healing fixes, and responsive Admin Update Center improvements.

- **Build Identity**: `0.0.11`
- **Release Channel**: `stable`
- **Release Type**: `setup` (Full Installer)
- **Target Artifact**: `dist-electron/POS-Desktop-Setup-0.0.11.exe`
- **Date**: `2026-10-25`
- **Application ID (`appId`)**: `com.abdoteck.pos` (Strictly preserved, compatible with `0.0.1`)
- **Product Name**: `POS Desktop`

### Verification Status & Readiness Limitations
- **Local Unsigned Status**: Source and metadata prepared in a local clean worktree. heavy builds, signing, runtime verification, installer packaging, and upgrade tests are strictly restricted to GitHub Actions.
- **NOT Ready for Customer Rollout without CI Acceptance**: This artifact is **NOT ready for general customer rollout** until an ephemeral Windows GitHub Actions runner passes end-to-end upgrade preservation acceptance testing migrating an actual old `0.0.1` installation (`POS-Desktop-Setup-0.0.1.exe`) to this new Setup. The original lifecycle verification script (`scripts/verify-installer-lifecycle.ps1`) only tests reinstalling the same new version, which is **not** proof of successful preservation during an old `0.0.1` -> new upgrade. The lifecycle script's environment guards (`GITHUB_ACTIONS` / data safety checks) remain strictly preserved.
- **Scope of Packaging Verification**: Packaging this Setup build verifies that the delivery mechanism correctly packages checked repository files and patterns into the ASAR archive, PHAR bundle, and NSIS installer without packaging forbidden private files. It does **not** guarantee that packaging "heals the runtime contract" or that any customer machine has been repaired or restored without applying controlled, verified support procedures. No runtime fix has been verified on a live customer machine.
- **Untested Real Lifecycle on Local Host**: The installer executable has not been executed on this developer workstation. In accordance with safety policies, live installation, upgrade, uninstallation, and packaged UI smoke testing are prohibited on developer workstations to protect local runtime databases, preferences, and credentials.

---

## 2. Remediations Packaged in v0.0.11

This Setup build packages the following cumulative remediations:
1. **Electron Delta Handoff & Rollback Hardening (`electron/services/delta-update-handoff.js`, `electron/services/php-server.js`, `electron/main.js`)**:
   - Bounded, awaitable termination of POS-owned runtime processes (`php.exe`) prior to file replacement, mitigating process file-lock contention during updates (note: third-party antivirus scanners or external indexing processes may still cause transient locks; bounded retries are applied).
   - Unique per-operation temporary filenames preventing temp-file collision between apply and rollback phases in the same process.
   - Target directory write permission probes upfront returning structured `install_permission_required` without touching installed files.
   - Safe `FILE_ATTRIBUTE_READONLY` stripping on owned temporary files and preservation on managed targets.
   - Coordinated fault-tolerant rollback preserving primary apply errors alongside rollback errors, preventing silent masking.
2. **MariaDB Catalog Trigger DEFINER Healing (`backend/Services/MigrationService.php`, `backend/Services/ProductService.php`, `backend/cli/migrate.php`)**:
   - Elimination of insecure definer prefix matching; triggers are reconciled to match `CURRENT_USER()` (the actual administrative migration connection identity executing the CLI migration, such as `pos_migration@127.0.0.1`).
   - Fast-skip migration path propagates legacy artifact reconciliation failures rather than masking them.
   - `backend/cli/migrate.php` checks `$result['errors']` before `$result['skipped']`, exiting non-zero on failure.
   - Complete removal of dangerous `emergencyBypassCatalogTriggers()` drop-and-bypass logic in `ProductService`.
3. **Responsive Admin Update Center (`backend/Services/BackgroundUpdateApplyService.php`, `backend/Controllers/UpdateController.php`, `frontend/src/pages/settings/UpdateSection.tsx`, etc.)**:
   - Immediate local installed version and channel-independent remote checks.
   - Queued asynchronous background apply so HTTP polling stays responsive during delta installation.
   - Honest byte download progress reporting and indeterminate verification/install phase indicators.
   - Stale check defense, unknown release admission, and persistent handoff state tracking.
4. **Private SSL Assets Exclusion in Package Configuration (`package.json`)**:
   - Packaging filters explicitly exclude `electron/assets/ssl/**` and `!**/*.key` to ensure development certificates and private keys are never bundled into the distributed ASAR archive.
   - The production HTTPS proxy (`electron/services/https-proxy.js`) dynamically generates required LAN SSL certificates and keys at runtime in the writable `userData/ssl` directory; existing proxy code requires no runtime modification.

---

## 3. Mandatory Installation Protocol (In-Place Upgrade)

### Install OVER THE SAME Installation
When updating an existing terminal:
1. **Match Existing Windows Account, Scope, and Path**:
   - The installer MUST be run over the existing installation directory (e.g. `C:\Program Files\POS System\POS Desktop` for per-machine installs, or `%LOCALAPPDATA%\Programs\POS Desktop` for per-user installs).
   - If the previous version was installed per-machine (All Users), install with matching per-machine scope. If installed per-user, install with matching per-user scope.
2. **Elevation is for the Installer Only — DO NOT Launch Elevated**:
   - Run the installer executable with administrative elevation (**Run as Administrator**) to permit NSIS to overwrite files in `C:\Program Files` and update machine registry keys.
   - **CRITICAL**: On the final installer completion screen, **UNCHECK** the option to launch "POS Desktop" (`Run POS Desktop`).
   - **Reason**: The application's data directory selection logic (`getDataDir()` in `electron/utils/paths.js`) chooses between `C:\ProgramData\POS System` and `%LOCALAPPDATA%\POS System\Data` based on user-level write permissions. Launching the desktop shell elevated alters the permission context, which can cause the app to select a different data path than the standard user account, making existing data appear lost or missing.
   - **Post-Install Launch**: Launch the application normally from the Start Menu or Desktop shortcut as the **original standard Windows user account**. Verify the active data directory path in the application logs before processing business transactions.
   - **Prohibition**: Do not perform manual file moves, database resets, or credential regenerations.

### STRICT WARNING: DO NOT UNINSTALL
- **NEVER** run the Windows uninstaller or "Add or Remove Programs" prior to installing this update.
- **NEVER** delete or clear `mysql_data`, `storage`, or configuration files.
- **Architectural Reason**: In `build/installer.nsh`, the custom uninstallation hook contains an `${IfNot} ${isUpdated}` guard:
  ```nsis
  !macro customUnInstall
    ${IfNot} ${isUpdated}
      nsExec::ExecToStack '"$R2" ... "$INSTDIR\resources\uninstall-data.ps1" ...'
    ${EndIf}
  !macroend
  ```
  - When the Setup installer runs over an existing installation, electron-builder invokes the previous uninstaller with the `--updated` flag, causing `${isUpdated}` to evaluate to true and **skipping** `uninstall-data.ps1`.
  - If an operator manually uninstalls first, `${isUpdated}` is false; `uninstall-data.ps1` runs and permanently wipes `%PROGRAMDATA%\POS System`, `%APPDATA%\POS System`, customer MariaDB databases, and device printing trust certificates!

---

## 4. Pre-Upgrade Quiesced Backup Procedure

Before applying `POS-Desktop-Setup-0.0.11.exe` over an existing deployment, an administrator must take a complete, quiesced offline backup:

1. **Gracefully Stop Owned POS Services ONLY**:
   - Only shut down POS-owned processes (`POS Desktop.exe`, POS-spawned `php.exe`, POS-spawned `mysqld.exe`, and POS-spawned `qz-tray.jar`).
   - Do **NOT** terminate arbitrary, unrelated PHP or MySQL services running on the developer or customer machine (such as external XAMPP, IIS, or system-wide database servers).
   - Ensure the POS MariaDB instance has completely shut down; **never** copy an active, running MariaDB data directory.
2. **Backup Complete ACTUAL Active Data Root**:
   - Identify the actual active data directory from runtime application logs, configuration files, or the `--datadir` process argument. Paths may vary depending on installation history:
     - Primary: `C:\ProgramData\POS System\`
     - Fallback: `%LOCALAPPDATA%\POS System\Data\`
     - Custom: Configured location indicated by runtime logs.
   - Backup the entire active directory tree, including:
     - The shutdown `mysql_data/` directory.
     - `storage/`, `uploads/`, and `backups/`.
     - Update staging and snapshot folders (`update-backups/`, containing `migration_safety_backup.sql`).
3. **Backup Full Configuration & Secret Directory**:
   - Backup `%APPDATA%\POS System\` (and `%PROGRAMDATA%\POS System\` if present) in full, including:
     - `db_credentials.json`
     - `.env`
     - Recovery authentication and runtime metadata (`recovery_auth.json`, update state files).
   - Securely copy shared printing trust certificates (`%PROGRAMDATA%\qz`) and actual device SSL certificates and private keys from `%APPDATA%\POS Desktop\ssl\` or `userData/ssl`.
   - **Confidentiality Rule**: Store these backups securely; never print, log, or expose secret credentials in reports or public channels.
4. **Offline Verification & Best-Effort Nature**:
   - Verify backup archive integrity, file readability, and file access control lists (ACLs) offline before proceeding.
   - **No Zero-Loss Guarantee**: Because MariaDB DDL operations execute implicit commits and cannot be undone via ACID transactional rollback, pre-migration SQL dumps and file snapshots are best-effort safeguards. Complete recovery cannot be promised without inspecting database table consistency directly.

---

## 5. Handling Mixed beta.8 & `recovery_required` Installations

If a customer terminal previously experienced a failed delta update from `0.0.1` to `0.0.10-beta.8` (e.g. an `EPERM` rollback failure):
- The terminal may be in a mixed-state or `recovery_required` state where some files belong to `0.0.1` and others to `beta.8`.
- **Specialized Support Inspection First**: Support personnel must inspect the installation state, runtime logs, and database status before taking corrective action.
- Installing `POS-Desktop-Setup-0.0.11.exe` in place cleanly replaces the entire application shell, packaged runtime binaries, and `backend.phar` without wiping the customer's `mysql_data` directory.
- Upon first launch as the standard user, the privileged CLI migration step reconciles catalog triggers to match `CURRENT_USER()` (the actual administrative migration connection identity; for example, `pos_migration@127.0.0.1`).

---

## 6. Prohibited Remediation Tools

- **Do NOT recommend or run `scripts/update-repair/Repair-POS.ps1`**:
  - `Repair-POS.ps1` invokes `repair.php`, which was written specifically for `0.0.10-beta.2` from baselines `0.0.1` or `beta.1`.
  - It explicitly rejects any payload with database migrations (`RuntimeException: Repair requires a baseline-specific delta without migrations`).
  - It does not support custom MariaDB data directories or trigger DEFINER reconciliation.
  - Running it on a newer system will fail and risks leaving the environment in an invalid state.

---

## 7. Package Verification Evidence

The built setup package is verified using:
```powershell
node scripts/verify-setup-recovery.mjs
```
This automated verification validates:
1. Exact byte equality between repository source files and shipped ASAR entries:
   - `electron/main.js`
   - `electron/services/delta-update-handoff.js`
   - `electron/services/php-server.js`
2. PHAR compilation integrity via bundled PHP:
   - `Services/MigrationService.php` in `backend.phar` matches repository source.
   - `Services/ProductService.php` in `backend.phar` matches repository source.
   - `cli/migrate.php` in `backend.phar` matches repository source.
   - `Services/BackgroundUpdateApplyService.php` in `backend.phar` matches repository source.
   - `Controllers/UpdateController.php` in `backend.phar` matches repository source.
   - `Services/DeltaUpdateService.php` in `backend.phar` matches repository source.
   - `Services/UpdateAssetDownloader.php` in `backend.phar` matches repository source.
   - `Services/UpdateService.php` in `backend.phar` matches repository source.
   - `Helpers/JobQueue.php` in `backend.phar` matches repository source.
   - `Services/ChannelTransitionService.php` in `backend.phar` matches repository source.
3. Verification of checked exclusion patterns in ASAR and portable trees:
   - Private keys (`*.key`, `*.p12`, `*.pfx`), `.env` files, credentials, and runtime data folders (`mysql_data`, `storage`, `logs`) are strictly excluded.
   - Public CA certificates required for HTTPS verification (`backend/certs/cacert.pem`, `update_public_key.pem`) are intentionally shipped and verified.
   - Scoped allowlist for vendor runtime configurations: only legitimate pinned Java and QZ vendor properties files (`java/conf/logging.properties`, `java/conf/management/management.properties`, `java/conf/net.properties`, `java/conf/sound.properties`, `java/lib/psfontj2d.properties`, `qz-tray/qz-tray.properties`) are permitted, while private keys, override certificates, and runtime data directories remain strictly banned.
4. Verification of the `${IfNot} ${isUpdated}` safety guard in `build/installer.nsh` and unchanged `appId` (`com.abdoteck.pos`).

---

## 8. True End-to-End Upgrade Preservation Verification

In addition to package inspection, true end-to-end upgrade preservation is automated and verified on an ephemeral Windows GitHub Actions runner via:
```powershell
./scripts/verify-original-setup-upgrade.ps1
```

This automated test validates:
1. **Runner Environment & Path Boundary Safety**:
   - Strictly enforces `GITHUB_ACTIONS -eq 'true'` and `RUNNER_TEMP` environment guards; prohibited on developer workstations.
   - Asserts no pre-existing POS data exists across cleanup targets prior to testing.
   - Validates owned temporary install and evidence directories are strictly within `RUNNER_TEMP` and contains no unsafe reparse points or junctions.
2. **Original Baseline Installation**:
   - Downloads original `POS-Desktop-Setup-0.0.1.exe` and verifies SHA256 checksum (`2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a`).
   - Installs original `0.0.1` silently into the isolated test path.
3. **Alive SQL Fixture Seeding, Compatibility Preparation & Offline Quiesced Snapshot**:
   - Launches original `0.0.1` UI and, while the runtime is alive and active, executes `scripts/fixtures/channel-transition-capability.php 'prepare-compatibility,seed'` via `scripts/verify-first-run-ui.mjs`.
   - Prepares migration `059_add_update_compatibility_metadata.sql` and snapshots baseline normalized `schema_sha256` and `ledger_sha256` via `UpdateDataCompatibilityService`.
   - Seeds customer business rows and settings, snapshotting baseline raw `schema_sha256`, raw migration ledger, customer rows (`id, name, phone, initial_balance, loyalty_points`), and settings (`key, value`).
   - Snapshots a SHA256 identity digest of the active administrator record (`admin_digest`: hash of `id, name, email, password, role`) without logging or exposing secret credentials.
   - Snapshots SHA256 hashes of `db_credentials.json` and `.env` (using generic mismatch assertions without logging sensitive hashes).
   - Discovers the real printing ownership receipt (`qz-shared-install.json`) across POS cleanup targets, verifies its root certificate installation in the Windows Enterprise root store (`HKLM\SOFTWARE\Microsoft\EnterpriseCertificates\Root\Certificates\<thumbprint>`), and snapshots hashes of all owned printing files.
   - Requires and snapshots the actual runtime `userData/qz-certs/private-key.pem` and `digital-certificate.pem` certificate pair generated upon printing service initialization, plus the LAN HTTPS proxy `userData/ssl/server.key` and `server.crt` pair when enabled. Strictly prohibits zero-private-key passes.
   - Writes a business sentinel file containing a unique GUID.
4. **In-Place Upgrade & Live SQL Inspection**:
   - Executes the new Setup `0.0.11` installer silently over the exact same installation path.
   - Launches the upgraded UI and verifies version `0.0.11`, existing admin retention (`newAdmin: false`), and absence of first-run password modals.
   - While the upgraded runtime is alive, executes `scripts/fixtures/channel-transition-capability.php 'inspect-compatibility,inspect'` to retrieve live normalized and raw SQL state.
5. **Strict Upgrade Preservation Assertions**:
   - Verifies normalized `schema_sha256` and `ledger_sha256` are strictly preserved against baseline compatibility contract.
   - Verifies raw `schema_sha256` and raw migration ledger are strictly identical to baseline.
   - Verifies administrative user identity digest (`admin_digest`) is strictly identical, proving the original administrator was retained.
   - Verifies seeded customer business rows (`id, name, phone, initial_balance, loyalty_points`) and settings (`key, value`) are strictly preserved after canonical JSON normalization.
   - Verifies `db_credentials.json` and `.env` SHA256 hashes are strictly unchanged.
   - Verifies device SSL certificate and key SHA256 hashes are strictly unchanged across canonical absolute paths.
   - Verifies QZ printing ownership receipt, Enterprise trust store certificate, and owned printing files are strictly unchanged.
   - Verifies business sentinel GUID file is strictly unchanged.
6. **Evidence Recording & Safe Clean Uninstallation**:
   - Generates `upgrade-acceptance.json` evidence only after all preservation assertions pass.
   - Verifies the uninstaller executable exists and fails if missing.
   - Runs silent uninstallation and verifies complete removal of managed data across all POS targets.
