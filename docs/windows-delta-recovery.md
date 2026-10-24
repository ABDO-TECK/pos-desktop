# Windows Delta Handoff & Recovery Design (Phase 2)

## 1. Incident Analysis: Customer Stable 0.0.1 -> beta.8 Failure

### Observed Evidence
A customer updating from `stable 0.0.1` to `0.0.10-beta.8` encountered a fatal error dialog showing an `EPERM` copy failure with backup source and temporary target:
```text
source: C:\ProgramData\POS System\update-backups\patch_0.0.1_to_0.0.10-beta.8_20261023_050811\files\backend\backend.phar
target: C:\Program Files\POS System\POS Desktop\resources\app.asar.unpacked\backend\.backend.phar.delta-25084.tmp
```

Key factual deductions from the screenshot and path structure:
1. **Failure Occurred During Rollback**: The source path is inside `update-backups/.../files/backend/backend.phar`. In the delta handoff protocol, only `rollback(plan)` reads from `update-backups`. This proves the initial file application failed, triggered a rollback, and that rollback itself failed while attempting to restore `backend.phar`.
2. **Primary Error Masking & Uncaught Rollback Exceptions**: In the unpatched codebase, `delta-update-handoff.js` wrapped file replacement in a `try/catch` that called `rollback(plan)` without catching rollback exceptions. When rollback threw an exception during `copyFileSync`, the uncaught exception bubbled directly to the Electron main process, completely masking the primary apply error that triggered the abort.
3. **Temporary Filename Collision**: The temporary filename `.backend.phar.delta-25084.tmp` was formed via `.${path.basename(target)}.delta-${process.pid}.tmp`. Apply and rollback ran in the same process (`PID 25084`), reusing the exact same temporary path. If apply failed and left an uncleaned, locked, or read-only temporary file, the subsequent rollback failed immediately on `copyFileSync` into the same path.
4. **Cascading Failure in Electron Main & No Customer DB Restore**: In unpatched `main.js`, `delta:apply-staged` caught the error from `applyPendingDelta`. Because the failure occurred during file application prior to executing database migrations, database migrations were never executed and no database restore occurred for this customer, while the root cause remained obscured.

### Observed vs. Unproven Distinctions (Conditional Source Bug Nuance)
- **What is proven**:
  - The source in the error dialog (`C:\ProgramData\POS System\update-backups\...files\backend\backend.phar`) is inside the backup directory. In the delta update architecture, the backup directory is *only* read by `rollback(plan)`.
  - This proves conclusively that the initial apply phase encountered a failure and attempted an automated rollback, but the rollback itself failed while attempting to restore `backend.phar`.
  - Uncaught exceptions during rollback masked the primary error that triggered the abort.
  - Furthermore, apply and rollback ran in the same process (`PID 25084`) and used the predictable pattern `.${basename}.delta-${pid}.tmp`. If apply created that file and failed, rollback attempted to write to the exact same temporary path.
- **What is unproven (Conditional Possibilities)**:
  - An `EPERM` error code on Windows does **not** prove an exclusive file lock by itself. It can be caused by:
    1. **UAC / ACL Protection**: Application installed under `C:\Program Files\...`, where standard user processes lack write permissions (`FILE_WRITE_DATA`).
    2. **Antivirus Scanning**: Windows Defender or third-party Antivirus holding an opportunistic lock while inspecting `backend.phar`.
    3. **Un-Awaited Process Exit**: `php.exe` or background job worker process still running and keeping the file handle open because `stopPhpServer` historically did not await process exit.
    4. **ReadOnly Attribute Inheritance**: Windows `CopyFile` propagating `FILE_ATTRIBUTE_READONLY` from the staged or backup source to the temp file.
    5. **Pre-existing / Dirty Installation**: Stale leftover temporary files or mixed version artifacts from an earlier aborted installation attempt.
- **Contract Rule**:
  - Never assert file lock, ACL denial, or Antivirus as the sole root cause without empirical runtime proof.
  - The system must capture and preserve the *primary apply error*, the *failure phase*, and any *rollback errors*.
  - Never present a false claim of successful rollback to the customer if rollback encountered errors.

---

## 2. Transaction & Error Contract

### Phase 1: Preflight Validation (Zero Mutation, No Runtime Interruption)
Before stopping any runtime service (PHP, worker) and before mutating any filesystem artifact:
1. **Plan & Path Scoping**:
   - Verify `desktop-handoff.json` format, version format, `plan.storage_dir`, and `plan.root_dir`.
   - Reject any path traversal (`..`), drive letters, UNC paths (`\\`), or reparse escapes in `files`, `deleted_files`, and `baseline_files`.
   - Strictly enforce allowlist prefixes:
     - `backend/backend.phar`
     - `backend/certs/`
     - `database/pos_schema.sql`
     - `frontend/dist/`
     - `frontend/public/`
     - `version.json`
   - Forbid user databases, storage directories, `.env` files, and device printing certificates.
2. **Baseline Verification**:
   - Verify every file in `manifest.baseline_files` exists in the target directory and its SHA-256 hash matches the baseline manifest exactly.
   - If any baseline artifact differs or is missing, preflight fails immediately before touching any file.
3. **Upfront Staged Artifact Integrity**:
   - Verify every file in `manifest.files` exists in `staging_dir` and matches its signed SHA-256 hash upfront.
   - If any staged file is corrupt or truncated, preflight fails before touching any installed file.
4. **Target Parent Permission Probes**:
   - Collect unique parent directories of all files to be replaced or deleted, plus `version.json`.
   - Perform a probe in each directory: create, rename, and unlink a dedicated probe file (`.probe-delta-${pid}-${time}-${rand}.tmp`).
   - If write access is denied (`EPERM` or `EACCES`):
     - Return stable `reason_code: 'install_permission_required'`.
     - Return actionable Arabic guidance directing the user to the full Setup installer or support:
       `"يتطلب تثبيت التحديث صلاحيات المسؤول للكتابة في مجلد البرنامج. يرجى تثبيت التحديث باستخدام برنامج التثبيت الكامل (Setup) أو التواصل مع الدعم الفني."`
     - Runtime services remain running; no files are modified; no rollback is required.
     - Note on Administrator limitations: Running the POS application continuously with elevated Administrator privileges is not recommended for regular retail operation under Windows UAC guidelines. The canonical remedy for permissions restrictions in `C:\Program Files` is running the official Setup installer with elevation.

### Phase 2: Bounded Awaitable Runtime Termination
- `stopPhpServer()` and `stopPHP()` historically issued `child.kill()` and immediately cleared `phpProcess = null`. In Node.js, `child.kill()` only transmits the signal; it does **not** wait for process termination.
- Replacing or renaming `backend.phar` while `php.exe` is still terminating triggers `EPERM` / `EBUSY` on Windows.
- Added `stopPhpServerAwaitable({ timeoutMs = 5000 })`:
  - Listens for `'exit'` and `'close'` events.
  - Sends `SIGTERM`.
  - If process does not exit within timeout, escalates to `SIGKILL` with a grace period.
  - If termination still fails: **does not clear `phpProcess`**; aborts the update transaction before any file mutation and reports `PHP_STOP_TIMEOUT`.
  - Does not execute arbitrary wildcard process termination (`taskkill /F /IM php.exe`).

### Phase 3: Atomic File Replacement (`replaceFile`)
1. **Fresh Unique Temp Path**:
   - Every replacement generates an operation-unique temporary path:
     `.${basename}.delta-${process.pid}-${Date.now()}-${randomBytes}.tmp`
   - Apply and rollback never collide, even within the same process.
2. **ReadOnly Attribute Handling**:
   - Windows `CopyFile` propagates `FILE_ATTRIBUTE_READONLY` from source to target.
   - `replaceFile` calls `clearReadOnlyIfSet` **only** on its owned temporary file.
   - If the managed target already has ReadOnly set, `clearReadOnlyIfSet` clears it on the managed target to allow atomic rename, recording the attribute state.
3. **Bounded Transient Retry**:
   - Bounded retries (default 5 attempts, 50ms interval) on `EPERM`, `EBUSY`, or `EACCES` during `fs.renameSync` to accommodate transient Antivirus scanning and Windows Search indexing.
4. **Target Atomicity**:
   - Do **not** fall back to in-place `copyFileSync(temporary, target)` on rename failure. An in-place copy on a busy or permission-restricted file risks leaving a corrupted, truncated target.
   - Guaranteed cleanup in `finally`: owned temporary files are always unlinked.
5. **Ordering**:
   - Non-version files are replaced first, followed by deletions.
   - `version.json` is replaced strictly **last**.

### Phase 4: Coordinated Rollback Safety
- `rollback(plan)` attempts to restore all files from `snapshot_path/files` and removes newly added files from `metadata.new_files`.
- **Fault-Tolerant Loop**: A failure on any individual file does not abort the loop. Errors are collected in an array while remaining entries continue to be restored.
- **Independent Transaction Expectation**: Preflight captures an immutable, in-memory expectation of the complete signed baseline, backed-up/deleted artifacts, absent new files, original version bytes, and snapshot metadata. Changed or incomplete metadata cannot silently shrink the recovery scope.
- **Post-Verification**: Verify the complete expected baseline and absence of new files before restoring `version.json`, then verify the entire state again before declaring rollback successful or restarting HTTP. Unchanged baseline artifacts are checked too. A zero-mutation abort also verifies the original baseline before restarting runtime.
- **Persistence Limitation**: This independent expectation belongs to the current process/transaction; it is not a persistent crash-recovery protocol.
- `version.json` is excluded from the normal restore loop and is restored atomically strictly last only after all other restores succeed.
- **Structured Rollback Result**:
  ```json
  {
    "ok": true,
    "restored_count": 3,
    "errors": null,
    "verified": true,
    "retained_backup": "path/to/snapshot",
    "retained_staging": "path/to/staging"
  }
  ```
- If rollback is incomplete (`ok: false`):
  - Do **not** mark update state as `rolled_back`.
  - Mark state as `recovery_required`.
  - **Do not restart the HTTP runtime on mixed artifacts**.
  - Retain backup snapshot and staging directories for manual recovery.

### Phase 5: Database Recovery Sequencing
- Database recovery (`restore-migration-safety`) is invoked **only** if database migrations actually started (`migration_started: true`).
- If migrations fail:
  1. Database recovery is executed **first**.
  2. If Database recovery fails: update state is marked `database_recovery_failed` / `recovery_required`. File rollback is not falsely claimed.
  3. If Database recovery succeeds: file rollback is executed **second**.
- If runtime restart fails post-migration, the primary error reported is the restart failure, not masked by recovery operations.

---

## 3. Stale-ReadOnly TMP, ACL, and Antivirus Distinctions

| Failure Mechanism | Root Cause | Behavioral Indicator | Resolution in This Phase |
| :--- | :--- | :--- | :--- |
| **Stale ReadOnly TMP** | Source archive has ReadOnly attribute; Windows `CopyFile` propagates it to temp; previous crash left file behind; same PID path re-used. | Error cites `.tmp` path; file exists before copy; attributes show `R`. | Generate unique per-operation temp path (`${pid}-${time}-${rand}`); call `clearReadOnlyIfSet` on owned tmp; clean up in `finally`. |
| **UAC / ACL Protection** | Application installed under `C:\Program Files\...`; Electron runs as standard user token lacking `FILE_WRITE_DATA`. | Preflight probe fails to create/rename/unlink probe file in parent dir. | Preflight detects write denial before stopping PHP or mutating files; returns `install_permission_required` with Arabic guidance. No ACL hacking. |
| **Antivirus / File Lock** | Windows Defender or Antivirus scanner locks newly created file; or PHP process still terminating. | Transient `EPERM` / `EBUSY` during `renameSync` that clears after 50-100ms. | Await PHP process exit before file mutations; apply bounded transient retry (5x50ms) during rename. |

---

## 4. Delivery Limitations & No Publish Authority

### Immutable 0.0.1 Shell in Program Files
- The original published `0.0.1` desktop shell runs as a standard user process.
- When installed in `C:\Program Files`, standard users do **not** have write access to replace `app.asar`, `app.asar.unpacked`, or the Electron executable.
- The Electron code modified in this phase (such as `main.js` and `delta-update-handoff.js`) resides inside the desktop shell.
- **Electron is excluded from the delta payload regardless of elevation; delta updates alone cannot deliver fixes to the Electron main process for an installed 0.0.1 client.**
- Correcting this transaction logic fixes behavior for future versions and portable/writable installations, but does **not** magically elevate existing 0.0.1 customer installations.

### Customer Remedy Constraints
- **Full Setup Required**: Delivery of the updated Electron shell requires a new full installer (`POS-Desktop-Setup.exe`). A local full installer package (`0.0.10-beta.9`) has been prepared locally in `dist-electron` as an unsigned recovery artifact per `docs/setup-recovery.md`, without public publishing, tagging, or executing real lifecycle mutations on the host.
- **Status of `scripts/update-repair/Repair-POS.ps1`**:
  - The script `scripts/update-repair/Repair-POS.ps1` invokes `scripts/update-repair/repair.php`.
  - `repair.php` is strictly hardcoded to only target `0.0.10-beta.2` from baselines `0.0.1` or `0.0.10-beta.1` (`$target = '0.0.10-beta.2'`).
  - It explicitly throws a `RuntimeException('Repair requires a baseline-specific delta without migrations')` if any migration is declared.
  - It does not support custom MariaDB data directories or definer healing.
  - Therefore, `scripts/update-repair/Repair-POS.ps1` is **not** a runnable remedy for mixed `beta.8` customer installations and must not be recommended to customers.
- **Preserve Customer Data & Actual MySQL Data Directory Paths**:
  - In production desktop environments, MariaDB/MySQL is configured and spawned by `electron/services/mysql-server.js` using paths from `electron/utils/paths.js`:
    - `getDataDir()` returns `C:\ProgramData\POS System` (primary) or `%LOCALAPPDATA%\POS System\Data` (fallback).
    - `getMysqlDataDir()` returns `path.join(getDataDir(), 'mysql_data')` (typically `C:\ProgramData\POS System\mysql_data`).
    - The server runs with `--datadir` pointing to `getMysqlDataDir()`.
  - There is no `business.db` SQLite database in the production application; database storage is purely MariaDB/MySQL.
  - Under no circumstances should customers be advised to uninstall, delete data, or wipe custom MariaDB directories or storage. Retained backup snapshots and SQL safety dumps provide best-effort recovery artifacts, but do not promise complete or no-loss restoration.
  - **Non-Atomic Nature of SQL Schema Dumps**: MySQL/MariaDB DDL statements (`ALTER TABLE`, `CREATE TABLE`, `DROP TABLE`) trigger implicit commits and cannot be rolled back atomically via transactions. The `migration_safety_backup.sql` is a best-effort structural snapshot taken prior to migrations to facilitate manual or scripted recovery, not an atomic ACID rollback.
- **Non-Waivable Cryptographic Fingerprints & No Publish Authority**:
  - Cryptographic baseline verification in `manifest.baseline_files` is hardcoded in the client updater logic and is **not waivable** by signatures or flags. If installed file hashes do not match the expected baseline, the client will unconditionally refuse to apply deltas.
  - We do not create releases, tags, or publish new delta packages.

---

## 5. Verification Test Suite

### 1. `scripts/verify-delta-handoff-recovery.mjs`
Automated, dependency-free Node.js unit and orchestration tests isolated in temporary directories:
0. `startupMigrations: [] passes --migrations= to CLI`: Verifies `php-server.js` passes `--migrations=` to the CLI for DEFINER reconciliation even when startup migrations array is empty.
1. `success and version last`: Verifies full successful delta application; `version.json` applied strictly last; state marked `completed`.
2. `all staged hashes checked upfront`: Corrupt staged payload caught in preflight; runtime untouched; zero file mutations.
3. `no baseline unsafe apply`: Baseline mismatch caught before writes; runtime not stopped; target files unchanged.
4. `EPERM copy/rename + rollback same PID temp not reused`: Stale temp file present with same PID; rollback creates fresh temp and succeeds without collision.
5. `original error preserved if rollback also fails`: Injects apply failure on one file and rollback failure on another; asserts primary apply error is preserved and reported alongside rollback errors with `recovery_required`; runtime is not restarted on mixed files.
6. `remaining restore entries attempted`: First backup file fails; rollback continues and successfully restores remaining files.
7. `structured partial recovery retained backup`: Incomplete rollback returns `reason_code: recovery_required`; retains backup snapshot and staging directories.
8. `no DB restore on preflight fail`: Preflight failure never triggers `restoreDatabaseSafety`.
9. `migration failure DB restore then files exactly once`: Migration failure executes database restore first, then file rollback second, exactly once.
10. `restart failure doesn't overwrite cause and recovers in order`: Failure during post-install runtime restart preserves restart error, confirms runtime process exit, restores database, and rolls back files in order.
11. `stop await exit not killed timeout no changes`: PHP stop timeout aborts before file mutation; unit tests faithful mock child process termination and timeout rejection without clearing process reference.
12. `plan paths escapes & exact allowlist rejected`: Rejects traversal (`..`), absolute paths, UNC shares, and directory prefix circumventions (`version.jsonx`, `backend/backend.phar.evil`).
13. `Reparse / junction escape check`: Validates physical paths of target and ancestors, rejecting NTFS directory junctions and symlinks; fails non-zero if junctions cannot be created.
14. `Preflight schema & metadata structural validation`: Validates malformed plan JSON, missing signed version.json entry, null metadata, metadata list conflicts, missing mutated file coverage, and corrupt/missing backup artifacts.
15. `Target ReadOnly attribute restored on rename failure`: Verifies that if target had ReadOnly set and rename fails, target's ReadOnly attribute is safely restored.
16. `Guarded state write fault injection`: Verifies safe abort before mutations if initial state write fails, and verifies runtime stop + DB/file recovery if completed state write fails after apply.
17. `Post-preflight metadata tampering`: Removing replaced/deleted/new file entries or changing original version bytes after apply fails closed, preserves the primary failure, and prevents a second HTTP start or an old version stamp over unrestored files.
18. `Complete baseline recovery`: Corruption of an unchanged baseline artifact blocks version restoration and HTTP restart, including zero-mutation aborts. Successful recovery verifies replaced/deleted/version bytes and absence of new artifacts.
19. `Pre-restart installed verification`: Full desired installed state is checked before the first HTTP restart; forged rollback metadata hashes cannot override baseline hashes.

### 2. `scripts/verify-delta-handoff-windows.ps1`
Real Windows filesystem acceptance tests executed in a dedicated temporary directory containing spaces:
1. **Real Exclusive PHAR Lock**: Uses `[System.IO.File]::Open(..., [FileShare]::None)` to simulate real Windows kernel file locking on `backend.phar`. Verifies that `replaceFile` cleanly fails without in-place corruption and leaves original bytes intact.
2. **Real READONLY Attributes & Stale TMP**: Verifies that source and target files with `FILE_ATTRIBUTE_READONLY` are handled safely, stale temporary files are not collided with, and target is replaced atomically.
3. **Protected Parent ACL Probe (Current Identity SID)**: Applies deny ACL on target parent directory using the current identity SID (`icacls /deny *<SID>:(WD,AD)`). Fails non-zero if security confinement prevents `WRITE_DAC`. When allowed, verifies preflight returns `install_permission_required` with actionable Arabic guidance while leaving all files untouched.
4. **Guaranteed Cleanup**: Releases all locks, clears ReadOnly attributes, removes applied ACLs, and deletes temporary lab directories in a `finally` block.
