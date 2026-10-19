# Sale idempotency

Every `POST /sales` request must include an `idempotency_key` containing a UUID
v4. The client creates one key for a logical sale before its first request and
keeps that key unchanged for online retries and offline replay.

The server hashes the validated request after recursively sorting object keys.
It stores that hash and the successful response snapshot in
`sale_idempotency_keys`. Keys are unique per branch:

- The same key and hash returns the stored HTTP status, message, invoice, and
  low-stock result with `data.idempotency.replayed` set to `true`.
- The same key with a different hash returns HTTP 409 and does not mutate data.
- A database unique-key claim occurs inside the sale transaction. Concurrent
  duplicates therefore cannot both create invoices or apply stock, ledger,
  loyalty, or inventory-event side effects.

## Reserved invoice replacements

Updating or completing a reserved invoice is a new logical sale operation and
must use a new idempotency key. The key claim is stored separately from the
invoice, and the target invoice row is locked before its old lines are restored
or replaced.

Each successful replacement keeps its own immutable response snapshot. Replaying
an older replacement key returns that key's original response and never rewinds
or reapplies the invoice. If the invoice is later deleted, its idempotency record
and response snapshot are retained (the invoice foreign key becomes `NULL`) so
an old retry cannot recreate the deleted sale.

## Deployment and rollback

Run migration `042_add_sale_idempotency.sql` before deploying the backend and
frontend that require the key. The schema change is additive. To roll it back,
first roll back the application release, then run:

```sql
DROP TABLE IF EXISTS sale_idempotency_keys;
```
