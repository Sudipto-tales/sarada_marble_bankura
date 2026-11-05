# ADM-UI-01 — Hyper theme parity for the staff admin panel, with live AJAX updates

**Status: specification, not completed behaviour.** Nothing in this document has been
implemented. It is the execution plan for porting the Hyper template's eCommerce and Analytics
screen designs onto the live Vayu admin panel without visual changes, and for making the panel
update in place over AJAX instead of reloading.

Read [contracts.md](../contracts.md) and the repository [AGENTS.md](../../../AGENTS.md) before
editing: escaped output, prepared SQL, CSRF on every cookie-authenticated mutation, server-side
permission/ownership checks, integer minor/milli money and quantity, operation keys and
revision-based optimistic concurrency. This plan changes **presentation and transport only** —
no domain service relaxes a rule, and no AJAX path becomes authoritative for money, stock or
grants.

- Guide index entry: add `ADM-UI-01` to [tasks/README.md](README.md).
- Tracker: add `ADM-UI-01` to [tracking.md](../tracking.md) before the first edit (claim first).
- Related existing guides: [07-admin.md](07-admin.md) (ADM-01/02/03 boundaries),
  [08-storefront-api.md](08-storefront-api.md#web-03) (JSON API contract),
  [09-analytics-search-pricing.md](09-analytics-search-pricing.md#evt-02) (metrics source),
  [admin-panel-design.md](../../admin-panel-design.md) (delivered ADM-01 shell).

---

## 0. Fidelity rule — this is the binding constraint

**Copy the Hyper markup. Do not redesign anything. Nothing is dropped.**

For every screen in §2, the conversion is a two-step mechanical operation:

1. **Copy the Hyper page's content region verbatim** — `<div class="content-page">` through the
   closing `</div> <!-- content -->`, from the file and line range named in §2. Every element,
   every class, every `id`, every `data-*` attribute, every wrapper `<div>`, every
   `<!-- end card -->` comment, every inline `style=`, in Hyper's order and nesting. Byte-for-byte.
2. **Replace only the values** — the text nodes and attribute values that are sample data — with
   `<?= $e(...) ?>` expressions over the real row, and wrap repeating blocks in `foreach`.

What may change, and nothing else:

| May change | Must not change |
| --- | --- |
| Text content of a cell, label, heading or badge | Any class, `id`, `data-*`, `style`, `role`, element name or nesting |
| `href` / `action` / `value` / `src` attribute values | The presence or position of any element |
| Number of rows in a `<tbody>` (`foreach`) | The number or order of `<th>` / `<td>` per row |
| A `<tr>`/`<div>` repeated by a loop | Column widths, `col-*` grid classes, card order |

### 0.1 Sizes are part of the design — do not change any of them

Every dimension token is copied as-is. That means **all** of these, not just the obvious ones:

| Size carrier | Rule |
| --- | --- |
| Grid classes — `col-12`, `col-xl-3`, `col-xl-9`, `col-lg-8`, `col-lg-4`, `col-md-10`, `col-sm-11`, `col-sm-5`, `col-sm-7` | Copied exactly, **including every breakpoint in the list**. Never add, drop or re-pick a breakpoint. |
| Inline `style="width: …"` / `style="height: …"` / `style="max-width: …"` | Copied byte-for-byte, except a percentage that *is* the datum (a progress bar's `width`, the step bar's `process-line`) |
| `height="48"` / `height="42"` attributes on `<img>` | Copied exactly |
| Font-size classes — `font-13`, `font-14`, `font-16`, `font-22` | Copied exactly |
| Spacing utilities — `mt-0 mt-2 mt-3 mt-4`, `mb-0 mb-1 mb-2 mb-3 mb-4`, `me-1 me-2 me-3`, `ms-2`, `ps-lg-4`, `p-2`, `pt-0`, `pb-5`, `gy-2 gx-2`, `g-*` | Copied exactly. These set the rhythm; changing one shifts the whole card. |
| Component size modifiers — `progress-sm`, `table-sm`, `btn-sm`, `avatar-sm`, `avatar-xs`, `card-h-100` | Copied exactly |
| ApexCharts `height:` in the options object (`309`, `345`, `320`, `269`, `150`) | Copied exactly from `demo.dashboard-analytics.js` |
| `#world-map-markers` `style="height: 300px"` | Copied exactly |
| `admin.css` | Must not introduce any `width`, `height`, `max-width`, `padding`, `margin`, `font-size`, `gap` or `grid-template-columns` rule that targets a Hyper class. §4.3's deletions only. |

Consequence: **no responsive "improvements."** If Hyper puts the order step bar in
`col-lg-7 col-md-10 col-sm-11`, it stays in `col-lg-7 col-md-10 col-sm-11` even though a wider
column would suit seven steps better. Raise it as a question instead of widening it.

The one legitimate exception, stated so it is not mistaken for drift: `w-100` on a DataTables
`<table>`. Hyper itself ships it on its DataTables pages
(`apps-ecommerce-products.html:1247`: `class="table table-centered w-100 dt-responsive nowrap"`),
and DataTables needs it to compute column widths. It is Hyper's own token, not a new one.

### 0.2 Verified size inventory per screen

Taken from the files themselves — use it as the checklist when converting, and assert it in
`Tools/compare_structure.py`.

| Screen | Every size token in Hyper's content region |
| --- | --- |
| `apps-ecommerce-orders-details.html` | `col-12`, `col-lg-7 col-md-10 col-sm-11` (step bar), `col-lg-8`, `col-lg-4` ×4, `style="width: 33%;"` (process-line), `font-14`, `pb-5`, `mt-4 mb-4` |
| `apps-ecommerce-products-details.html` | `col-12` ×2, `col-lg-5`, `col-lg-7`, `col-md-4` ×3, `style="max-width: 280px;"`, `style="max-width: 75px;"` ×3, `style="width: 90px;"`, `progress-sm` ×4, bar widths `89% 72% 56% 16%`, `font-14` ×6, `font-16`, `p-2`, `ps-lg-4` |
| `apps-ecommerce-orders.html` | `col-12` ×2, `col-xl-8`, `col-xl-4`, `style="width: 20px;"` (checkbox `<th>`), `style="width: 125px;"` (action `<th>`), `gy-2 gx-2` |
| `apps-ecommerce-products.html` | `col-12` ×2, `col-sm-5`, `col-sm-7`, `style="width: 20px;"`, `style="width: 85px;"`, `height="48"` ×12, `font-16` ×12 |
| `apps-ecommerce-customers.html` | `col-12` ×2, `col-sm-5`, `col-sm-7`, `style="width: 20px;"`, `style="width: 75px;"` |
| `dashboard-analytics.html` | `col-xl-3 col-lg-4`, `col-xl-9 col-lg-8`, `col-12` (map card), `col-lg-8` + `col-lg-4` (inside it), `col-xl-4 col-lg-12` ×2, `col-xl-4 col-lg-6` ×4, `style="height: 300px"`, `th style="width: 40%;"` ×2, `th style="width: 30%;"` ×2, `progress style="height: 3px;"` ×8, bar `style="width: N%; height: 20px;"` ×8, `table-sm` ×3, `font-13`, `font-14` |
| `Html/admin/apps-ecommerce-products-add.html` | `col-lg-8` + `col-lg-4` (form / sidebar split), `col-12` ×3, `col-md-4` ×10, `col-md-6` ×4, `col-md-3` ×4, `form-control` ×15, `form-select` ×6 |
| `Hyper/widgets.html` (overview, stock tiles) | `col-xxl-3 col-sm-6` per `.card.widget-flat` — **not** `col-md-6 col-xl-3`; see §0.3 |
| `Hyper/form-elements.html` (coupon, settings, delivery, staff, customer) | `col-lg-6` ×2, `col-12` ×2 per card row |
| `Hyper/pages-login-2.html` | No grid columns at all — flex layout via `.auth-fluid` → `.auth-fluid-form-box` + `.auth-fluid-right`, with `.card-body.d-flex.flex-column.h-100.gap-3`, `.auth-brand.text-center.text-lg-start`, `height="22"` on both logos, `footer.footer-alt`, and `<body class="authentication-bg pb-0">`. All seven classes are present in the vendored `app-saas.min.css` (verified), so the whole page is a markup copy with no CSS work. |

Note the action-column widths differ per screen on purpose — `125px` on orders (3 icons),
`85px` on products, `75px` on customers. Copy each screen's own value; do not standardise them.

The login page is the only screen whose sizes live entirely in CSS rather than in markup. That
makes it the lowest-risk conversion of the ten and the one that lets §4.3 delete `admin.css`'s
`.login-*` block — about a fifth of the file.

### 0.3 Resolved — `.widget-flat` columns are Hyper's `col-xxl-3 col-sm-6`

**Decided 2026-11-05 by the user: use Hyper's `col-xxl-3 col-sm-6`.**

`overview.php` currently ships `col-sm-6 col-xl-3`, which switches to 4-up at ≥1200 px.
Hyper's widget source `widgets.html:1277` uses `col-xxl-3 col-sm-6`, which switches at ≥1400 px.
Between 1200 and 1400 px the two differ (4-up vs 2-up). `Hyper/index.html` could not break the
tie — it contains **zero** `.widget-flat` elements — so `widgets.html` is the sole reference, and
§0 makes it authoritative. The existing value came from the earlier `ADM-01` approximation, not a
design decision.

**Exactly two places change**, both in `app/page/admin/overview.php`, both `.card.widget-flat`:

| Loop | Current | Becomes |
| --- | --- | --- |
| `$cards` — the overview counts (`a.card.widget-flat.text-reset`) | `col-sm-6 col-xl-3` | `col-xxl-3 col-sm-6` |
| `$attention` — the "Needs attention" counts (`a.card.widget-flat.text-reset…`) | `col-sm-6 col-xl-3` | `col-xxl-3 col-sm-6` |

`stock.php` has no columns yet (it uses the custom `.metric-grid` / `.metric-card`); its new
`.card.widget-flat` tiles are authored directly at `col-xxl-3 col-sm-6`.

**Not covered by this decision.** The third grid in `overview.php` — the "Your daily workspace"
tiles at `col-md-6 col-xl-4` — is `a.card.text-reset.h-100` with an `.avatar-sm`, **not** a
`.widget-flat`. `widgets.html` is therefore not its reference, and Hyper has no 3-up plain-card
equivalent. It keeps `col-md-6 col-xl-4` unchanged until a Hyper source is named for it. Changing
it would be a redesign, which §0 forbids.

This is now a closed decision, not an assumption: S9 must produce exactly these two edits, and
`Tools/compare_structure.py` asserts `col-xxl-3 col-sm-6` against `widgets.html:1277` for both.

## 1. What exists today (verified, 2026-11-04)

### 1.1 Three copies of the theme are already in the repository

| Location | What it is | Role in this task |
| --- | --- | --- |
| `Hyper/` | Untouched Coderthemes **Hyper** Bootstrap 5 export, 131 HTML pages + full `assets/` | **Design source of truth.** Copy markup from here. |
| `Html/admin/` | 17-page static adaptation of Hyper for this store (task `HTML-ADM-01`, done 2026-10-28), full `assets/` tree including every vendor plugin | **Second source.** Already renamed to marble/INR wording and contains a purpose-built Add Product form. Also the asset donor. |
| `Website/assets/admin/hyper/` | Partial vendoring: 2 CSS files, fonts, 3 JS files, `apexcharts`, `datatables.net*` | **Target.** The live panel already loads this. Needs 4 more plugins (§3). |

`Website/assets/admin/hyper/README.md` records the provenance and the Bootstrap-5.2 caveat
(`*-subtle` utilities do not exist; use Hyper's `bg-*-lighten` / `avatar-title`). Keep that file
accurate — the repository template-licensing policy depends on it.

### 1.2 The live panel is half-converted already

`Website/app/page/admin/header.php:10-19` and `footer.php:1` **already render the Hyper shell**:
`.wrapper`, `.navbar-custom` / `.topbar`, `.leftside-menu` + `.side-nav` + `data-simplebar`,
`.content-page` / `.content` / `.container-fluid`, `.page-title-box`, `footer.footer`. It loads
`hyper-config.js`, `app-saas.min.css`, `icons.min.css`, `vendor.min.js`, `app.min.js`,
`apexcharts.min.js`, DataTables 2.3.4 and `admin.js`.

Two views follow suit:

- `overview.php` — Hyper `.card.widget-flat`, `.avatar-title.bg-primary-lighten`, `.widget-icon`.
- `table.php` — Hyper `.card` / `.card-body` / `.header-title` / `.btn.btn-primary` wrapper around
  a DataTable.

**Eleven views still use the pre-Hyper custom design system** defined in
`Website/assets/admin/admin.css` (22 long lines, ~26 KB): `.panel`, `.panel-heading`, `.pill`,
`.field`, `.form-grid`, `.button` / `.button.secondary` / `.text-button`, `.notice`, `.admin-table`,
`.metric-grid` / `.metric-card`, `.order-grid`, `.totals`, `.timeline`, `.image-grid`,
`.variant-row`, `.report-controls`, `.login-page`.

Those views are: `order.php`, `product.php`, `reports.php`, `stock.php`, `customer.php`,
`coupon.php`, `reviews.php`, `delivery.php`, `staff.php`, `settings.php`, `login.php`.

So the task is **not** "add Hyper" — it is "finish the conversion and delete the parallel design
system", plus the analytics dashboard and AJAX.

### 1.3 Dead code and stale tests found during this analysis

These are pre-existing defects, not caused by this plan. Fix them inside the slices below.

| Finding | Evidence |
| --- | --- |
| `admin.js:4-25` builds a mobile drawer for `.sidebar` / `.drawer-toggle` / `.sidebar-backdrop` / `.drawer-close`. **No admin view renders any of those selectors** — the Hyper shell uses `.button-toggle-menu` + `.leftside-menu`, handled by `app.min.js`. The whole block is dead. | `grep -l 'drawer-toggle\|class="sidebar' Website/app/page/admin/*.php` → no matches; `grep -c` in `admin.js` → 1, 1, 2 |
| `admin_http_test.py --browser` asserts `.welcome-panel` (1×) and `.drawer-toggle` (3×), including `document.querySelector('.sidebar').inert`. Those elements no longer exist, so the `--browser` run **cannot pass today**. The last recorded 144-check browser pass predates the shell conversion. | `grep -c` in `tests/admin_http_test.py` → `welcome-panel` 1, `drawer-toggle` 3 |
| `<body data-admin-base="…">` is set at `header.php:10` and read by nothing. | `grep -rn 'data-admin-base\|adminBase'` → one hit, the definition |
| `admin.css` still ships `.login-page` / `.sidebar` / `.topbar` / `.admin-workspace` / `.quick-grid` / `.welcome-panel` / `.brand-emblem` rules for a layout that no longer renders. | `admin.css` lines 3–4 vs `header.php` |

`data-admin-base` becomes useful in §7 — it is exactly the base URL the AJAX layer needs.

### 1.4 Routes, controller and data services

Admin routes: `Website/app/view.php:72-96`. Order matters — `admin/data/{slug}` (line 74) and the
fixed paths precede the `admin/{slug}` catch-all (line 78).

`Website/app/bridge/Admin.php` (438 lines) is the only HTML controller:

| Concern | Symbol |
| --- | --- |
| Per-session staff elevation, 1800 s idle | `Admin::elevated()` (`:21`), `ELEVATION_IDLE` (`:6`) |
| Actor + grants, 403 for non-staff | `Admin::actor()` (`:33`) |
| Render a view under `app/page/admin/` | `Admin::render()` (`:40`) |
| POST → save → flash → 303, re-render on 422/409/503 | `Admin::submitted()` (`:46`) |
| Table page + DataTables JSON | `tablePage()` (`:91`), `data()` (`:107`) |
| Reports page + CSV export | `reports()` (`:319`), `reportCsv()` (`:329`) |

Read models: `Website/app/Services/AdminReadService.php`
- `TABLES` (`:6`) — the 8 list modules, each `[label, permission, columns]`.
- `table()` (`:28`) — bounded server-side DataTables projection; allowlisted sort columns,
  `ESCAPE '!'` LIKE search, `length` capped at 100, staff excluded from `customers`.
- `reports()` (`:84`) — UTC range ≤ 93 days → `sales`, `coupons`, `statuses`, `topProducts`,
  `lowStock`.
- `overview()` (`:129`), `attention()` (`:114`) — dashboard counts.
- `decimal()` (`:22`) — integer → fixed-precision string. **Use this, never floats.**

Writes stay in the domain services: `ProductService`, `InventoryService`, `OrderService`,
`CouponService`, `SettingsService`, `DeliveryService`, `StaffAccessService`, `ReviewService`,
`ProductImageService`.

`OrderService::staffDetail()` (`:200`) → `hydrate()` (`:184`) returns
`items[]`, `history[]` and `address` (decoded from `orders.address_snapshot`).

### 1.5 Existing JSON API (WEB-03)

`Website/api/gateway.php:26-29` registers only three staff endpoints, implemented in
`Website/app/bridge/AdminApiController.php`:

| Method + path | Handler | Grant |
| --- | --- | --- |
| `GET /api/admin/products` | `products()` | `admin.products.view` |
| `POST /api/admin/products` | `productSave()` | `admin.products.edit` |
| `POST /api/admin/inventory` | `inventoryAdjust()` | `admin.inventory.adjust` |
| `POST /api/admin/orders/{id}/status` | `orderStatus()` | `admin.orders.edit` |

Every mutation calls `RequestSecurity::requireMutation()`, re-resolves grants from the database via
`CommerceAccess::requirePermission()`, and takes `operation_key` (or `Idempotency-Key`) plus
`revision`. Responses go through `StorefrontController::json()` / `jsonValues()`, which stringifies
`id`, `*_id` and `*_minor` so JavaScript never sees money as a float.

The storefront already has the progressive-enhancement pattern to copy:
`Website/assets/storefront/storefront-ajax.js` (106 lines) — delegate on submit, POST JSON with
`X-CSRF-Token`, patch the DOM, toast, and `form.submit()` as the fallback on network failure.

### 1.6 Analytics data that exists

- `events` + `event_processing` + `event_business_keys` — migration `0029_events.php`,
  `EventService` (`PUBLIC_TYPES = product_view, search, add_to_cart`; trusted purchase/cancellation
  from `PurchaseEventJob`).
- `product_daily_metrics` — migration `0030_analytics_metrics.php`: unique
  `(product_id, metric_date)`, columns `views`, `add_to_carts`, `orders_gross`, `orders_net`,
  `units_gross_milli`, `units_net_milli`. Filled by `MetricsService::aggregate()`.
- `analytics_checkpoint` — observability row; `MetricsService::pending()` reports lag.
- `orders` — `status`, `payment_status`, `total_minor`, `created_at`, indexed by
  `idx_order_status_time (status, created_at, id)`.

**Gap for the map:** delivery geography lives only inside the `orders.address_snapshot` TEXT
column (migration `0008_order_snapshots.php:12`). There is no queryable state or PIN column, so a
state choropleth cannot be produced by `GROUP BY`. §6.1 adds one.

---

## 2. Screen mapping — Hyper source → live route → file to edit

| # | Hyper source file | Also adapted in | Live route | View to convert | Controller |
| --- | --- | --- | --- | --- | --- |
| 1 | `Hyper/dashboard-analytics.html:1211-1673` | `Html/admin/index.html:577-1039` | `GET /admin/reports` | `app/page/admin/reports.php` | `Admin::reports()` |
| 2 | `Hyper/apps-ecommerce-orders.html:1207-1575` | `Html/admin/apps-ecommerce-orders.html` | `GET /admin/orders` | `app/page/admin/table.php` (`$module==='orders'`) | `Admin::table()` |
| 3 | `Hyper/apps-ecommerce-orders-details.html:1207-1408` | `Html/admin/apps-ecommerce-orders-details.html` | `GET /admin/order?id=` | `app/page/admin/order.php` | `Admin::orderForm()` |
| 4 | `Hyper/apps-ecommerce-products.html:1211-1790` | `Html/admin/apps-ecommerce-products.html` | `GET /admin/products` | `app/page/admin/table.php` (`$module==='products'`) | `Admin::products()` |
| 5 | `Hyper/apps-ecommerce-products-details.html:1207-1392` | `Html/admin/apps-ecommerce-products-details.html` | `GET /admin/product?id=` | `app/page/admin/product.php` (read panels) | `Admin::productForm()` |
| 6 | — | `Html/admin/apps-ecommerce-products-add.html:577-752` | `GET /admin/product` | `app/page/admin/product.php` (form) | `Admin::productForm()` |
| 7 | `Hyper/apps-ecommerce-customers.html:1211-1670` | `Html/admin/apps-ecommerce-customers.html` | `GET /admin/customers` | `app/page/admin/table.php` (`$module==='customers'`) | `Admin::table()` |
| 8 | `Hyper/widgets.html:1276-1340` (`index.html` has no `.widget-flat`) | `Html/admin/index.html` | `GET /admin` | `app/page/admin/overview.php` | `Admin::index()` |
| 9 | `Hyper/pages-login-2.html` | — | `GET /admin/login` | `app/page/admin/login.php` | `Admin::login()` |
| 10 | `Hyper/form-elements.html`, `ui-cards.html` | `Html/admin/form-elements.html` | `/admin/stock`, `/admin/customer`, `/admin/coupon`, `/admin/delivery`, `/admin/staff`, `/admin/settings`, `/admin/product-reviews` | the 7 remaining views | various |

`Html/admin/apps-ecommerce-products-add.html` is the most valuable donor: its field names already
match `ProductService::save()` input exactly (`name`, `slug`, `category_id`, `brand_id`,
`description`, `tags`, `status`, `featured`, `advance_rate`, `variants[0][sku]`,
`variants[0][sell_unit]`, `variants[0][price]`, `variants[0][increment]`,
`variants[0][coverage]`, `variants[0][thickness]`, `variants[0][length]`,
`variants[0][width]`, `variants[0][enabled]`, `stock`).

---

## 3. Slice S1 — vendor the missing plugins

Copy from `Html/admin/assets/` (identical to `Hyper/assets/`) into
`Website/assets/admin/hyper/`. Do not minify, rename or edit the plugin files.

```
Html/admin/assets/vendor/admin-resources/jquery.vectormap/jquery-jvectormap-1.2.2.css
Html/admin/assets/vendor/admin-resources/jquery.vectormap/jquery-jvectormap-1.2.2.min.js
Html/admin/assets/vendor/admin-resources/jquery.vectormap/maps/jquery-jvectormap-in-mill-en.js
Html/admin/assets/vendor/admin-resources/jquery.vectormap/maps/jquery-jvectormap-world-mill-en.js
Html/admin/assets/vendor/daterangepicker/daterangepicker.css
Html/admin/assets/vendor/daterangepicker/daterangepicker.js
Html/admin/assets/vendor/daterangepicker/moment.min.js
Html/admin/assets/vendor/datatables.net-bs5/css/dataTables.bootstrap5.min.css
Html/admin/assets/vendor/datatables.net/js/jquery.dataTables.min.js
Html/admin/assets/vendor/datatables.net-bs5/js/dataTables.bootstrap5.min.js
Html/admin/assets/vendor/datatables.net-responsive/js/dataTables.responsive.min.js
Html/admin/assets/vendor/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css
Html/admin/assets/vendor/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js
```

→ the same relative paths under `Website/assets/admin/hyper/vendor/`.

**Both maps are vendored.** Hyper's analytics card is `Sessions by country` over
`world_mill_en`, so the world map is part of the design and ships. The India map ships alongside it
because every order this store has is Indian and a world choropleth would render 33 grey regions
and one tinted one. The *markup is identical either way* — only the `map:` option differs — so make
it a stored setting instead of a code decision:

- add `analytics_map` to `store_settings` (migration `0016_store_settings.php` already holds the
  key/value table; `SettingsService::effective()` surfaces it), allowlisted to
  `in_mill_en | world_mill_en`, default `in_mill_en`;
- `reports.php` emits it as a `data-map` attribute on the unchanged `#world-map-markers` div;
- `admin-analytics.js` passes it straight to `vectorMap({map: el.dataset.map, …})`;
- Store settings (§9) gets the select, so switching is a UI action, not an edit.

Also vendor `jquery-jvectormap-world-mill-en.js`. Record both in the README.

**Verified class availability.** `Website/assets/admin/hyper/css/app-saas.min.css` already defines
every component class this plan uses: `horizontal-steps`, `tilebox-one`, `cta-box`, `widget-flat`,
`progress-w-percent`, `badge-success-lighten`, `table-user`, `action-icon`, `avatar-title`,
`bg-primary-lighten`, `card-h-100`, `apex-charts`, `jvectormap`. One exception —
**`table-action` is absent** in this build; use `action-icon` on the links and no wrapper class
on the `<td>`.

**DataTables decision.** The panel currently loads DataTables **2.3.4** from
`assets/admin/vendor/` with a hand-rolled server-side config in `admin.js`. Hyper's own pages use
DataTables **1.x + bootstrap5** and `demo.customers.js` relies on 1.x-only options
(`columns[].checkboxes`, `dataTables_paginate`, `#products-datatable_length`). **Keep 2.3.4** and
reproduce Hyper's *look* via `layout` + `renderer` + the `.table-centered.table-nowrap` classes
(S3). Porting `demo.*.js` verbatim would require downgrading DataTables and re-breaking the
server-side search/pagination that `admin_http_test.py:111-115` proves. Vendor the bs5 CSS anyway
so the pagination/length chrome matches, and load the bs5 pagination stylesheet after
`dataTables-2.3.4.min.css`.

Update `Website/assets/admin/hyper/README.md`: list the new plugin groups, both vector maps and
the `analytics_map` setting that chooses between them, and why DataTables 2.3.4 is retained.

**Verify:** `curl -sI` each new asset path returns `200` with the right `Content-Type`; add the
probes to `admin_http_test.py` next to the existing asset checks.

---

## 4. Slice S2 — shell parity, then delete the parallel design system

### 4.1 `header.php` additions

Keep the existing structure. Add the Hyper pieces that are missing, all from
`Hyper/dashboard-analytics.html:30-1210`:

1. **Breadcrumb** in `.page-title-right`, replacing the lone "View storefront" button
   (`header.php:18`). Hyper's shape (`apps-ecommerce-orders.html:1216-1220`):

   ```php
   <div class="page-title-right">
     <ol class="breadcrumb m-0">
       <li class="breadcrumb-item"><a href="<?= $u('admin') ?>">Admin</a></li>
       <?php foreach($crumbs??[] as $crumb): ?>
         <?php if(!empty($crumb['href'])): ?>
           <li class="breadcrumb-item"><a href="<?= $e($crumb['href']) ?>"><?= $e($crumb['label']) ?></a></li>
         <?php else: ?>
           <li class="breadcrumb-item active"><?= $e($crumb['label']) ?></li>
         <?php endif; ?>
       <?php endforeach; ?>
     </ol>
   </div>
   ```

   Each view sets `$crumbs` before `require __DIR__.'/header.php'`. Keep the storefront link —
   move it next to the user dropdown in `.topbar-menu` (it is already there at `header.php:13`).

2. **`.leftbar-user`** above `.side-nav` (`Html/admin/index.html` sidebar block): initial-avatar
   span reusing the topbar's existing `mb_strtoupper(mb_substr($actor['name'],0,1))` expression,
   plus `$actor['name']`. No `assets/images/users/avatar-1.jpg` — that file is not vendored and the
   store has no staff avatars.

3. **Dark-logo pair.** Hyper renders `.logo-light` and `.logo-dark`; the panel renders only
   `.logo-light`. `hyper-config.js` defaults to `theme:"light"`, so this is cosmetic today — add
   the `.logo-dark` anchor pointing at the same brand assets so a later theme switch does not blank
   the logo.

4. **Notices → Hyper alerts.** `header.php:19` currently emits `.notice.success` / `.notice.error`:

   ```php
   <?php if($notice): ?>
     <div class="alert alert-success alert-dismissible fade show" role="status">
       <?= $e($notice) ?>
       <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
     </div>
   <?php endif; ?>
   <?php if($errors): ?>
     <div class="alert alert-danger error-summary" role="alert" tabindex="-1">
       <h5 class="alert-heading">We couldn’t save these changes.</h5>
       <ul class="mb-0"><?php foreach(array_unique($errors) as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
       <?php if(http_response_code()===409): ?><hr><p class="mb-0">Reload the current record before submitting again. Your entered values are shown below.</p><?php endif; ?>
     </div>
   <?php endif; ?>
   ```

   Keep `class="error-summary"` and `tabindex="-1"`: `admin.js:85` focuses it.

5. **Toast container** for S7: one empty `<div id="admin-toasts" class="toast-container position-fixed bottom-0 end-0 p-3"></div>` just inside `<main>`.

6. **New asset tags**, conditional so ordinary pages do not download the map:

   ```php
   <?php if(($page_assets??'')==='analytics'): ?>
   <link rel="stylesheet" href="<?= $u('assets/admin/hyper/vendor/daterangepicker/daterangepicker.css') ?>">
   <link rel="stylesheet" href="<?= $u('assets/admin/hyper/vendor/admin-resources/jquery.vectormap/jquery-jvectormap-1.2.2.css') ?>">
   <?php endif; ?>
   ```

   and the matching deferred `<script>` tags. `reports.php` sets `$page_assets='analytics';`
   before the `require`. Scripts must stay `defer` and must load **after** `vendor.min.js`
   (jvectormap and daterangepicker are jQuery plugins; `vendor.min.js` bundles jQuery).

### 4.2 `admin.js` cleanup

- **Delete lines 4-25** (the dead `.sidebar` drawer). `app.min.js` already owns
  `.button-toggle-menu`.
- Keep the DataTable block (`:26-45`), the `#product-form` variant block (`:46-70`), the
  `form[data-validate]` block (`:71-84`), `form[data-confirm]`, the `.error-summary` focus and the
  `pageshow` button restore.
- Read `document.body.dataset.adminBase` once and export it for S7.

### 4.3 `admin.css` reduction

After S3–S6 and S9 no view renders the old classes. Reduce `admin.css` to only what Hyper does not
provide:

**Keep:** the `@font-face` for Inter, the brand token block (`:root`), `.skip-link`,
`.table-scroll`, `.table-message`, `.table-fallback-search` / `.fallback-pagination` /
`.table-panel.enhanced` (the no-JS fallback that `table.php` depends on), the `.dt-container`
overrides, `.image-grid`, `.variant-row` / `.variant-heading`, and the `.login-*` block until S9
converts `login.php`.

**Delete:** `.sidebar*`, `.topbar`, `.admin-workspace`, `.drawer-*`, `.page-heading`, `.eyebrow`,
`.button*`, `.text-button`, `.panel*`, `.pill`, `.welcome-panel`, `.brand-emblem`, `.metric-grid`,
`.metric-card`, `.metric-alert`, `.quick-grid`, `.quick-card`, `.admin-table`, `.form-grid`,
`.field*`, `.form-actions`, `.sticky-actions`, `.notice*`, `.order-grid`, `.totals`,
`.settings-list`, `.timeline`, `.archive-panel`, `.report-controls`, `.admin-footer`, and the
`@media` blocks that only target them.

Do this **last**, as one commit, with a `grep -rn` sweep over `app/page/admin/` proving each
deleted selector has zero remaining references.

### 4.4 `header.php` helper changes

`header.php:5` defines `$field()`, which emits `<label class="field">` + bare `<input>`. Rewrite it
to emit Bootstrap markup once, so all twelve views convert for free:

```php
$field=static function(string $name,string $label,$value='',string $type='text',string $attributes='',?string $errorKey=null,string $wrap='col-md-6')use($e,$val,$errors){
  $id='field-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$name);
  $error=$errors[$errorKey??$name]??null;
  echo '<div class="'.$e($wrap).' mb-3">';
  echo '<label class="form-label" for="'.$e($id).'">'.$e($label).'</label>';
  echo '<input id="'.$e($id).'" name="'.$e($name).'" type="'.$e($type).'" value="'.$e($val($value)).'" class="form-control'.($error?' is-invalid':'').'" '.$attributes
     .($error?' aria-invalid="true" aria-describedby="'.$e($id).'-error"':'').'>';
  if($error) echo '<div class="invalid-feedback d-block" id="'.$e($id).'-error">'.$e($error).'</div>';
  echo '</div>';
};
```

Add a sibling `$select()` and `$textarea()` with the same signature so views stop hand-writing
`<label class="field"><span>…</span><select>`.

**Do not change** the generated `id`/`name` attributes or the `value` escaping —
`admin_http_test.py:129,131` asserts on `name="slug"`, `value="original-stone"`, `value="1e3"` and
`&lt;script&gt;` in re-rendered bodies.

---

## 5. Slices S3–S5 — the eCommerce screens

### 5.1 S3 — list screens (`table.php` + `admin.js`)

`table.php` already has the Hyper card. Add Hyper's **toolbar row** and **per-module cell
renderers**.

Toolbar, from `apps-ecommerce-orders.html:1231-1259`, replacing the current heading `<div>`:

```php
<div class="row mb-2">
  <div class="col-xl-8">
    <form class="row gy-2 gx-2 align-items-center justify-content-xl-start justify-content-between" method="get">
      <div class="col-auto">
        <label for="table-search" class="visually-hidden">Search</label>
        <input type="search" id="table-search" name="q" class="form-control" placeholder="Search…" maxlength="80"
               value="<?= $e(is_string($_GET['q']??null)?$_GET['q']:'') ?>">
      </div>
      <?php if($module==='orders'): ?>
      <div class="col-auto">
        <div class="d-flex align-items-center">
          <label for="status-select" class="me-2">Status</label>
          <select class="form-select" id="status-select" name="status">
            <option value="">All</option>
            <?php foreach(array_keys(OrderStates::NEXT) as $status): ?>
              <option value="<?= $e($status) ?>" <?= ($_GET['status']??'')===$status?'selected':'' ?>><?= $e($status) ?></option>
            <?php endforeach; ?>
            <option value="delivered" <?= ($_GET['status']??'')==='delivered'?'selected':'' ?>>delivered</option>
            <option value="cancelled" <?= ($_GET['status']??'')==='cancelled'?'selected':'' ?>>cancelled</option>
          </select>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-auto"><button class="btn btn-light">Search</button></div>
    </form>
  </div>
  <div class="col-xl-4">
    <div class="text-xl-end mt-xl-0 mt-2">
      <?php /* existing primary action, unchanged logic from table.php */ ?>
      <?php if($module==='products'&&$write): ?><a class="btn btn-primary mb-2 me-2" href="<?= $u('admin/product') ?>"><i class="mdi mdi-plus-circle me-1"></i> Add material</a><?php endif; ?>
      <?php if($module==='coupons'&&$can('admin.pricing.edit')): ?><a class="btn btn-primary mb-2 me-2" href="<?= $u('admin/coupon') ?>"><i class="mdi mdi-plus-circle me-1"></i> Add coupon</a><?php endif; ?>
      <?php if($module==='orders'&&$can('admin.analytics.view')): ?><a class="btn btn-light mb-2" href="<?= $u('admin/reports') ?>">Reports</a><?php endif; ?>
    </div>
  </div>
</div>
```

**The status filter needs a server change.** `AdminReadService::table()` has no status parameter.
Add one, allowlisted against `array_keys(OrderStates::NEXT) + ['delivered','cancelled']`, appended
to `$where` as `o.status = ?` with a bound argument — never interpolated. Reject anything else via
`CommerceValues::invalid('status')`. Thread it through `Admin::tablePage()` and `Admin::data()`.

Table classes: use each screen's own Hyper value, not one shared string —
`class="table table-centered table-nowrap mb-0"` on orders (`apps-ecommerce-orders.html:1261`),
`class="table table-centered w-100 dt-responsive nowrap"` on products
(`apps-ecommerce-products.html:1247`), `class="table table-centered table-striped dt-responsive
nowrap w-100"` on customers (`apps-ecommerce-customers.html:1247`). Where a screen's Hyper string
lacks `w-100`, add only that one token, because DataTables needs it to compute column widths — it is
Hyper's own class on its other DataTables pages (§0.1). Keep `id="records-table"` and every
`data-*` attribute — `admin.js:28` and `admin_http_test.py:111-115,185-190` depend on them.

#### 5.1.1 The checkbox column

Hyper's first `<th style="width: 20px;">` and every row's first `<td>` carry a
`.form-check` + `.form-check-input` pair (`apps-ecommerce-orders.html:1264-1269`). Copy both
verbatim, `style="width: 20px;"` included. The action `<th>` keeps its own per-screen width —
`125px` orders, `85px` products, `75px` customers (§0.2) — so the eight list modules do **not**
share one header string; each takes the width from the Hyper page it maps to in §2. Hyper hard-codes `id="customCheck1"`, `customCheck2`, … — ids must stay unique, so the
header keeps `customCheck1` and the row renderer emits `customCheck-<module>-<row id>`, with the
`<label for>` matched. Same classes, same nesting, same `&nbsp;` label.

Wire it:

- header checkbox toggles every checkbox on the **current page** only (server-side paging means the
  other pages are not loaded, and a "select all 5 000 records" that silently means 20 would be a
  lie). After toggling, the toolbar shows `n selected` and enables the bulk buttons.
- `admin.js` keeps the selected **ids** in a `Set`, not DOM state, so a DataTables redraw does not
  lose them; re-check boxes in `drawCallback`.

#### 5.1.2 Wiring the toolbar buttons Hyper ships

| Hyper control | Source | Becomes |
| --- | --- | --- |
| `btn btn-danger` "Add New Order" / "Add Products" / "Add Customers" | `orders.html:1254`, `products.html:1237`, `customers.html:1237` | The module's real create action. Orders have no staff-side create path (orders originate at checkout), so on the orders list this button becomes **"New store visit enquiry → order"**, linking to `admin/enquiries` — same `btn btn-danger mb-2 me-2` + `mdi mdi-basket me-1` markup. |
| `btn btn-success` cog | `products.html:1241`, `customers.html:1241` | **Column chooser** — a `dropdown-menu` of the module's `AdminReadService::TABLES` columns with `form-check` toggles, persisted per staff user in `localStorage`, applied with `adminTable.column(i).visible(…)`. No server state. |
| `btn btn-light` "Export" | `products.html:1242`, `orders.html:1255` | **Real CSV export.** New `GET /admin/export/{module}?q=&status=&ids=` on `Admin::export()`, grant = the module's `TABLES[1]`, reusing `AdminReadService::table()` with `length` raised to a hard cap of 5 000 and streamed with `fputcsv` exactly as `Admin::reportCsv()` (`:329`) already does. With `ids` present it exports the §5.1.1 selection; without, the current filter. Add `Content-Disposition: attachment`. |
| `btn btn-light` "Import" | `products.html:1243`, `customers.html:1243` | Blocked — see §12. Rendered with the identical classes plus `disabled` and `title="Validated CSV import is task OPT-02"`. |
| `status-select` | `orders.html:1239-1249` | The server-side status filter below. |

#### 5.1.3 The three action icons

Hyper's action cell is three `<a class="action-icon">` links with `mdi-eye`,
`mdi-square-edit-outline`, `mdi-delete` (`orders.html:1309-1311`). Copy all three. They differ only
in destination, and the panel already has every backing action:

| Icon | Action | Backing |
| --- | --- | --- |
| `mdi-eye` | open the record read-only | the existing `data-destination` link |
| `mdi-square-edit-outline` | open the edit form | `admin/product?id=`, `admin/coupon?id=`, `admin/order?id=`, `admin/{module}?edit=` for taxonomy |
| `mdi-delete` | **archive, not delete** | `POST /api/admin/products/{id}/archive` (§8.3) and `Admin::archiveCoupon()`. For `orders` it maps to the cancel transition, which `OrderStates::CANCELLABLE` already restricts, so render it `disabled` on a non-cancellable order. For `customers`, `inventory` and `enquiries` there is no delete in the domain model, so the icon is rendered `disabled` with a `title`. |

Nothing in this store is hard-deleted — `0008_order_snapshots.php` uses `ON DELETE RESTRICT`
throughout. So `mdi-delete` is an archive/cancel affordance, confirmed through the existing
`data-confirm` path (`admin.js:84`), and the confirm text says "archive" or "cancel", not "delete".

#### 5.1.4 Bulk actions bar

Hyper's list pages show no bulk bar, only the checkboxes. Rather than invent one, the selection
drives the buttons Hyper *does* ship: Export (selection), and `mdi-delete`-equivalent bulk archive
behind a single `POST /api/admin/{module}/bulk` that loops the per-record service call in one
transaction with one operation key per record, reporting `{applied, skipped:[{id,reason}]}`. Partial
success is reported honestly in the toast; it never claims all rows succeeded.

**Cell renderers in `admin.js`.** Today every column renders as escaped text
(`admin.js:33`). Add a per-module render map so the Hyper badges and action icons appear. Build
every node with `textContent` / `createElement`, never string concatenation of values:

```js
const badge = (text, tone) => {
  const h5 = document.createElement('h5'); h5.className = 'm-0';
  const span = document.createElement('span');
  span.className = 'badge badge-' + tone + '-lighten';
  span.textContent = text ?? '—';
  h5.appendChild(span); return h5.outerHTML;
};
const ORDER_TONE = { placed:'warning', awaiting_verify:'warning', confirmed:'info',
  processing:'info', shipped:'info', outForDelivery:'info', delivered:'success', cancelled:'danger' };
const PAYMENT_TONE = { paid:'success', advance_paid:'info', awaiting_payment:'warning',
  unpaid:'warning', simulated:'secondary', refunded:'danger', failed:'danger' };
const STATUS_TONE  = { active:'success', draft:'warning', archived:'danger' };
```

Per-module, keyed on `th.dataset.field`:

| Module | Field | Render |
| --- | --- | --- |
| orders | `order_number` | `<a class="text-body fw-bold" href="admin/order?id=…">` |
| orders | `status` | `badge(value, ORDER_TONE[value] ?? 'secondary')` |
| orders | `payment_status` | `badge(value, PAYMENT_TONE[value] ?? 'secondary')` |
| orders | `total` | `'₹' + value` right-aligned (value is already a `decimal()` string) |
| products | `name` | `<a class="text-body" href="admin/product?id=…">` |
| products | `status` | `badge(value, STATUS_TONE[value] ?? 'secondary')` |
| customers | `name` | `.table-user` cell: `.avatar-xs.rounded-circle` initial + `<a class="text-body fw-semibold">` |
| customers | `status` / `email_verify` | `badge(value==='1'?'Active':'Disabled', …)` |
| categories, brands, coupons | `enabled` | `badge(value==='1'?'Enabled':'Disabled', value==='1'?'success':'danger')` |
| inventory | `available` | `.progress-w-percent`: `.progress-value` = quantity, `.progress-bar` width = `min(100, available/on_hand*100)`, `bg-success` ≥ 25 % else `bg-danger` |
| all | `action` | existing `#` link, restyled as `<a class="action-icon"><i class="mdi mdi-eye"></i></a>` |

`inventory` needs `on_hand` in the same row to compute the bar — it already is
(`AdminReadService.php:40`).

### 5.2 S4 — order detail (`order.php`)

Target: `Hyper/apps-ecommerce-orders-details.html:1207-1408`. Four blocks, in Hyper's order.

**(a) `horizontal-steps` pipeline** (`:1227-1247`). Hyper hard-codes 4 steps; this store has
6 forward statuses in `OrderStates::NEXT` plus two terminals. Drive it from the constant:

```php
<?php
$pipeline=['placed','awaiting_verify','confirmed','processing','shipped','outForDelivery','delivered'];
$labels=['placed'=>'Placed','awaiting_verify'=>'Verifying','confirmed'=>'Confirmed','processing'=>'Processing',
         'shipped'=>'Shipped','outForDelivery'=>'Out for delivery','delivered'=>'Delivered'];
$reached=[];foreach($order['history'] as $entry)$reached[$entry['to_status']]=(int)$entry['created_at'];
$index=array_search($order['status'],$pipeline,true);
$progress=$order['status']==='cancelled'?100:($index===false?0:(int)round($index/(count($pipeline)-1)*100));
?>
<div class="row justify-content-center">
 <div class="col-lg-7 col-md-10 col-sm-11">
  <div class="horizontal-steps mt-4 mb-4 pb-5" id="tooltip-container" data-order-steps>
   <div class="horizontal-steps-content">
    <?php foreach($pipeline as $step): ?>
     <div class="step-item<?= $step===$order['status']?' current':'' ?>">
      <span<?= isset($reached[$step])?' data-bs-container="#tooltip-container" data-bs-toggle="tooltip" data-bs-placement="bottom" title="'.$e(gmdate('Y-m-d H:i',$reached[$step])).' UTC"':'' ?>><?= $e($labels[$step]) ?></span>
     </div>
    <?php endforeach; ?>
   </div>
   <div class="process-line" style="width: <?= $progress ?>%;"></div>
  </div>
 </div>
</div>
<?php if($order['status']==='cancelled'): ?>
<div class="alert alert-danger">This order was cancelled. Consumed stock was restored once.</div>
<?php endif; ?>
```

`process-line` must carry a real inline width — Hyper's own page does the same
(`:1244`, `style="width: 33%;"`). Tooltips need `app.min.js`, already loaded.

**(b) Items card** — `col-lg-8` → `.card` → `.header-title` "Items from order #…" → `.table-responsive` → `<table class="table mb-0">` with `<thead class="table-light">`. Columns `Material / SKU`, `Quantity`, `Unit price`, `Total`. Keep `$q($item['qty_milli'])` and `$m($item['line_total_minor'])`.

**(c) Order summary card** — `col-lg-4`, Hyper's two-column description/price table
(`:1296-1337`). Rows: subtotal, discount, shipping, GST, **Total** as `<th>`, then — only when
`amount_due_minor < total_minor` — "Advance collected online" and "Balance due on delivery" from
the existing `$due` / `$balance` computation (`order.php:2`). The "Record balance collected" form
stays, restyled `btn btn-primary`.

**(d) Three info cards** — Hyper's `Shipping Information` / `Billing Information` / `Delivery Info`
row (`:1342-1404`) maps cleanly:

| Hyper card | Content here |
| --- | --- |
| Shipping Information | `<address class="mb-0 font-14 address-lg">` from `$order['address']` (`recipient`, `line1`, `line2`, `city`, `state`, `postal_code`, `country_code`, `phone` via `<abbr title="Phone">P:</abbr>`) |
| Billing Information | `<ul class="list-unstyled mb-0">` with `payment_mode`, `payment_status`, `calculation_policy`, `tax_bps` as `%` via `AdminReadService::decimal($order['tax_bps'],2)` |
| Delivery Info | `mdi-truck-fast h2 text-muted` + order number + `payment_mode`. Hyper's "UPS Delivery" becomes the matched `delivery_zones.name` when `DeliveryService` resolves `address.postal_code`, otherwise "Not in a delivery zone". |

**Keep as extra cards below** (no Hyper equivalent, and they are the operational core):
the verification panel (`awaiting_verify`), the timeline (`<ol class="timeline">` → Hyper's
`.timeline` from `pages-timeline.html`, or keep the local `.timeline` rule), and the
"Update fulfillment" form with `OrderStates::targets()`. All three keep their `csrf`, `revision`
and `operation_key` hidden inputs verbatim.

### 5.3 S5 — product detail and form (`product.php`)

`product.php` serves both create and edit. Split the view:

**Read header (edit only), from `apps-ecommerce-products-details.html:1228-1302`.** One
`.card` → `.row`: `col-lg-5` gallery (hero `img-fluid` at `max-width:280px` + thumbnails
`img-thumbnail p-2` at `max-width:75px`), `col-lg-7` facts:

- `<h3 class="mt-0">` product name + `mdi-square-edit-outline` anchor to the form below.
- `Added Date:` → `gmdate('Y-m-d',$product['created_at'])`.
- Hyper's 5-star row (`:1253-1259`) keeps **all five `<span class="text-warning mdi …">`** in the
  same `<p class="font-16">`. Only each span's icon class is computed from the real
  `ReviewService` aggregate: `mdi-star` below the rating, `mdi-star-half-full` on the fractional
  one, `mdi-star-outline` above. With no approved reviews all five render `mdi-star-outline` and a
  `<small class="text-muted ms-1">No reviews yet</small>` is appended inside the same `<p>` — the
  element count and classes are unchanged, the fact asserted is true.
- `<h4><span class="badge badge-success-lighten">` → the real publication state computed at
  `product.php:3-9` (`$published`, `$visualizable`, `$enabledVariants`): `badge-success-lighten`
  "Published", `badge-warning-lighten` "Draft", `badge-danger-lighten` "Archived".
- `Retail Price:` → `$m(min(unit_price_minor of enabled variants))`.
- Hyper's quantity + `btn btn-danger` block (`:1273-1281`) is **kept exactly** — the
  `<div class="mt-4">`, `<h6 class="font-14">`, `<div class="d-flex">`, the
  `<input type="number" min="1" value="1" class="form-control" style="width: 90px;">` and the
  button with `mdi mdi-cart me-1`. Staff do not buy, so it is bound to the action a storekeeper
  actually wants from this spot: **add stock**. The `<h6>` reads `Add stock (<unit>)`, the button
  reads `Add stock`, and it posts to `POST /api/admin/inventory` with
  `{variant_id, delta_milli, reason:'Received stock', operation_key}` — the endpoint that already
  exists (`AdminApiController::inventoryAdjust()`), with `admin.inventory.adjust` enforced
  server-side. `min`/`step` follow the variant's `qty_increment_milli`. The button is `disabled`
  when the actor lacks the grant.
- The three existing storefront links from `product.php:10` ("Open shop ↗", "Open product ↗",
  "Preview in visualizer") move into the `.card-header` of this card as `btn btn-light btn-sm`, so
  nothing from the current panel is lost either.
- Hyper's three stats (`Available Stock` / `Number of Orders` / `Revenue`) → real values:
  `SUM(on_hand_milli - reserved_milli)` across the product's variants, and
  `orders_net` / revenue from `product_daily_metrics` for the last 30 days. This needs a new
  `AdminReadService::productStats(int $actorId,int $productId): array` (§6.2).

**Variant table, from `apps-ecommerce-products-details.html:1305-1390`.** Hyper's "Outlets"
table is copied verbatim — `class="table table-bordered table-centered mb-0"`,
`<thead class="table-light">`, four `<th>`, and the stock cell's exact
`.progress-w-percent` → `.progress-value` + `.progress.progress-sm` → `.progress-bar` nesting with
its inline `style="width: N%"` and `aria-valuenow`/`aria-valuemin`/`aria-valuemax`. Four columns
stay four columns; only the headers' text and the row loop change:

| Hyper header | Becomes |
| --- | --- |
| Outlets | SKU + finish (`<td>`, SKU bold, finish in a `<small class="text-muted d-block">`) |
| Price | `$m($variant['unit_price_minor'])` per `sell_unit` |
| Stock | the same progress widget: `.progress-value` = `$q(available_milli)`, bar width `min(100, available/on_hand*100)`, `bg-success` at ≥ 25 % else `bg-danger` — Hyper itself switches between `bg-success` and `bg-danger` on the same threshold idea (`:1322`, `:1336`) |
| Revenue | 30-day net revenue from `product_daily_metrics` |

Each row's SKU cell links to `admin/stock?id={variant_id}`.

**The form** comes from `Html/admin/apps-ecommerce-products-add.html:598-752`, which is already
Bootstrap and already uses the right field names. Port it as separate `.card`s —
*Material details*, *Product page information*, *Selling variants*, *Publication* — and keep from
the current `product.php`:

- the `<fieldset <?= $editable?'':'disabled' ?>>` wrapper,
- `$hidden(['csrf'=>…,'id'=>…,'revision'=>…,'operation_key'=>…])`,
- `<input type="hidden" name="form_complete" value="1">` (`admin_http_test.py:133` asserts a
  truncated form is rejected),
- `id="product-form"`, `id="variant-rows"`, `id="add-variant"`, `id="variant-count"`,
  `.variant-row`, `.remove-variant`, `.variant-number`, `.variant-unit` — **all of them**;
  `admin.js:46-70` and `admin_http_test.py:191-196` depend on each one.

Restyle `.variant-row` as `<div class="card variant-row"><div class="card-body">` with a
`.variant-heading` → `d-flex justify-content-between align-items-center` header carrying
`<span class="badge bg-light text-dark">Variant <span class="variant-number">1</span></span>` and a
`btn btn-sm btn-outline-danger remove-variant`.

The images panel keeps `.image-grid` (no Hyper equivalent) but gains `.card` chrome and a
`badge bg-light text-dark` for the `n/12 images` counter.

---

## 6. Slice S6 — analytics data layer

### 6.1 Migration `0034_order_geography.php` — make the map possible

Delivery state is only inside `orders.address_snapshot` (TEXT JSON). A `GROUP BY` state is
impossible, and decoding every order in PHP does not scale and cannot be indexed.

```php
<?php
/** ADM-UI-01 map support: denormalise the two geography fields the staff analytics choropleth and
    PIN report need out of the immutable `orders.address_snapshot` JSON. The snapshot stays the
    authority; these columns are a derived, indexed projection written in the same transaction as
    the order and never read by checkout, pricing or fulfilment. */
class OrderGeography extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        MigrationSchema::ensureColumn($this->pdo,'orders','ship_state',"VARCHAR(100) NOT NULL DEFAULT ''");
        MigrationSchema::ensureColumn($this->pdo,'orders','ship_postal_code',"VARCHAR(16) NOT NULL DEFAULT ''");
        MigrationSchema::ensureIndex($this->pdo,'orders','idx_order_state_time',['ship_state','created_at'],false);
        MigrationSchema::ensureIndex($this->pdo,'orders','idx_order_postal',['ship_postal_code'],false);
        $this->backfill();
        $this->verify();
    }
    /** Bounded, resumable backfill: only rows still at the default, 500 at a time. */
    private function backfill(): void { /* SELECT id,address_snapshot … WHERE ship_state='' LIMIT 500; decode; UPDATE by id; repeat */ }
    public function verify(): void { /* MigrationSchema::verifyDefinition(… 'ship_state'=>'varchar(100)','ship_postal_code'=>'varchar(16)' …) */ }
}
```

**Check `MigrationSchema` for an `ensureColumn` helper before writing this.** If it only has
`ensureIndex` / `verifyDefinition`, add `ensureColumn` there (MySQL `information_schema.COLUMNS`
vs SQLite `PRAGMA table_info`) rather than inlining dialect branches — AGENTS.md requires SQL
dialect differences be handled explicitly.

Write path: in `OrderService::place()`, inside the existing transaction that inserts the order, set
both columns from the same decoded address array that produces `address_snapshot`. One statement,
same transaction — `AGENTS.md` requires order totals and durable writes to be transactionally
consistent, and a split write would let the projection drift.

**Honesty note for the tracker:** `ship_state` is free text the customer typed. A choropleth over
it is approximate. The dashboard card must say so (§7.3).

### 6.2 `AdminReadService` additions

Three new methods, all `CommerceAccess::requirePermission('admin.analytics.view', $actorId)` first,
all bounded, all returning integer minor/milli plus `decimal()` strings — never floats.

```php
/** One payload for the whole analytics dashboard. $from/$to are UTC Y-m-d, range ≤ 93 days —
    reuse the exact validation in reports() rather than duplicating it. */
public function dashboard(int $actorId, string $from, string $to): array
```

Returns:

| Key | Source | Shape |
| --- | --- | --- |
| `range` | validated input | `{from,to,days}` |
| `tiles` | `orders`, `product_daily_metrics` | `{orders_total, orders_paid, revenue_minor, aov_minor, views, add_to_carts, conversion_bps, pending_verify, low_stock}` |
| `trend` | `orders` grouped by UTC day | `[{date, orders, revenue_minor}]`, one row per day in range (zero-filled in PHP) |
| `statuses` | reuse `reports()` query | `[{status, order_count, value_minor}]` |
| `payments` | `orders` grouped by `payment_status` | `[{payment_status, order_count, value_minor}]` |
| `states` | `orders` grouped by `ship_state` (needs §6.1) | `[{state, code, orders, revenue_minor}]`, top 15 + `other` |
| `topProducts` | reuse `reports()` query | `[{name, qty_milli, revenue_minor, order_count}]` |
| `funnel` | `product_daily_metrics` summed over range | `{views, add_to_carts, orders_net}` |
| `lowStock` | reuse `reports()` query | `[{name, sku, sell_unit, available_milli}]` |
| `lag` | `MetricsService::pending()` + `analytics_checkpoint.updated_at` | `{pending, updated_at}` |

`conversion_bps` = `intdiv(orders_net * 10000, max(1, views))` — basis points, integer division,
no float. `aov_minor` = `intdiv(revenue_minor, max(1, orders_paid))`.

**State → jvectormap code.** Verified against
`Html/admin/assets/vendor/admin-resources/jquery.vectormap/maps/jquery-jvectormap-in-mill-en.js`:
34 regions, `IN-AN … IN-WB`. The dataset is dated and this must be handled, not assumed:

- **No `IN-TG`** — Telangana does not exist in this map.
- `IN-UT` is named **"Uttaranchal"**, not Uttarakhand.
- `IN-OR` is named **"Orissa"**, not Odisha.
- Ladakh is inside `IN-JK`.

So add a PHP constant mapping normalised state names (lowercased, spaces/punctuation stripped) to
codes, including the aliases `odisha→IN-OR`, `uttarakhand→IN-UT`, `nctofdelhi→IN-DL`,
`pondicherry→IN-PY`, `andamannicobarislands→IN-AN`, `jammukashmir→IN-JK`, `ladakh→IN-JK`. Map
`telangana` to `null` and count it under an explicit **"Not on this map"** row — do **not** fold it
into Andhra Pradesh. Unrecognised names go to the same row. The dashboard shows that row's count
next to the map so a mis-typed state is visible rather than silently dropped.

```php
/** Hyper product-details header stats for one product; bounded to its own variants. */
public function productStats(int $actorId, int $productId): array
```
Returns `{available_milli, on_hand_milli, reserved_milli, orders_net_30d, units_net_milli_30d, revenue_minor_30d, views_30d}`.

```php
/** Serviceability report for the PIN table beside the map. */
public function pincodeReach(int $actorId, string $from, string $to): array
```
Returns `[{pincode, zone_name|null, orders, revenue_minor}]`, top 25 — joining
`orders.ship_postal_code` to `delivery_pincodes` / `delivery_zones`, so unserviced PIN codes that
nonetheless received orders become visible.

Two things to get right, because SQLite is the local store and MySQL is production:

- Day bucketing: `created_at` is a BIGINT epoch. Group in SQL with
  `(created_at - (created_at % 86400))` and format in PHP with `gmdate('Y-m-d', …)`. Do **not**
  use `DATE(FROM_UNIXTIME())` (MySQL-only) or `strftime` (SQLite-only).
- `SUM()` over an empty set is `NULL`: wrap every sum in `COALESCE(…,0)` and push it through
  `CommerceValues::integer()` exactly as `reports()` already does (`AdminReadService.php:105-109`).

---

## 7. Slice S7 — the analytics dashboard view

Target: `Hyper/dashboard-analytics.html:1211-1673` (identical to `Html/admin/index.html:577-1039`).
File: `Website/app/page/admin/reports.php`, rewritten; `Admin::reports()` switches from
`reports()` to `dashboard()` + `pincodeReach()` and keeps the CSV branch untouched.

Hyper's widget inventory, and what each becomes:

| Hyper element (id) | Hyper meaning | This store |
| --- | --- | --- |
| `#dash-daterange` + refresh button | daterangepicker, single date | **Range** picker (`singleDatePicker:false`, `maxSpan 93 days`), initialised from `$dashboard['range']`, submits `from`/`to` |
| `.card.tilebox-one` ×2 (`#active-users-count`, `#active-views-count`) | random numbers on a 2 s interval | Same cards, same ids, same `uil` float-end icons, same `<h2 class="my-2">`: **Orders today** and **Revenue today**, refreshed by the §8 poll — real values, no `Math.random()` |
| `.card.cta-box` | "Enhance your Campaign" marketing panel | Same `.card.cta-box.overflow-hidden` → `.card-body` → `.cta-box-title` + `mdi-arrow-right`: **orders awaiting action**, linking to `admin/orders?status=awaiting_verify` |
| `#sessions-overview` (area, `col-xl-9`) | sessions by day | **Orders & revenue by day** — ApexCharts mixed: `column` orders + `area` revenue on a second y-axis |
| **`#world-map-markers`** (jvectormap `world_mill_en`, 300 px) | sessions by country | Same div, same id, same `style="height: 300px"`. Map dataset comes from the `analytics_map` setting (§3), default `in_mill_en`: **orders per state** |
| `#country-chart` (horizontal bar, `col-lg-4` beside the map) | top 10 countries | **Top 10 states** by orders |
| `#views-min` (bar) + table | views per minute | **Product funnel**: views → add-to-cart → orders, plus `topProducts` in the `table-sm font-13` table |
| `#sessions-browser` (radar) | sessions by browser | **Orders by status** radar over `OrderStates` |
| `#sessions-os` (radialBar) | sessions by OS | **Orders by payment status** radialBar |
| "Channels" table | traffic channels | **Order status table** (`statuses`): status badge, count, value |
| "Social Media Traffic" table | social sources | **PIN code reach** (`pincodeReach`): PIN, zone or "Unzoned", orders, revenue |
| "Engagement Overview" table | engagement | **Low stock** (`lowStock`): material, SKU, available + unit, link to `admin/stock?id=` |

Keep every Hyper class and id exactly: `.tilebox-one`, `.cta-box`, `.card-h-100`,
`.d-flex.card-header.justify-content-between.align-items-center`, `.card-body.pt-0`,
`.header-title`, `.apex-charts`, `#world-map-markers`, `data-colors="…"`. `data-colors` is how
Hyper's own initialisers pick series colours (`demo.dashboard-analytics.js`), so the new
initialiser must read it the same way: `$('#sessions-overview').data('colors').split(',')`.

The map widget, matching `dashboard-analytics.html:1316-1340` structurally:

Copy `dashboard-analytics.html:1316-1340` and change only the heading text, the two
`data-*` attributes and the loop. Hyper's `card-drop` dropdown in the header stays and gains real
items (Today / 7 days / 30 days / This range), which re-request the dashboard JSON (§8.5):

```php
<div class="col-12">
 <div class="card">
  <div class="d-flex card-header justify-content-between align-items-center">
   <h4 class="header-title">Orders by state</h4>
   <div class="dropdown">
    <a href="#" class="dropdown-toggle arrow-none card-drop" data-bs-toggle="dropdown" aria-expanded="false">
     <i class="mdi mdi-dots-vertical"></i>
    </a>
    <div class="dropdown-menu dropdown-menu-end">
     <?php foreach([7=>'Last 7 days',30=>'Last 30 days',93=>'Last 93 days'] as $days=>$label): ?>
      <a href="javascript:void(0);" class="dropdown-item" data-range-days="<?= (int)$days ?>"><?= $e($label) ?></a>
     <?php endforeach; ?>
    </div>
   </div>
  </div>
  <div class="card-body pt-0">
   <div class="row">
    <div class="col-lg-8">
     <div id="world-map-markers" class="mt-3 mb-3" style="height: 300px"
          data-map="<?= $e($dashboard['map']) ?>"
          data-regions='<?= $e(json_encode($dashboard['states'], JSON_THROW_ON_ERROR)) ?>'></div>
    </div>
    <div class="col-lg-4" dir="ltr">
     <div id="country-chart" class="apex-charts" data-colors="#727cf5"></div>
    </div>
   </div>
  </div>
 </div>
</div>
```

`#world-map-markers` and `#country-chart` keep Hyper's ids. The unmapped-state count (§6.2) goes
into the `#country-chart` bar series as a final **"Not on this map"** category rather than a new
badge, so no element is added to the card.

Hyper's `card-drop` dropdowns appear on five of these cards (`:1310`, `:1345`, `:1409`, `:1431`,
`:1472`). Copy all five and give each real items — range on the map and trend cards, "Export CSV"
on the table cards (reusing §5.1.2's exporter). An empty dropdown is not acceptable, and neither is
removing it.

**New file `Website/assets/admin/admin-analytics.js`**, replacing Hyper's
`demo.dashboard-analytics.js`. Same structure, real data, no random numbers:

```js
(() => {
  'use strict';
  const el = document.getElementById('world-map-markers');   // Hyper's own id (§0, §12.4)
  if (!el || !window.jQuery || !jQuery.fn.vectorMap) return;
  const rows = JSON.parse(el.dataset.regions || '[]');
  const max = rows.reduce((m, r) => Math.max(m, Number(r.orders)), 0);
  const ramp = ['#e7e9fd', '#c3caff', '#9aa5fb', '#727cf5'];
  const values = {};
  rows.forEach((r) => {
    if (!r.code) return;                                  // unmapped states are reported, not drawn
    const step = max ? Math.min(3, Math.floor((Number(r.orders) / max) * 4)) : 0;
    values[r.code] = ramp[step];
  });
  jQuery(el).vectorMap({
    map: 'in_mill_en', normalizeFunction: 'polynomial',
    hoverOpacity: 0.7, hoverColor: false, backgroundColor: 'transparent', zoomOnScroll: false,
    regionStyle: { initial: { fill: 'rgba(93,106,120,0.2)' } },
    series: { regions: [{ values, attribute: 'fill' }] },
    onRegionTipShow: (e, tip, code) => {
      const row = rows.find((r) => r.code === code);
      tip.text(tip.text() + (row ? ' — ' + row.orders + ' orders' : ' — no orders'));
    },
  });
})();
```

Hyper's options (`normalizeFunction`, `hoverOpacity`, `regionStyle.initial.fill`,
`backgroundColor:'transparent'`, `zoomOnScroll:false`) are copied verbatim from
`demo.dashboard-analytics.js` so the map looks identical — only `map`, `values` and the tooltip
change.

The chart initialisers follow the same rule: copy each `ApexCharts` option object out of
`demo.dashboard-analytics.js` (height, `stroke.curve`, `fill.gradient` stops, `plotOptions.radar.polygons`,
`plotOptions.radialBar.dataLabels`, `grid.strokeDashArray`) and swap only `series`, `labels` /
`xaxis.categories` and the `yaxis.labels.formatter` (₹ and counts instead of `k` and `%`). Read the
series from a `<script type="application/json" id="dashboard-data">` block that
`reports.php` emits with `json_encode(..., JSON_THROW_ON_ERROR)` — not from inline JS, so the CSP
posture and escaping stay the same as the rest of the panel.

`window.Apex = {…}` global defaults: copy Hyper's block verbatim (`parentHeightOffset:0`,
`toolbar.show:false`, `grid.padding`, the four-colour palette).

---

## 8. Slice S8 — AJAX: live updates without a page reload

### 8.1 What "live" can mean here, honestly

`AGENTS.md` forbids a daemon and makes Redis non-authoritative. So: **no WebSocket, no SSE, no
long-poll.** Live means

1. **reads** refresh on an interval and on demand, and
2. **writes** happen over `fetch` and patch the DOM in place,

both as progressive enhancement over forms that still work with JavaScript off. That is the
pattern `storefront-ajax.js` already uses and that `AGENTS.md` describes for queue work.

### 8.2 Read endpoints to add

| Method + path | Handler | Grant | Returns |
| --- | --- | --- | --- |
| `GET /api/admin/dashboard?from=&to=` | `AdminApiController::dashboard()` | `admin.analytics.view` | `AdminReadService::dashboard()` + `pincodeReach()` |
| `GET /api/admin/overview` | `AdminApiController::overview()` | any `admin.*` | `overview()` + `attention()` |
| `GET /api/admin/orders/{id}` | `AdminApiController::order()` | `admin.orders.view` | `OrderService::staffDetail()` + `OrderStates::targets()` |
| `GET /api/admin/products/{id}` | `AdminApiController::product()` | `admin.products.view` | `ProductService::adminDetail()` + `productStats()` |
| `GET /api/admin/inventory/{variantId}` | `AdminApiController::stock()` | `admin.inventory.view` | `AdminReadService::stock()` |

Register in `Website/api/gateway.php` **after** the existing staff block. `api/admin/orders/{id}`
must not shadow `api/admin/orders/{id}/status` — put the longer, more specific pattern first and
add a `RoutingTest.php` case proving both resolve.

Every one of these reuses an existing read method. None adds a query.

### 8.3 Write endpoints to add

Three exist. These are the remaining admin mutations, each delegating to the service the HTML
controller already calls, with `RequestSecurity::requireMutation()`, an exact grant, `revision`
where the service takes one, and `operation_key` / `Idempotency-Key`:

| Method + path | Service call | Grant |
| --- | --- | --- |
| `POST /api/admin/orders/{id}/verify` | `OrderService::confirmByCall()` | `admin.orders.edit` |
| `POST /api/admin/orders/{id}/balance` | `OrderService::recordBalance()` | `admin.orders.edit` |
| `POST /api/admin/taxonomy/{module}` | `ProductService::saveTaxonomy` path used by `Admin::saveTaxonomy()` | `admin.products.edit` |
| `POST /api/admin/coupons` | `CouponService` save used by `Admin::saveCoupon()` | `admin.pricing.edit` |
| `POST /api/admin/reviews/{id}` | `ReviewService` moderation used by `Admin::reviews()` | `admin.products.edit` |
| `POST /api/admin/settings` | `SettingsService` save used by `Admin::saveSettings()` | `admin.settings.edit` |
| `POST /api/admin/delivery/zones` | `DeliveryService::saveZone` | `admin.settings.edit` |
| `POST /api/admin/staff/role` | `StaffAccessService` role write | `admin.access.edit` |
| `POST /api/admin/products/{id}/archive` | `ProductService` archive | `admin.products.edit` |

**Deliberately excluded from AJAX:** `POST /admin/product/image` (multipart upload + GD re-encode;
it stays a normal form post — `admin_http_test.py:221-228` covers the rejection paths and the
redirect) and `POST /admin/staff` (creates an account with a password; a full page response with
an explicit flash is the right behaviour).

Read the actual method signatures in `Admin.php` before writing each endpoint — several take
`(int $actorId, int $id, int $expectedRevision, string $key)` in a specific order, and
`confirmByCall()` additionally takes `(string $outcome, ?string $note)`.

### 8.4 `Website/assets/admin/admin-ajax.js` (new)

Modelled on `storefront-ajax.js`. One delegated capture-phase `submit` listener.

```js
(() => {
  'use strict';
  const base = document.body.dataset.adminBase || '/admin/';     // header.php:10, finally used
  const api  = base.replace(/\/admin\/$/, '/api/admin/');
  const csrf = () => document.querySelector('input[name=csrf]')?.value || '';
```

Rules, each one load-bearing:

1. **Opt-in only.** Act on `form[data-ajax]`. Every other form posts normally. Converting a form
   is then a one-attribute change per view, reviewable in isolation.
2. **Map, don't guess.** A `data-ajax="orders.verify"` attribute names the endpoint; a lookup table
   in this file turns it into method + path + the fields to send. No string-munging of
   `form.action`.
3. **Read the attribute, not the property.** `form.getAttribute('action')` — several admin forms
   contain `<input name="action">`, which shadows `form.action`. `storefront-ajax.js:68` documents
   exactly this trap.
4. **Send the real tokens.** `X-CSRF-Token: csrf()`, `X-Requested-With: XMLHttpRequest`,
   `Idempotency-Key` from the form's `operation_key`, and `revision` from its hidden input. The
   server re-validates all three; the client is never trusted.
5. **Fail safe.** Non-2xx with a JSON body → Hyper toast with `payload.error.message`, re-enable
   the submit button, and on `409` show "Reload this record before saving again" and offer a
   reload. Network failure → `form.submit()`, i.e. the native post. **Never** swallow an error and
   claim success.
6. **A 401 means the 1800 s staff elevation lapsed** (`Admin::elevated()`), not a bug. Toast
   "Your staff session expired" and redirect to `base + 'login'`.
7. **Patch, don't re-render.** On success, update only the nodes the response describes:
   `[data-field=status]`, `[data-field=revision]`, the order step bar's `.step-item.current` and
   `.process-line` width, the badge cell, the totals rows. Always refresh the hidden `revision` and
   mint a fresh `operation_key` (`crypto.randomUUID().replace(/-/g,'')`), or the next submit
   replays the previous one.
8. **Rebuild nodes, never interpolate.** `textContent` and `createElement` for every server value.

Toasts use Bootstrap's own component into the `#admin-toasts` container from §4.1:

```js
const toast = (message, tone) => {
  const host = document.getElementById('admin-toasts');
  if (!host) return;
  const el = document.createElement('div');
  el.className = 'toast align-items-center text-white bg-' + tone + ' border-0';
  el.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
  el.setAttribute('aria-live', tone === 'danger' ? 'assertive' : 'polite');
  const body = document.createElement('div');
  body.className = 'toast-body';
  body.textContent = message;                       // textContent, never innerHTML
  el.appendChild(body);
  host.appendChild(el);
  new bootstrap.Toast(el, { delay: 5000 }).show();
  el.addEventListener('hidden.bs.toast', () => el.remove());
};
```

### 8.5 Live refresh

- **Tables** already refresh without a reload — `admin.js:36` drives server-side DataTables. Add a
  "Refresh" `btn btn-light` calling `adminTable.ajax.reload(null, false)` (keeps the current page),
  and on the orders list only, an interval reload.
- **Dashboard and overview:** `setInterval` → `GET /api/admin/dashboard` → update tiles, chart
  series (`chart.updateSeries(...)`), map values (`$(el).vectorMap('set','values',…)`) and tables.
  Hyper's own dashboard already does interval updates (`demo.dashboard-analytics.js` uses `2e3`);
  **2 s is wrong for a database read**. Use **30 s**, pause when `document.hidden`, back off to
  60 s after a failure, and stop after 5 consecutive failures with a toast. State the interval in
  the UI ("Updated 14:32 UTC · refreshes every 30s") so a stale number is never mistaken for a
  live one.
- **Order detail:** no polling. Refresh after a successful mutation only.
- Respect `(prefers-reduced-motion: reduce)` for chart animations: `chart.updateOptions({chart:{animations:{enabled:false}}})`.

### 8.6 Documentation

Extend `Website/docs/commerce-api.md` with every new endpoint: method, path, grant, request body,
response shape, and the 401/403/409/422 meanings. Keep its existing table format.

---

## 9. Slice S9 — the remaining screens

Mechanical once §4.4's `$field()` / `$select()` / `$textarea()` helpers land. Per file: wrap each
`<section class="panel">` in `.card` → `.card-body`, `panel-heading` → a `d-flex
justify-content-between align-items-center` header with `.header-title`, `.pill` →
`badge bg-light text-dark`, `.button` → `btn btn-primary`, `.button.secondary` → `btn btn-light`,
`.button.danger` → `btn btn-outline-danger`, `.text-button` → `btn btn-link p-0`, `.form-grid` →
`.row` (helpers already emit `col-md-6`), bare `<table>` → `.table-responsive` +
`<table class="table table-centered mb-0"><thead class="table-light">`.

Sizes come from the referenced Hyper page, per §0.1 — never from the view being replaced, and
never chosen fresh. `.form-grid` becomes `.row`, and the `$field()` helper's default `col-md-6`
(§4.4) is overridden per field to whatever the Hyper source uses at that position (`col-md-4`,
`col-sm-5`, `col-12`), not left at the default.

| File | Hyper reference | Notes |
| --- | --- | --- |
| `overview.php` | `Hyper/widgets.html` | Already Hyper. Add `.card.cta-box.overflow-hidden` for "Needs attention" and keep `.widget-flat`. Replace `border border-danger` with `badge badge-danger-lighten` on the count. Columns: `col-xxl-3 col-sm-6` on both `.widget-flat` loops (§0.3, decided); the `col-md-6 col-xl-4` workspace grid is unchanged. |
| `stock.php` | `Hyper/widgets.html` tiles | `.metric-grid` → a `.row` of `.card.widget-flat` in `col-xxl-3 col-sm-6` (`widgets.html:1277`); adjustment form in a `.card`. Keep `name="quantity"` (`admin.js:79` validates non-zero). |
| `customer.php` | `apps-ecommerce-customers.html` | Lookup form + `.table-user` device table. `onsubmit="return confirm(...)"` → `data-confirm` so `admin.js:84` owns it. |
| `coupon.php` | `form-elements.html` | Pure helper conversion. |
| `reviews.php` | `apps-social-feed.html` cards | One `.card` per review, `mdi-star` rating, approve/reject as `btn btn-sm`. |
| `delivery.php` | `apps-ecommerce-orders.html` table | Zone table + zone form + PIN textarea. |
| `staff.php` | `apps-ecommerce-sellers.html` | Roster table with role `badge`, create form in a `.card`. Keep `autocomplete="off"`. |
| `settings.php` | `form-elements.html` switches | `<input type="checkbox">` → `.form-check.form-switch`. Keep names `cod_enabled`, `razorpay_enabled`, `delivery_strict`. |
| `login.php` | `Hyper/pages-login-2.html` | `.authentication-bg` + `.card` split layout. Then delete the `.login-*` block from `admin.css`. |

---

## 10. Execution order

Each slice is independently shippable and independently verifiable. Claim `ADM-UI-01` in
`Docs/commerce/tracking.md` before S1 and append evidence after each slice.

| Slice | Files owned | Depends on | Verify |
| --- | --- | --- | --- |
| **S1** assets | `Website/assets/admin/hyper/vendor/**`, `…/hyper/README.md` | — | asset probes return 200 + correct MIME |
| **S2** shell | `app/page/admin/header.php`, `footer.php`, `assets/admin/admin.js` | S1 | `admin_http_test.py` HTTP tier; breadcrumb/alert/toast markup present on all 11 screens |
| **S3** lists | `app/page/admin/table.php`, `assets/admin/admin.js`, `AdminReadService::table()`, `Admin::tablePage()`/`data()` | S2 | DataTables search/pagination checks still pass; new status-filter allowlist rejects junk with 422 |
| **S4** order detail | `app/page/admin/order.php` | S2 | order render + transition/verify/balance HTTP checks |
| **S5** product | `app/page/admin/product.php` | S2 | all `--browser` variant checks; 422/409 bodies still retain escaped input |
| **S6** analytics data | `database/migrations/0034_order_geography.php`, `core/MigrationSchema` (if `ensureColumn` is missing), `app/Services/OrderService.php`, `app/Services/AdminReadService.php` | — | new `AdminAnalyticsTest.php`; migration up/verify on disposable SQLite **and** MySQL; backfill idempotent |
| **S7** dashboard view | `app/page/admin/reports.php`, `assets/admin/admin-analytics.js`, `app/bridge/Admin.php` (`reports()`) | S1, S2, S6 | map + 6 charts render with zero console errors; CSV export byte-identical to today |
| **S8** AJAX | `app/bridge/AdminApiController.php`, `api/gateway.php`, `assets/admin/admin-ajax.js`, `app/page/admin/*.php` (`data-ajax` attributes), `Website/docs/commerce-api.md` | S3–S7 | `api_http_test.py` extensions; CSRF/grant/revision/replay per endpoint; JS-off fallback |
| **S9** remaining screens | the 9 views in §9 | S2 | render + mutation checks per screen |
| **S10** cleanup | `assets/admin/admin.css`, `tests/admin_http_test.py`, `Docs/commerce/tracking.md`, `tasks/README.md` | S1–S9 | full suite; `grep` proof that every deleted selector is unreferenced |

Plus one helper, built in S2 and used by every later slice:

| Slice | Files owned | Verify |
| --- | --- | --- |
| **S2a** structural parity harness | `Tools/compare_structure.py` | Run against an unmodified Hyper page and itself → passes; against a page with one `<div>` removed → fails |

`Tools/compare_structure.py` is the mechanical enforcement of §0: it is the gate each of S3–S9 must
pass before the slice is called done. Without it, "same to same" is an intention rather than a check.

S6 has no dependency on S1–S5 and can run in parallel with S2–S5 if the user authorises parallel
agents (`Docs/commerce/agents.md`); it touches disjoint files. S7 and S8 are strictly sequential
after it.

---

## 11. Verification

Run from `Website/`.

**Must pass before each slice is called done:**

```sh
php -l <each changed PHP file>
php tests/DeveloperAccessTest.php          # existing Developer regression (AGENTS.md)
php tests/RoutingTest.php                  # route shadowing, esp. after S8
python3 tests/admin_http_test.py           # HTTP tier
python3 tests/api_http_test.py             # after S8
python3 tests/storefront_http_test.py      # proves the storefront is untouched
git diff --check
```

**Structural parity, per converted screen (the §0 gate):**

```sh
python3 Tools/compare_structure.py \
  Hyper/apps-ecommerce-orders.html --region content-page \
  --against 'http://127.0.0.1:8000/admin/orders' --cookie-jar /tmp/staff.jar
```

It must report: every Hyper element present, in order, with **identical class sets** — including
every `col-*` breakpoint, `font-*`, `progress-sm`/`table-sm`/`btn-sm`/`avatar-*` and every spacing
utility — and **identical `style` and `height=` attributes**, except a percentage width that is the
datum (progress bars, `process-line`). `id` values identical except where §5.1.1 documents a
per-row suffix. The only other permitted differences are text nodes,
`href`/`action`/`value`/`src` values, the `disabled` attribute on the controls §12 names, and
repeated loop blocks.

Fail the run on any of these, which are the drift modes to expect:

- a `col-*` breakpoint added, dropped or changed (e.g. `col-lg-7 col-md-10 col-sm-11` → `col-lg-9`)
- a `<th style="width: …">` standardised across screens instead of per-screen (§0.2)
- an inline `height`/`max-width` dropped (`style="height: 300px"`, `style="max-width: 280px;"`)
- a spacing utility quietly normalised (`mt-4 mb-4 pb-5` → `my-4`)
- an ApexCharts `height:` changed from `demo.dashboard-analytics.js`
- a new dimension rule in `admin.css` targeting a Hyper class

Run it for all nine screens in §2.

**After S5 and S7, with a browser:**

```sh
python3 tests/admin_http_test.py --browser
```

**This run cannot pass as the suite stands** (§1.3). Fix it inside S2:

| Current assertion | Replace with |
| --- | --- |
| `b.wait("document.querySelector('.welcome-panel')!==null")` | `.page-title-box` or `.card.widget-flat` |
| `.drawer-toggle` click / `aria-expanded` | `.button-toggle-menu` click, then `document.documentElement.getAttribute('data-sidenav-size')` |
| `document.querySelector('.sidebar').inert` | `.leftside-menu` visibility, or drop — `app.min.js` owns it |

Add to the `--browser` tier:

- `/admin` at **1280 px** (between Hyper's `xxl` and the old `xl` breakpoint): the `.widget-flat`
  cards render **2 per row**, not 4 — proving §0.3 landed. At 1440 px they render 4 per row.
- `/admin/reports` at 360/390/768/1024/1440: no horizontal overflow, `#world-map-markers svg` exists,
  each of the 6 chart containers has a child `svg`, `b.errors` empty.
- Map region fill: a seeded West Bengal order colours `IN-WB` away from the initial
  `rgba(93,106,120,0.2)`.
- A state the map lacks (Telangana) raises the "not on this map" badge and does **not** colour a
  region.
- `data-ajax` order status change: status badge, step bar and hidden `revision` update with no
  navigation (`performance.getEntriesByType('navigation').length` unchanged).
- Same submit with `fetch` stubbed to reject: falls back to a real post and still succeeds.
- A stale `revision` returns 409 and toasts, leaving the record unchanged.

**New PHP test `tests/AdminAnalyticsTest.php`** (S6), on SQLite **and** `--mysql`:

- `dashboard()` requires `admin.analytics.view`; a viewer without it gets 403.
- Range > 93 days and malformed dates → 422 via `CommerceValues::invalid`.
- Zero-order range returns zeros, not `NULL`, and no division-by-zero in `aov_minor` /
  `conversion_bps`.
- Day bucketing identical on SQLite and MySQL for orders straddling a UTC midnight.
- `states` codes match the 34 verified `in_mill_en` ids; Telangana and a nonsense state both land
  in `unmapped` and the counts sum to the order total.
- Migration `0034` `up()` twice is a no-op; `verify()` passes on both drivers; the backfill
  reproduces `address_snapshot.state` exactly; a new order written by `OrderService::place()` has
  `ship_state` set in the same transaction (assert by rolling the transaction back and seeing
  neither the order nor the projection).

**MySQL is mandatory for S6.** `AGENTS.md`: do not infer concurrency or dialect correctness from
SQLite. Use a disposable database. If no MySQL listener is available in the session, record the
pending `--mysql` run as a blocker in the tracker exactly as `WEB-03`/`EVT-01`/`EVT-02` already do —
do not mark S6 done.

Record in the tracker: actual command output and counts, PHP/SQLite/MySQL/Chrome versions, and any
pending external check. Planned commands are not evidence.

---


## 12. Decisions, and the one blocked control

Taken, all of them consistent with §0 (copy the markup, bind the data):

1. **Every Hyper control is kept and wired** — checkbox column, select-all, cog/column chooser,
   Export, the three `action-icon` links, all five `card-drop` dropdowns, the quantity+button block
   on product details, the five-star row. §5.1.1–5.1.4, §5.3, §7.
2. **`mdi-delete` means archive or cancel**, because nothing in this schema is hard-deleted
   (`ON DELETE RESTRICT` throughout `0008_order_snapshots.php`). Where the domain has no archive
   (`customers`, `inventory`, `enquiries`) the icon renders `disabled` with a `title` — present,
   same classes, honestly inert.
3. **Both vector maps ship**; the dataset is a store setting (`analytics_map`, default
   `in_mill_en`). `#world-map-markers` keeps Hyper's id.
4. **Every Hyper id is preserved**, including `world-map-markers`, `country-chart`,
   `sessions-overview`, `views-min`, `sessions-browser`, `sessions-os`, `active-users-count`,
   `active-views-count`, `products-datatable`. Only the *series* change.
5. **DataTables stays at 2.3.4**, with Hyper's look reproduced through config. Downgrading to the
   1.x that `demo.customers.js` needs would break the server-side search/pagination proven by
   `admin_http_test.py:111-115`. This is a library version, not a UI change — the rendered table
   markup is Hyper's.
6. **Hyper's 2 s random-number interval becomes a 30 s real poll.** The cards, ids and layout are
   identical; only the number source and cadence change. A 2 s database round-trip per staff tab is
   not a design choice, it is a load problem.
7. **AJAX is opt-in per form** (`data-ajax`) with native-submit fallback, matching
   `storefront-ajax.js`.
8. **Image upload and staff creation stay non-AJAX** (multipart + GD; password handling).

### The one blocked control

**"Import" on the products and customers lists.** Rendered with Hyper's exact
`btn btn-light mb-2 me-1` markup, plus `disabled` and
`title="Validated CSV import is task OPT-02"`. It is blocked, not dropped:

- `OPT-02` ("Validated CSV import / parser decision") is **deferred** in
  [tracking.md](../tracking.md), depends on `ADM-02`, `INF-02`, `OPS-02`, and is explicitly an
  optional milestone requiring a parser decision.
- Wiring an importer here would mean accepting arbitrary spreadsheet rows into `ProductService` and
  `InventoryService` with no validated column contract — exactly what `OPT-02` exists to specify.

If you want Import live in this task instead, say so and it becomes slice S11: a bounded
`POST /api/admin/import/{module}` with a fixed column header, dry-run preview, per-row
accept/reject report and one operation key per row. That is a task of its own size, which is why
`OPT-02` is separate.

### Still worth your call (defaults stated; work is not blocked on an answer)

- **`orders.ship_state` denormalisation** (§6.1). Default: add it. The alternative — decoding
  `address_snapshot` per request — cannot be indexed. Fallback if a schema change is unwelcome:
  compute the map from the 1 000 most recent orders in range and label the card "sample".
- **Map granularity.** Default: state. This export has no India district map, and the store's own
  serviceability model is PIN-based, so PIN detail lives in the table beside the map.
- **Theme switcher.** Hyper ships `#theme-settings-offcanvas` (light/dark, sidebar colour/size) in
  every page's `<body>`. Under §0 it should be copied. Default: **copy it**, since `hyper-config.js`
  is already vendored and already reads/writes the `data-*` attributes it drives — it works with no
  server state. Say so if you would rather it stay out.

---

## 13. What the admin panel is missing

Separate question, separate answer. This is a gap list against the code as it stands on
`main` today, not part of ADM-UI-01's scope. Each row is verified against the repository.

### 13.1 Backed by working code, but with no screen at all

These have migrations, services, permissions and in some cases audit contracts — and no admin
route. The work is a view, not a feature.

| Missing screen | What already exists | Evidence |
| --- | --- | --- |
| **Staff activity / audit log** | `admin_activity_log` table with a unique operation key and an `(actor_user_id, created_at)` index; `StaffAudit::record()` writes **21 action types** (`product.save`, `order.transition`, `settings.save`, `access.assign`, `coupon.save`, `delivery.zone`, …) with an allowlisted field diff | `database/migrations/0004_commerce_audit.php:15-16`, `core/StaffAudit.php:8-28`. No route in `app/view.php`, no view in `app/page/admin/`. **Every staff action is being recorded and nobody can read it.** |
| **Pricing rules** | `pricing_rules` table, `PricingService::saveRule()` with `admin.pricing.edit`, demand/inventory/campaign/membership factors, `pricing.save` audit action | `database/migrations/0031_pricing_rules.php`, `app/Services/PricingService.php:105`. No route, no view. `PRC-01` is marked done but is unreachable from the panel. |
| **Pricing preview** | The grant `admin.pricing.view` ("View pricing previews") is seeded and assigned to roles — and **checked by no code anywhere in the repository** | `database/migrations/0003_commerce_identity.php:12`; `grep -rl admin.pricing.view app/ core/ api/` → no matches |
| **Refunds** | `payment_refunds` table, `RefundTest.php` | `database/migrations/0027_payment_refunds.php`. Nothing in `app/bridge/` or `app/page/admin/` references it. Refunds can be recorded by code but not by a person. |
| **Payment diagnostics** | `payment_diagnostics` table from the Razorpay work | `database/migrations/0019_payment_diagnostics.php`. No screen — a failed payment cannot be investigated from the panel. |
| **Low-stock alerts** | `low_stock_alerts` table + the `ORD-02` alert job | `database/migrations/0020_low_stock_alerts.php`. The reports page shows a *live* low-stock query, but the alert rows themselves (what fired, when, acknowledged?) are invisible. |
| **Queue / job health** | `jobs` + `job_failed` tables, `QueueWorker`, bounded retries and leases | `database/migrations/0009_durable_queue.php`. A stuck order-confirmation email or a dead `purchase_events` consumer is invisible to staff. `OPS-01` covers the runbook; the panel has no view. |
| **Notification delivery log** | `notification_deliveries` table | `database/migrations/0010_notification_delivery.php`. "Did the customer get the confirmation email?" is unanswerable in the UI. |
| **Recommendations** | `rec_suggestions` + `rec_pair_support`, `RecommendationService::rebuild()` with a published generation | `database/migrations/0033_recommendations.php`. No screen to see the live generation, trigger a rebuild, or inspect what is being recommended. |
| **Analytics beyond the 5-block report** | `product_daily_metrics`, `events`, `analytics_checkpoint`, `MetricsService::pending()` | `0029`/`0030`. Per-product views and add-to-carts are aggregated daily and **never displayed**. ADM-UI-01 §7 is the fix. |

### 13.2 Screens that exist but are thin

| Screen | Gap |
| --- | --- |
| `admin/customer` | Only a device list. No order history, no addresses, no lifetime value, no enable/disable, no password reset, no account-deletion handling — although `AccountDeletionService` exists. Looking up a customer requires knowing their **numeric ID** (`customer.php:4`); there is no search by name/email/phone despite `customers` being a searchable list module. |
| `admin/enquiries` | List only. A store-visit lead cannot be opened, assigned, annotated, marked contacted or converted. `store_visit_enquiries` has no status column. |
| `admin/product-reviews` | Moderates **pending** reviews only (`reviews.php`). No way to find, re-moderate or un-approve an already-published review. |
| `admin/reports` | Fixed 5 blocks, CSV only, no charts, no comparison period, no per-category or per-brand breakdown, no customer cohort. |
| `admin/orders` | No status filter (ADM-UI-01 §5.1 adds it), no date-range filter, no payment-status filter, no export. |
| `admin/order` | No invoice or packing slip. `Hyper/pages-invoice.html` is sitting right there unused, and orders carry an immutable snapshot that is exactly what an invoice needs. No partial fulfilment, no tracking number, no courier field, no internal notes. |
| `admin/inventory` | Shows current numbers. `inventory_movements` records the full audited history and there is **no screen to view a variant's movement log** — only the adjustment form. |
| `admin/settings` | No GST-per-HSN, no email/SMS template editing, no store address or business hours, no OTP channel test-send, no invoice numbering series. |
| `admin/staff` | Create + set role. No deactivate, no password reset, no "force sign-out", no per-session list, no last-login column. `StaffAccessService` has `createStaff()` and `setRole()` and nothing else. |

### 13.3 Absent entirely

| Missing | Why it matters here |
| --- | --- |
| **Staff profile / change own password** | There is no `admin/profile` route. A staff member cannot change their own password or name. `Html/admin/pages-profile.html` is already adapted and unused. |
| **Global search** | No cross-module search. Finding "that marble order from the Bankura customer" means guessing which list to open. |
| **Dashboard notifications** | Hyper's topbar has a notification dropdown; `header.php` renders only the storefront link and the user menu. `attention()` already computes the counts that belong there. |
| **Invoice / packing slip PDF** | §13.2. Needed for actual dispatch. |
| **Media library** | Images are only reachable through the product that owns them. `AdminImageMaintenanceTest.php` shows orphan reconciliation exists in CLI form, with no UI. |
| **Order editing** | An order cannot have a line adjusted, an item removed or a discount applied after placement — only a status transition. For a marble business where slabs get swapped at dispatch, that is a real operational hole. |
| **Backorder / purchase orders / supplier records** | `brands` holds supplier names only. No inbound stock workflow; `inventory.adjust` with a free-text reason is the only path. |
| **Tax reports (GSTR-ready)** | `tax_bps` and `tax_minor` are stored per order and per line. No period tax summary, no HSN grouping — which an Indian store will need for filing. |
| **Role/permission editor** | Roles are five fixed constants (`StaffAccessService::ROLES`) with permissions seeded by migration. Changing who can do what requires a migration. |
| **2FA for staff** | Customers have OTP (`CustomerOtp`, `0014`, `0024`). Staff sign-in is email+password plus a 1 800 s idle elevation. The panel can change prices and cancel orders. |
| **Soft-delete / restore** | Archive exists for products and coupons. Nothing else can be reversed from the UI. |

### 13.4 The three I would do first, and why

1. **Audit log viewer** — the data is already being written on every mutation, the index already
   exists, and it is the only way to answer "who changed this price". Smallest work, highest value;
   roughly one read method plus one list view that reuses `AdminReadService::TABLES` machinery.
2. **Invoice / packing slip** from `Hyper/pages-invoice.html` — the order snapshot is immutable and
   complete, the template is already in the repo, and dispatch cannot run without it.
3. **Customer detail screen** — lookup by numeric ID is the sharpest usability edge in the panel
   today, and order history per customer is one query against an existing index.

Each is a separate task with its own ID; none belongs inside ADM-UI-01, which is the theme port.
