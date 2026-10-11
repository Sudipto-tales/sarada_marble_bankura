# PAR-00 — storefront foundation repair

Scope: first sequential implementation slice of the [parity plan](../../website-parity-integration-plan.md).
Use [WEB-01/02/03](08-storefront-api.md), [ORD-01/02](04-inventory-orders.md),
and the [shared contracts](../contracts.md). Status and executed evidence belong
in the [tracker](../tracking.md).

## Integrated behavior

- `StorefrontController` uses the existing bootstrapped PDO and full view paths,
  relative installation-aware URLs, exact money display and sanitized HTTP errors.
  Commerce responses are private/no-store; Developer uses its independent boundary.
- `/shop`, `/category/{slug}`, `/product/{slug}` use the global service map and
  actual catalog result shapes. Listing filters are validated and missing category
  or product routes return 404. Public product APIs return JSON data, metadata,
  string IDs/minor-unit money and structured errors.
- GET `/cart` reads the server principal. POST `/cart` validates CSRF, decimal
  quantity, variant, owned cart and revision, then calls `CartService::change`.
  Browser decimal quantities convert to integer milli-units without floats.
- GET/POST `/account` provides verified-account sign-in through `Auth`, guest merge
  and visible excluded-item feedback. POST `/account/logout` clears commerce
  checkout state. POST `/account/addresses` creates an owned delivery address.
  These are the minimum checkout entry points; full account parity remains PAR-03.
- GET `/checkout` chooses an owned address and enabled payment method, then
  generates a server quote. At most 20 private session review records retain
  key/actor/semantic inputs/accepted quote. Identical review reloads retain their
  key; changed prices or policy create a different review key.
- POST `/checkout` validates CSRF and the retained review before calling
  `OrderService::place`. Authoritative locked prices/stock still decide acceptance.
  Changed prices/charges yield 409; browser totals are ignored. A committed retry
  resolves its saved result before rejecting the closed cart. No controller clears
  an accepted cart separately. GET `/order/{id}` reads only the actor's persisted
  order and immutable snapshots.
- `OrderService::transition(actor, order, target, revision, key, reason)` requires
  staff grants and the shared state machine. Its `cancel` wrapper requires the
  expected revision and stable key too. Cancellation locks the order, reservation,
  products/variants and inventory, validates consumption evidence, restores on-hand
  once, preserves unrelated reserved quantities, and commits history/audit/version
  and a cancellation notification together. No customer cancellation route is
  exposed yet. Fulfillment changes never consume stock a second time.
- Completed checkout/history/audit replay reads use current locking reads on
  InnoDB so a waiting request does not see a snapshot from before the first commit.

## Verification

From `Website/`, run `php tests/OrderPlacementTest.php` and
`python3 tests/storefront_http_test.py`. Repeat with `--mysql` and an explicitly
disposable `TEST_MYSQL_HOST/PORT/USER/PASSWORD` target. The harness creates and
drops its own random schema; never point tests at production. Verify migration,
authentication, catalog, cart, inventory, cache, queue and Developer regressions.

Placement checks cover rounding/tax allocation, ownership, revision conflicts,
completed retries, disabled-new-payment replay, changed prices/policy, expired
and existing holds, item/queue failure rollback, last-stock and duplicate-key
races, audited transitions and cancellation replay/rollback. Synthetic deadlock
exceptions verify the three-attempt whole-transaction retry budget; they are
identified separately from actual independent-process InnoDB contention.

The HTTP harness uses the real Vayu launcher and real Auth login, with independent
cookie jars and disposable data. It checks HTML escaping, product JSON, guest
ownership, CSRF/method guards, login/merge/address creation, quote refresh and
retry, ignored browser totals, confirmation ownership, logout and durable counts.

## Remaining boundaries

PAR-01 ports the existing HTML appearance into this working PHP flow. Signup,
verification UI, full profile/address editing and wishlist remain PAR-03; customer
order history/tracking/cancel/reorder/returns remain PAR-04. Cart/address/order API
registration and full contracts are still WEB-03 work. Maintenance consumers,
low-stock policy, analytics reversals and production/provider/hosting verification
remain separate tasks. Flutter stays offline. No production migration, release,
deployment, source credentials or production seed account is part of this slice.
