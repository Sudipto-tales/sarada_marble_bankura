# UX-DESIGN-01 — Storefront UI polish, account hub, account deletion and payment clarity

Customer-facing design pass over the existing storefront. **Specification, not completed behaviour.**
Everything reuses services/views already in `Website/app/page/storefront/` and `Website/core/` — no new
framework, no new tables except where stated (none required). Read [contracts.md](../contracts.md)
(escape output, prepared SQL, CSRF on cookie-authenticated mutations, integer money/quantity) before
editing. Money/quantity stay integer minor/milli via `StorefrontController::money()`/`quantity()`.

The storefront is skinned by the token set in `Website/assets/storefront/html-parity.css:2`
(`--cream --sage --deep --clay --ink --muted --line --ice --teal`). Every snippet below uses those tokens
and the existing component classes (`.panel`, `.button`, `.button.secondary`, `.text-button`,
`.page-intro`, `.grid`, `.notice`, `.muted`, `.help-text`) and the local SVG `$icon()` helper from
`app/page/storefront/icons.php` (`icons.php` is already required by `header.php`, so `$icon` is in scope on
every storefront page). Do not introduce new colours or a CSS framework.

---

## D1 — Fix the blanket link underline (P0, one rule)

**Cause.** `Website/assets/storefront/php.css:2` underlines *every* `<a>` inside `main` on commerce pages:

```css
body[data-page=commerce] main a{color:var(--clay,#ad573c);text-decoration:underline}
```

This hits panel cards (`Your orders ↗`, `Saved materials ♡`), `↗` call-to-action links and button-styled
links, overriding the base `a{text-decoration:none}` (`storefront-base.css:1`). Underline only belongs on
links inside flowing text.

**Change** (replace that one declaration):

```css
body[data-page=commerce] main a{color:var(--clay,#ad573c)}
body[data-page=commerce] main p a,
body[data-page=commerce] main li a{text-decoration:underline}
body[data-page=commerce] main a.panel,
body[data-page=commerce] main a.button{text-decoration:none;color:inherit}
```

Second offender: `Website/assets/storefront/product-detail.css:68` — `.pdp-options{text-decoration:underline}`
should not be underlined (it is not a text link). Drop `text-decoration:underline` from that rule.
Keep `.text-button` underlined (`storefront.css`) and `.notice a` underlined (`php.css:8`) — those are
intentional inline links.

---

## D2 — Account page as a real hub (P1)

File: `Website/app/page/storefront/account.php`. Today it is a flat stack of panels; **Add address**,
**Saved materials** and **Saved photo designs** already exist (lines 5–6) but there is no **Cart** or
**Favourites** quick-link and no account-management grouping. Restructure the signed-in branch (line 4
onward) into labelled sections, and add an icon quick-links row so the account page is the one hub.

Replace the `php-account-links` nav (line 5) with an icon grid using the existing `$icon()` helper and
the live counts `header.php` already computes (`$navCounts` at `header.php:10-17` — expose them to the
view, or recompute the two cheap counts here):

```php
<nav class="php-account-links" aria-label="Your account">
  <a class="panel" href="<?= $u('orders') ?>"><?= $icon('return') ?><span>Your orders</span><small>Track status &amp; invoices</small></a>
  <a class="panel" href="<?= $u('wishlist') ?>"><?= $icon('heart') ?><span>Favourites</span><small><?= (int)$wishlistCount ?> saved material<?= $wishlistCount === 1 ? '' : 's' ?></small></a>
  <a class="panel" href="<?= $u('cart') ?>"><?= $icon('bag') ?><span>Shopping bag</span><small><?= (int)$cartCount ?> item<?= $cartCount === 1 ? '' : 's' ?></small></a>
  <a class="panel" href="<?= $u('saved-designs') ?>"><?= $icon('spark') ?><span>Saved photo designs</span><small>Your visualizer ideas</small></a>
</nav>
```

Group the rest under clear `<h2>` section headers in this order: **Delivery addresses** (existing grid +
`php-address-create`), **Sign-in security** (existing trusted-device / PIN panel, line 7), then a new
**Danger zone** panel (D3). Each section already uses `.panel`; only the headings and the quick-links row
are new markup.

Styling (add to `Website/assets/storefront/html-parity.css`, the storefront override layer):

```css
.php-account-links{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.php-account-links .panel{display:flex;flex-direction:column;gap:6px;align-items:flex-start;padding:16px;text-decoration:none;color:inherit}
.php-account-links svg{width:22px;height:22px;fill:none;stroke:var(--teal);stroke-width:1.5}
.php-account-links span{font-size:13px;font-weight:600}
.php-account-links small{font-size:11px;color:var(--muted)}
.php-account-links .panel:hover{border-color:var(--teal)}
@media(max-width:650px){.php-account-links{grid-template-columns:repeat(2,minmax(0,1fr))}}
.php-danger-zone{border-color:var(--clay)}
.php-danger-zone h2{color:var(--clay)}
.php-danger-zone .button{background:var(--clay)}
```

The controller `CustomerAccount::index()` (`Website/app/bridge/CustomerAccount.php:6`) already loads
`$devices`, `$addresses`, etc. Add `$wishlistCount`/`$cartCount` to the `respond(...)` payload there —
reuse `WishlistService::ids()` and `CartService::itemCount()` exactly as `header.php:13-16` does, so there
is one shared way to count (see **Optimization O1**).

---

## D3 — Delete account: OTP + type "Delete" (P1, reuses the OTP engine)

**Soft-delete only.** Orders snapshot the customer name + address into `order_items`/order rows and are a
financial ledger — a hard `DELETE FROM users_tbl` would orphan or break them. Keep the row, disable login
and scrub PII. `users_tbl` (`database/migrations/UsersTable.php`) has `status INT DEFAULT 1`; `Auth`
only logs in / resets when `status === 1` (`core/Auth.php:56`, and the `forgot` guard in
`CustomerAccount.php:176`), so `status = 0` cleanly disables the account with **no migration**.

Reuse `CustomerOtp` — do not build a second OTP path:

1. `Website/core/CustomerOtp.php:12` — add the purpose:
   `private const PURPOSES = ['register', 'login', 'reset', 'delete'];`
   and in `deliver()` (`:99`) add to the action map: `'delete' => 'confirm account deletion'`.
   The rate-limit (`:55`), hashing (`:90-91`) and verify (`:120`) paths then work unchanged.

2. `Website/app/view.php` (with the other `account/...` routes, ~line 50):

   ```php
   'account/delete'         => ['POST' => ['CustomerAccount', 'deleteStart']],
   'account/delete/confirm' => ['POST' => ['CustomerAccount', 'deleteConfirm']],
   ```

3. `Website/app/page/storefront/account.php` — **Danger zone** panel (signed-in branch):

   ```php
   <section class="panel php-danger-zone">
     <h2>Delete account</h2>
     <p>Permanently closes your account. Your past orders are retained for legal and billing records, but your profile, saved materials, designs and addresses are removed and you will be signed out.</p>
     <form method="post" action="<?= $u('account/delete') ?>">
       <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
       <button>Delete my account…</button>
     </form>
   </section>
   ```

4. `Website/app/page/storefront/otp_verify.php` — reuse it for the confirm step by branching on
   `$purpose === 'delete'`: change the form `action` to `<?= $u('account/delete/confirm') ?>` and add,
   inside that form, a required phrase field:

   ```php
   <label>Type <strong>Delete</strong> to confirm<input name="confirm" autocomplete="off" required pattern="Delete" title="Type Delete exactly"></label>
   ```

   Add `'delete' => 'Confirm account deletion'` to the `$lead` map (`otp_verify.php:2`). (The generic
   `account/otp` handler must **not** be reused for deletion — it completes a login; the dedicated
   `deleteConfirm` route below is why the action is switched.)

5. `Website/app/bridge/CustomerAccount.php` — two methods mirroring the existing `login()`/`otp()` shape
   (`:18`, `:57`):

   ```php
   public function deleteStart(): void
   {
       $this->handle(function (): void {
           $actor = CommerceAccess::requireActor();
           RequestSecurity::assertCsrf();                 // same guard the other POSTs use
           $_SESSION['pending_otp'] = ['user_id' => (int) $actor['id'], 'purpose' => 'delete', 'email' => $actor['email']];
           $sent = CustomerOtp::request((int) $actor['id'], 'delete', $actor['email']);
           $this->respond('app/page/storefront/otp_verify.php',
               ['purpose' => 'delete', 'email' => $actor['email'], 'sent' => $sent, 'offerTrust' => false]);
       });
   }

   public function deleteConfirm(): void
   {
       $this->handle(function (): void {
           $actor = CommerceAccess::requireActor();
           RequestSecurity::assertCsrf();
           $pending = $_SESSION['pending_otp'] ?? null;
           if (!$pending || ($pending['purpose'] ?? '') !== 'delete' || (int) $pending['user_id'] !== (int) $actor['id']) {
               throw new RequestRejected(400, 'otp_context_missing', 'Your confirmation session expired. Please start again.');
           }
           if (($_POST['confirm'] ?? '') !== 'Delete') {
               throw new RequestRejected(422, 'confirm_phrase', 'Type Delete exactly to confirm.');
           }
           if (!CustomerOtp::verify((int) $actor['id'], 'delete', (string) ($_POST['code'] ?? ''))) {
               throw new RequestRejected(422, 'otp_invalid', 'That code is invalid or expired. Request a new one.');
           }
           (new AccountDeletionService($this->pdo))->delete((int) $actor['id']);
           CustomerOtp::revokeAll((int) $actor['id']);
           CustomerOtp::clearDeviceCookie();
           unset($_SESSION['pending_otp']);
           $this->logout();                                // existing method clears the session (:92)
       });
   }
   ```

6. `Website/app/Services/AccountDeletionService.php` — **new file** (one real transactional method; it
   warrants a test, so it is a service, not inline). All writes in one transaction so a failure leaves the
   account intact:

   ```php
   final class AccountDeletionService
   {
       public function __construct(private PDO $pdo) {}

       public function delete(int $userId): void
       {
           $this->pdo->beginTransaction();
           try {
               // Free the unique email so the person can re-register later, and scrub profile PII.
               $this->pdo->prepare("UPDATE users_tbl SET status = 0, name = 'Deleted user',
                   firstname = 'Deleted', lastname = 'User', email = ?, password = ?, remember_token = NULL,
                   verify_token = NULL WHERE id = ?")
                   ->execute(['deleted+' . $userId . '@deleted.invalid',
                       password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $userId]);
               // Address PII is not needed once orders have snapshotted it.
               $this->pdo->prepare('DELETE FROM addresses WHERE user_id = ?')->execute([$userId]);
               $this->pdo->prepare('DELETE FROM wishlists WHERE user_id = ?')->execute([$userId]);
               $this->pdo->prepare("UPDATE carts SET status = 'abandoned' WHERE user_id = ? AND status = 'active'")->execute([$userId]);
               $this->pdo->prepare('DELETE FROM auth_trusted_devices WHERE user_id = ?')->execute([$userId]);
               $this->pdo->commit();
           } catch (\Throwable $e) {
               $this->pdo->rollBack();
               throw $e;
           }
       }
   }
   ```

   Orders (`orders`, `order_items`) are **not** touched — they keep the snapshotted name/address.
   Register `AccountDeletionService` the way the other services are autoloaded (`config/classes.php`
   if an explicit map entry is needed; otherwise the existing `Services/` loader covers it).

---

## D4 — Payment step: a real summary, not a bare button (P1)

File: `Website/app/page/storefront/checkout.php` lines 2–4. The `isset($payment)` branch is just a heading,
order number, amount and a lone "Pay online" button — no recap, no reassurance. Keep the Razorpay script
(lines 9–30) unchanged; expand only the visible panel. The controller that renders this branch should also
pass the order items/totals/address it already has (same shape `order.php` receives).

```php
<section class="panel php-payment">
  <div class="page-intro"><div><span class="eyebrow">SECURE CHECKOUT</span><h1>Complete your payment</h1>
    <p>Order <?= $e($payment['order_number']) ?> is reserved for you. Pay securely to confirm it.</p></div></div>

  <div class="php-payment-grid">
    <div>
      <h2>What you're paying for</h2>
      <?php foreach ($payment['items'] as $item): ?>
        <p><?= $e($item['name']) ?> · <?= $q($item['qty_milli']) ?> <?= $e($item['sell_unit']) ?> · <?= $m($item['line_total_minor']) ?></p>
      <?php endforeach; ?>
      <dl><?php foreach (['subtotal_minor'=>'Subtotal','discount_minor'=>'Discount','shipping_minor'=>'Delivery','tax_minor'=>'Tax','amount_minor'=>'Amount due'] as $f=>$l): ?>
        <dt><?= $e($l) ?></dt><dd><?= $m($payment[$f] ?? 0) ?></dd><?php endforeach; ?></dl>
    </div>
    <aside>
      <h2>Delivery</h2>
      <p><?= $e($payment['address']['recipient']) ?><br><?= $e($payment['address']['line1']) ?><br>
         <?= $e($payment['address']['city'] . ' ' . $payment['address']['postal_code']) ?></p>
      <p class="php-trust"><?= $icon('shield') ?> Payments are processed by Razorpay. We never see your card details.</p>
    </aside>
  </div>

  <button type="button" id="razorpay-pay" class="button">Pay <?= $m($payment['amount_minor']) ?> securely</button>
  <p id="payment-notice" class="muted" role="status">Your order is held while awaiting payment.</p>
</section>
```

CSS (`html-parity.css`):

```css
.php-payment-grid{display:grid;grid-template-columns:2fr 1fr;gap:28px;margin:18px 0}
.php-payment-grid dl{display:grid;grid-template-columns:1fr auto;gap:6px 24px;margin-top:14px}
.php-payment-grid dd{text-align:right;margin:0}
.php-payment-grid dt:last-of-type,.php-payment-grid dd:last-of-type{font-weight:700;border-top:1px solid var(--line);padding-top:8px}
.php-trust{display:flex;gap:8px;align-items:center;color:var(--muted);font-size:11px;margin-top:16px}
.php-trust svg{width:18px;height:18px;fill:none;stroke:var(--teal);stroke-width:1.5;flex:none}
@media(max-width:650px){.php-payment-grid{grid-template-columns:1fr}}
```

Apply the same `dl` + trust-row treatment to the **Order review** step (`checkout.php:44-49`) so review and
payment look like one flow.

---

## D5 — Reskin the invoice / order summary (P0 for consistency)

File: `Website/app/page/storefront/invoice.php`. Lines 6 ships a **self-contained inline `<style>` with raw
hex** (`#172c37`, `#ccc`) — it reads as a different website. Keep it a standalone print document (it
intentionally does not load the storefront shell, for clean PDF output) but switch the screen palette to the
brand tokens and group the blocks. Minimal change — replace the `<style>` body:

```css
:root{--ink:#292d29;--muted:#706e65;--line:#e5e0d6;--teal:#647158}
body{font:15px/1.6 Inter,system-ui,sans-serif;color:var(--ink);max-width:1100px;margin:2rem auto;padding:1rem}
h1{font-size:22px}h2{font-size:15px;color:var(--teal);letter-spacing:.08em;text-transform:uppercase}
table{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:8px}
th{text-align:left;color:var(--muted);font-weight:600;border-bottom:2px solid var(--line)}
td,th{padding:.55rem .5rem}td{border-bottom:1px solid var(--line)}
dl{max-width:24rem;margin-left:auto;display:grid;grid-template-columns:1fr auto;gap:6px 24px}
dd{text-align:right;margin:0}dl dt:last-of-type,dl dd:last-of-type{font-weight:700;border-top:1px solid var(--line);padding-top:8px}
.actions .button{display:inline-block;padding:10px 16px;border-radius:6px;background:var(--teal);color:#fff;text-decoration:none;border:0;font:inherit;cursor:pointer}
@media print{.actions{display:none}body{margin:0;padding:0;font-size:11pt;color:#000}table{font-size:9pt}tr{break-inside:avoid}@page{margin:15mm}}
```

The data rows (line table, totals `<dl>`, address) are already correct — no logic change. Give the
"Print / Save PDF" button and "Back to order" link the `.button`/`.button secondary`-style classes above.

---

## D6 — Site-wide polish (P2) — DONE

Audit first found most of this already present; only the gaps below were changed.

- **Empty states — already done.** `cart.php`, `wishlist.php` and `orders.php` already render the
  `.empty-state` block (heading + muted copy + `.button` CTA), and `.empty-state` is fully styled in
  `storefront.css`/`storefront-pages.css` (ornament, responsive). No change.
- **Auth-form consistency — already done.** `forgot_password.php`, `reset_password.php` and the sign-in
  form already use a `.panel` with a `.page-intro` lead and a "Back to sign in ↗" link. No change.
- **Button hierarchy — fixed.** `php.css:2` (`main button{background:var(--clay)…}`) makes *every* bare
  `<button>` a loud clay primary, so genuinely-secondary actions were competing with the real primary.
  Demoted to `class="button secondary"` (its `.button.secondary` rule out-specifies `main button`):
  "Sign out", "Remove address", "Revoke all trusted devices" in `account.php`, and "Remove coupon" in
  `checkout.php`. Primaries (Sign in, Set PIN, Place order, Pay, Delete my account) stay clay.
- **Descriptive microcopy + `.help-text`.** Added a helper line under "Delivery addresses" in
  `account.php`. `.help-text` was used in the codebase but never styled — added one muted rule to
  `html-parity.css`, which also styles the existing trusted-device helper line.

---

## Optimization (grounded, not speculative)

- **O1 — De-duplicate the per-request count queries.** `header.php:11-17` runs a wishlist-count query
  **and** a cart `itemCount()` query on *every* commerce page render. D2 would add the same two again on
  the account page. Compute them once per request (e.g. a memoised helper on `StorefrontController`, or
  pass the header's `$navCounts` down to the view) instead of re-querying. Low risk, removes 1–2 DB
  round-trips per page.
- **O2 — Stylesheet count.** Every storefront page loads 5–6 CSS files (`header.php:21`), of which
  `html-parity.css` is a large *override* layer re-patching the base files. That is extra cascade weight
  and bytes on first paint. Folding the stable html-parity overrides back into `storefront.css`/`php.css`
  is a real win but a **separate, larger task** (risk of visual regressions); note it, don't do it inside
  this UX pass. Until then, keep new rules in `html-parity.css` as this doc does, to avoid widening the gap.
- **O3 — Images.** Confirm product/catalogue `<img>` use `loading="lazy"` and width/height to avoid layout
  shift; the hero/logo stay eager. Cheap, measurable LCP/CLS improvement.
- Scripts are already `defer`-loaded (`header.php:22`) — no change.

> **Scope note (AGENTS.md):** this guide is an implementation specification. D1 (link fix) and D5 (invoice
> reskin) are self-contained, migration-free and reversible. D3 (deletion) touches auth + PII and must be
> implemented with its test and reviewed before release. None of this requires a production migration or
> deploy to verify.

---

## Verification (disposable/local only)

- **D1/D5/D6:** `php -l` each changed PHP file; load the affected pages locally and confirm cards/buttons
  are no longer underlined and the invoice uses brand colours. `git diff --check` for whitespace.
- **D3:** add `tests/AccountDeletionTest.php` (or extend `tests/CustomerOtpTest.php`) asserting, against a
  disposable SQLite DB: deletion requires a valid `delete` OTP **and** `confirm === 'Delete'`; after
  `AccountDeletionService::delete()` the user row has `status = 0`, a freed `deleted+{id}@…` email, no
  `addresses`/`wishlists`/`auth_trusted_devices` rows, and **orders are unchanged**; a subsequent login
  attempt is rejected (`status !== 1`). Replay/second call is a no-op.
- Re-run the existing suites: `php tests/DeveloperAccessTest.php`, `php tests/OrderPlacementTest.php`,
  `php tests/CustomerOtpTest.php`, `python3 tests/storefront_http_test.py`.
- Validate `account/delete` + `account/delete/confirm` enforce CSRF and `requireActor()` (anonymous → the
  normal 404/redirect).

## Out of scope

- Folding `html-parity.css` overrides back into the base stylesheets (O2) — separate task, regression risk.
- Hard-delete / full GDPR-style data export — soft-delete + PII scrub is the committed behaviour here.
- Any new payment provider UI — D4 only reskins the existing Razorpay step.
- A real PDF-generation library for invoices — the print path stays (see FULFIL-01 P4.3).
