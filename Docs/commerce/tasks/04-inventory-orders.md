# Inventory, order snapshots and transactional checkout

Scope: DAT-02, DAT-04, ORD-01, ORD-02. Use [contracts](../contracts.md) for units, lock order, rounding, actor ownership and idempotency. Use [queue](05-queue.md) for async work. The chosen MVP consumes stock at accepted order placement, not again at shipment.

## DAT-02

Existing references: [shared PDO and prepared queries](../../../Website/config/db.php#L7), [migration pattern](../../../Website/database/migrations/UsersTable.php#L4), [mobile cart prototype](../../../Mobile/lib/core/state/cart_controller.dart#L11). There is no existing production InventoryService.

Proposed tables:

- `inventory`: unique variant_id, on_hand_milli, reserved_milli, revision; enforce nonnegative values and `reserved <= on_hand` in every write.
- `inventory_reservations`: header ID, owner principal, cart/revision, state active/consumed/released/expired, expires_at; unique logical reservation key.
- `inventory_reservation_items`: header/variant unique pair and qty_milli.
- `inventory_movements`: variant, signed on-hand/reserved deltas, reason, actor/order/reservation references, unique `(business_key, variant_id)`, created_at.

Availability = on_hand − reserved. For a new stock-bearing variant, create exactly one inventory row; never interpret a missing row as unlimited stock. `InventoryService` operations use the caller's transaction/PDO. Validate all lines before changing any line.

### Reserve algorithm

1. Lock the owned cart and reservation header according to the shared order; resolve an existing operation key before creating a new header.
2. Lock variants then inventory by ascending IDs. Verify sell units/increments, quantities, product enabled status and sufficient available stock for every line.
3. For each line increase reserved by requested quantity, insert reservation line and uniquely keyed reserve movement. Set active/expiry using database time. Advance catalog availability version in the same transaction.
4. Return the existing reservation for a replay of the same inputs; reject conflicting inputs using its key.

### Consume/release algorithm

Lock header → re-read state/expiry → lock its stock rows in ascending order. Consume only active, unexpired reservations: subtract qty from both on-hand and reserved; set consumed; write unique movements. Release/expire only active reservations: subtract reserved only; close state; write unique movements. A previously consumed/released/expired reservation is a no-op for a matching replay; a contradictory transition is rejected.

Cleanup delay can conservatively keep expired stock counted as reserved; it can never permit consuming an expired reservation. A bounded cleanup job releases it using the same locks/state checks. Do not bulk-delete reservations and subtract stock afterwards.

Adjustment algorithm: staff grant → operation key → lock variant/stock → check `new_on_hand >= reserved >= 0` and bounds → insert signed movement, revision/audit/version → commit. Repeated operation key changes stock once. Reducing stock below live reservations requires an explicit separate cancellation process.

Vectors: stock 5,000; A reserves 3,000 and B requests 3,000 → B denied; A consumes → on-hand 2,000/reserved 0; replay consumes nothing; active expired 1,000 is released once; concurrent expiry/consume results in exactly one valid transition. Two actual MySQL connections are required for race evidence.

## DAT-04

References: [existing migration runner](../../../Website/config/migrate.php#L20), [mobile order status enum](../../../Mobile/lib/data/models/order.dart#L4), [mobile Order snapshot prototype](../../../Mobile/lib/data/models/order.dart#L76).

Proposed records:

| Table | Minimum fields / constraints |
| --- | --- |
| orders | Unique number, user_id, cart reference, status/revision, currency, subtotal/discount/shipping/tax/total_minor, address snapshot, calculation policy version, payment mode/status, UTC timestamps |
| order_items | Order FK, product/variant reference, immutable name/SKU/sell-unit/coverage/qty/price/tax snapshots, line_total_minor |
| order_status_history | Order FK, from/to, actor, reason, unique operation key, timestamp |
| checkout_requests | Unique principal_key + idempotency_key, fingerprint, state, resulting order ID and safe saved response |

Snapshot values are never re-rendered from a mutable catalog price/address. Product/variant archive preserves references. No cascade deletes of order history. Cart deletion does not erase orders. Principal key is server-generated, e.g. `user:<id>`; real order placement requires a logged-in user.

State machine: placed → confirmed → processing → shipped → outForDelivery → delivered. Cancellation allowed from placed/confirmed/processing; returned requires delivered and later explicit return workflow. Unknown/backward transitions reject. Payment state is separate: production COD starts unpaid; simulation starts simulated in non-production only. Neither order placement nor a browser callback proves provider payment.

Checks: invalid transition, duplicate order number/key, changed product/address after order, product/archive FK restrictions and preserved calculation snapshot.

## ORD-01

Existing references: [db helpers use global PDO](../../../Website/config/db.php#L38), [Auth session identity](../../../Website/core/Auth.php#L67), [Mailer.send()](../../../Website/core/Mailer.php#L8). Do not invoke Mailer in the transaction. Proposed `app/Services/OrderService.php` is the sole order-placement transaction owner.

### Placement algorithm

```text
validate principal, cart revision, address ownership, payment mode and key
canonicalize semantic input; compute fingerprint
BEGIN on the shared PDO
  insert or lock unique checkout_requests(principal, key)
  if fingerprint differs: reject conflict
  if completed: load saved order/result; finish read transaction; return it
  lock owned cart; verify revision and nonempty validated lines
  lock any existing reservation header; reject expired/conflicting reservation
  lock enabled products/variants then stock rows in ascending IDs
  derive server prices, units, configured shipping/tax; check bounds
  if matching active reservation exists:
    validate exact owned lines/quantities and reserved/on-hand invariants
  otherwise:
    validate every line against on_hand minus reserved before any stock change
  create immutable order + item/address/price snapshots
  if matching active reservation exists:
    consume already-reserved quantities for this order; do not reserve again
  otherwise:
    acquire a new reservation then consume it for this order
  write placed status history, unique movements, required audit/cache version
  enqueue emails/order_confirmation and held purchase_events/purchase_event
  mark cart checked_out; complete checkout_requests with result/order
COMMIT
return persisted order/result; external jobs run later
```

A unique insert conflict is normal concurrent idempotency: use a driver-specific safe insert/no-op strategy, then lock/read the winning record. Do not swallow arbitrary SQL errors as duplicates. The whole transaction must commit or roll back. A second request waits/retries within a bounded lock deadline and returns the same result when the first commits; if first rolls back, retry may become the owner.

Example: on-hand 1,000, reserved 1,000 owned by this checkout, unreserved availability 0. A matching live reservation may consume its 1,000; a new buyer cannot reserve any. Validate that reserved counters include its exact lines and every on-hand counter can cover consumption. Order writes and stock operations remain in the same transaction, so any later history/job/idempotency write failure rolls back both the order and consumption.

Use a cart revision/input fingerprint rather than hashing its current prices or the current clock. After success, replay checks the completed key before rejecting the now-checked-out cart. A different key on the already-checked-out cart cannot create another order.

Held purchase intents are durable but their queue is excluded until EVT-01. Email queues are enabled only after ORD-02 handlers exist. No Redis required. Catch deadlocks/lock timeouts using bounded whole-transaction retry; re-read facts each time. Map insufficient stock/stale cart/key conflict to safe 409 response.

Required vectors: two users racing for last 1,000 unit; two simultaneous identical checkout keys; same key/different address/revision; new key/reused checked-out cart; invalid address; expired reservation; queue insert failure; item insert failure; client retry after response timeout; bounded deadlock retry. Verify order/item/stock/movement/job/key counts after each failure, not only HTTP status.

## ORD-02

Cancellation algorithm: auth/ownership + allowed transition → begin → lock order/reservation/variants/stock → reject stale revision → if stock was consumed at placement, increase on-hand once with `cancel:<order>:<variant>` movement; do not subtract reserved again → write history/audit/version and enqueue cancellation notification/analytics reversal intent → commit. A repeat of the same successful operation returns its prior result; other attempts cannot restock twice. Shipping does not consume stock again. Return/restock after delivery is an optional reviewed workflow, not a generic status toggle.

Expiry jobs scan a bounded indexed batch of active expired headers, queue deterministic release intents, then handlers lock/recheck state. Use [queue ORD-02](05-queue.md#ord-02) for lease/effect details. Tests race cleanup with order consumption and replay release/cancel jobs; final arithmetic must match one accepted transition.

Order notification handlers read committed order snapshots, validate payload version, use bounded environment-configured transport and avoid long SQL locks. Low-stock notifications use threshold-crossing/deduplication policy so every page view does not send an alert. Provider uncertainty and duplicate-delivery limits remain documented.
