# POS Desktop Catalog Performance & Correctness Analysis

## 1. Executive Summary

This document details the observed issues, architectural fixes, verification methodology, and simulated metrics for the Point of Sale (POS) Desktop catalog synchronization system in `frontend/src/store/productStore.ts`.

All changes preserve existing Zustand action signatures (`fetchProducts`, `fetchCategories`, `findByBarcode`, `setProducts`, `invalidateCache`) and maintain offline IndexedDB (IDB) functionality without modifying database schemas or `useAuthStore`.

---

## 2. Root Cause Analysis

Inspection of `fetchProducts` identified several correctness and concurrency issues:

### 2.1 Lack of In-flight Request Deduplication
When multiple UI components (such as POS catalog grid, barcode scanners, or cart initialization) invoked `fetchProducts()` concurrently on mount or during user interactions, each invocation triggered an independent network request and execution loop.
- In concurrent calls, each caller triggered `getProductCatalogPage(checkpoint)` and sequentially called `applyProductCatalogPage(...)` against IndexedDB.
- Multiple overlapping snapshot writers wrote to IndexedDB concurrently, causing redundant network requests and disk contention.

### 2.2 Broken Cache TTL Guard & Filtered Overwrite
The baseline TTL check was implemented as:
```typescript
if (!forceRefresh && !params.search && !params.category_id) {
  if (state.lastFetched && (Date.now() - state.lastFetched) < CACHE_TTL_MS) {
    return state.products
  }
}
```
This check only inspected `search` and `category_id`. Consequently:
- Requests with parameters like `low_stock: true`, `page: 2`, or custom filters bypassed the full catalog sync loop, fell into `getProducts(params)`, and fetched a partial list (e.g., 5 items).
- The returned filtered subset was saved to `state.products` and updated `state.lastFetched = Date.now()`.
- Subsequent full catalog requests (e.g. `fetchProducts()`) saw `lastFetched` was recent and returned `state.products` from memory—serving the filtered 5 items as if they were the entire product catalog.
- In addition, filtered requests like `low_stock` were never meant to share full catalog TTL expiration.
- In earlier revisions, filtered requests also checked auth epoch solely for publication, returning old-session filtered results to callers even after an auth change.

### 2.3 Stale Responses Across Auth, User, and Branch Transitions
The store lacked an auth lifecycle subscription:
- If a sync was initiated under Branch 1 and the user switched to Branch 2, or logged out, the asynchronous flight continued executing in the background.
- Upon completion, the flight could write Branch 1 pages to IndexedDB under the active session and publish Branch 1 products to the store of Branch 2, risking cross-branch catalog state mixing.
- Same-user logout and immediate re-login also risked applying stale in-flight pages from the terminated session into the fresh session.
- Stale `finally` handlers could decrement `activeRequestCount` belonging to a subsequent session, prematurely clearing the loading state.

### 2.4 Race Conditions in Missing Branch Resolution
When a user logged in with an unassigned `branch_id: undefined`, the first catalog page scope `branch:<id>` was parsed to resolve the user's branch via `authStore.setUser(...)`. Without synchronization guards, updating `user` in `authStore` risked triggering store resets or race conditions with other concurrent callers.

---

## 3. Architecture & Design Fixes

The updated `frontend/src/store/productStore.ts` implements the following defenses:

### 3.1 Single-Flight Full Catalog Synchronization
- An `InFlightFullCatalogSync` registry records the currently executing full catalog sync promise, tagged with the initiating `epoch`, `userId`, and `branchId`.
- Concurrent invocations of `fetchProducts()` (unforced or forced) with identical scope share the active promise, collapsing redundant concurrent network synchronization routines into 1.
- If a flight fails, the registry is cleaned up immediately in a `finally` block, allowing subsequent calls to retry fresh.

### 3.2 Auth Context & Session Epoch Tracking
- An auth subscription (`useAuthStore.subscribe`) monitors transitions across `userId`, `branchId`, and `isAuthenticated`.
- Any material auth boundary (logout, user switch, branch change, or relogin) increments `sessionEpoch`, immediately nullifies the active flight, resets catalog presence flags, clears active request counts, and purges store products.
- The sync loop verifies `sessionEpoch === requestEpoch` before fetching each page, before applying each page to IDB, and before publishing final products to Zustand. Stale responses from prior sessions are aborted.
- Filtered queries similarly check `sessionEpoch === requestEpoch` before returning results, aborting with cancellation rather than leaking old-session items, and suppressing misleading error toasts on session change.
- In `finally` handlers, `activeRequestCount` decrement and loading state adjustments are restricted to matching epochs (`sessionEpoch === requestEpoch`), preventing stale completions from mutating a new session's loading state.

### 3.3 Safe Missing Branch Resolution
- When a user's `branch_id` is initially `undefined`, the store inspects the first returned page scope (`branch:<id>`).
- To prevent self-invalidation when calling `setUser`, an `isResolvingBranch` flag is set during the `setUser` call, informing the auth subscriber to update its internal baseline without bumping the session epoch or canceling the flight.
- If subsequent pages return conflicting scopes (e.g. `branch:4` after `branch:2`), or if the server returns a scope mismatched with an assigned user branch, the sync is rejected immediately before committing invalid data.

### 3.4 Strict Cache TTL Separation & Request Ordering
- A helper `isFullCatalogRequest(params)` verifies that all query parameters are `undefined`. Filtered queries (`search`, `category_id`, `low_stock`, `page`, etc.) are routed to `getProducts(...)`.
- `lastFetched` and `storeHasFullCatalog` are set exclusively by successful full catalog syncs. Filtered responses explicitly clear `lastFetched` and `storeHasFullCatalog`, ensuring that subsequent full requests restore the complete catalog.
- Request sequence counters (`latestRequestId` and `latestPublishedRequestId`) ensure that older, slower queries cannot overwrite newer store state.
- IDB hydration publications are likewise guarded by `requestId >= latestPublishedRequestId`, preventing an older full catalog hydration from clobbering newer filtered results or marking the store as full.

### 3.5 Optimizations: IDB Pre-hydration & Empty Delta Preservation
- **Hydration**: When an expected branch scope has a verified complete cache in IDB (`cacheState.complete === true`), cached products are loaded into Zustand before the network flight settles, improving UI responsiveness while background delta sync runs.
- **Empty Delta**: When the server returns `mode: 'delta'`, `products: []`, and `hasMore: false`, the store advances checkpoint metadata in IDB while avoiding a full IDB re-read, preserving store array reference identity (`prevProducts === nextProducts`) to prevent unnecessary React re-renders.

---

## 4. Verification Harness & Simulated Results

A dedicated automated test harness is implemented in `scripts/verify-catalog-performance.mjs`.

> **Note on Test Environment & Methodology:**
> In accordance with project test guidelines, all verification scenarios run in Node.js using `ts.transpileModule` and `vm.runInNewContext` with controlled stubs and an embedded deterministic baseline fixture.
> Reported counters reflect **simulated in-memory call counts** verifying control flow, request collapsing, and data isolation paths.

### 4.1 Demonstrated Test Scenarios

- **In-flight Deduplication (Test 0)**: 3 overlapping `fetchProducts` calls collapse to 1 `getProductCatalogPage` request and 1 IDB write.
- **Multipage Sync & Checkpoint Recovery (Test 1)**: Traversal of multipage cursor pagination and 422 checkpoint error recovery.
- **Filtered Queries & TTL Isolation (Test 2)**: `low_stock`, `page`, `search`, and custom parameters bypass full catalog TTL.
- **Full After Filtered (Test 3)**: Filtered query clears `lastFetched`; subsequent full request restores full catalog.
- **Concurrency & Forced Refresh (Test 4)**: Overlapping unforced and forced calls share flight; solo forced call executes sync.
- **Failure Registry Clearance (Test 5)**: Server error clears active flight and permits subsequent successful retry.
- **Invalidation During Flight (Test 6)**: `invalidateCache()` during sync prevents setting valid TTL timestamp.
- **Auth & Session Defenses (Test 7a–d)**: Stale full sync pages dropped across logout, user switch, branch switch, and same-user relogin.
- **Missing Branch Resolution (Test 8)**: Resolves user `branch_id` from scope safely without self-canceling.
- **Reject Mismatched Scope (Test 9)**: Rejects mismatched branch responses and mid-sync page scope alterations.
- **Hydration, Request Guards & Delta Refreshes (Test 10a–e)**:
  - 10a: Incomplete snapshots are not hydrated before sync completion.
  - 10b: Hydration request-generation guard prevents older full hydration from clobbering newer filtered data.
  - 10c: Empty delta avoids IDB re-reads and preserves array reference identity.
  - 10d: Changed and deleted delta refreshes correctly update products and apply tombstones.
  - 10e: Empty complete catalog functions cleanly with valid cache metadata.
- **Race Defenses, Auth Regressions & Loading Counts (Test 11a–d)**:
  - 11a: Slow filtered query resolving after fast query cannot overwrite newer store products.
  - 11b: Filtered queries across logout, user switch, branch switch, and relogin reject cleanly without leaking old data or showing toast errors.
  - 11c: 1-old + 2-new filtered request sequence verifies old session completion does not decrement new session's request count.
  - 11d: Full sync variant of 1-old + 2-new sequence verifies old session completion does not clear new session's active flight or loading state.

### 4.2 Simulated Request Count Reduction (Test 0)

Under a simulated burst of 3 overlapping calls (`fetchProducts()`, `fetchProducts({}, true)`, `fetchProducts()`):
- **Baseline (Original Store Source)**:
  - Network sync page requests (`getProductCatalogPage`): **3**
  - IndexedDB page write operations (`applyProductCatalogPage`): **3**
- **Fixed (Current Store Source)**:
  - Network sync page requests (`getProductCatalogPage`): **1** (66.7% reduction)
  - IndexedDB page write operations (`applyProductCatalogPage`): **1** (66.7% reduction)
  - Result reference identity: identical object reference shared across all 3 callers.

---

## 5. System Limitations & Boundaries

To maintain engineering precision, the following boundaries and demonstrated limits apply:

1. **Simulated vs. Real-world Benchmarks**: The reductions documented above represent deterministic function call counts in a simulated JavaScript VM harness. They demonstrate algorithmic efficiency and the elimination of redundant network/database calls, but do not represent physical milliseconds on end-user hardware. Physical round-trip times depend on network latency, local SQLite/IndexedDB disk speed, and machine CPU.
2. **Transaction Abort Boundaries**: Guards placed before calling `applyProductCatalogPage` prevent scheduling new write operations after an auth boundary occurs. However, once an asynchronous IndexedDB transaction has already begun execution inside the browser database engine, client JavaScript cannot retroactively cancel that specific transaction. Instead, the store discards the resolution promise and prevents committing stale products to the Zustand store.
3. **In-Memory Harness vs. True Browser Environment**: Verification runs via Node.js VM with controlled stubs and in-memory mock structures rather than an actual Chromium/Electron DOM and real IndexedDB storage.
4. **Backend Push Limitations**: The client store uses cursor/checkpoint delta polling. In environments without persistent WebSocket or Server-Sent Events (SSE) push, catalog freshness still relies on user interaction, periodic interval checks, or manual refresh.
5. **Reports & Heavy Queries**: This optimization specifically governs POS desktop catalog synchronization. Analytical queries, sales report aggregations, and inventory valuation calculations run on distinct backend endpoints and are unaffected by catalog store deduplication.
