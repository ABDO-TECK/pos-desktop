# Admin Update Center Architecture & Operational Guide

## 1. Overview & Core Mission

The Admin Update Center is the core operational interface in the POS system for monitoring, downloading, verifying, and applying application updates. It communicates with GitHub Releases for signed release manifests, delta zip archives, and full packages, applying updates with RSA-2048 digital signature verification, automatic atomic backups, and rollback capabilities.

In desktop deployment environments on Windows running PHP's single-threaded CLI web server (`php -S`), long-running synchronous updates block subsequent HTTP polling requests. To maintain high responsiveness, absolute stability, and honest user feedback, the Admin Update Center implements an opt-in background apply pipeline, decoupled state hydration, rate-limited polling, and real-time byte-level progress reporting.

---

## 2. Opt-in Background Apply & Job Queue Architecture

### 2.1 The Concurrency Model
On Windows, PHP's built-in web server processes requests sequentially. If `POST /api/updates/apply` runs synchronously for 30–90 seconds (downloading large patch archives, verifying cryptographic signatures, creating database backups, and unzipping files), any concurrent `GET /api/updates/status` polling request blocks until the apply finishes.

### 2.2 Background Job Dispatch
To avoid blocking the HTTP server thread:
1. The frontend invokes `POST /api/updates/apply` with `{ background: true, delta_capable: true }`.
2. `UpdateController::apply()` delegates to `BackgroundUpdateApplyService::scheduleApply()`.
3. `BackgroundUpdateApplyService` validates that auto-updates are enabled, no active operation lock is held, and dispatches a unique job via `JobQueue::dispatchUnique('apply_update', ...)`.
4. The endpoint immediately returns HTTP 200 with `{ ok: true, job_id: <int>, status: 'scheduled' }`.
5. The background CLI worker (`php backend/cli/process-jobs.php`) claims the job and calls `BackgroundUpdateApplyService::processWorkerJob()`.

### 2.3 Job Status, Storage Identity & Data Sanitization
Job execution metadata and settlement results are stored in `storage/update-jobs/job_<id>.json`.
- **Atomic Worker Settlement**: `scheduleApply()` enqueues the job into `job_queue` with an immutable `operation_id` without touching the filesystem. The background CLI worker alone creates and persists `storage/update-jobs/job_<id>.json` atomically using temporary file renaming (`rename()`) upon claiming and completing the job.
- **Fail-Closed Result Verification**: In `getJobStatus(jobId)`, completed jobs are validated strictly. A job is reported as `completed` with safe result payload only if:
  1. A result file `job_<id>.json` exists and can be parsed.
  2. The job file's `operation_id` strictly matches the immutable `operation_id` bound at scheduling.
  3. The job file's `job_id` matches the query `jobId`.
  4. The result indicates `ok: true`.
  If any check fails, the status is returned as `needs_attention` with a `settlement_error` marker (never claiming false success or prompting dangerous unverified retries).
- **Strict Parameter Validation**: `UpdateController::apply()` validates any supplied `background` parameter using `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`. Invalid non-null values return HTTP 400 immediately rather than silently falling back to synchronous execution.
- **Path Redaction**: To prevent disclosing host filesystem paths to client browsers, error messages and log strings are sanitized by stripping absolute Windows drive paths (e.g. `C:\xampp\...`) and Unix system paths (e.g. `/var/www/...`). Safe placeholder `[path]` is substituted before returning JSON data via `GET /api/update/jobs/{id}`.

---

## 3. Rate-Limiting, Polling Isolation & Admission Safety

### 3.1 Rate Limiting Contract
The update status and job polling endpoints share a rate limiter key (`update_status`) configured to a maximum of 60 requests per minute (`EndpointRateLimiter::limit('update_status', 60, 60)`).

### 3.2 Polling Isolation & Single-Flight Loop
To prevent rate-limit exhaustion and eliminate race conditions:
- **Single-Flight Awaited Polling**: The frontend uses an awaited single-flight loop with recursive timers rather than uncoordinated `setInterval`. Requests are issued with 10-second deadlines.
- **Strict Bound Polling**: When `activeJobId` is set in the frontend, the UI polls **only** `getUpdateJob(activeJobId)` at 2-second intervals. There is **no** fallback or concurrent polling of `getUpdateStatus()`.
- **Consumption Rate**: At 2-second intervals, the polling consumes 30 requests per minute, comfortably within the 60 req/min budget.
- **SessionStorage Job Persistence**: Active job IDs are persisted to `sessionStorage` (`pos.active_update_job_id`) upon dispatch or discovery. The ID is cleared **only** on definitive safe settlement (`completed` with verified payload) or definitive non-retryable failure, remaining intact across component remounts, page refreshes, and transient errors.
- **Strict Job ID & Status Validation**: On every polling iteration, `UpdateSection` verifies that `job.id === activeJobId` and `isValidStatus(job.status)` across all returned statuses (not just completed). Explicit mismatched job IDs or bounded consecutive invalid responses halt automatic polling into `needs_attention` with the job ID safely retained.
- **Absolute Loop Deadlines & Status Resume**: Enforced on *every* loop iteration regardless of mixed network successes or failures:
  - If a scheduled job remains in `pending` state for 30 consecutive seconds, polling halts into `pending_timeout`.
  - If a job remains in `processing` state for 15 minutes, polling halts into `processing_timeout`.
  In both timeout scenarios, `activeJobId` is retained without clearing, rendering a manual "استئناف متابعة الحالة" action to resume monitoring with a fresh loop start deadline via `pollNonce`. Resume never triggers automatic `applyUpdate` POST requests.

### 3.3 Persistent Conservative Handoff State Machine
Desktop delta updates require invoking Electron runtime IPC (`posRuntime.applyStagedDelta`). To prevent duplicate invocations or false-success claims across component remounts and page reloads:
- Per-job handoff lifecycle is persisted to `sessionStorage` under `pos.update_handoff_${jobId}` with states `'running' | 'done' | 'failed'`.
- Before invoking IPC, the state is recorded as `'running'`.
- Upon component remount or status resume:
  - If the persisted marker is `'running'` or `'failed'`, the component stays in `needs_attention`, refusing to re-execute IPC or report success.
  - Only on verified IPC success (`res.ok === true`) is the marker transitioned to `'done'`, clearing the active job from `sessionStorage` before triggering page reload.
  - If IPC rejects or returns non-ok, the marker transitions to `'failed'`, setting `jobState` to `needs_attention` without reloading.

### 3.4 Admission Uncertainty Sentinel & 409 Isolation
Network drops or server crashes during update submission (`POST /api/updates/apply`) create admission uncertainty:
- **Admission Unknown Sentinel**: If the apply POST fails with a network/timeout error or returns a malformed 200 lacking a positive `job_id` or verified result, `sessionStorage.setItem('pos.update_admission_unknown', 'true')` is persisted.
- **Fail-Closed Locking Across Remounts**: The UI displays a warning banner ("حالة إرسال التحديث غير مؤكدة") and blocks duplicate automatic POST submissions upon component remounts until an operator performs read-only reconciliation or explicitly clicks "إلغاء القفل بعد التحقق".
- **Validation Rejection Unlocking**: Standard client-side validation failures (HTTP 400 or 403) unlock the interface safely without triggering the admission unknown sentinel.
- **409 Conflict Isolation**: A 409 response indicating a background queue or active lock (`queue_busy` or `active_lock`) halts cleanly without offering the destructive "replace local modifications" force dialog. The force confirmation prompt is restricted strictly to explicit `local_modifications` conflict codes.

---

## 4. Generation & Context Ownership in updateStore

### 4.1 Four-Level Generation Tracking
To eliminate race conditions between background checks, manual operator actions, channel switches, and local hydration, `updateStore` maintains four distinct monotonic generations:
1. `channelGeneration`: Incremented on `setChannelState` and `invalidate`. Cancels all in-flight promises (`activeSilentPromise = null`, `activeManualPromise = null`, `activeHydratePromise = null`), resets check cooldown timestamps, and resets `isChecking`, `isPending`, and `lastChecked` to clean baseline states.
2. `manualGeneration`: Incremented on `forceCheck`. Stale silent checks (`currentManualGen !== manualGeneration`) abort immediately without updating store state.
3. `hydrateGeneration`: Incremented per `hydrateLocalStatus(true)` to coalesce non-forced calls while discarding out-of-order older hydration responses.
4. `authoritativeGeneration`: Incremented on definitive manual or silent check results. Prevents older in-flight hydration calls from overwriting authoritative versions.

### 4.2 Guard Placement & Channel Switch Protection
Guards (`if (!isCurrent()) return`) are enforced before every state write, check cooldown timestamp update, and inside `finally` blocks in `forceCheck`, `silentCheck`, and `hydrateLocalStatus`.
- If a silent check is initiated on the `stable` channel and the administrator switches to `beta` while the check is in flight, the deferred silent check resolution detects `channelGeneration` mismatch and quietly discards its response, never clobbering the new channel context.
- Calling `setChannelState` invalidates all pending promises immediately so subsequent checks never join stale in-flight promises.

### 4.3 Honest Baseline 0.0.1 Acceptance & Immediate Pending Version
- The POS system honestly accepts `0.0.1` as a legitimate baseline version if reported by `version.json` or `getUpdateStatus()`, removing synthetic rejections (`!== '0.0.1'`).
- While background checks are pending, incoming `current_version` from `checkForUpdates` or status responses is consumed immediately into `currentVersion`, ensuring the user sees their active installed version without a 30-second delay.

---

## 5. Honest Progress Reporting & Channel Transitions

### 5.1 Real Byte Tracking (Download Phase)
During the network download stage:
- `UpdateAssetDownloader` hooks into `CURLOPT_XFERINFOFUNCTION`, throttling progress updates to ~1-second intervals.
- `DeltaUpdateService::recordDownloadProgress(bytes, total, attempt)` records real bytes to `update-state.json`.
- The UI displays:
  - Formatted byte progress: e.g. `(4.2 ميجابايت / 12.8 ميجابايت - 33%)`.
  - Proportional determinate progress bar width based on `(downloaded_bytes / total_bytes) * 100`. When `transferredBytes` is 0, progress bar width renders as `0%` honestly.

### 5.2 Indeterminate Indicators (Non-Download Phases)
Hardcoded arbitrary percentages (such as 25%, 45%, 65%, 80%, 92%) have been completely eliminated.
- For non-download stages (`verifying`, `backing_up`, `applying`, `migrating`, `desktop_handoff_pending`), progress bars use honest indeterminate pulsing indicators (`animation: pulse`) and clear textual stage labels.
- Upon phase transition out of `downloading`, download byte counters are reset to zero to prevent stale progress display.
- During channel transitions, UI progress derives honestly from `TransitionPhase` (`preparing`, `installing`, `verifying`), preventing contamination by stale delta update states.

### 5.3 Channel Transitions & Selection Guards
- The channel selector state in `UpdateSection` initializes as `null`.
- While `currentChannel` is null, a loading indicator (`جاري التحميل...`) is displayed, preventing race conditions or premature channel switching.
- `ChannelTransitionPanel` remains unmounted until both `currentChannel` and `installed_channel` are loaded from the backend.
- Switching channels resets `hasUpdate`, `latestVersion`, and check cooldowns to allow immediate checks.
- In `useChannelTransition`, setting `autoPreview: false` resets existing preview data to `null` and prevents unsolicited network requests.

---

## 6. Verification Suite & Real React DOM Test Harness

All updates to the Admin Update Center must pass the full verification matrix:

| Verification Script / Gate | Target | Key Assertions & Focus Area |
| :--- | :--- | :--- |
| `scripts/verify-admin-update-center.mjs` | Node.js / VM | Generation guards, context ownership, honest 0.0.1 baseline, immediate pending version, endpoints background forwarding, executes UI test harness |
| `scripts/verify-admin-update-ui.mjs` | Rolldown + Electron | **Real React DOM UI Harness**: mounts actual `UpdateSection`, `UpdateProgress`, and `useChannelTransition` with 9 concrete assertions |
| `scripts/verify-admin-update-center.php` | PHP CLI | Downloader throttling, Delta progress reset, sanitized jobs, strict background parameter validation |
| `scripts/verify-update-background.mjs` | Node.js | Background check scheduling, debouncing, store state |
| `scripts/verify-update-background.php` | PHP CLI | Provider caching, job worker isolation, telemetry safety |
| `scripts/verify-transition-ui.mjs` | Electron / Rolldown | Channel panel preview lifecycle, wait retention, stale response handling |
| `scripts/verify-update-notifications.mjs` | Node.js | Update notification badge and toast triggers |
| `scripts/verify-update-http-timeout.mjs` | Node.js | Update HTTP timeout budgets and retry policies |
| `scripts/verify-update-execution-budget.php` | PHP CLI | PHP execution time budget management |
| `scripts/verify-delta-flow.mjs` | Node.js | Delta update manifest, patching, and staging workflow |
| `frontend` typecheck | TypeScript CLI | Strict typecheck (`tsc --noEmit`), zero type errors |
| `frontend` lint | ESLint CLI | Strict ESLint compliance |

### 6.1 Real React DOM UI Assertions (`verify-admin-update-ui.mjs`)
1. **Gated Admission with Completed Old Status**: Mount without active job when API returns completed status; verifies zero IPC calls, zero reloads, and zero success toasts.
2. **Pending Timeout Retains Busy**: Fast-forward past 30s deadline; verifies active job retained in `sessionStorage`, resume banner displayed, and clicking resume triggers status check without re-POSTing.
3. **Mismatched Job ID Cannot Settle**: API returns mismatched `job.id !== activeJobId`; verifies active job retained, zero reloads, zero IPC calls, and transitions to `needs_attention`.
4. **Completed Job with Null Result**: Completed status with `result: null`; verifies active job retained, zero reloads, and zero success toasts.
5. **Desktop Handoff Invocation & Indeterminate Stage**: Gated IPC; verifies handoff invoked exactly once, marker set to `'running'`, indeterminate stage displayed, and verified completion transitions marker to `'done'` and clears active job.
6. **Remount During/After IPC Failure**: Mount with marker `'failed'`; verifies zero duplicate IPC calls and stays in `needs_attention`.
7. **Admission Unknown Sentinel Across Remounts**: Mount with `pos.update_admission_unknown`; verifies zero automatic apply POSTs, warning banner rendered, and manual unlock action available.
8. **Zero Byte Progress**: Renders `UpdateProgress` with 0 transferred bytes; verifies progress bar width style is strictly `'0%'` and text displays `'0%'`.
9. **Channel Transition `autoPreview: false`**: Mounts `useChannelTransition` with `autoPreview: false`; verifies zero preview API requests and `preview === null`.
