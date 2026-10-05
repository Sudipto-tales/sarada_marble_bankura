# Shared implementation contracts

Design specification, not implemented code. Read this with the guide for your selected task. Existing-source anchors are dated 2026-10-05; verify the named symbols if lines move. See [code map](code-map.md), [task index](tasks/README.md), and [data flows](data-flow.md).

## Values and boundaries

| Value | Canonical representation | Validation |
| --- | --- | --- |
| IDs | Positive database integer, serialized as a decimal string in JSON | Reject signs, exponents, leading ambiguity, unknown IDs and unauthorized ownership |
| Money | Nonnegative integer `*_minor` in INR paise; signed integers only for explicitly signed adjustments | Verify 64-bit PHP, bound input and multiplication; no binary float calculation |
| Quantity | Integer `qty_milli`, 1,000 = one sell unit | `sqft` may use configured increments; `slab`/`box` require multiples of 1,000 |
| Coverage | `coverage_sqft_milli` per slab/box | Positive and fixed for an ordered variant; display square-foot conversion separately |
| Price | `unit_price_minor` per sell unit | One variant uses one sell unit; do not bill sqft price against box count |
| Factors | Positive integer parts-per-million, 1,000,000 = 1.0 | Allowlisted rule type, bounds and effective time interval |
| Tax/percentage | Integer basis points, 10,000 = 100% | Store-configured basis/rate; validate before production use |
| Commerce time | UTC timestamp; ISO-8601 with `Z` in APIs | Localize for display only; no global timezone mutation during auth |
| Queue time | DB-derived epoch seconds, per [queue guide](tasks/05-queue.md) | One authoritative DB clock for availability/leases |
| Guest cart | Random token in a protected cookie; SHA-256 hash stored in DB | At least 32 random bytes; do not accept another owner's cart ID as authority |
| Authenticated principal | Server-derived user ID, status and current grants | Client cannot choose role, user ID or permissions |

Prefer integer SQL columns for canonical minor units/milli-units; `DECIMAL` is allowed for reporting/import staging with explicit conversion. This refines the initial plan's fixed-precision requirement into one unambiguous calculation path. Mobile `double` fields are presentation/prototype values, not trusted checkout input.

### Required rounding algorithm

For nonnegative integers `n` and positive `d`, `round_half_up(n / d)` is `quotient + (remainder >= ceil(d / 2) ? 1 : 0)` using integer quotient/remainder. This avoids overflowing `2 * n` or `n + d/2`. Check multiplication bounds before computing `n`.

- Line subtotal = round_half_up(`unit_price_minor * qty_milli / 1000`).
- Tax-exclusive default = round_half_up(`taxable_minor * tax_bps / 10000`); inclusive tax requires a separately tested store policy.
- Total = sum(line subtotals) − validated discount + shipping + tax; reject negative totals.
- Sum rounded line amounts; do not alternately round a cart-wide float. Persist the rounding/tax policy version in the order snapshot.

Vectors: price 12,500, quantity 1,500 → 18,750 minor; price 101, quantity 500 → 51 minor; 10,000 taxable at 500 bps → 500 tax. Reject quantity 1,500 for `slab`, even if its money arithmetic is valid.

## Proposed service boundaries

Names below are planned method contracts, not existing classes. Keep services focused; no generic repository factory or dependency-injection framework is needed.

| Service / operation | Inputs | Outputs and transaction ownership |
| --- | --- | --- |
| ProductService.list / detail | Validated public filters/slug | Public product records; bounded pagination |
| ProductService.save | Current staff actor, validated product/variants, expected revision | Persisted product/revision; owns one transaction including audit/cache version |
| InventoryService.reserve / consume / release | Caller PDO, reservation/variant IDs, qty_milli, business key | Locked transitions; never commits an OrderService-owned transaction |
| InventoryService.adjust | Staff actor, variant, signed delta, reason, operation key | Audited adjustment; owns transaction, cannot reduce on-hand below reserved |
| CartService.change / merge | Server principal, variant, quantity, expected cart revision | Updated owned cart; ignores browser prices |
| OrderService.place | Server principal, cart revision, owned address ID, payment mode, idempotency key | Existing or new order with totals; owns entire checkout transaction |
| OrderService.transition | Authorized actor, order ID, target, expected revision, operation key | One legal transition/history/stock effect/audit transaction |
| Queue.enqueue (public push alias optional) | Caller PDO, enabled-or-held queue, type/version, payload, dedupe key | Durable job ID; no reconnect, implicit commit or handler execution |
| Cache read | Authoritative namespace version, canonical bounded filter key | Hit or miss; miss does not fail the business request |
| EventService.record | Allowlisted event, principal/anonymous context, bounded validated fields, event key, caller PDO/transaction when present | Append-only record or duplicate result; standalone caller owns transaction, nested order/job usage never begins/commits another transaction; purchase authority comes from order data |

Controllers call services directly. Existing [BaseController.apiGet](../../Website/core/BaseController.php#L15) and [Helpers.api_request](../../Website/core/Helpers.php#L18) make outbound HTTP calls; they are not the in-process service layer. Calling your own public API from a PHP view/controller introduces extra auth, latency and failure modes.

## Transaction and lock policy

One top-level service owns begin/commit/rollback. Sub-operations receive that PDO and do not begin another transaction. Cache version, required audit, stock movements, checkout result and jobs use that connection. External network/filesystem work occurs outside DB locks, with explicit compensation/staging where needed.

For order mutations use one lock order: checkout idempotency record (placement only), cart (placement only), order (if already created), reservation headers, product/variant rows in ascending IDs, inventory rows in ascending variant IDs, then version/audit/job rows. A caller can omit unrelated earlier groups, but must not lock a later group and return to an earlier group. Reservation cleanup locks reservation header then inventory. Admin catalog writes lock product/variants before dependent inventory. Never lock all orders/stock for a dashboard read.

DB-only queue handlers obey the same domain-first order, then acknowledge their current live lease as the final write in the effect transaction; a failed acknowledgment rolls back the effect. Queue claim transactions commit before handlers start. Do not hold a job-row lock while acquiring domain locks in the opposite order.

After discovering a concurrency failure, retry the entire top-level transaction, not only its final statement. Limit retries (initially three attempts with bounded jitter). Re-read all prices/statuses/stock; perform no external effects before a retry can occur. MySQL inventory uses locking reads; SQLite uses a deliberate write transaction/conditional updates and cannot substitute for MySQL race verification.

## HTTP/API contract

Use globally unique controller class names (`AdminProductController`, `StorefrontProductController`) unless an agreed namespace loader is implemented. Use the existing render contract: [respond](../../Website/core/BaseController.php#L10) ultimately passes its argument directly to [load_view](../../Website/config/bootstarp.php#L25); actual views require paths such as `app/page/admin/products/index.php`, including the extension.

New parameterized routes group handlers by HTTP method; legacy `[class, method]` entries keep their current controller guards. Route selection never resolves a client-supplied controller filename. JSON errors contain `error.code`, `error.message`, optional safe field errors, and `request_id`. Use 401 unauthenticated, 403 unauthorized, 404 absent/not-visible record, 405 method mismatch, 409 revision/idempotency/stock conflict, 422 invalid business input, 429 throttled, 503 temporary infrastructure failure. Do not return SQL or SMTP exception text.

Checkout request contains a stable cart ID/revision, owned address ID, configured payment mode and idempotency key; no trusted totals or user ID. The fingerprint is a versioned canonical serialization of those semantic inputs, excluding CSRF token/request ID. Database uniqueness is `(principal_key, idempotency_key)`. Same key/same fingerprint returns the saved response; same key/different fingerprint is 409.

## Minimal task handoff

For one task read root `AGENTS.md`, its tracker row, this contract's relevant sections, the selected guide and its linked source functions. Do not load every mobile asset or the entire Developer view. Produce working behavior for that task, actual checks, changed paths and unresolved prerequisites. Avoid empty controllers/services, pass-through wrappers, invented demo dashboards and tests that merely check a method exists.
