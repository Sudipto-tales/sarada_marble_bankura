# Responsive commerce admin panel

Authorized 2026-10-11. Sequential delivery of ADM-01, ADM-02 and the implemented ADM-03 controls; preserve Developer identity, storefront work and offline Flutter. No deployment or production account provisioning.

## Design and workflow

- Use the existing Maa Sarada logo, deep navy (#082c40), teal (#087e8b), cyan accents (#58cbd2), pale blue backgrounds and white content panels. Local Inter font. Apply the palette to the storefront adapters too.
- Desktop: 248px sidebar, compact top bar, responsive metric cards, full-width table/work area. Mobile: accessible drawer, single-column forms/cards, horizontally scrollable data tables with visible actions. No page-level horizontal overflow.
- Staff sign-in uses existing commerce authentication; no public staff registration or Developer account reuse. Navigation reflects exact grants; services independently enforce writes.
- Real overview counts only. Products, categories, brands, stock, orders and minimum customer lookup use DataTables with server-side bounded search/paging/sorting across the full dataset, plus basic HTML/search fallbacks.
- Product form: core details, publication, category/brand, up to 20 SKU/unit/finish/price/coverage/dimension rows, explicit archive controls and revision. Human INR/quantity fields convert through exact decimal arithmetic. Removing a variant deactivates it through existing ProductService behavior.
- Categories/brands: create/edit with existing uniqueness/tree validation. Product images: generated safe paths, server MIME/dimension/decoder checks, private staging, re-encode and compensating cleanup; unavailable decoder disables upload. Preserve licensing.
- Stock form: variant, signed quantity change, reason, expected stock revision, stable operation key. Orders: immutable detail/history and only valid next/cancel status choices, revision and stable operation key. Customers/settings: bounded minimum lookup/read-only effective implemented configuration; no arbitrary environment writes.
- All cookie mutations POST + CSRF + server permission. Field validation summaries/inline errors retain escaped input; stale conflict requires reload. Never silently turn malformed input into zero or lose a variant through truncated POST input.

## Dependencies and provenance

Use locally vendored DataTables 2.3.4 and jQuery 3.7.1 (MIT), not a premium editor/template. Search/paging follows the official [server-side protocol](https://datatables.net/manual/core/server-side); official [download/license information](https://datatables.net/download/). Asset sources/licenses and hashes live in `Website/assets/admin/vendor/README.md`.

## Verification gates

Use disposable SQLite and supported MySQL/MariaDB when available. Verify actual form saves and persisted values, exact decimal conversion, bad values/CSRF/grants, escaped retained values, duplicate SKU/slug, stale revision, foreign variant ownership, taxonomies, stock retry/idempotency/reserved-floor protection, status retries/history and customer field minimization. Browser checks prove real DataTables searching/paging, mobile drawer/keyboard behavior, form validation and no page overflow. Preserve Developer regression. Record actual results and any unrun environment checks below.

## Delivered behavior and setup

### Admin to website integration (ADM-WEB-01)

Admin and storefront use the same PDO database and commerce services. Committed product, price, image and inventory changes advance the durable catalog version; home/shop/category/detail/calculator read current data without manually clearing public cache. Order updates appear in the owning customer's order list and timeline; cancellation restores availability through OrderService.

To publish your first real material:

1. Open `/admin/categories` and create an enabled category. Create an enabled brand if needed.
2. Open `/admin/product`, enter the product details and a selling variant with its actual price, unit and increment. Select **Published** and save. Select **Featured material** to prioritize it on the home page.
3. Upload a clear, straight-on surface photograph from the saved product form. Use gallery position to choose the main photograph. A photo attached only to a disabled variant is excluded from the visualizer picker.
4. Use **Manage stock** for the variant and record actual received quantity. Zero stock is shown as unavailable; positive availability enables buying.
5. Use **View published material** or **Preview in a room** in the product form. Public product pages also link directly to the selected visualizer material.

The visualizer reads up to 50 published photographed products from committed SQL on each request, prioritizing featured/recent materials. A product deep link includes its selected material even when older than that default window. Store materials and planning samples are labelled separately. Selecting a store material loads the original safe uploaded image and links to the real product page. Room-photo processing, progress feedback and browser-local designs remain in place. Draft/archived products, disabled categories/brands and ineligible photos are excluded. Refresh an already open page to see admin changes; there is no push/live polling.

Hero artwork, the bundled KasaMood PDF library and brand/contact copy remain curated local content, without admin content-editing forms. Settings remain read-only. This integration adds no external payment/provider or mobile APIs. The 50-material picker could later gain bounded search/paging for a larger catalog; saved designs still depend on availability of referenced store photos.

Verification: PHP 8.3.6, SQLite 3.45.1 and MariaDB 10.11.14. `AdminStorefrontSyncTest.php` **15 passed on each database**, proving publication/taxonomy/variant-photo visibility, gallery ordering and deep selection beyond 50 products. `admin_http_test.py --mysql` **113 passed**; SQLite `admin_http_test.py --browser` **181 passed** including real admin edits propagating through warmed storefront pages, original photo loading in the visualizer and customer order status/history. Existing storefront HTTP **96 passed**, catalog **32 passed**, tools **42 passed**, Developer **19 passed** and photo-editor browser regression **25 passed**. Seven related PHP files pass syntax checks; `git diff --check` passes. Standalone Node syntax check was unavailable (`node` absent); actual Chrome executed the editor without runtime errors. Temporary test schemas/files/profiles were cleaned; the live catalog was not populated with test merchandise.

Implemented locally with full PHP view paths in `Website/app/page/admin/` and local `Website/assets/admin/` assets. The public storefront PHP adapters also use the navy/teal logo palette. Staff templates share their own shell; Developer pages/auth remain independent.

| URL | Behavior and authority |
| --- | --- |
| `/admin/login`, `/admin/logout` | Existing commerce Auth, CSRF and staff grants; no staff signup |
| `/admin` | Real, grant-filtered database counts and workspace links |
| `/admin/products`, `/admin/product` | Search/list, create/edit, exact decimal fields, up to 20 variants, revision guard |
| `/admin/product/archive` | Explicit archive preserving order history |
| `/admin/product/image` | Validated original raster upload/removal via ProductImageService and ProductService; generated paths, private staging and re-encoding |
| `/admin/categories`, `/admin/brands` | Search/list and guarded create/edit via existing catalog services |
| `/admin/inventory`, `/admin/stock` | Stock list and unit-valid signed adjustment; reason/revision/stable operation key; InventoryService |
| `/admin/orders`, `/admin/order` | Search/list, immutable snapshots/history, allowed transitions/cancellation and retry; OrderService |
| `/admin/customers` | Minimum name/email/active/verified projection; no tokens/passwords/addresses or staff records |
| `/admin/settings` | Read-only effective checkout/account/image capability; never edits environment files |
| `/admin/data/{slug}` | New protected, GET-only DataTables JSON projections with bounded search/sort/paging; exact per-module read grants |

Table search covers all records, including SKU search for products; paging is bounded to 100 rows per request. SQL ordering uses allowlisted columns and directions. The original HTML table/search remains available without JavaScript. Staff writes have independent service permissions, CSRF and audit; product, stock and order updates check revisions. Invalid product inputs preserve escaped field values and display a focused summary/inline errors. Incomplete variant forms are rejected instead of silently disabling omitted records. Category hierarchy, uniqueness, reserved-stock floors and state transitions retain their domain validation.

Open **http://localhost:8000/admin/login**. The current local database contains no provisioned staff grants. Create your own account, supplying your own password at the prompt:

```bash
cd Website
php vayu commerce:create your@email.com admin
```

Allowed operator roles also include `catalog_manager`, `operations` and `viewer`. No production or local usable staff password was seeded by this work.

### Image capability and maintenance

The system PHP 8.3 installation has no GD extension. A matching Ubuntu PHP 8.3.6 GD package was downloaded/verified through apt, tested and copied only to ignored private `var/php-extensions/gd.so`, with its local INI under `var/php-conf.d/`. The local Vayu server was restarted with this environment and is running on port 8000:

```bash
cd Website
PHP_INI_SCAN_DIR=/etc/php/8.3/cli/conf.d:/home/luv/Projects/sarada_marble_bankura/var/php-conf.d php vayu run --host=127.0.0.1 --port=8000
```

This is an Ubuntu/PHP-8.3 local convenience, not a production PHP installation. After a PHP ABI/version upgrade, install the matching GD package instead of reusing this binary. A normal plain-PHP launch without GD shows an honest unavailable upload state. Production should install/enable its supported PHP GD package. No system package was installed and no compiled module/config was committed.

Uploads accept server-verified JPEG/PNG/WebP under 4 MB, at most 6000px per side and 12 megapixels; decoded pixels are re-encoded as PNG, removing embedded source metadata/code. Original names never determine paths. Up to 12 images per product; variant ownership is checked. Files written before a rejected metadata save are removed. Removal occurs after committed metadata deletion and reference checking. Apache execution-denial rules are in `assets/products/.htaccess`; deployment must also configure the corresponding nonexecuting image location in Nginx and verify the actual host. The Vayu development router's execution denial was verified.

`php vayu catalog:images:clean` defaults to a dry-run. An operator-authorized cron invocation with `--delete` removes only generated, unreferenced public files and abandoned private staging files older than 24 hours. Passes scan at most 500 entries and check a five-second budget between entries, serialize through a private lock and maintain a public scan cursor. Referenced/recent/non-generated files are preserved. The deletion algorithm was tested against a copied implementation in a disposable public root; no live orphan cleanup was run.

## Executed verification

Runtime: PHP 8.3.6, SQLite 3.45.1, MariaDB 10.11.14/InnoDB, Python 3.12.3 and Chrome 154.0.8037.97. Tests used disposable users/databases/Chrome profiles; sample records in screenshots are test fixtures, not live store data.

- Admin HTTP + browser suite with GD: **144 checks passed on SQLite**, including the 11 admin screens at 360/390/768/1024/1440px, actual DataTables search beyond the first page/pagination, drawer/Escape, variant controls and browser required-field checks.
- Admin HTTP suite without GD: **73 checks passed**, proving unavailable-decoder behavior as well as core workflows.
- Final admin HTTP with GD: **83 checks passed on each SQLite/MariaDB**, including three additional root-relative asset/form/navigation checks rendered under `/stone/`. Live subdirectory-server verification remains a hosting check. Includes actual create/edit/archive, exact decimal persistence, malformed input, duplicate SKU/category, self-parent category, foreign variant, view-only/customer/operations permissions, CSRF, stale revisions, retry-safe stock and order changes, cancellation, customer field minimization, safe images, cleanup on metadata failure and upload-directory execution denial.
- Isolated orphan/staging maintenance: **9 checks passed on each SQLite/MariaDB**.
- Existing catalog, inventory-race and atomic-checkout suites: **32 / 34 / 52 passed on each SQLite/MariaDB**.
- Existing storefront HTTP: **96 passed on SQLite**. Developer regression **19 passed**; routing regression **50 passed**. Related PHP syntax, Python test parsing and `git diff --check` passed.
- Live root, shop, visualizer, admin login, admin CSS/JS/DataTables/jQuery/font paths returned 200.

Physical-device performance and the actual production Apache/Nginx execution policy remain hosting/release checks. Settings mutations, gateway integration, native/mobile tokens and the full versioned commerce API are not introduced by these admin forms. The new private table feeds do not mean all `/api/v1` endpoints are complete. No deployment, commit, push, production migration or external account change occurred.
