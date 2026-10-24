# Database Catalog Trigger Definer Root-Cause Recovery & Boundary Architecture

## 1. Incident Analysis: Confirmed Source Bugs vs. Customer Device Hypotheses

### Confirmed Source Bugs in Repository
Inspection of the codebase revealed five verified defects in trigger handling and migration execution:
1. **Fast-Skip False-Success in `MigrationService::runPendingMigrations()`**: When migration files were unchanged, `isUpToDate()` invoked `reconcileLegacyArtifacts()`, but discarded its return value and returned `['executed' => 0, 'errors' => [], 'skipped' => true]`. If trigger reconciliation reported errors, the migration fast-path swallowed them and reported success.
2. **Exit Code Sequencing in `backend/cli/migrate.php`**: `cli/migrate.php` evaluated `if ($result['skipped']) exit(0);` *before* inspecting `$result['errors']`. A skipped fast-path containing errors still exited with code `0`.
3. **Insecure Definer Prefix Matching in `MigrationService::reconcileCatalogTriggers()`**: The definer check accepted any user starting with `pos_app@` or `pos_migration@`. Triggers defined with `pos_app@127.0.0.1` were marked healthy even though `pos_app` lacks the `TRIGGER` privilege.
4. **Dangerous DROP-and-Bypass in `ProductService`**: When trigger errors occurred, `emergencyBypassCatalogTriggers()` dropped the triggers and inserted change records manually via `recordCatalogChangeDirectly()`, leading to trigger suppression, untracked modifications, and schema degradation.
5. **Overbroad Error Code 1142 Classifier in `ProductService`**: Any `1142` driver error code was treated as a trigger definer issue, misclassifying routine DML permission denials (`SELECT`, `INSERT`, `UPDATE`, `DELETE`).

### Customer Upgrade Incident & Hypotheses
During the upgrade from stable version `0.0.1` to `beta.8`:
- **Observed Updater EPERM Symptom**: The updater screenshot shows `copyfile EPERM` during rollback (`update-backup -> .tmp`) inside `C:\Program Files\...`. While an EPERM error was directly observed, the exact underlying cause (Windows filesystem ACL permissions, Antivirus inspection locks, concurrent process file locks, or source file access denial) remains unconfirmed on the customer machine. Because the rollback mechanism encountered this EPERM error, rollback was incomplete and may have left the installation in a mixed-version state.
- **Runtime Error Symptoms**: The frontend displayed the toast notification: `خطأ في صلاحيات مشغلات قاعدة البيانات (Trigger Definer). يرجى تحديث النظام لإعادة بناء المشغلات.` during product creation attempts.
- **Definer Error Hypothesis**: The exact MariaDB driver error on the customer device (Error 1449 `ER_NO_SUCH_USER` vs. Error 1142 `ER_TABLEACCESS_DENIED_ERROR`) was not directly captured in available logs, but both failure modes share the same mechanism: triggers execute under definer security context, and an invalid definer (`root@localhost`) or unprivileged definer (`pos_app@127.0.0.1`) halts all DML on the table.

---

## 2. Architectural Boundary: Privileged CLI Startup vs. DML-Only HTTP Runtime

The application enforces a strict privilege separation boundary between migration and request servicing:

| Context | Process / Execution | Database Identity | Allowed Privileges |
| :--- | :--- | :--- | :--- |
| **Startup CLI** | `electron/services/php-server.js` &rarr; `backend/cli/migrate.php` | `pos_migration@127.0.0.1` (`DB_MIGRATION_USER`) | Database-scoped `ALL PRIVILEGES` on `pos_db.*` (including `TRIGGER`, `CREATE`, `ALTER`, `DROP`) |
| **HTTP Runtime** | `electron/services/php-server.js` &rarr; `php -S 127.0.0.1:<port> router.php` serving deployed `backend.phar` | `pos_app@127.0.0.1` (`DB_USER`) | DML-only: `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE TEMPORARY TABLES`, `LOCK TABLES`, `EXECUTE` (explicitly **NO `TRIGGER`**, **NO `DDL`**) |

### Security Invariants
- The HTTP runtime environment (`createBackendEnv` in Electron) intentionally omits `DB_MIGRATION_USER` and `DB_MIGRATION_PASS`.
- The runtime user `pos_app` must **never** be granted `TRIGGER` or `DDL` privileges.
- The HTTP server must **never** attempt DDL operations or construct migration connections during request servicing.
- Triggers must always have their `DEFINER` set to the exact privileged migration identity (`pos_migration@127.0.0.1`), ensuring they execute with full privileges even when triggered by DML from `pos_app`.

---

## 3. Analysis of Root-Cause Remediations

### Remediation 1: Fast-Skip Failure Propagation in `MigrationService`
`MigrationService` now captures `$reconciliationErrors = $this->reconcileLegacyArtifacts()`. If non-empty, the service invalidates the flag file (`@unlink($this->flagFile)`) and returns `['executed' => 0, 'errors' => $reconciliationErrors, 'skipped' => true]`.

### Remediation 2: Exit Code Sequencing in `backend/cli/migrate.php`
Reordered checks so `if (!empty($result['errors'])) exit(1);` executes prior to checking `$result['skipped']`.

### Remediation 3: Exact `CURRENT_USER()` Definer Alignment in `MigrationService`
Replaced prefix matching with an exact match against `SELECT CURRENT_USER()` from the privileged migration connection. If the user identity cannot be determined, reconciliation fails closed. Rebuilds only the three owned catalog triggers:
- `trg_products_catalog_insert`
- `trg_products_catalog_update`
- `trg_products_catalog_delete`
Definers are verified by reading back `information_schema.TRIGGERS` after creation.

### Remediation 4: Elimination of Dangerous Workarounds in `ProductService`
Completely removed `emergencyBypassCatalogTriggers()` and `recordCatalogChangeDirectly()`. `ProductService` performs at most a bounded single retry in CLI-only privileged contexts. If repair is unavailable or fails, it returns an honest, actionable HTTP 503 response with `'reason_code' => 'catalog_trigger_repair_required'`.

### Remediation 5: Strict Error 1142 Classifier Discrimination
Restricted `isTriggerDefinerOrPrivilegeError()` to code `1449` or explicit trigger/definer messages for code `1142` (e.g. `TRIGGER command denied` or `does not have the TRIGGER privilege`). Standard DML permission errors (`SELECT`, `INSERT`, `UPDATE`, `DELETE`) return `false`.

---

## 4. Trigger Contract Specifications

All catalog triggers log row modifications to `product_catalog_changes` with the following schema:
```sql
CREATE TABLE IF NOT EXISTS product_catalog_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_catalog_changes_branch_sequence (branch_id, id),
    KEY idx_catalog_changes_product (product_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Trigger SQL definitions:
- **AFTER INSERT**:
  ```sql
  CREATE TRIGGER trg_products_catalog_insert
  AFTER INSERT ON products
  FOR EACH ROW
  INSERT INTO product_catalog_changes (branch_id, product_id)
  VALUES (NEW.branch_id, NEW.id)
  ```
- **AFTER UPDATE**:
  ```sql
  CREATE TRIGGER trg_products_catalog_update
  AFTER UPDATE ON products
  FOR EACH ROW
  INSERT INTO product_catalog_changes (branch_id, product_id)
  VALUES (NEW.branch_id, NEW.id)
  ```
- **AFTER DELETE** (Historical tombstone contract):
  ```sql
  CREATE TRIGGER trg_products_catalog_delete
  AFTER DELETE ON products
  FOR EACH ROW
  INSERT INTO product_catalog_changes (branch_id, product_id)
  VALUES (OLD.branch_id, OLD.id)
  ```

---

## 5. Verification & Test Evidence

The following test suites provide automated verification:

1. **`scripts/verify-trigger-healing.php`**:
   - SIMULATED privileged repair mock in SQLite testing harness.
   - Verifies honest HTTP 503 response when privileged repair is absent.
   - Verifies bounded single retry when privileged CLI repair succeeds.
   - Strict SQL error handling preserved.

2. **`scripts/verify-catalog-trigger-recovery.php`**:
   - 40 isolated standalone checks verifying:
     - Hash skip failure propagation and flag unlinking.
     - Real subprocess CLI `migrate.php` exit code sequencing using stdio file descriptors.
     - Exact `CURRENT_USER()` vs bad definers (`pos_app`, ghost definers, `root@localhost`).
     - Fail-closed behavior on unresolvable identity.
     - Error 1142 discrimination (ordinary DML denials not misclassified).
     - Complete removal of bypass methods.
     - Preservation of trigger contracts and tombstone records.
     - SQLite graceful limit handling.
     - Strict fixture parameter validation and negative identity guards (rejects 3306/3307/remote host/unsafe paths).

3. **`scripts/verify-catalog-trigger-recovery-mariadb.ps1`**:
   - Automated integration orchestrator using bundled portable MariaDB binaries (`portable/mysql/bin/mysql_install_db.exe`, `portable/mysql/bin/mysqld.exe`).
   - Initializes a fresh, ephemeral datadir in `%TEMP%` and allocates a dynamic loopback port.
   - Verifies `@@datadir`, `@@pid_file`, and owned process PID before performing any SQL.
   - Configures ephemeral `pos_app` (DML only) and `pos_migration` (`ALL ON test_pos_recovery.*`).
   - Reproduces actual MariaDB Error 1449 and Error 1142.
   - Reconciles triggers using privileged migration credentials to exact `pos_migration@127.0.0.1` via non-forced startup flow.
   - Proves runtime `pos_app` successfully creates, updates, and deletes products with triggers firing.
   - Confirms exact `product_catalog_changes` counts, sentinel business row value preservation, and idempotency.
   - Guarantees complete cleanup in `finally` block with reparse point rejection and owned PID termination.

---

## 6. Remaining Windows Handoff Work

The following items are outside the database/PHP layer and belong to the Windows/Electron packaging domain:

1. **Windows Installer / Updater Replacement Investigation**:
   - The observed symptom in the updater log was `copyfile EPERM` when updating files inside `C:\Program Files\...` during rollback (`update-backup -> .tmp`).
   - The Electron updater must ensure that all PHP child processes (`php -S` and any CLI workers) are completely terminated before attempting to swap `backend.phar`.
   - The backup/rollback step (`update-backup -> .tmp`) should handle file-in-use or permission denial scenarios with proper retry or restart prompts rather than leaving an incomplete rollback.

2. **Deployment of Repaired `backend.phar`**:
   - Once the packaging/updater fix is applied, the new `backend.phar` containing the updated `MigrationService`, `ProductService`, and `cli/migrate.php` will be deployed.
   - On the customer's next application launch, `electron/services/php-server.js` executes `backend/cli/migrate.php` with privileged migration credentials (`pos_migration`).
   - `MigrationService::reconcileCatalogTriggers(false)` will detect that the triggers have defunct definers (such as `root@localhost`), drop them, and recreate them with the exact `pos_migration@127.0.0.1` definer.
   - After CLI migration completes with exit code `0`, `php-server.js` starts the HTTP runtime (`php -S`) with `pos_app` DML-only credentials. The trigger definer barrier will be eliminated, allowing normal product catalog DML operations to proceed without definer privilege errors (subject to standard input validation and business rules).

---

## 7. Operational Limits & Live Database Safety

- **No Production Database Modified**: All testing and verification suites run exclusively against in-memory SQLite instances or dynamically provisioned, ephemeral MariaDB instances on non-standard ports.
- **No Customer Credentials Read**: Tests do not read `.env` or access port 3306/3307.
- **Clean Teardown**: All ephemeral test directories, database files, and background processes are completely terminated and removed following test execution.
