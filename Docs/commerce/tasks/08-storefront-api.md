# Storefront, checkout UI and versioned APIs

Scope: WEB-01/02/03. Read [contracts](../contracts.md), [order placement](04-inventory-orders.md#ord-01) and [data-flow diagrams](../data-flow.md). HTML and APIs share services; PHP pages must not HTTP-call their own API.

## WEB-01

Sources: [Welcome.index()](../../../Website/app/bridge/Welcome.php#L6), [BaseController.respond()](../../../Website/core/BaseController.php#L10), [view provider](../../../Website/app/view.php#L5), [mobile Product attributes](../../../Mobile/lib/data/models/product.dart#L3).

Proposed controllers: StorefrontCatalogController, StorefrontProductController; views use full `app/page/storefront/...php` render paths. Public `/` can become catalog/home while preserving explicit legacy demo routes and Developer behavior.

Listing algorithm: whitelist category/brand/finish/price/availability/sort filters → clamp page size (initial max 50) → build prepared SQL with allowlisted sort expressions → filter active products/variants → paginate using deterministic ID tie-break → bulk load primary image/variant summaries → return escaped cards and explicit empty/error states. SQL sort names are never interpolated from unchecked query strings.

Product detail: validate slug → load active product with enabled variants/images → present explicit sale unit and coverage/availability → choose variant ID for cart operations. One product ID is not sufficient when finish/thickness/price/stock varies. Category and product unknown/inactive records return 404; image URLs are generated public assets, not arbitrary remote URLs.

Checks: invalid slug/filter/sort, empty category, inactive product, multiple variants/images without duplicate cards, stable page ordering, escaped descriptions, responsive cards, correct sqft/slab/box labeling and availability advisory text.

## WEB-02

Sources: [Auth.login()](../../../Website/core/Auth.php#L102), [mobile offline checkout model](../../../Mobile/lib/data/models/order.dart#L76), [cart prototype](../../../Mobile/lib/core/state/cart_controller.dart#L11). Proposed CartService and OrderService are described in their guides; mobile demo totals are not the server algorithm.

Flow: owned guest/user cart → verified login → idempotent guest merge → owned address selection → server quote preview → configured payment mode → submit stable checkout key → persisted confirmation → owned order history. MVP preview does not reserve inventory by default; accepted placement reserves/consumes atomically. If explicit expiring checkout reservation is enabled, use the same DAT-02 state machine and validate expiry on submit.

Generate a random checkout key for a specific cart revision/address/mode selection. Keep it across retries and browser errors; regenerate only when semantic inputs change. Do not generate a new key on every network retry. Successful redirect uses server order ID, not browser totals. On 409 show refreshed prices/stock/revision and let the customer accept changes before a new submission.

Production starts with simulation disabled and COD disabled until store configuration enables it. Non-production simulation is visibly marked; it cannot create a production paid status. Real UPI/card/EMI flows remain OPT-04. Delivery/tax policies must be configured, not copied from mobile demos.

Checks: guest ownership/login merge, customer B address/order denial, changed cart/price, last-item race, repeated submit, client timeout after commit, invalid payment mode, simulated payment rejected in production, totals match stored line snapshots. Responses and pages use private/no-store headers.

## WEB-03

Existing sources: [API gateway registration](../../../Website/api/gateway.php#L5), [RouteManager currently exact paths](../../../Website/core/RouteManager.php#L5), [Developer.metrics()](../../../Website/app/bridge/Developer.php#L68) as a current JSON endpoint. Future mobile interfaces are at [repositories.dart](../../../Mobile/lib/data/repositories/repositories.dart#L18).

### Minimum endpoint behavior

| Method / path | Guard / request | Response |
| --- | --- | --- |
| GET `/api/v1/products` | Public, bounded filters/page | 200 data list + pagination metadata |
| GET `/api/v1/products/{slug}` | Public, active/visible product | 200 detail; absent 404 |
| GET `/api/v1/cart` | Owned guest or logged-in cart | 200 cart/revision/server quote |
| POST `/api/v1/cart` | Same owner + cookie CSRF, variant/qty/revision | 200 updated cart; invalid 422/conflict 409 |
| GET/POST `/api/v1/addresses` | Current user + CSRF for mutation | Owned address list/create result only |
| GET `/api/v1/orders` | Current user, pagination | Owned order summaries |
| POST `/api/v1/orders` | Current user + CSRF + Idempotency-Key | 201 persisted order; same completed key returns saved response/status |
| GET `/api/v1/orders/{id}` | Current user owns order | 200 snapshot/history; other owner's ID 404 |
| GET/POST `/api/admin/products` | Exact staff view/edit grant; CSRF mutation | Same ProductService validation/audit |
| POST `/api/admin/inventory` | Inventory-adjust grant + CSRF/operation key | Accepted adjustment result |
| POST `/api/admin/orders/{id}/status` | Orders-edit grant + CSRF/operation key | Validated transition result |

This table is planned registration, not current availability. API authentication endpoints/CSRF bootstrap must also be documented when customer browser auth is exposed. Native bearer-token endpoints are designed in MOB-01; do not pretend current PHP sessions already supply secure mobile tokens. Disable cross-origin credential access by default; only explicitly allowed origins with suitable CSRF policy can use cookie sessions.

Canonical checkout body example (IDs are JSON strings; key is `Idempotency-Key` header or HTML hidden field mapped into the same service input):

```json
{"cart_id":"17","cart_revision":4,"address_id":"9","payment_mode":"cod"}
```

Saved result shape includes `data.order_id`, status, currency, fixed-precision `total_minor` decimal string, plus `request_id`. Error example:

```json
{"error":{"code":"stock_conflict","message":"An item is no longer available."},"request_id":"request-correlation-id"}
```

Document all required/nullable fields and unit semantics. No floating-point money, passwords/token fields or raw provider errors. Add explicit stock/cart/ownership/idempotency/CSRF examples and unsupported method/404 behavior. Include maximum JSON body size, malformed JSON response and field validation. Test HTML/API equivalence against the same seeded disposable state; API tests must prove effects and ownership rather than only response shape.
