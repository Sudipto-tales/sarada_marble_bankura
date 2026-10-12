# PHP website conversion and visualizer report

Verified locally on 2026-10-11. The website is running at **http://localhost:8000/** through `php vayu run --host=127.0.0.1 --port=8000`.

## Completed design conversion

All eleven standalone HTML pages now have PHP counterparts inside `Website/`. The original `Html/` files remain as references; the running PHP application uses its own views/assets.

| Original design | PHP URL | Working behavior |
| --- | --- | --- |
| `maa-sarada.html` | `/` | Hero, inspiration panels, real categories/materials, catalogue and planning shortcuts |
| `all-products.html` | `/shop`, `/category/{slug}` | Server search, brand/category/finish/price/stock filters, sort and pagination |
| `product-details.html` | `/product/{slug}` | Gallery, real variants, prices, selling units, availability, save/compare and cart/calculator links |
| `cart.html` | `/cart` | Owned guest/customer cart, quantity updates/removal and server subtotal |
| `wishlist.html` | `/wishlist` | Guest-session shortlist; signed-in wishlist persists privately in the database |
| `compare.html` | `/compare` | Up to four actual materials with their own variant prices/units/coverage |
| `calculator.html` | `/calculator` | Named surfaces, feet/metres/inches, cut-outs, wastage, optional labour estimates and actual variant quantity handoff |
| `catalogues.html` | `/catalogues` | Original KasaMood library, cover and download |
| `catalog-details.html` | `/catalogue/kasamood` | Real PDF reader, full-screen link, download and fallback |
| `visualizer.html` | `/visualizer` | Photo-based floor/wall texture editor, prepared masks, uploads, correction, saving and export |
| `saved-designs.html` | `/saved-designs` | Open/remove browser-local photo designs |

The shared responsive shell also covers account, registration, checkout, order history/details and errors. Address edit/archive uses existing ownership/revision checks. Order timelines use actual persisted history. Signup links now reach the existing verification service when delivery is configured. The admin panel now has its own responsive navy/teal shell, protected searchable DataTables, catalog/taxonomy/image forms, inventory adjustments and order fulfillment controls. See the [admin delivery and setup](admin-panel-design.md). Developer identity remains independent.

Prototype prices, stock, reviews, orders and browser carts were not imported as store data. The visualizer now loads published original product photographs from the admin catalog alongside explicitly labelled planning samples. Store materials link to their real product pages for price/availability. Flutter remains offline.

## Is the visualizer working?

**Yes: the PHP photo visualizer works locally.** Open **http://localhost:8000/visualizer**. It requires JavaScript and a WebGL-capable browser. No API key or Python installation is needed for prepared presets or manual photo editing.

Verified behavior:

- All eight room presets load. Luxury Living and Sunlit Living include prepared SAM 2 floor/wall selections; the other six start with manual selection guidance.
- Independent floor/wall materials, perspective corners, texture repeats/rotation, grout, shading, original toggle and erase/restore with undo work.
- A valid uploaded photo is normalized locally; applying a texture changes its preview. The upload handler sends no photo to PHP.
- PNG export and saving/restoring the exact preview work. Saved designs are separated by guest/account browser namespace, limited to 25, and can be removed. They do not sync between devices; clearing browser data removes them.
- Disabled WebGL and unavailable browser storage show useful errors. Use image export when storage is unavailable.
- A fresh SAM 2.1 tiny CPU run produced valid selections in **6.652 seconds inside the runner** on this machine. Its actual output successfully imported into the PHP editor. This single sample is not a production latency/capacity guarantee.

The editor is a perspective-correct **2D photo preview**. It does not measure rooms or reconstruct interactive 3D. Prepared masks still need review around furniture and shadows. The current PHP site does not automatically run SAM when a customer clicks include/exclude points.

## Use your own room photo now

1. Open `/visualizer`, then **Choose room photo** at the top. Choose JPEG/PNG/WebP under 12 MB.
2. Choose Floor or Feature wall and the Perspective corners tool. Position the four corners around the surface.
3. Reset selection to corners, erase furniture/fixtures from the selection, and restore any missing regions.
4. Apply a store material, labelled sample, or your own marble photo and adjust scale/rotation/shading. Use Show original to compare.
5. Save the photo design in this browser or Download image as PNG. Read `/visualizer/help` for the in-site guide.

For assisted AI selection, mark include/exclude points and download **source photo** and **selection prompts**. From the repository root, use the [local runner setup](../Services/visualizer/README.md), then run:

```sh
Services/visualizer/.venv/bin/python Services/visualizer/segment_image.py \
  --image /path/to/room-source.png \
  --prompts /path/to/room-prompts.json \
  --checkpoint Services/visualizer/models/sam2.1_hiera_tiny.pt \
  --model tiny --device cpu --threads 4 \
  --output /private/path/room-result.json
```

Choose a new output filename each time. Import `room-result.json` using **Prepared SAM selection**, then review/correct the mask and perspective. Keep environments/checkpoints/results outside the public website. On this development machine, `/tmp/sarada-sam2-env/bin/python` and `/tmp/sarada-sam2-tiny.pt` were used successfully; these temporary resources are not a durable deployment setup.

Use Python 3.11+ for this runner; its pinned environment uses PyTorch 2.5.1/TorchVision 0.20.1. Upstream SAM 2 requires Python 3.10 or newer. Trusted model downloads and upstream setup are documented in the [official SAM 2 repository](https://github.com/facebookresearch/sam2). No hosted AI credential is required for this local workflow.

## Verification evidence

| Executed check | Result |
| --- | --- |
| Complete PHP regression set | 15 suites passed on SQLite; 13 database suites passed on MariaDB 10.11.14/InnoDB |
| New tools/migration suite | 42 checks passed on each SQLite/MariaDB, including exact arithmetic, owner isolation, concurrent repeated saves, upgrade preservation and rerun |
| Real shopping/tools HTTP | 96 checks passed on each SQLite/MariaDB, including cart/checkout retries, wishlist/compare limits, address ownership/revisions, order isolation and staff guards |
| Registration UI HTTP | 13 passed against disposable SQLite/private test mail, including actual single-use verification and denial of signup role escalation |
| Existing routing/auth HTTP | 18 routing and 35 authentication checks passed |
| PHP visualizer browser suite | 25 passed: independent surfaces, furniture preservation, brush undo, exact save/restore, exports, SAM import/checksum and responsive preview |
| Additional browser verification | 84 checks passed: eight rooms, real runner output import, thirteen pages at five widths, calculator controls and no-JS fallbacks; two failure-path and four own-photo checks passed |
| Live localhost checks | Thirteen public pages and seventeen rendered asset URLs returned 200; four private paths returned 404; CSS assets resolved |
| Source verification | 37 related PHP files passed syntax checks; `git diff --check` passed |

Runtime: PHP 8.3.6, SQLite 3.45.1, disposable MariaDB 10.11.14, Python 3.12.3 and Chrome 154. Browser proof used a temporary profile and SwiftShader, not a physical-device/GPU benchmark. Temporary screenshots and generated proof files remain under `/tmp`.

The local development SQLite database was backed up to `/tmp/sarada-before-wishlist-20261011-151224.sqlite` before applying the wishlist migration. Production databases/accounts were not changed. No deployment, commit, push or Flutter networking was performed.

## What to improve next

1. **Publish real catalog content.** The current database has zero published products. Product/variant editing, safe image uploads, category/brand controls, stock adjustments and staff order controls now have their admin screens; provision a staff account and supply the actual store content. Supply approved product photos, units, prices and inventory. Upload a clear surface photograph in the product form to include the published material in the visualizer automatically.
2. **Configure real ordering and customer email delivery.** Current registration is disabled and there are no enabled payment modes. Define delivery/tax policies and enable COD only if the store accepts it. Configure SMTP plus short-lived queue workers before enabling registration/verification. Add password reset/resend UI. A payment gateway needs credentials, verified webhooks, idempotency and reconciliation before live collection.
3. **Automate AI only behind a private processing workflow.** Add owned private uploads, byte/pixel limits, quotas, durable bounded jobs, lease/revision fencing, cleanup/retention and authorized result downloads. Benchmark the processing machine before choosing worker concurrency. Avoid synchronous SAM execution in a checkout/catalog request. Keep manual editing as a fallback.
4. **Complete operational ecommerce features.** Customer cancellation/returns, staff fulfillment, order notifications/maintenance jobs, governed reviews/coupons, and private APIs remain tracked tasks. Tax invoices need actual business/tax information and durable numbering. The current document is correctly labelled an order summary.
5. **Optimize and prove the launch environment.** Keep editor code off shopping pages, fingerprint/cache static assets, use appropriately sized catalog images, and measure real-device LCP/CLS/interaction latency. Verify production PHP/MySQL/cron/private-storage capability, HTTPS, backups/restore and monitoring. If interactive 3D is desired, prove one licensed furnished GLB with separate surface meshes before expanding it.

The design conversion is complete within the original eleven-page prototype scope. The broader commerce/parity roadmap and the production release gates remain in the [tracker](commerce/tracking.md) and [parity plan](website-parity-integration-plan.md).

## Card and original-photo follow-up (PAR-UI-03)

The PHP storefront now uses larger cards, 18px product headings, 14px descriptive/action text and 20px prices. Long names and prices wrap instead of escaping the card. Product grids adapt to available width, with one larger card per row on phones. Home promotional rows grow with their content; planning cards and saved-design cards have larger type and spacing.

At `/visualizer`, **Choose room photo** is visible above the presets. Select a JPEG/PNG/WebP from your device. Under the material swatches, **Or use your original marble photo** accepts a stone photograph for the selected floor/wall. Both inputs use the existing 12 MB/24 megapixel limits and normalize locally to at most 1536px. The custom marble photo is included in browser-local saved designs and undo. It is a visual reference, without an automatic sellable-product association.

A progress overlay now appears while decoding/loading and rendering a material. It yields a browser frame before expensive work and clears after rendering, without a simulated processing timer. Export/save and conflicting surface edits are temporarily disabled. A later material selection supersedes an older pending request; failed downloads preserve the prior material and can be retried. Room-photo corners and furniture exclusions still require review; this change does not add automatic SAM inference.

### API availability audit

**The complete API set is not established.** Registered routes and live localhost responses were checked against `Website/api/gateway.php` and the real controllers:

| API | Current result |
| --- | --- |
| `GET /api/v1/products` | Implemented JSON listing; live response 200 with an empty catalog |
| `GET /api/v1/products/{slug}` | Implemented JSON detail; missing-product probe correctly returned 404 |
| `/api/developer/metrics` | Existing conditional Developer-console endpoint, separate from commerce; not a public commerce API |
| `/api/v1/cart`, `/api/v1/addresses`, `/api/v1/orders` | Unregistered; live probes returned 404. Their existing PHP pages/forms call services directly. |
| `/api/admin/products` | Unregistered; live probe returned 404; staff operations now use protected HTML forms and private `/admin/data/{slug}` table feeds, separate from the planned REST API |
| Visualizer upload/segmentation jobs | No server API established; `/api/visualizer/jobs` probe returned 404. Photo processing/saves are browser-local; SAM inference remains a separate local command. |
| Payment provider/webhooks and mobile token APIs | Not integrated; Flutter remains offline |

The remaining versioned commerce endpoints are specified in WEB-03; private visualizer jobs in SAM-03/04, payment providers in OPT-04 and mobile authentication/adapters in MOB-01. Existing PHP shopping behavior does not depend on these future APIs.

Follow-up verification: `Website/tests/storefront_layout_browser_test.py` passed **45 checks** on a disposable Chrome profile, including actual PHP card markup with long fields at 360/390/600/768/1024/1440px, real delayed-texture loading, rapid-selection races, failure/retry, native photo file selection, exact custom-photo save/restore and undo. The existing visualizer suite passed **25 checks** and two additional room-picker checks passed. The Developer regression passed **19 checks**; visualizer-help PHP syntax and `git diff --check` passed. Runtime: PHP 8.3.6, Python 3.12.3 and Chrome 154.0.8037.97 with SwiftShader; physical-device/GPU performance remains a separate check.


## Admin data integration (ADM-WEB-01, 2026-10-11)

Admin saves feed the real home/shop/category/detail/calculator, current prices, product galleries, stock availability and customer order timelines. The room visualizer now reads eligible published product photos from the same database, with product detail links and product-specific preview URLs. Admin product forms expose shop, published detail, room-preview and stock-management links. No separate API call is required between the PHP admin and PHP website.

Verification: 113 admin-to-storefront HTTP checks on MariaDB, 181 HTTP/browser checks on SQLite, 15 visibility/deep-selection checks on each database, 96 existing storefront HTTP checks, 25 photo-editor browser checks and 19 Developer checks passed. Setup and exact boundaries: [admin integration guide](admin-panel-design.md#admin-to-website-integration-adm-web-01).

The live local database still contains no categories/products/images/orders; add actual merchandise through admin to populate the website. Hero artwork, bundled PDF catalogues and brand/contact copy remain curated files rather than admin-managed content.
