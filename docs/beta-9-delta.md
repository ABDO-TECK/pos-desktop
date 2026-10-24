# 0.0.10-beta.9 Beta Delta Release Note

## Overview

Release `0.0.10-beta.9` is a targeted **backend-only delta update** delivered via `pos-desktop-updates`.
It preserves the immutable desktop shell runtime (`0.0.1`) while deploying an updated `backend/backend.phar`, frontend production assets, and `version.json`.

---

## What This Delta Delivers (Backend-Only Healing)

1. **MariaDB Catalog Trigger DEFINER Alignment**:
   - `MigrationService::reconcileCatalogTriggers` detects orphaned or mismatched trigger definers (`root@localhost`, `pos_app@127.0.0.1`, or previous hosts) and realigns the three catalog triggers (`trg_products_catalog_insert`, `trg_products_catalog_update`, `trg_products_catalog_delete`) to the exact privileged migration identity (`CURRENT_USER()`, i.e., `pos_migration@127.0.0.1`).
   - Trigger definers are verified by querying `information_schema.TRIGGERS` after recreation.

2. **Fast-Skip Reconciliation Failure Propagation**:
   - When migration files are unchanged, `MigrationService::isUpToDate()` executes `reconcileLegacyArtifacts()`.
   - Any reconciliation error is returned in `$result['errors']` and invalidates the cached `migrations_hash.flag` file, ensuring unresolved defects are not masked as clean skips.

3. **CLI Exit Code Sequencing**:
   - `backend/cli/migrate.php` checks `$result['errors']` and exits immediately with code `1` before evaluating `$result['skipped']`.

4. **Removal of Unsafe HTTP Workarounds**:
   - Removed `emergencyBypassCatalogTriggers()` (which dropped triggers) and `recordCatalogChangeDirectly()` from `ProductService`.
   - Definer or trigger privilege errors during HTTP DML return an honest `503 Service Unavailable` with `reason_code: "catalog_trigger_repair_required"`, prompting an application restart so privileged CLI startup can heal the triggers.

---

## Compatibility with Immutable Baseline Shell

- **Shell Identity**: Tracked `electron/**`, `build/**`, `package.json`, `package-lock.json`, and `scripts/desktop-runtime.json` remain 100% byte-identical to baseline commit `8bd223e26020f447e1011820ff4ad70a0ecdf55f`.
- **Startup Execution**: In the immutable baseline shell, `electron/services/php-server.js` unconditionally calls `runDatabaseMigrations()` before starting the HTTP web server (`php -S`).
- **Privilege Separation**: `runDatabaseMigrations()` spawns `backend.phar migrate` (in packaged mode) with `DB_MIGRATION_USER` (`pos_migration`).
- **No New Shell Parameters Needed**: The existing baseline shell invokes migrations with default arguments (`migrations = null`). The patched CLI and `MigrationService` automatically execute artifact reconciliation without requiring future Electron changes such as `startupMigrations: []`.

---

## Shell Limitations & Realistic Boundaries

- **No Electron Shell / Handoff Patch**: Delta updates cannot update Electron binary assets, Node scripts in the host shell (`electron/main.js`, `delta-update-handoff.js`), or Windows service wrappers.
- **Rollback and EPERM Limits**: If an existing installation encounters Windows filesystem permission denials (`EPERM`), file locks from unclosed worker processes, or Antivirus locks inside `C:\Program Files\...`, this delta cannot modify the handoff or rollback mechanism in the installed executable.
- **Resolution Path for Shell Locks**: Installations blocked by shell-level `EPERM` errors cannot be cured solely by delta updates and will require an updated stable Windows Setup installer or local administrative permissions.
