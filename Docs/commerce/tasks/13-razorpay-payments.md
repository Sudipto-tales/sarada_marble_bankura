# PAY-01 — Razorpay online payments (test + live)

Priority 2 of the production commerce prompt. Realises the payment half of the deferred `OPT-04`
boundary. This is an **implementation specification, not completed behaviour**. Read
[contracts.md](../contracts.md) (integer paise money, atomic checkout, idempotent handlers,
"external calls happen outside DB transactions") and [AGENTS.md](../../../AGENTS.md) first.

> Money is already integer **paise** everywhere (`total_minor` etc.). Razorpay's `amount` field is
> also integer paise, so pass `total_minor` directly — never convert to/from float.

## Prerequisites (all integrated)

- `OrderService::place()` — `Website/app/Services/OrderService.php:32` — creates the order row with
  `payment_mode`/`payment_status` and shares one transaction with stock consume + queue enqueue.
- `OrderStates::payment()` / `paymentModes()` — `Website/core/OrderStates.php:17` — decides the
  initial `payment_status` from the mode and lists enabled modes for the checkout UI.
- Order schema `orders(... payment_mode, payment_status ...)` — `Website/database/migrations/0008_order_snapshots.php:9`.
- Durable queue: `Queue` (`Website/core/Queue.php`), `QueueService` (`Website/app/Services/QueueService.php`),
  `JobRegistry` (`Website/app/Jobs/JobRegistry.php`), `NotificationJob` (`Website/app/Jobs/NotificationJob.php`),
  worker CLI `php vayu queue:work` (`Website/vayu:120`), `QueueWorker` (`Website/core/QueueWorker.php`).
- Checkout controller `Cart::checkout()` / `Cart::placeOrder()` — `Website/app/bridge/Cart.php:72,101`.
- Public API route table — `Website/api/gateway.php` (`ApiGatewayProvider::routes()`).
- HTML route table + class map — `Website/app/view.php`, `Website/config/classes.php`.
- Admin audit — `StaffAudit` (`Website/core/StaffAudit.php`); DB helpers `CommerceDatabase::{transaction,query,lock,clock}` (`Website/core/CommerceDatabase.php`).

## Decision (from the user)

- **Force login before payment** — already enforced: `Cart::checkout()` redirects guests to
  `account` (`Website/app/bridge/Cart.php:76`) and `OrderService::place()` calls
  `CommerceAccess::requireActor()`. Do not add guest payment.
- **COD and Razorpay side-by-side**, each independently enable/disable-able.
- Build so it runs with **test keys**; the same code path serves live keys.

## Payment lifecycle (target)

```
checkout review (existing) ──POST /checkout──► OrderService::place(..., paymentMode='razorpay')
   order row created: status='placed', payment_status='awaiting_payment'
   PaymentService::createGatewayOrder(orderId)  (AFTER commit, see below)
      → create razorpay_order (Orders API)  → store payments row (status='created')
      → render checkout.php with Razorpay Checkout handler (key id + rzp order id + amount)
Customer pays in Razorpay modal
   success callback POST /payment/verify  → verify razorpay_signature (HMAC) → mark 'paid' path
   OR webhook POST /api/webhooks/razorpay  → verify webhook HMAC → same 'paid' path (idempotent)
Either path, inside ONE transaction, idempotently:
   payments.status='captured', orders.payment_status='paid'
   enqueue order_confirmation  (stock was already consumed at placement)
Failure/cancel: payments.status='failed'; order stays payment_status='awaiting_payment' (expire later)
```

Reducing stock at **placement** (already implemented) is the correct, race-free choice: the slab is
held the moment the order exists, so two buyers cannot both reach Razorpay for the last slab. If the
business prefers to hold stock only after payment, that is a separate reservation-expiry change — do
**not** attempt it here.

## New files

### 1. Migration `Website/database/migrations/0015_razorpay_payments.php`

Follow the exact shape of `0014_customer_otp_auth.php` (driver-aware id/suffix, `ensureIndex`,
`verifyDefinition` in `verify()`). Two tables:

```
payments (
  id, order_id INT NOT NULL,
  provider VARCHAR(16) NOT NULL,            -- 'razorpay'
  provider_order_id VARCHAR(64) NOT NULL,   -- rzp order_... (unique)
  provider_payment_id VARCHAR(64) NULL,     -- rzp pay_...
  amount_minor BIGINT NOT NULL,             -- paise, == orders.total_minor at creation
  currency VARCHAR(3) NOT NULL,             -- 'INR'
  status VARCHAR(16) NOT NULL,              -- created|captured|failed
  created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT )
  UNIQUE idx_payments_provider_order (provider, provider_order_id)
  INDEX  idx_payments_order (order_id)

payment_events (                            -- idempotent webhook/callback ledger
  id, provider VARCHAR(16) NOT NULL,
  event_id VARCHAR(80) NOT NULL,            -- razorpay event id, or 'callback:'+payment_id
  payment_id INT NULL,                      -- FK payments.id
  status VARCHAR(16) NOT NULL,              -- received|processed
  created_at BIGINT NOT NULL,
  FOREIGN KEY(payment_id) REFERENCES payments(id) ON DELETE RESTRICT )
  UNIQUE idx_payment_event (provider, event_id)
```

`verifyDefinition` types: `int`, `bigint`, exact `varchar(N)`, `?` prefix for the nullable columns.

### 2. `Website/core/RazorpayClient.php`

A thin HTTP client — **no SDK dependency** (`composer.json` stays PHPMailer+dotenv only). Use cURL
with explicit finite timeouts and sanitized errors, exactly like `Website/core/Mailer.php`'s style.

```php
final class RazorpayClient {
  public function __construct(string $keyId, string $keySecret, int $timeout = 15) {...}
  // POST https://api.razorpay.com/v1/orders  (HTTP Basic: keyId:keySecret)
  // body {amount:int paise, currency:'INR', receipt:order_number, notes:{order_id}}
  public function createOrder(int $amountMinor, string $receipt, array $notes): array;
  // GET https://api.razorpay.com/v1/payments/{id}  (verify server-side before trusting a callback)
  public function fetchPayment(string $paymentId): array;
}
```

- Read keys only from env (never log them). Fail with a generic message; put the detail in
  `DeveloperTrace`/error log, never in the HTTP response.
- `hash_equals()` for every signature comparison. Never compare with `==`.

### 3. `Website/app/Services/PaymentService.php`

All money/DB effects go through `CommerceDatabase::transaction()` on the injected PDO.

```php
final class PaymentService {
  public function __construct(private PDO $pdo) {}

  // Called right AFTER OrderService::place() commits (see wiring). Creates the gateway order once.
  // Idempotent: if a 'created' payments row already exists for this order, return it.
  public function beginOnline(int $userId, int $orderId): array;   // ['key_id','provider_order_id','amount_minor','currency','order_number']

  // Success callback: verify sha256 HMAC of (provider_order_id|provider_payment_id) with KEY_SECRET.
  // Then fetchPayment() to confirm status=='captured' and amount==amount_minor before marking paid.
  public function confirmCallback(int $userId, string $providerOrderId, string $providerPaymentId, string $signature): array;

  // Webhook: verify HMAC of RAW body with WEBHOOK_SECRET, then settle idempotently by event id.
  public function handleWebhook(string $rawBody, string $signatureHeader): void;
}
```

Signature formulas (document exactly — a free model must not guess):
- **Checkout callback:** `expected = hash_hmac('sha256', $razorpay_order_id.'|'.$razorpay_payment_id, $keySecret)` compared to `razorpay_signature`.
- **Webhook:** `expected = hash_hmac('sha256', $rawRequestBody, $webhookSecret)` compared to the `X-Razorpay-Signature` header.

`settle(paymentId, providerPaymentId)` core, inside one `CommerceDatabase::transaction`:
1. Insert into `payment_events (provider,event_id,status='received')` with `ON CONFLICT/ON DUPLICATE ... DO NOTHING`; re-select with `CommerceDatabase::lock()`. If already `processed`, return (idempotent no-op).
2. Lock the `payments` row (`... FOR UPDATE` via `CommerceDatabase::lock()`); if already `captured`, mark event processed and return.
3. Lock the `orders` row; set `payments.status='captured'`, `orders.payment_status='paid'`, `updated_at=clock()`.
4. `(new QueueService($pdo))->orderConfirmation($orderId)` (safe to repeat — `notification_deliveries` dedupes).
5. Mark the `payment_events` row `processed`.

All external HTTP (`createOrder`, `fetchPayment`) happens **outside** these transactions (contracts.md).

### 4. `Website/app/bridge/Payment.php` (controller)

```php
class Payment extends StorefrontController {
  public function verify(): void;   // POST /payment/verify  — browser callback, CSRF-protected
  public function webhook(): void;  // POST /api/webhooks/razorpay — server-to-server, NO CSRF
}
```

- `verify()`: `RequestSecurity::requireMutation()` (CSRF yes — it is a browser POST with the session),
  `$actor = CommerceAccess::requireActor()`, read `razorpay_order_id/payment_id/signature` via
  `CommerceValues::text(...)`, call `confirmCallback`, then `$this->redirect('order/'.$id)`.
- `webhook()`: **do not** call `requireMutation` (no cookie/CSRF). Read the raw body with
  `file_get_contents('php://input')` (the signature is over raw bytes — do not use
  `RequestSecurity::jsonBody()` which re-encodes). Pass raw body + `$_SERVER['HTTP_X_RAZORPAY_SIGNATURE']`
  to `handleWebhook`. Respond `200 {"status":"ok"}` on success (and on an already-processed duplicate),
  `400` on a bad signature. Never echo internal detail.

## Edits to existing files

| File | Change |
| --- | --- |
| `Website/config/classes.php` | Register `'RazorpayClient'`, `'PaymentService'`, `'Payment'` with their paths (same block style as `CustomerOtp`). |
| `Website/app/view.php` | Add `'payment/verify' => ['POST' => ['Payment','verify']]`. Keep it above the `admin/{slug}` template rules (exact routes win anyway). |
| `Website/api/gateway.php` | Add `'api/webhooks/razorpay' => ['POST' => ['Payment','webhook']]` inside `ApiGatewayProvider::routes()`. |
| `Website/core/OrderStates.php:17` | In `payment()`, add: `if($mode==='razorpay' && filter_var(env('CHECKOUT_RAZORPAY_ENABLED',false),FILTER_VALIDATE_BOOLEAN)) return 'awaiting_payment';`. Add `'razorpay'=>'Pay online (Razorpay)'` to the `paymentModes()` loop. This makes the checkout UI and `place()` accept the mode only when enabled. |
| `Website/app/bridge/Cart.php:111` | After `OrderService::place(...)` returns for a `razorpay` order, call `PaymentService::beginOnline($actor['id'],$result['id'])` and redirect to an order/pay page instead of the plain order page (COD/simulation keep the existing redirect). |
| `Website/app/page/storefront/checkout.php` | When the selected mode is `razorpay`, after placing, render the Razorpay Checkout script (`https://checkout.razorpay.com/v1/checkout.js`) configured with `key`, `order_id`, `amount`, `currency`, `name`, and a `handler` that POSTs `razorpay_order_id/payment_id/signature` + the session CSRF to `payment/verify`. Keep the existing COD submit untouched. |
| `Website/app/page/storefront/order.php` | If `payment_status==='awaiting_payment'`, show a "Complete payment" button that re-opens Razorpay Checkout for the stored `provider_order_id`. |
| `Website/.env.example` | Add the keys below with a comment block (mirror the existing `MAIL_*`/`AUTH_OTP_*` comment style). |

### Config keys (`.env.example`)

```
# Razorpay online payments (paise). COD and online can be enabled independently.
CHECKOUT_RAZORPAY_ENABLED=false
RAZORPAY_KEY_ID=
RAZORPAY_KEY_SECRET=
RAZORPAY_WEBHOOK_SECRET=
# Test mode: create a free Razorpay account, use the rzp_test_... keys and the
# dashboard's test cards/UPI. Point the dashboard webhook at
# <APP_URL>/api/webhooks/razorpay with events payment.captured, payment.failed, order.paid
# and set RAZORPAY_WEBHOOK_SECRET to the secret you configure there.
```

`OrderStates::payment('razorpay')` throws `invalid_payment_mode` when the flag is off, so disabling it
instantly removes the mode from checkout and rejects any crafted POST — no extra guard needed.

## Verification (disposable only)

New `Website/tests/PaymentServiceTest.php` (mirror `tests/CustomerOtpTest.php`'s harness: run the full
`MigrationRunner`, seed an order, stub `RazorpayClient` by injecting a fake via a settable static or a
subclass). Assert:

1. Valid callback signature → `payments.status='captured'`, `orders.payment_status='paid'`, one
   `order_confirmation` job enqueued.
2. **Tampered** signature → rejected, order unchanged (use a wrong secret / flipped byte).
3. **Replayed** webhook (same `event_id` twice) → settled exactly once (second call is a no-op; still
   `200`). Prove no second order_confirmation and no double paid-write.
4. Amount mismatch (gateway amount ≠ `amount_minor`) → rejected.
5. `fetchPayment` status `failed` → `payments.status='failed'`, order stays `awaiting_payment`.
6. Mode disabled (`CHECKOUT_RAZORPAY_ENABLED=false`) → `OrderStates::payment('razorpay')` throws 422.

Also run the existing regressions unchanged: `php tests/OrderPlacementTest.php`,
`php tests/DeveloperAccessTest.php`, `python3 tests/storefront_http_test.py`. `php -l` every changed
file; `git diff --check`.

## Out of scope / needs credentials

- **Refunds and reconciliation reports** beyond the `payments`/`payment_events` ledger — add a
  `PaymentService::refund()` + admin action in a later slice (refund uses the same client + a
  `refunds` table). Note it; do not stub it.
- **Live keys** and a **real webhook round-trip** cannot be verified without a Razorpay account —
  record them as pending external checks in the tracker, exactly like the MySQL gates.
- Do not add a daemon; the webhook is handled synchronously, the confirmation email goes through the
  existing cron worker (`php vayu queue:work`).
