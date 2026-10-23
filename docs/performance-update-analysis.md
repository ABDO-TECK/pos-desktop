# Update Check Architecture & Performance Analysis

## Overview

This document details the background update check architecture, outbound network footprint, and execution characteristics of application update checks in the POS desktop environment.

---

## Outbound Network Call Analysis

Under controlled provider testing, outbound network calls to GitHub / release distribution servers operate as follows:

| Endpoint / Operation | Previous Behavior | Current Background Architecture |
| :--- | :--- | :--- |
| **GET /api/updates/status** (Cold Cache Miss) | 1 outbound request (release metadata only via `fetchRemoteVersion`) | **0 outbound requests** (reads local cached remote version only) |
| **GET /api/updates/status** (Warm Cache) | 0 outbound requests | **0 outbound requests** |
| **Automatic Check** (`silentCheck` Cold Cache Miss) | 1–3 outbound requests on the web/HTTP thread (release metadata, delta manifest, cryptographic signature) | **0 outbound requests on HTTP thread**; delegates check to asynchronous background worker via `JobQueue::dispatchUnique` |
| **Automatic Check** (`silentCheck` Concurrent Mounts) | Multiple concurrent HTTP requests and network discovery bursts | **Single-flight deduplicated**: exactly 1 HTTP request, 0 outbound calls on HTTP thread |
| **Automatic Check** (`silentCheck` Warm Cache) | 0 outbound requests (6h TTL) | **0 outbound requests** (reads local atomic JSON cache) |
| **Manual Check** (`forceCheck` / "Check Now") | Full synchronous discovery, manifest retrieval, and signature verification | **Preserved full synchronous discovery**: 1–3 outbound requests (release, manifest, signature) under explicit user action |

*Note on status endpoint:* The status endpoint (`/api/updates/status`) originally fetched release metadata only (1 external HTTP request to the releases endpoint), not manifests or signatures. Under the current architecture, status reads strictly inspect local cache and never make outbound requests on cache misses.

*Note on timing metrics:* In accordance with testing policy, no simulated or artificial millisecond benchmarks are claimed. Performance improvement is characterized by the elimination of external network discovery and blocking I/O from the HTTP request lifecycle.

---

## Architecture & Lifecycle

### 1. Fast HTTP Background Check (`BackgroundUpdateCheckService`)
- When the frontend invokes `GET /api/updates/check?background=true`, the controller delegates to `BackgroundUpdateCheckService::readOrSchedule()`.
- **Cache Hit:** If an unexpired, validated JSON cache entry matching the exact context fingerprint exists, the result is returned immediately (0 queue jobs, 0 outbound requests).
- **Cache Miss:** A non-blocking schedule file lock (`LOCK_EX | LOCK_NB`) coordinates dispatch. If acquired, a unique background job (`check_for_updates`) is scheduled via `JobQueue::dispatchUnique()`, and the request returns `status: 'check_pending'` (`pending: true`, `has_update: false`).
- If another process is holding the schedule lock (busy), the service returns `pending: true` immediately without redundant queue dispatches or network calls.

### 2. Worker Discovery & Atomic Cache Publication
- The worker executes asynchronously in the CLI environment (`JobQueue`).
- The worker verifies the context fingerprint before initiating network discovery to ignore stale queued jobs.
- The worker invokes `UpdateService::checkForUpdate()`, performing complete release discovery, channel compatibility checks, manifest validation, and cryptographic signature verification.
- The worker re-verifies the context fingerprint after discovery to prevent publishing results if the configuration changed during execution.
- Validated results are written atomically via temporary file and atomic rename without pre-unlinking the target, preserving cache availability.

### 3. Frontend Polling & Generation Safety (`updateStore.ts`)
- **Single-Flight IPC Guard:** A module-scoped promise deduplicates concurrent mounts before IPC capability resolution.
- **Bounded Polling:** When `pending: true` is received, the store polls up to ~30 seconds (at 2-second intervals). If the worker does not finish within this window (e.g., worker daemon stopped), the store retains `isPending: true` and applies a 60-second retry cooldown rather than marking a false 6-hour success.
- **Polling Scope:** The 30-second loop covers internal scheduling intervals; IPC and HTTP network transport latency may extend overall elapsed time.
- **Generation Counters:** Both silent checks and manual checks increment a monotonically increasing generation counter. If a user triggers a manual check while a silent check or polling loop is active, the manual check takes precedence. Late completions from older silent checks are discarded and cannot overwrite store state or cooldown timestamps.
- **Manual Check Priority:** If a manual check is actively in flight, subsequent silent checks quietly join or wait for the manual check rather than queuing competing background operations.

---

## State & User Interface Scope

- `isPending`: The `isPending` flag is currently maintained at the Zustand store level (`useUpdateStore`). No visual pending indicator or UI badge changes were introduced in this performance-focused refactor.
- **Error Handling & Cooldown Separation:** Successful checks trigger a 6-hour cooldown. Failed checks or unreachable networks trigger a short 60-second retry cooldown. Manual check failure timestamps are strictly segregated from successful check timestamps, ensuring failed manual checks do not suppress subsequent automated background retries.
