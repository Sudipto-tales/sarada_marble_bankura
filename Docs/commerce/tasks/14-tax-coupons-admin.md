# TAX-CPN-01 — Tax rules, coupons and admin wire-up

Priority 3 of the production commerce prompt. Extends `PRC-01`/`OPT-01` intentions and the existing
admin (`ADM-02`/`ADM-03`). **Specification, not completed behaviour.** Read
[contracts.md](../contracts.md) (integer paise, authoritative DB prices, "concurrent coupon limits",
cache version advanced transactionally) and the money rules in `Website/core/CommerceValues.php`.

The order money path is already correct and snapshotted — this task **threads discount and
category tax through the existing engine**, it does not rewrite it.

## Where money is computed today (read these first)

- `OrderService::policy()` — `Website/app/Services/OrderService.php:8` — returns `shipping_minor`
  and `tax_bps` from env (`CHECKOUT_SHIPPING_MINOR`, `CHECKOUT_TAX_BPS`, basis points 0–10000).
- `OrderService::quote()` — `:14` — advisory preview: `tax = round(subtotal*tax_bps, 10000)`.
- `OrderService::place()` — `:71-102` — authoritative: per-line subtotal, whole-order tax with
  **largest-remainder** per-line distribution (`:72-81`), writes `orders` (`:89`) and `order_items`
  (`:93`) with the `tax_bps` snapshot and `discount_minor` (currently hard-coded `0`).
- Order CHECK constraints (must keep holding): `orders` — `total=subtotal-discount+shipping+tax`,
  `discount<=subtotal`; `order_items` — `line_total=subtotal-discount+tax`
  (`Website/database/migrations/0008_order_snapshots.php:15,24`). The `orders.discount_minor` and
  `order_items.discount_minor` columns already exist.
- Admin settings view (read-only today) — `Website/app/page/admin/settings.php`, served by
  `Admin::settings()` (`Website/app/bridge/Admin.php:181`).
- Admin table engine + definitions — `AdminReadService::TABLES`/`definition()`
  (`Website/app/Services/AdminReadService.php:5`), generic table view `app/page/admin/table.php`,
  form pattern `Admin::stockForm()/adjustStock()` (`Website/app/bridge/Admin.php:131`).
- Audit allowlist — `StaffAudit::ACTIONS`/`FIELDS` (`Website/core/StaffAudit.php:8,17`). Permissions
  `admin.settings.edit`, `admin.pricing.edit`, `admin.analytics.view` already exist
  (`Website/database/migrations/0003_commerce_identity.php:10-13`).

## Part A — Editable tax & payment settings

Today settings are env-only and the admin panel is read-only. Make the store-level knobs editable and
authoritative while keeping env as the default.

1. **Migration `Website/database/migrations/0015_store_settings.php`** (or `0016` if PAY-01's 0015
   exists — numeric prefix just orders them). Table `store_settings(id, skey VARCHAR(40) NOT NULL,
   svalue VARCHAR(190) NOT NULL, updated_at BIGINT NOT NULL)`, unique index on `skey`. Shape/verify
   like `0014_customer_otp_auth.php`.
2. **`Website/app/Services/SettingsService.php`** — `get(string $key, string $default): string` reads
   the one row (fallback to env then the passed default), `effective(): array` returns the typed set
   (`tax_bps`, `shipping_minor`, `cod_enabled`, `razorpay_enabled`), and `save(int $actorId, array
   $input, string $operationKey)` validates with `CommerceValues` (`tax_bps` 0–10000 via
   `CommerceValues::integer`, money via `CommerceValues::decimal(...,2,...)` → minor), writes each row
   and records `StaffAudit::record($pdo,$actorId,'settings.save','settings',null,$key,[...])` inside
   one `CommerceDatabase::transaction` (action already allowlisted; put values in the `tax_bps`,
   `shipping_minor`, `cod_enabled` FIELDS which already exist at `StaffAudit.php:17-20`).
3. **Point `OrderService::policy()` at `SettingsService`** instead of raw `env()` so admin edits take
   effect (DB value → env → default). Keep returning the same array keys/`version` so order snapshots
   stay stable. One indexed row read per checkout is fine; a cache is optional later.
4. **`OrderStates::payment()`/`paymentModes()`** (`Website/core/OrderStates.php:17`) — read
   `cod_enabled`/`razorpay_enabled` from `SettingsService` rather than env directly, so the admin
   toggles enable/disable COD and online side-by-side (PAY-01 adds the `razorpay` branch).
5. **Admin edit UI** — give `Admin::settings()` a POST path (reuse the `submitted()` helper at
   `Website/app/bridge/Admin.php:21`), guarded by `CommerceAccess::requirePermission('admin.settings.edit')`.
   Turn `app/page/admin/settings.php` into a form when `$can('admin.settings.edit')`, else keep the
   read-only `<dl>`. Add route `'admin/settings' => ['GET'=>['Admin','settings'],'POST'=>['Admin','saveSettings']]`
   in `Website/app/view.php` (replace the GET-only entry).

### Per-category tax (GST per category)

Default is a single GST% (set default `CHECKOUT_TAX_BPS=1800` in `.env.example` = 18%). Optional
per-category override:

1. Add nullable `tax_bps INT NULL` to `categories` via a small forward migration (reuse
   `MigrationSchema::ensureColumn`-style DDL as in existing migrations; verify with
   `verifyDefinition(['tax_bps'=>'?int'])`). `NULL` means "use store default".
2. In `OrderService::place()` loop (`:72-81`), resolve each line's rate as
   `lineBps = category.tax_bps ?? policy.tax_bps`, compute per-line tax on the **discounted** taxable
   amount (see Part B), and keep the largest-remainder distribution **per distinct rate group** so
   each `order_items.tax_minor` is exact and `sum == order tax_minor`. The order-level `tax_bps`
   snapshot stays the store default (document that per-line rates live on the items).
3. `quote()` stays a single-rate advisory preview (it is explicitly non-authoritative at `:13`).

## Part B — Coupons

1. **Migration `coupons` + `coupon_redemptions`** (same style):
   ```
   coupons(id, code VARCHAR(40) NOT NULL, kind VARCHAR(8) NOT NULL,   -- 'percent'|'fixed'
     value INT NOT NULL,                 -- percent: basis points 0..10000; fixed: minor units
     min_order_minor BIGINT NOT NULL DEFAULT 0, max_discount_minor BIGINT NULL,
     usage_limit INT NULL, used_count INT NOT NULL DEFAULT 0, per_user_limit INT NULL,
     starts_at BIGINT NULL, ends_at BIGINT NULL, enabled INT NOT NULL DEFAULT 1,
     created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)
     UNIQUE idx_coupon_code (code)
   coupon_redemptions(id, coupon_id INT NOT NULL, user_id INT NOT NULL, order_id INT NOT NULL,
     amount_minor BIGINT NOT NULL, created_at BIGINT NOT NULL,
     FK coupon_id->coupons, FK user_id->users_tbl, FK order_id->orders ON DELETE RESTRICT)
     UNIQUE idx_redemption_order (order_id)         -- one coupon per order, idempotent
     INDEX idx_redemption_user (coupon_id, user_id) -- per-user limit counting
   ```
   (Optional `coupon_categories`/`coupon_products` pivots for scope; start without — note it as a
   follow-up rather than stubbing empty tables.)
2. **`Website/app/Services/CouponService.php`**
   - `preview(array $cart, string $code): array` — advisory: validate window/enabled/min-order,
     compute discount = `kind==='percent' ? round(subtotal*value,10000) : value`, cap at
     `max_discount_minor` and at `subtotal`. Returns `['code','discount_minor']` or a rejection reason.
     Used by the cart/checkout to show the discount; **never** authoritative.
   - `redeem(PDO $pdo, int $couponId, int $userId, int $orderId, int $subtotal): int` — called
     **inside** `OrderService::place()`'s transaction. Lock the coupon row (`CommerceDatabase::lock`),
     re-check enabled/window/`min_order_minor`, `used_count < usage_limit`, and per-user count
     (`SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=? AND user_id=?`) `< per_user_limit`;
     recompute the discount from authoritative `subtotal`; `INSERT coupon_redemptions` (the
     `UNIQUE(order_id)` makes retries idempotent) and `UPDATE coupons SET used_count=used_count+1`.
     Return `discount_minor`. Reject with `RequestRejected(409,...)` on a limit race.
   - Admin CRUD `save/archive` with `CouponService`, validated via `CommerceValues`, audited.
3. **Carry the applied code into checkout.** Add an `apply coupon` POST on the cart/checkout
   (store the validated code in the session alongside the existing `checkout_keys` fingerprint at
   `Website/app/bridge/Cart.php:84-96`, and **include the code in the fingerprint** so changing the
   coupon invalidates a stale review). Pass the coupon id into `OrderService::place()` as a new
   trailing optional argument.
4. **Thread discount through `place()`** (`Website/app/Services/OrderService.php:71-94`):
   - After per-line subtotals and `$subtotal`, call `CouponService::redeem(...)` → `$discount`
     (`0` when no coupon). Enforce `$discount <= $subtotal`.
   - Allocate `$discount` across lines by largest remainder on each line's share
     (`lineDiscount[vid] = round(subtotal[vid]*discount, subtotal)` then fix the remainder) so
     `sum(lineDiscount) == discount`.
   - **Tax base is post-discount:** `taxableLine = subtotal[vid] - lineDiscount[vid]`; compute
     `tax[vid]` from `taxableLine * lineBps` with the existing largest-remainder fix.
   - Write `orders.discount_minor=$discount`, `tax_minor=sum(tax)`, `total=subtotal-discount+shipping+tax`;
     write each `order_items` with its `discount_minor` and `tax_minor` so
     `line_total=subtotal-discount+tax` holds. The CHECK constraints then pass unchanged.
   - Keep the `$expectedQuote` price-conflict guard (`:83-87`): include coupon+discount in the quote
     so a changed coupon surfaces as `price_conflict`.

## Part C — Admin wire-up & reports

1. **Coupons admin**: add `'coupons'=>['Coupons','admin.pricing.edit',[... columns ...]]` to
   `AdminReadService::TABLES` (`:5`) and a `coupons` branch in its `table()` SQL (`:42`-style). Add
   `Admin::couponForm()` (GET) + `Admin::saveCoupon()`/`archiveCoupon()` (POST) mirroring
   `productForm()/saveProduct()` (`Website/app/bridge/Admin.php:87-110`), new view
   `app/page/admin/coupon.php` mirroring `app/page/admin/stock.php`, and routes
   `'admin/coupon' => ['GET'=>['Admin','couponForm'],'POST'=>['Admin','saveCoupon']]` +
   `'admin/coupon/archive'` in `Website/app/view.php`.
2. **Audit**: add to `StaffAudit::ACTIONS` (`:8`): `'coupon.save'=>['admin.pricing.edit','coupon']`,
   `'coupon.archive'=>['admin.pricing.edit','coupon']`; add allowed fields `code`, `kind`, `value`,
   `usage_limit`, `per_user_limit`, `min_order_minor`, `max_discount_minor` to `StaffAudit::FIELDS`
   (`:17`).
3. **Sales reports**: `Admin::reports()` (GET, `admin.analytics.view`) + view
   `app/page/admin/reports.php`, backed by bounded `AdminReadService` queries: order count &
   revenue `SUM(total_minor) WHERE payment_status='paid'` over a date range, and coupon usage
   `SELECT code, used_count, SUM(amount_minor) FROM coupons JOIN coupon_redemptions ...`. Use
   `LIMIT`/date bounds — no unbounded scans (contracts.md). Add a `reports` link in the admin sidebar
   (`app/page/admin/header.php`) under the `admin.analytics.view` check.
4. Products/variants/images/stock/orders admin and order status transitions already exist
   (`ADM-02`/`ADM-03`, `OrderService::transition` at `:127`). Do **not** rebuild them.

## Config keys (`.env.example`)

```
CHECKOUT_TAX_BPS=1800        # 18% GST default; per-category overrides live on categories.tax_bps
CHECKOUT_SHIPPING_MINOR=0    # flat shipping in paise (admin-editable via store_settings)
CHECKOUT_COD_ENABLED=false   # already present; now also surfaced as an admin toggle
```

## Verification (disposable only)

- New `Website/tests/CouponTaxTest.php` (harness like `tests/CustomerOtpTest.php`, full migrations +
  seeded catalog via `DemoCatalogSeeder`): fixed & percent coupon math; `max_discount`/`min_order`
  caps; **usage_limit and per_user_limit races** (two concurrent `place()` calls like the competing
  processes in `tests/AuthSecurityTest.php` — only one redemption beyond the limit succeeds);
  discount≤subtotal; **tax computed on the discounted base**; per-category rate selection; order CHECK
  constraints satisfied (insert succeeds) and `sum(order_items.tax_minor)==orders.tax_minor`,
  `sum(discount)==orders.discount_minor`.
- Settings: admin save changes `OrderService::policy()` output and the enabled payment modes; reject
  `tax_bps>10000`; audit row written with `admin.settings.edit`.
- Re-run `php tests/OrderPlacementTest.php` (adjust its expected totals if it asserted zero tax/discount),
  `php tests/DeveloperAccessTest.php`, `python3 tests/storefront_http_test.py`, admin tests. `php -l`
  all changed files; `git diff --check`.

## Out of scope / notes

- Coupon category/product scoping pivots — follow-up; note, don't stub.
- Full `PRC-01` dynamic pricing rules stay separate (default-off); coupons are not willingness-to-pay
  pricing.
- MySQL/MariaDB verification of the new tables, locking and the coupon-limit race is a **required**
  pending check — keep the task `in_progress` until it runs on a real MySQL target (AGENTS.md).
