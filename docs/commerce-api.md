# Versioned commerce JSON API (WEB-03)

The storefront and admin HTML controllers and these API endpoints call the same services
(`CartService`, `AddressService`, `OrderService`, `ProductService`, `InventoryService`); the API never
HTTP-calls the HTML surface. Responses are server-side rendered JSON with the same ownership,
validation, audit and idempotency rules as the HTML flow.

Registered in [`api/gateway.php`](../api/gateway.php); controllers
[`StorefrontApiController`](../app/bridge/StorefrontApiController.php) and
[`AdminApiController`](../app/bridge/AdminApiController.php).

## Transport, identity and CSRF

- All responses set `Content-Type: application/json; charset=utf-8` and `Cache-Control: private, no-store`,
  and carry a `request_id` correlation string.
- Identity is the shared commerce **cookie session** (customer sign-in at `account/login`, staff sign-in
  at `admin/login`). There is **no bearer token**; native mobile tokens are MOB-01 and are not implied here.
- Cross-origin credentialed reads are blocked by the deliberate absence of any
  `Access-Control-Allow-Origin` header, so the browser's same-origin policy keeps the CSRF token private.
- **Mutations require the session CSRF token** in the `X-CSRF-Token` request header. Bootstrap it from any
  owned `GET` endpoint (`/api/v1/cart`, `/api/v1/addresses`, `/api/v1/orders`, `/api/admin/products`), which
  returns `csrf_token`. The token rotates on sign-in/out; re-read it after an auth change.
- Request bodies must be a single JSON **object** with `Content-Type: application/json`. Wrong content type
  is `415`; a body larger than the endpoint limit (16 KiB customer, 32 KiB staff) is `413`; malformed JSON or
  a non-object top level is `400 invalid_json`.

## Unit and money semantics

- Money is integer **minor units** (paise); quantities are integer **milli-units** (`qty_milli`, 1000 = one
  sell unit). In JSON, every `id`, `*_id` and `*_minor` field is a **string** to avoid float/precision loss;
  `qty_milli`, `revision`, `*_bps` and counts are JSON numbers. There is no floating-point money anywhere.
- Request `quantity` is a decimal **string** such as `"1.5"` (max 3 decimal places); an integer JSON number is
  also accepted. `cart_revision`/`revision` are integers.
- Timestamps are UTC ISO-8601 with `Z`. Passwords, OTP codes, tokens and raw provider errors are never
  returned.

## Error envelope

```json
{"error":{"code":"stock_conflict","message":"An item is no longer available."},"request_id":"…"}
```

Status usage: `401` unauthenticated, `403` missing grant / invalid CSRF, `404` absent or not-owned record,
`405` method mismatch (with `Allow`), `409` revision/idempotency/stock/transition conflict, `413`/`415`/`400`
body problems, `422` invalid business input, `429` throttled, `503` temporary failure. No SQL/SMTP text.

## Customer endpoints

| Method / path | Guard | Request | Success |
| --- | --- | --- | --- |
| `GET /api/v1/cart` | owned guest/user cart | – | `200` `{data: cart, csrf_token}` |
| `POST /api/v1/cart` | same owner + CSRF | `{action, variant_id, quantity?, cart_revision?, cart_id?}` | `200` updated cart; `422`/`409` on invalid/conflict |
| `GET /api/v1/addresses` | current user | – | `200` `{data: [address], csrf_token}` |
| `POST /api/v1/addresses` | current user + CSRF | address fields (`id?`, `expected_revision?`) | `201` created / `200` updated |
| `GET /api/v1/orders` | current user | `?page=` | `200` `{data: [summary], meta:{page}, csrf_token}` |
| `POST /api/v1/orders` | current user + CSRF + `Idempotency-Key` | `{cart_id, cart_revision, address_id, payment_mode}` | `201` persisted order |
| `GET /api/v1/orders/{id}` | current user owns order | – | `200` snapshot + history; other owner `404` |
| `GET /api/v1/products` | public | bounded filters/`page`/`per_page` (≤50) | `200` `{data, meta}` |
| `GET /api/v1/products/{slug}` | public, active | – | `200` detail; absent `404` |

**Cart line fields** (`data.items[]`): `variant_id` (string), `qty_milli` (number), `unit_price_minor`,
`line_total_minor`, `original_price_minor` (nullable), `line_original_minor` (nullable), `available_milli`,
`purchasable` (bool), `name`, `slug`, `sell_unit`. Cart has `id` (nullable when empty), `revision`,
`subtotal_minor`, `savings_minor`, `currency` (`"INR"`).

**Address create** required: `recipient`, `phone`, `line1`, `city`, `state`, `postal_code` (6-digit Indian
PIN). Optional/nullable: `label` (default `"Home"`), `line2`, `country_code` (must be `"IN"`),
`is_default`, `id` (update), `expected_revision` (optimistic lock, default `0`).

**Checkout body** — IDs are JSON strings; the `Idempotency-Key` header is the checkout key
(`[A-Za-z0-9][A-Za-z0-9:_-]{7,99}`):

```json
{"cart_id":"17","cart_revision":4,"address_id":"9","payment_mode":"cod"}
```

Placement reads authoritative database prices/availability inside one transaction. The saved result
(`data`) includes `order_id` (and `id`), `order_number`, `status`, `currency`, `subtotal_minor`,
`discount_minor`, `shipping_minor`, `tax_minor`, `total_minor`, `amount_due_minor`, `payment_mode`,
`payment_status`. The same completed `Idempotency-Key` returns the saved result (still `201`) without placing
a second order; a different body under the same key is `409`. `payment_mode` must be a store-enabled method
(`cod`, `razorpay`; `simulation` only in non-production). Online (`razorpay`) completion/refunds remain
OPT-04; this endpoint places the order and returns its `awaiting_payment` status.

## Staff endpoints

Each reloads the actor's grants from the database and requires the exact permission below plus CSRF; a stable
`operation_key` (body field or `Idempotency-Key` header) makes mutations idempotent and audited.

| Method / path | Grant | Request | Success |
| --- | --- | --- | --- |
| `GET /api/admin/products` | `admin.products.view` | filters/`page`/`per_page` | `200` `{data, meta:{page,per_page,total,pages}, csrf_token}` |
| `POST /api/admin/products` | `admin.products.edit` | `ProductService::save` input (`slug,name,category_id,status,variants[],revision,operation_key,…`) | `200` `{id, revision, variant_ids}` |
| `POST /api/admin/inventory` | `admin.inventory.adjust` | `{variant_id, delta_milli, reason, operation_key, revision?}` | `200` updated stock row |
| `POST /api/admin/orders/{id}/status` | `admin.orders.edit` | `{target, revision, operation_key, reason?}` | `200` `{id, status, revision}` |

Order status follows the fixed machine `placed → awaiting_verify → confirmed → processing → shipped →
outForDelivery → delivered` (plus `cancelled` from `placed`/`awaiting_verify`/`confirmed`/`processing`); an
out-of-order `target` is `409 invalid_transition`. A replayed `operation_key` with matching inputs returns the
current state; different inputs are `409`.

## Verification

Exercised end-to-end in [`tests/api_http_test.py`](../tests/api_http_test.py) against a disposable
SQLite/MariaDB store with real sessions and CSRF: cart add/validation/CSRF/content-type/body-size, owned
address create, idempotent COD placement, order ownership (`404` cross-owner, `401` anonymous), method `405`,
HTML/API equivalence (an API order shows in HTML history; a staff API transition shows in the customer API),
staff grant/CSRF enforcement, persisted inventory adjustment with operation-key replay, the status machine,
and a staff-created product appearing on the public API.
