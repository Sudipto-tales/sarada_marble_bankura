# Flutter / website product detail comparison

Task `PAR-PDP-01`, 2026-10-14. Compared the actual offline Flutter screen and widgets with the Vayu website before editing. This delivery updates website presentation and uses existing commerce data; it does not connect Flutter or add schema/provider integrations.

Sources: `Mobile/lib/features/product/product_details_screen.dart`, `widgets/product_gallery.dart`, `widgets/product_specs.dart`, `widgets/review_summary.dart`, `widgets/product_discovery.dart`, `Mobile/lib/data/models/product.dart`, `Mobile/lib/features/brand_catalogs/brand_catalog_widgets.dart`, and website `app/bridge/StorefrontProduct.php`, `app/page/storefront/product_detail.php`, `app/Services/ProductService.php`, `CartService.php`, `CatalogReadService.php`, `database/migrations/0005_catalog_schema.php`.

## Sections and behavior

| Flutter section / interaction | Website before | Website after / outstanding requirement |
| --- | --- | --- |
| Gallery, full-screen viewing, photograph navigation | Main image and small links opening raw image; no full-screen viewer | Larger contained image, selected thumbnail state, accessible modal with previous/next, arrow keys, Escape and focus return; original image links remain usable without JS |
| Product identity, brand, finish and stock badges | Brand/name/description/from-price; availability buried in variant forms | Brand/category navigation, featured and actual availability badges, larger title, finish summary, selected variant price with selling unit |
| Bestseller / trending | Missing | Still needs trusted merchandising flags/event aggregates. Existing `featured` is labeled Featured selection |
| Discount / original price / savings | Missing | Still needs approved original-price/promotion fields and pricing rules; no invented crossed-out price |
| Quantity stepper and live material estimate | Plain quantity input for every variant | Select a variant, use its actual increment and see an integer-rounded material estimate. All original server forms remain usable without JS |
| Calculator handoff | Existing variant-specific calculator link | Prominent variant-specific link retained. Existing calculator rounds and hands off to cart; it does not return a result to prefill this page like Flutter |
| Persistent Add to cart / Buy now | No persistent controls | Mobile bar submits the selected variant's existing CSRF/revision-protected cart form. View bag is available in the purchase panel. Scoped Buy now remains missing; redirecting the entire cart would also purchase unrelated lines |
| Brand-specific catalogue | Only global header link | Added nearby catalogue-library action. A specific product/brand-to-PDF mapping is still missing; no unrelated catalogue is presented as the product's own |
| Visualizer | Small button when an eligible image exists | Prominent room-photo preview card using the actual product ID and existing published images. This website renderer is a photo editor; Flutter's furnished 3D room mode remains separate work |
| Delivery checker and promises | Missing | Delivery/selection guidance added. PIN serviceability, delivery dates, damage/replacement, grading and installation promises require approved policy and service data. Flutter currently chooses its delivery date by PIN parity, a prototype rule |
| About this stone / applications | Description mixed into purchase column; no section | Separate readable About section using the stored description. Structured applications remain missing |
| Specifications | No specifications section; coverage only shown in forms | Per-variant table: category, brand, SKU, finish, thickness, length, width, selling unit, increment, coverage and authoritative available quantity, where supplied |
| Ratings, distribution, review cards, see-all/write-review | Missing | Dedicated section accurately says website reviews are unavailable. Trusted reviews, moderation, purchase verification, rating aggregates and write/list routes remain OPT-01/PAR-05 work |
| You might also like / Similar products | Missing | Up to four in-stock products from the same category, excluding the current item; bounded public cached listing. Personalized rail and ranking remain separate REC/EVT work |
| Better together / combo savings / add pair | Missing | Still needs curated pairing data, approved pricing and atomic two-variant cart mutation. Independent add requests would allow partial combos |
| Explore this stone photo rail | Missing separate rail | Existing product photos are all accessible in the enhanced gallery. Room/slab/detail photo classifications and approved additional images remain data gaps |
| Shop by brands | Missing | Added links using enabled database brands, limited to twelve; no fabricated brand photographs |
| Save / compare | Existing CSRF forms | Preserved existing ownership/session behavior and four-item comparison limit; larger actions remain visible near the price |

## Missing product data

The database currently supports name, description, tags, category, optional brand, active/featured state; variants support SKU, finish, thickness/length/width in milli-mm, unit, increment, coverage and price in INR paise. Inventory supplies available quantity after reservations. Images supply path, alt text, position and optional variant association.

The Flutter model additionally has **colour, origin, structured applications, original price, rating, review count, trending and bestseller flags**, plus a texture ID and richer gallery associations. These have no equivalent authoritative published commerce fields/services today. Do not derive them from demo product names or import Flutter review/discount/delivery claims as live facts. Admin-entered dimensions/coverage display only when present; empty optional fields are omitted.

Needed follow-up work: validated metadata migration/admin fields for colour/origin/applications; approved brand-catalogue and photo-role mappings; reviewed promotion/pricing rules; trusted review lifecycle; shipping/serviceability policies and integrations; scoped Buy now; atomic curated pair additions; separately verified analytics/recommendation features. These requirements remain open, rather than counting a visual placeholder as completed functionality.

## Implementation and verification

Changes are confined to the existing product controller/view and page-specific `assets/storefront/product-detail.css` / `product-detail.js`. Existing layout palette, local assets, cart authorization/CSRF/revisions and domain price/stock checks are preserved. JavaScript uses BigInt milli-unit multiplication and half-up division for display estimates; those values are never sent as trusted cart totals. Initial selection prefers a purchasable variant. Changing variants synchronizes price/unit, specifications and the sticky submit target. Out-of-stock controls are disabled.

Validation results are recorded after execution in the commerce tracker. Browser screenshots are local review artifacts under `/tmp`, not deployed assets. No production migration, publishing, account modification or mobile integration is part of this slice.

Executed checks: PHP 8.3.6 / SQLite 3.45.1, `python3 tests/storefront_http_test.py` **99 passed** (including actual dimensions/coverage and server-rendered section checks), `php tests/DeveloperAccessTest.php` **19 passed**, both modified PHP files passed `php -l`, and `git diff --check` passed. A real headless Chrome/CDP check verified six widths (360, 390, 700, 768, 1024, 1440), document-flow section navigation, variant/price/spec/submit synchronization, increment controls, integer half-up rounding (101 paise × 0.5 → 51 paise), bounded alternatives excluding the current product, out-of-stock controls, slab units/coverage, image dialog/Escape and no JavaScript exceptions. **34 browser assertions passed**. Screenshots: `/tmp/sarada-product-desktop.png`, `/tmp/sarada-product-mobile.png`, `/tmp/sarada-product-specifications.png`.

The browser check used the local demo catalog and did not submit a purchase/cart mutation. Behavioral cart/checkout/ownership regression ran against the existing disposable SQLite HTTP harness. No MySQL-specific execution was performed for this presentation slice; existing domain locking guarantees are unchanged and no new concurrency claim is made. Node is unavailable; Chrome parsed and executed the page module successfully.
