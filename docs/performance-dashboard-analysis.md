# Dashboard Aggregation Performance Analysis

## Executive Summary

This document details the surgical aggregation optimization applied to `ReportService::getDashboardSummary()`. The optimization reduces the executed database queries from **12 down to exactly 6** (a 50% reduction in query executions) while preserving exact financial accounting semantics, clock boundary distinctions, and full backward compatibility.

---

## Query Count Reduction (Fixture Measurements)

Under the SQLite in-memory fixture test harness (`scripts/verify-dashboard-performance.php`), query executions were instrumented using `CountingPDO` and `CountingStatement` tracking active statement execution calls:

| Operation | Baseline (Before) | Optimized (After) | Reduction |
|---|:---:|:---:|:---:|
| Invoice Header Revenue & Counts | 3 queries | 1 query (`getDashboardRevenueTotals`) | -2 queries |
| Invoice Items Cost & Profit | 4 queries | 1 query (`getDashboardItemTotals`) | -3 queries |
| Expense Totals | 2 queries | 1 query (`getDashboardTotals`) | -1 query |
| Product Counters | 2 queries | 2 queries (unchanged) | 0 |
| Supplier Counter | 1 query | 1 query (unchanged) | 0 |
| **Total Query Executions** | **12 queries** | **6 queries** | **-6 queries (-50%)** |

> **Note**: These metrics reflect statement execution counts captured under isolated test fixtures. No production database latency claims or deployed MariaDB EXPLAIN assertions are made.

---

## Architectural Changes (Surgical Design)

All changes strictly follow the existing Model -> Repository -> Service pattern without altering public signatures, return array shapes, or existing method behaviors:

### 1. `InvoiceStatsTrait::getDashboardRevenueTotals(): array`
- Replaces 3 separate header queries (`getTodayRevenue`, `getMonthRevenue`, `getTodayInvoicesCount`) with a single query.
- Operates strictly on the `invoices` table (no `invoice_items` join) to eliminate double-counting risk when invoices contain multiple items or zero items.
- Preserves the existing **DB clock boundary** using `CURDATE()` and `DATE_FORMAT(CURDATE(), "%Y-%m-01")`.
- Filters by `WHERE branch_id = ? AND status = ? AND created_at >= month_start AND created_at < next_month_start`.
- Uses conditional aggregation `SUM(CASE WHEN created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY THEN total ELSE 0 END)` and `SUM(total)`.

### 2. `InvoiceStatsTrait::getDashboardItemTotals(string $today): array`
- Replaces 4 item-level queries (`getTotalCostForDate`, `getTotalCostForMonth`, `getTotalProfitForDate`, `getTotalProfitForMonth`) with a single query.
- Joins `invoice_items ii INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"`.
- Filters by the whole month range derived from the **PHP clock** (`$today`), preserving the existing convention.
- Uses positional placeholders (`?`) exclusively to prevent driver issues with repeated named parameters.
- Avoids wrapping columns in functions (no `DATE(created_at)`), ensuring raw timestamp comparisons `created_at >= ? AND created_at < ?`.
- Uses historical snapshot `ii.unit_cost` rather than mutable product costs (no join to `products`).

### 3. `Expense::getDashboardTotals(string $today): array`
- Replaces 2 separate expense queries (`getTotalExpensesForDate`, `getTotalExpensesForMonth`) with a single whole-month query.
- Conditionally aggregates day expenses within the whole-month range using PHP date boundaries.

### 4. Thin Delegators & Seam in `ReportService`
- Thin delegators added to `InvoiceRepository` and `ExpenseRepository` without any business logic or direct SQL in services/controllers.
- `ReportService::getDashboardSummary()` calls the 3 batch methods plus the 3 unchanged product/supplier counters, executing exactly 6 queries.
- Protected seam `getDashboardDate(): string` captures `date('Y-m-d')` once per request while allowing test subclasses to control target dates deterministically.

---

## Preserved Accounting & Clock Invariants

1. **Clock Boundary Distinction**:
   - Invoice header revenue and count continue to rely on database time (`CURDATE()`).
   - Costs, profits, and expenses continue to rely on PHP runtime date (`date('Y-m-d')`).
   - Verified across differing DB vs PHP clock boundaries (e.g. crossing midnight).
2. **Whole Month Horizon**:
   - Month totals include all records within the calendar month (`[month_start, next_month_start)`), including future dates in the month, strictly matching existing semantics.
3. **Invoices Without Items**:
   - Completed invoices with zero items are included in `today_revenue`, `month_revenue`, and `today_invoices`.
4. **Historical Snapshot Integrity**:
   - Profit and cost calculations read historical `ii.unit_cost` from `invoice_items` at point-of-sale time, never reading mutable current `products.cost`.
5. **No Caching / Zero Invalidation Risk**:
   - `Response::cacheable` header is client-facing HTTP metadata, not server-side query caching.
   - Subsequent writes in the same session immediately reflect in subsequent summary calls without stale state.
6. **Multi-Branch Isolation**:
   - Scoped strictly via `AuthService::getGlobalBranchId()`.
