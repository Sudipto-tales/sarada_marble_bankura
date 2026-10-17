# HTML design restoration — PAR-HTML-01

Authorized 2026-10-15. The reference is the current `Html/` implementation, especially `storefront.js`, `maa-sarada.html`, `home.css` and `storefront-pages.css`. This is a visual restoration within Vayu, not a replacement with the offline prototype.

## Delivery plan

1. Restore the homepage and shared shell: limestone/sage/clay palette, compact reference spacing, layered stone hero and manual carousel, photographic categories, four promotional panels, collection tiles, offers row, feature spotlight, dark selection strip, planning tools, app showcase, community/enquiry/statistics and shared SVG icons. Keep database product links, prices and units, CSRF and account workflows. Use real reference prices for discounts; show honest empty states. No invented bestseller ranks or customer quotes.
2. Restore companion presentation: photographic collection shortcuts, mobile filter disclosure, compact cards, banner metadata, shared planning cards, cart imagery/links, comparison and catalogue details. Preserve real server filtering and variant selection. Data-heavy colour/rating sorting needs its own validated query implementation; visual controls must not imply unsupported behavior.
3. Complete product-detail presentation around the existing ongoing PAR-PDP-02 work: show stored origin/colour/applications and published reviews; preserve its gallery, variant-aware purchase and cart revision controls. No replacement of another unfinished implementation.
4. Verify desktop/mobile screenshots against HTML at 390/768/1440 pixels, keyboard carousel/navigation/dialog behavior, overflow and actual asset loads; run existing disposable storefront HTTP and Developer regression checks. Record exact outcomes and outstanding work after each slice.

## Acceptance and content rules

All original sections should have corresponding PHP presentation. Real catalog content may differ from sample content. Empty catalogs remain browsable with truthful designed fallbacks. Promotional/reference prices must belong to the displayed selling variant. Bestseller claims require actual sales data, and published testimonials require approved reviews. Enquiry drafts stay in the browser and are explicitly not sent. No demo fixtures, credentials, migrations, provider integrations or publishing are authorized by this design task.

## Current slice

Homepage/shared shell slice implemented and locally verified. Companion presentation has started: compact two-column mobile product grids, photographic collection shortcuts, real result-count banner, mobile filter disclosure, shared planning cards and cart thumbnail/product links. Existing account/checkout forms and independent product-detail work are preserved.

Remaining: exact product-card information hierarchy and heart placement; data-backed colour/rating filters and sorts; detailed comparison rows; catalogue/cart finishing and product-detail integration with PAR-PDP-02; full empty/populated/long-content visual acceptance. This task remains `in_progress`, not whole-site parity complete. The dark homepage strip uses “Featured picks” because no authoritative bestseller ranking exists. No discounts or testimonials are fabricated; current local data has neither, so the original areas have designed empty states.

## Verification — 2026-10-15

- PHP 8.3.6 / SQLite 3.45.1: `python3 tests/storefront_http_test.py` from `Website/`: **99 passed**, including the cart thumbnail controller change. `php tests/CustomerCartTest.php`: **35 passed**. `php tests/DeveloperAccessTest.php`: **19 passed**.
- Changed/new runtime PHP views/controllers/services pass `php -l`; `git diff --check` passes. Native local server serves `/` and `/shop` with HTTP 200.
- Headless Chrome: homepage checked at 390/768/1440 pixels, all loaded images valid, no page overflow or runtime exceptions. Twelve carousel/dialog interaction assertions passed. Additional promotional content-bound checks at 360/390/768/1440 initially found clipped actions; corrected button sizing and all four widths passed.
- Ten companion routes at each of 390/768/1440 pixels: **30 no-overflow checks**, plus **3 mobile filter open/close assertions**, no JavaScript exceptions. Routes: shop, catalogues, reader, calculator, compare, wishlist, visualizer, saved designs, account, cart.
- Compared actual PHP and HTML mobile screenshots; visually inspected desktop hero and mobile shop. Temporary harnesses: `/tmp/check-html-design.py`, `/tmp/inspect-html-design.py`, `/tmp/check-html-companions.py`; screenshots `/tmp/sarada-html-home-{390,768,1440}.png`, `/tmp/sarada-html-reference-390.png`, `/tmp/sarada-html-shop-{390,768,1440}.png`. Browser dependency `websocket-client` extracted only into `/tmp/sarada-html-browser-deps` (system pip/venv are unavailable); no repository or system package change.
- Remaining external verification: physical devices and production hosting; no new MySQL/concurrency claim for this presentation slice. No migration, production data edit, deployment, commit/push or Flutter networking. Local preview: `http://127.0.0.1:8000/`.
