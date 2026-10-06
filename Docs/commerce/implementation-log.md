# Continuous commerce implementation log

Authorized on 2026-10-06: complete programming/design, check behavior, fix bugs and continue without waiting for user replies. The Stage 1 plan and stable task IDs define the website milestone; operator/provider deployment checks remain separate, and optional integrations/Flutter networking remain deferred. Work is sequential. Existing user changes are preserved.

Each completed task has source/command evidence in [tracking.md](tracking.md). This log records implementation decisions and bug/fix follow-through.

## Configuration decisions

- Staff grants are explicit database pivots, with operator-supplied staff passwords. No production password or implicit promotion of legacy/Developer identities.
- Use configurable INR integer paise, milli-unit quantities and half-up rounding. Tax/shipping/COD defaults will be documented and configured before launch, rather than invented store values.
- Queue and money authority remain SQL; private storage is outside Website. No deployment or provider account changes for local verification.

## Completed sequence and findings

- FND-01/02/03 already verified; details remain in the tracker.
- IAM-01: explicit roles/grants and secure staff CLI implemented. SQLite and actual MySQL each passed 24 checks.
- Regression maintenance: new migration/FK dependencies made old fixed migration counts and users-table replacement fixtures outdated. Updated counts and the disposable-only parent repair; further full regression runs are required as the schema expands.

## Next integrated task

DAT-01: catalog schema and service with fixed-precision stone units/prices, audited writes and revision conflicts. Continue into inventory/carts after verification.

- IAM-02: guards/audit implemented; 23 SQLite and 23 MySQL checks passed. Domain ownership fixtures will be supplemented with real cart/address/order service checks as those modules are integrated.
- Bug/fix: users-only destructive regression fixture lost SQLite NOCASE index and MySQL rejected incompatible FK parent types. Plan: recreate only the disposable source schema, preserve fixture probes, rerun all migration contracts. Implemented; Foundation 32 SQLite/31 MySQL and Developer 19 pass; Auth 95 per driver pass.

- DAT-01: catalog/schema/integer arithmetic implemented; 32 checks per SQLite/MySQL. Public price filters use the lowest enabled price consistently, avoiding a false range match from two different variants. Category tree writes serialize before checking ancestry.
- Test finding/fix: copied black-granite fixture had white-marble tags, correctly matching multi-field search. Corrected fixture and reran both drivers successfully.
- Configuration bounds: up to 20 submitted variants, 100 retained variants/product, 12 images/product, page size at most 50, page at most 1000, category ancestry at most 30. Public category/brand visibility uses the directly assigned enabled records. Monetary multiplication rejects overflow rather than switching to floats.
- Next: DAT-02 inventory/reservation/movement algorithms and actual MySQL races; then DAT-03 owned carts/addresses.

- DAT-02: inventory and reservations implemented; 34 SQLite/34 actual MySQL checks, including independent-process races. New variants start at zero stock. Unit/coverage identity stays fixed once stock history exists.
- Concurrency review/fix: MySQL repeatable-read could hide a committed idempotency movement after waiting on a stock lock. Replay queries now use current locking reads; concurrent duplicate adjustment recovers with one movement/audit/stock effect.
- Forward dependency: cart and order metadata will gain scoped linkage checks in DAT-03/DAT-04; standalone inventory tests use no missing cart/order tables.

### DAT-03 — customer carts and delivery addresses

Implemented owned active carts, unique lines, current-price totals, bounded quantities, stale revision errors and explicit removal. Guest capability cookies contain random proof; SQL stores its SHA-256 hash. Login merge locks source/destination IDs in order, combines canonical quantities, reports unavailable variants and records a replay-safe closed source. Old guest proof cannot reopen or move the merged cart to another account. Reservation integration checks cart ownership/revision and permits only one active hold; cart changes require that hold to be released.

Addresses accept the server user only, validate India delivery fields, cap active addresses and retain soft-archived records. User-row serialization plus a generated unique default-owner key protects concurrent default updates. First address defaults automatically; archiving the default chooses another active address. Generated SQLite columns required changing schema inspection from table_info to table_xinfo. Forward cart links use a MySQL FK and explicit SQLite triggers.

Verification: CustomerCartTest passed 35 checks on each SQLite 3.45.1 and Oracle MySQL 8.0.46, including actual independent-process races. Inventory regression passed 34 SQLite checks. HTTP cookie/login integration remains assigned to WEB-02; no public checkout or reservation endpoint is exposed yet. Next task claimed: DAT-04.

### DAT-04 — order snapshots, history and state rules

Added immutable order/item/address/calculation snapshots, separate payment status, unique accepted cart/order number, principal-scoped checkout idempotency and append-only status history. History links restrict deletion rather than cascading. Arithmetic CHECK constraints reject incoherent snapshots. Inventory movements now link real orders through a MySQL FK or SQLite forward triggers. The status policy permits forward fulfillment and cancellation before shipment; returns require the later reviewed workflow. COD starts unpaid; simulation requires explicit non-production enablement.

Verification: OrderSchemaTest passed 32 checks per SQLite 3.45.1 and MySQL 8.0.46, including changed source records/archive preservation, restrictive deletion, unique keys, arithmetic and state/payment policy. Checkout itself remains ORD-01 after queue/cache prerequisites. Next task claimed: INF-01.

### INF-01 — durable queue producer

Implemented jobs/dead letters, strict versioned ID-only payloads and typed notification/expiry/purchase producers. Enqueue requires the caller's transaction on the exact same PDO; it cannot commit independently. Canonical payload normalization resolves equivalent concurrent active keys while rejecting different semantic input or explicit schedules. MySQL dedupe keys use binary collation. Purchase intents are held by excluding their queue from worker allowlists until EVT-01. Identity columns use the common signed INT boundary; leases/deadlines use BIGINT database epoch seconds.

Verification: QueueProducerTest passed 24 checks per SQLite 3.45.1 and MySQL 8.0.46, including independent concurrent enqueue, rollback, key/payload corruption and schedule checks. Workers and delivery are not claimed by producer completion; INF-02 is now claimed.

### INF-02 — bounded workers, leases and queued verification

Implemented portable conditional-update claims rather than assuming SKIP LOCKED hosting support. Successful claims consume attempts once and use fresh random lease proof; all lease mutation/final acknowledgment paths lock/recheck current proof and fresh DB time. Added bounded exponential retry, permanent archival, crash exhaustion reaping, durable operator replay and a short CLI worker. Fenced acknowledgment is the final write of DB-only effects. Workers reject held queues and leave no-work/budget-limited invocations successfully. MySQL domain waits are bounded at 3 seconds; workers use 1-second waits.

Notification delivery has persistent dedupe and a separate sender lease. SMTP runs outside SQL transactions with 1–10 second configured timeout. Queue-mode signup atomically stores user, hashed proof placeholder and ID-only intent; the worker generates the raw verification proof in memory, persists only its hash and sends the URL. Production defaults remain disabled until operator-configured SMTP/cron; local test delivery is private 0600 JSON. Invalidated/expired verification intents are safe no-ops. SMTP accepted then SQL failed can cause a later duplicate; a test deliberately demonstrates this boundary.

Verification: QueueWorkerTest 47 per SQLite 3.45.1/MySQL 8.0.46, including independent worker/operator races, lease recovery/fencing, real inventory-effect rollback, archive failure, bounded CLI and queued signup/transport. AuthSecurityTest 95 SQLite, QueueProducerTest 24 per driver, DeveloperAccessTest 19. External SMTP and hosting cron remain pending. INF-03 now claimed.

### INF-03 — committed catalog versions and private cache

Implemented canonical public listing/detail read keys using current committed SQL versions, bounded validated JSON envelopes and atomic private file publication. Transaction snapshot reads bypass cache and file work. Dirty/expired/malformed entries miss; unavailable storage, disabled cache or absent Redis fall back to SQL. Identity/CSRF/address/cart/order data cannot enter public keys/payloads. Existing transactionally advanced catalog versions make cleanup/eviction optional for correctness. Cleanup is bounded and preserves active temp writers.

Verification: CacheTest passed 26 per SQLite 3.45.1/MySQL 8.0.46, covering warm/current price and availability, late old-version writes, failed version rollback, corruption/private fields, permissions, transaction bypass, key normalization and Redis absence. FoundationTest passed 32 SQLite. Connected Redis remains optional unverified. ORD-01 now claimed.
