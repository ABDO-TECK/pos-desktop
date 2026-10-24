# Cumulative STABLE DELTA 0.0.10 Release Guide

## 1. Executive Summary & Purpose

Release `0.0.10` is the cumulative **Stable Delta update** published to the `ABDO-TECK/pos-desktop-updates` repository. It rolls up all verified bug fixes, architectural improvements, and healing mechanisms developed across the `v0.0.10-beta.1` through `v0.0.10-beta.10` release cycle into a single, production-grade stable release.

This release follows the **Immutable Desktop Shell** architectural contract:
- The native desktop shell binary (`POS Desktop.exe`), Electron runtime core (`resources/app.asar`), and packaged installer remain locked at version `0.0.1`.
- The update payload delivers exclusively updated application layers: `backend/backend.phar`, frontend production assets (`frontend/dist/**`), and `version.json`.
- The update is delivered via signed delta packages generated against 11 explicit baseline releases.
- Delta archives are file-level packages containing complete replacement files for modified or added assets, rather than binary-byte-diff patch algorithms.

---

## 2. Cumulative Scope: Beta Enhancements Included

The `0.0.10` stable delta incorporates the cumulative stability improvements delivered across all beta iterations:

1. **Responsive Admin Update Center**:
   - Accurate byte-level progress reporting during **download only**; subsequent verification, backup creation, extraction, and installation phases operate as honest indeterminate status states.
   - Fast local status hydration (`hydrateLocalStatus`) decoupled from background update checks.
   - Background apply operations queued via `JobQueue` and executed by the CLI worker to avoid thread contention on Windows PHP built-in server (`php -S`).
   - Persistent handoff guards preventing premature reloads during staging.

2. **MariaDB Catalog Trigger Healing & DEFINER Alignment**:
   - Automatic detection and reconciliation of orphaned or mismatched trigger definers (`trg_products_catalog_insert`, `trg_products_catalog_update`, `trg_products_catalog_delete`).
   - Direct realignment to the privileged migration identity (`CURRENT_USER()`, i.e., `pos_migration@127.0.0.1`).
   - Fast-skip reconciliation failure propagation preventing silent skips on failed trigger definers.
   - Removal of unsafe HTTP trigger drops (`emergencyBypassCatalogTriggers`), failing closed with honest `503 Service Unavailable` requiring application restart for privileged CLI repair.

3. **Database Migration Ledger & Schema Healing**:
   - Automated repair and normalization of legacy database migration ledger entries.
   - Resilient verification of table schemas, column types, and constraints across version jumps.

4. **Catalog & Dashboard Performance Optimizations**:
   - Product catalog request deduplication and efficient batch query handling.
   - Consolidated dashboard query executions, eliminating N+1 bottlenecks.
   - Granular in-memory caching across product listing and lookup operations.

5. **Cryptographically Signed Channel Transitions**:
   - Explicit signed transitions between `stable` and `beta` release channels.
   - Comprehensive source and target deployment inventory validation before staging deltas.

6. **Verified Restore Points & Rollback Protection**:
   - Atomic pre-update backups of files and database states.
   - Cryptographic verification of baseline files prior to delta application; automated rejection of corrupted or modified rollback payloads.

7. **Resilient Delta Downloads**:
   - Bounded retries and connection recovery for interrupted downloads.
   - Verified update repair capabilities.

8. **Local Network & Peripheral Configuration Persistence**:
   - Persistent LAN and mobile device connection preferences preserved across page transitions and application restarts.

---

## 3. Explicit Boundaries: Excluded Capabilities (Reserved for Setup 0.0.11)

To protect system integrity, the stable delta update observes strict architectural boundaries:

| Scope | In Delta 0.0.10 | Setup 0.0.11 Boundary |
|---|---|---|
| **Payload Scope** | `backend/backend.phar`, `frontend/dist/**`, `version.json` | Full application tree, native installer, updated desktop runtime metadata |
| **Electron & Node Runtimes** | Unchanged (pinned to `0.0.1`) | Pinned to `0.0.1` runtime dependencies (no Electron or Node upgrades) |
| **Handoff Shell Implementation** | Unchanged (`electron/services/delta-update-handoff.js` at `0.0.1`) | Imports handoff shell JS fixes + version metadata; bounded safe owned-process termination and replacement (no unbounded process killing) |
| **Filesystem Lock Resolution** | Cannot override Windows `EPERM` locks on running shell files | Standalone installer can replace files before child processes spawn, but cannot bypass persistent external administrative or antivirus locks |
| **Tracked Shell Files** | `electron/**`, `build/**`, `package.json`, `package-lock.json`, `scripts/desktop-runtime.json` are byte-identical to `0.0.1` | Updated shell JS fixes and package metadata |

**Operational Boundaries**:
- Delta updates cannot modify native shell scripts or binaries outside the web/PHP payload.
- Neither Delta 0.0.10 nor Setup 0.0.11 includes Electron/Node dependency upgrades or "advanced process killing" / guaranteed administrative lock overrides. Both use bounded, safe owned-process termination and clean replacement.
- Installations experiencing external filesystem permission denials (`EPERM`) or locked files inside protected directories (`C:\Program Files\...`) from unclosed background workers cannot be solved by delta packages alone and require the full Windows installer.

---

## 4. Supported Baseline Releases

Delta packages are generated and cryptographically signed for 11 explicit baselines:

1. `v0.0.1` (Original published Windows Setup release)
2. `v0.0.10-beta.1`
3. `v0.0.10-beta.2`
4. `v0.0.10-beta.3`
5. `v0.0.10-beta.4`
6. `v0.0.10-beta.5`
7. `v0.0.10-beta.6`
8. `v0.0.10-beta.7`
9. `v0.0.10-beta.8`
10. `v0.0.10-beta.9`
11. `v0.0.10-beta.10`

Each baseline produces three published release assets:
- `manifest-<baseline_version>.json`: Signed release manifest defining source version, target version (`0.0.10`), channel (`stable`), SHA256 hashes of all updated files, and list of deleted files.
- `manifest-<baseline_version>.sig`: Base64 RSA-2048 signature of the manifest file.
- `delta-<baseline_version>-to-0.0.10.zip`: Compressed zip archive containing full replacement files for changed or added assets (a file-level delta archive, not a binary-byte-diff patch algorithm).

---

## 5. Release Pipeline Gates & Security Architecture

The automated publishing pipeline (`scripts/build-delta-release.ps1`) runs exclusively in GitHub Actions under the repository `ABDO-TECK/pos-desktop-updates`.

### 5.1 Pre-Packaging Quality Gates (Stable Channel)
Before any delta package is assembled, the pipeline executes rigorous readiness, backend, and frontend regression suites:
- `scripts/verify-stable-delta-readiness.mjs`: Tests release metadata parsing against isolated temporary fixtures (11 baselines, 16 cap, duplicates/malformed rejection, setup mode), verifies baseline resolution and git ancestry, asserts zero git diff against `v0.0.10-beta.10` across all production application source (`backend`, `frontend/src`, `frontend/public`, `database`), checks immutable shell byte-identity, verifies owned-process inspection query generation (safe base64 path encoding, trailing separator boundary, case-insensitive normalization), tests injected query failures and fail-closed handling, and parses PowerShell AST syntax safely.
- `scripts/verify-admin-update-center.php`: Validates background update job queues, storage serialization, and path redaction.
- `scripts/verify-trigger-healing.php`: Validates MariaDB catalog trigger recreation and definer alignment.
- `scripts/verify-catalog-trigger-recovery.php`: Validates recovery pathways for catalog trigger failure states.
- `scripts/verify-admin-update-center.mjs`: Tests Admin Update Center UI components against an actual Electron/React browser DOM harness (not JSDOM), validating download progress bars, hydration generation sequencing, and channel switch dialogs.

### 5.2 Strict Secret Confinement
The repository signing secret (`UPDATE_PRIVATE_KEY`) is strictly confined:
- `$env:UPDATE_PRIVATE_KEY` is loaded into a local script variable and immediately removed from the environment (`Remove-Item env:UPDATE_PRIVATE_KEY`).
- Package signing invokes `build-release-package.php` inside a `try / finally` block that guarantees `$env:UPDATE_PRIVATE_KEY` is scrubbed from child process environments even if package assembly fails.
- The secret is set to `$null` before test processes or acceptance scripts run.
- Acceptance scripts explicitly assert that `UPDATE_PRIVATE_KEY` is not present in their environment.

### 5.3 Post-Packaging Packaged UI Acceptance
After generating all delta packages, the pipeline invokes `scripts/prepare-stable-delta-ui.ps1` and `scripts/verify-stable-delta-ui.mjs` on an ephemeral Windows runner:
- Downloads the published Setup `v0.0.1` and verifies its SHA256 checksum (`2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a`).
- Verifies upgrade from `0.0.1` directly to `0.0.10` using the candidate delta package.
- Verifies upgrade from `0.0.10-beta.10` to `0.0.10`.
- Asserts that all files in `deployment-inventory.json` match candidate hashes exactly.
- Asserts that shell binaries (`POS Desktop.exe`, `resources/app.asar`) remain byte-identical.
- Compares normalized SQL schema (`schema_sha256`), migration ledger (`ledger_sha256`, raw `ledger`), and compatibility views strictly: asserts identical schema and ledger without unverified migration additions across upgrades.
- Snapshots and verifies device credentials and SSL key material at actual original runtime paths without printing secret output: requires actual QZ private key (`private-key.pem`) and certificate (`digital-certificate.pem`), requires HTTPS private key (`server.key`) and certificate (`server.crt`) if active, explicitly distinguishing disabled HTTPS as optional, and asserts at least one actual private key pair is present and verified.
- Employs guarded cleanup of exact fixture-owned POS roots (`PROGRAMDATA/POS System`, `APPDATA/POS System`, `APPDATA/pos-desktop`) with per-run marker validation (`.pos-fixture-run-marker`), ancestor/no-reparse validation (`Assert-PosTreeSafe`), and awaiting owned runtime child process shutdown (scanned executables under app lab directory via base64 path encoding, trailing separator boundary, case-insensitive normalized path matching, and fail-closed CIM query handling with `-ErrorAction Stop`) without global process killing.
- Publishes public evidence `stable-delta-acceptance.json` with the release assets.

### 5.4 Candidate CI Validation (`publish: false`) vs. Final Publication Tag (`publish: true`)

The release pipeline enforces a strict separation between candidate CI validation and final publication to guarantee **tag immutability**: release tags are never created speculatively, moved, or force-updated.

#### 1. Lifecycle and Operational Order
1. **Candidate CI Validation (`publish: false`)**: Executed against the candidate branch `release-stable-delta-0.0.10` before any permanent source tag is published.
2. **Review & Maintainer Approval**: Verification results and generated evidence (`stable-delta-acceptance.json`) are reviewed.
3. **Immutable Tag Push**: Maintainers create and push the final immutable tag `v0.0.10` pointing to the verified commit at `HEAD`.
4. **Final Publication Run (`publish: true`)**: Executed using the official tag `v0.0.10`, producing and publishing signed release assets to GitHub Releases.

#### 2. Candidate CI Validation Mode (`publish: false`)
- **Invocation**: Triggered manually via `workflow_dispatch` on `ABDO-TECK/pos-desktop-updates` with:
  - `source_ref`: `release-stable-delta-0.0.10`
  - `tag`: `v0.0.10`
  - `publish`: `false` (or `'false'`)
- **Ephemeral Candidate Tag Hook (`Sync-ReleaseCandidateTag`)**:
  - `scripts/validate-release-source.mjs` strictly requires that `HEAD` commit matches the local Git tag commit (`headCommit === tagCommit`).
  - In candidate runs prior to publication, the official tag does not exist on the remote repository.
  - The minimal source hook in `scripts/build-delta-release.ps1` safely inspects the execution context:
    - Enforces `$env:GITHUB_ACTIONS -eq 'true'` and `$env:GITHUB_REPOSITORY -eq 'ABDO-TECK/pos-desktop-updates'`.
    - Confirms `$env:GITHUB_EVENT_NAME -eq 'workflow_dispatch'` with readable `$env:GITHUB_EVENT_PATH`.
    - Enforces explicit boolean `false` or string `'false'` on `inputs.publish`, preventing PowerShell truthiness traps where non-empty strings evaluate to true.
    - Confirms `inputs.tag == $Tag`, `$Tag` matches `^v\d+\.\d+\.\d+$`, `$Tag == "v$($Version.version)"`, and `channel == 'stable'`.
    - Never creates candidate tags on missing inputs, malformed JSON, truthy publish, non-workflow_dispatch events (`push`, `release`), or beta channels.
  - If all guards pass and the tag is missing, the hook creates ONLY an ephemeral lightweight local candidate tag at `HEAD` (`git tag $Tag HEAD`).
  - The ephemeral tag exists strictly inside the ephemeral CI runner and is **never pushed** to any remote repository (`no push`).
  - Full pre-packaging quality gates, delta compilation, package verification, and packaged UI acceptance execute cleanly without publishing release assets.

#### 3. Final Publication Mode (`publish: true`)
- **Invocation**: Triggered after candidate CI validation passes and the immutable `v0.0.10` tag has been pushed:
  - `source_ref`: `v0.0.10` (or `release-stable-delta-0.0.10`)
  - `tag`: `v0.0.10`
  - `publish`: `true`
- **Strict Verification of Existing Tag**:
  - The source hook in `scripts/build-delta-release.ps1` **never creates tags** during publish runs (`isPublishExplicitFalse` is false).
  - The hook detects the existing tag: if the existing tag points to `HEAD`, it is accepted and logged.
  - If an existing tag points to a commit other than `HEAD` (`tagCommit -ne headCommit`), the hook **fails closed immediately** (`throw`), refusing to force or retag (`no force / retag`).
  - If the tag does not exist when `publish: true`, the hook refuses to create it, and downstream `validate-release-source.mjs` fails closed.
  - Official delta packages and signed manifests are uploaded to the GitHub Release associated with the immutable tag.

#### 4. Operational Comparison Matrix

| Property | Candidate CI Validation (`publish: false`) | Final Publication (`publish: true`) |
| :--- | :--- | :--- |
| **Workflow Dispatch `publish`** | Explicit boolean `false` or string `'false'` | `true` |
| **Target Branch / Ref** | Candidate branch (`release-stable-delta-0.0.10`) | Immutable tag (`v0.0.10`) or candidate branch |
| **Pre-existing Remote Tag** | None (tag not yet published) | Required (`v0.0.10` already pushed to remote) |
| **Source Hook Behavior** | Creates ephemeral lightweight local tag at `HEAD` | Verifies existing tag matches `HEAD`; NEVER creates tag |
| **Tag Mutability & Remote Push** | Zero remote tag mutation; never pushed | Tag is immutable; already permanent on remote |
| **Diverged Tag Behavior (`tag != HEAD`)** | Fails closed (`throw`); no force, no retag | Fails closed (`throw`); no force, no retag |
| **Release Asset Upload** | Staged and tested locally in runner; no release created | Uploaded and published to official GitHub Release |

---

## 6. Acceptance Evidence & Verification Limitations

The acceptance evidence recorded in `stable-delta-acceptance.json` reflects rigorous testing with clearly documented operational boundaries:

1. **Transport Mechanism**: Acceptance testing uses candidate packages staged directly (`transport: "prepublication_copied_signed_packages"`). It validates packaging, extraction, signature verification, and runtime handoff before the GitHub Release is published (`live_feed: false`).
2. **Ephemeral Environment**: Acceptance tests execute exclusively on ephemeral Windows GitHub Actions runners with no pre-existing POS state.
3. **Database Isolation**: Tests execute against clean isolated fixture databases, guaranteeing that production customer data is never accessed during CI verification.
4. **Factual Metric Reporting**: Recorded evidence fields reflect exact measured facts and strict assertion results, avoiding unconditional claims.
