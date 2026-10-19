# POS Update Self-Healing & Recovery Guide

## 1. Overview & Architecture

Phase 12 introduces a **Self-Healing Update System** into the POS update ecosystem. The recovery architecture wraps around the existing update engine, detecting faults (e.g. power losses, interrupted network transfers, corrupted package signatures, migration errors) and executing safe, deterministic remediation without human intervention.

```mermaid
graph TD
    StartupCheck[POS Startup / Boot] --> DiagnoseState[Diagnose update-state.json]
    
    DiagnoseState -->|State: healthy / idle| BootNormal[Normal Application Boot <50ms]
    
    DiagnoseState -->|State: downloading (interrupted)| CleanDownload[Clean Partial Files & Schedule Retry]
    CleanDownload --> TrackAttempts{Attempts >= 3?}
    TrackAttempts -->|No| ResumeOrRetry[Retry Download with Backoff]
    TrackAttempts -->|Yes| EscalateAdmin[Escalate to Admin]
    
    DiagnoseState -->|State: verifying (corrupted)| DeleteCorrupt[Delete Corrupted Package & Re-download]
    DeleteCorrupt --> TrackVerify{Attempts >= 2?}
    TrackVerify -->|No| ReVerify[Retry Verification]
    TrackVerify -->|Yes| EscalateAdmin
    
    DiagnoseState -->|State: applying (partial replace)| AutoRollback[Execute Atomic Rollback to Snapshot]
    DiagnoseState -->|State: migrating (DB error)| AutoRollback
    
    AutoRollback --> PreserveSnapshot[Preserve Backup Snapshot Files]
    AutoRollback --> AuditLog[Write recovery_audit.json & Dispatch Telemetry]
```

---

## 2. Failure Scenarios & Recovery Matrix

| Detected State | Problem / Trigger | Selected Action | Retry Policy | Behavior & Safeguards |
| :--- | :--- | :--- | :--- | :--- |
| **`downloading`** | Network dropped, power cut mid-download | `retry_download` | Max 3 attempts | Deletes `.part` temp file, increments attempt counter, resumes. |
| **`verifying`** | Corrupted download, SHA256 / RSA mismatch | `retry_verification` | Max 2 attempts | Deletes corrupted zip, triggers clean re-download and re-verification. |
| **`applying`** | Terminal restarted mid-file replacement | `rollback` | 0 retries | Automatically restores files from pre-update snapshot. |
| **`migrating`** | SQL constraint / schema migration error | `rollback` | **NO RETRY** | Immediate atomic rollback of files and schema to prevent corruption. |
| **`failed_max_retries`**| Consecutive download or verify failures | `escalate` | — | Sets status to `escalated_to_admin`, pauses auto-loop, alerts admin. |

---

## 3. Strict Production Safeguards

### A) Idempotency Guarantee
Running recovery operations or startup checks multiple times produces the exact same clean, stable outcome without corrupting files or database records.

### B) Concurrency Lock (`recovery.lock`)
- Prevents concurrent recovery processes from colliding.
- Automatically handles stale locks (TTL = 300 seconds).

### C) Lightweight Startup Check (< 50ms)
- Application boot is never blocked by long operations.
- Clean states return immediately in `< 1ms`.
- Interrupted states are remediated with minimal overhead.

### D) Structured Audit Trail (`recovery_audit.json`)
Every recovery action is immutably logged with:
- `previous_state`
- `detected_problem`
- `selected_action`
- `result` (success/failure)
- `timestamp`
- Detailed diagnostic metadata

### E) Snapshot Preservation
- **Snapshots are NEVER deleted automatically during recovery.**
- All pre-update file and database snapshots remain safely preserved in `backend/storage/update_backups/` until cleared by the data retention policy.

---

## 4. Post-Update Health Validation

After an update is applied (or on startup following a fresh update), `UpdateRecoveryService` executes a multi-layer health validation:

1. **Database Connectivity**: Active PDO query execution.
2. **Core Database Tables**: Verifies existence of `users`, `products`, `settings`, `sales`, `update_history`.
3. **Application Semver Integrity**: Validates `version.json` syntax and version string.
4. **Backend Boot Check**: Checks `backend/index.php` entry point integrity.

> [!IMPORTANT]
> If any critical health check fails post-update, the system initiates an **Automatic Auto-Rollback** (`update_auto_rollback`) to return the terminal to the last known working state.

---

## 5. Telemetry & Fleet Monitoring

Self-healing operations emit dedicated telemetry events to the central fleet management table:
- **`update_recovery_started`**: Emitted when diagnosis triggers remediation.
- **`update_recovery_completed`**: Emitted upon successful self-healing.
- **`update_recovery_failed`**: Emitted if remediation encounters an error.
- **`update_auto_rollback`**: Emitted when post-update health validation triggers an automatic rollback.

---

## 6. Admin Manual Overrides & API Endpoints

Authorized administrators (`updates.recovery.manage`) can trigger manual recovery actions via the Admin Update Center or REST API:

```http
POST /api/admin/updates/recovery/execute
Content-Type: application/json

{
  "action": "rollback"
}
```

Available actions:
- `retry_download`
- `retry_verification`
- `rollback`
- `clear`
- `escalate`
- `health_check`

---

## 7. Verification & Automated Testing

Run the self-healing test suite:
```bash
php scripts/test-update-recovery.php
```

All 9 scenarios are validated:
1. Interrupted download recovery
2. Corrupted package retry & deletion
3. Interrupted applying state detection
4. Migration failure immediate rollback
5. Startup self-healing execution
6. Escalation after max retries
7. Structured audit logging & telemetry
8. Lock mechanism & idempotency
9. Snapshot preservation guarantee
