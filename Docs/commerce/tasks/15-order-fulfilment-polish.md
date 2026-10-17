# FULFIL-01 — Order notifications, invoice, errors and seed data

Priority 4 ("missing but important") of the production commerce prompt. Small, mostly-wiring slices on
top of existing services. **Specification, not completed behaviour.** Read [contracts.md](../contracts.md)
(idempotent handlers, no exactly-once delivery promise, escape output).

## P4.1 Password reset UI + resend OTP — ALREADY DONE

Delivered in `AUTH-OTP-01` (see [tracking.md](../tracking.md)). Routes exist:
`account/forgot` (GET/POST), `account/reset` (POST), `account/otp/resend` (POST) in
`Website/app/view.php`; controller `Website/app/bridge/CustomerAccount.php` (`forgot/reset/resendOtp`);
views `forgot_password.php`, `reset_password.php`, `otp_verify.php`. **No new work** — only verify the
links are reachable from the sign-in page (`app/page/storefront/account.php` already links
`account/forgot`).

## P4.2 Order confirmation / status emails (queue)

Confirmation and cancellation already work: `NotificationJob::run()`
(`Website/app/Jobs/NotificationJob.php:9`) handles `order_confirmation`/`order_cancelled`, enqueued by
`QueueService` (`Website/app/Services/QueueService.php:8-9`) from `OrderService::place()` (`:99`) and
`transition()` (`:153`). The worker is `php vayu queue:work --queues=emails` (`Website/vayu:120`).
Add status-change emails for `confirmed → processing → shipped → outForDelivery → delivered`.

1. `Website/app/Jobs/JobRegistry.php:6` — add `'order_status'=>['emails',['order_id']]` to `TYPES`;
   add `'order_status'` to the allowlist in `JobRegistry::execute()` (`:43`) so it routes to
   `NotificationJob`. `enabledQueues()` already returns `['emails']` (`:39`) — no change.
2. `Website/app/Jobs/NotificationJob.php:9` — add `'order_status'` to the `in_array(...)` guard, and a
   branch that loads the order (like the `order_confirmation` branch at `:26-31`), **reads the current
   `status`**, and builds a subject/body for that status (e.g. "Your Maa Sarada order is on the way").
   Reuse the existing `notification_deliveries` idempotency and lease fencing unchanged — a
   per-(type,target) delivery key dedupes repeats.
3. `Website/app/Services/QueueService.php` — add
   `public function orderStatus(int $id, string $status): int { return $this->queue->enqueue('emails','order_status',1,['order_id'=>$id],'order:'.$id.':status:'.$status.':v1'); }`.
   The status is only in the **dedupe key** (unique per transition); the payload stays id-only so it
   passes `JobRegistry::envelope()`'s field validation.
4. `Website/app/Services/OrderService.php:153` — in `transition()`, after the history insert, enqueue
   `(new QueueService($this->pdo))->orderStatus($id,$target)` for shipping-relevant targets (keep the
   existing `orderCancelled` for `cancelled`). It is inside the same transaction, so a rolled-back
   transition sends nothing.

`notification_deliveries` (`Website/database/migrations/0010_notification_delivery.php`) already has
the unique `delivery_key` — no migration needed. Email transport stays `Mailer` (disabled offline;
SMTP when configured). Delivery is at-least-once, never exactly-once (contracts.md).

## P4.3 Invoice / order summary with tax breakdown

No PDF library (keep `composer.json` minimal). Deliver a **print-friendly HTML invoice**; the browser's
"Save as PDF" is the PDF path. A real PDF lib is a documented later option, not this slice.

1. Route in `Website/app/view.php`: `'order/{id}/invoice' => ['GET' => ['Cart','invoice']]` (place it
   with the other `order/...` routes; exact/template precedence already handles it).
2. `Website/app/bridge/Cart.php` — add `invoice(int $id)` mirroring `order()` (`:116`): `$actor =
   CommerceAccess::requireActor(); $order = (new OrderService($this->pdo))->owned($actor['id'],$id);`
   then `$this->respond('app/page/storefront/invoice.php', compact('order'))`. `owned()` already
   enforces ownership (404 otherwise) and hydrates items + address.
3. View `Website/app/page/storefront/invoice.php` — escape everything via the `$e` helper; render
   order number, date (`gmdate` of `created_at`), address snapshot, a line table using
   `order_items` (name, sku, `qty_milli` via the `quantity()` helper, `unit_price_minor`,
   `subtotal_minor`, `discount_minor`, `tax_minor`, `line_total_minor` via the `money()` helper from
   `Website/app/bridge/StorefrontController.php:34`), then the order totals
   (subtotal / discount / shipping / tax / total) and `payment_mode`/`payment_status`. Add a tiny
   `@media print` block and a "Print / Save PDF" button (`window.print()`). Link it from
   `app/page/storefront/order.php`.

Money/quantity are already integer minor/milli units with `StorefrontController::money()`/`quantity()`
formatters — use them; never recompute with floats.

## P4.4 Guest checkout vs force login — DECIDED: force login

Already consistent and enforced — document, no code change: `Cart::checkout()` redirects anonymous
users to `account` (`Website/app/bridge/Cart.php:76`); `Cart::placeOrder()`/`OrderService::place()`
require `CommerceAccess::requireActor()` (`:104`, `OrderService.php:34`). PAY-01's payment controller
does the same. If guest checkout is ever wanted it is a separate design (guest-token order ownership +
contact verification) — out of scope here.

## P4.5 Clear errors & logging for payment failures

- Customer-facing errors already go through `StorefrontController::handle()`
  (`Website/app/bridge/StorefrontController.php:13`), which maps `RequestRejected` to a safe status +
  message and renders `app/page/storefront/error.php`; everything else is a generic 503. Payment code
  (PAY-01) must throw `RequestRejected` with specific `errorCode`s (`payment_declined`,
  `payment_signature_invalid`, `payment_amount_mismatch`) and **never** leak gateway detail to the page.
- Internal detail belongs in the existing tracing/log path: wrap gateway calls and webhook handling in
  `DeveloperTrace::span()` (`Website/core/DeveloperTrace.php`, already used by
  `CommerceDatabase::query`) and/or append a sanitized line to a private log file via
  `PrivateStorage::directory('logs')` (`Website/core/PrivateStorage.php`) — hashed/ids only, never keys,
  signatures, or raw PII. Record the `payment_events`/`payments` row with a `last_error_code` style
  classifier (reuse the `Queue::errorCode()` regex discipline at `Website/core/Queue.php:76`).
- The queue already classifies and dead-letters failed email jobs (`job_failed`, `php vayu queue:retry`);
  no new mechanism needed for notification failures.

## P4.6 Seed a few real products for end-to-end testing

- A seeder already exists: `DemoCatalogSeeder` (`Website/database/seeders/DemoCatalogSeeder.php`),
  run by `php vayu demo:seed` (`Website/vayu:41`). It is **development-only** and uses the
  staff-granted services. Use it to populate catalog + stock so checkout can be exercised locally.
- "Real products" for production are an operator task via the admin product CRUD (`ADM-02`) or a
  validated import (`OPT-02`) — do **not** ship demo rows to production or seed usable passwords
  (AGENTS.md). If more demo breadth is needed, extend `DemoCatalogSeeder` (idempotent `find()` upserts
  at `:87`), keeping it dev-only.

## Verification (disposable only)

- Status emails: in `tests/OrderPlacementTest.php` (or a new focused test), assert `transition()` to
  `shipped`/`delivered` enqueues one `order_status` job with the expected dedupe key, and that
  `NotificationJob::run()` renders a status-appropriate subject and records a single
  `notification_deliveries` row (replay → no second send). Confirmation/cancellation paths unchanged.
- Invoice: an HTTP check (extend `tests/storefront_http_test.py`) that `GET /order/{id}/invoice`
  returns 200 for the owner and 404/redirect for a non-owner/anonymous, and that totals in the HTML
  match the order row.
- Re-run `php tests/DeveloperAccessTest.php`, `php tests/OrderPlacementTest.php`,
  `python3 tests/storefront_http_test.py`; `php -l` changed files; `git diff --check`.

## Out of scope

- True server-side PDF generation (needs a licensed/standalone library) — note as a follow-up.
- SMS/WhatsApp order notifications — share PAY-01/AUTH-OTP's optional-channel boundary; configure a
  provider first.
