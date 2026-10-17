# Demo data and API audit

Task `DEMO-01`, 2026-10-13. This slice checks API availability and supplies development catalog fixtures; WEB-03 remains unfinished.

Run from `Website/` with an already migrated development/test database and an existing active verified staff ID that has both catalog-edit and inventory-adjust grants:

```sh
php vayu demo:seed --actor-id=1
```

Use your actual staff ID. If needed, provision staff separately with `php vayu commerce:create EMAIL`; supply your own password at the hidden prompt. The seeder creates no accounts, passwords, roles, orders, notifications or payment settings. Production environments are refused. Migration is a separate operator action (`php vayu migrate`), never automatically performed by seeding.

The catalog fixture seeds three demo categories, one demo brand, eight products, thirteen variants and eight local sample textures. Variants cover sqft (including fractional increments), slabs and boxes, with multiple finishes, featured products, low-stock granite and unavailable onyx. Money uses INR paise and quantity uses thousandths of each sell unit. All names/slugs/SKUs clearly identify demo data; prices, stock and images are illustrative. View `/shop`, `/product/demo-makrana-white`, `/admin/products` and `/admin/inventory`.

Reruns skip existing product edits and use stock movement history to avoid replenishing stock. Missing image/initial-stock slices can resume after interruption. Each service slice commits independently; this command is not an all-or-nothing import. Run one seeder process at a time. A conflicting non-demo product slug stops processing. No reset/truncate operation is provided. Existing disabled taxonomy and archived products remain disabled/archived.

Image sources are the bundled `assets/storefront/assets/textures/marble/*.webp` references, copied to the existing product-image directory under deterministic generated names. They are sample textures, not approved commercial product photography. Existing asset provenance and publisher rights remain in [storefront provenance](../assets/storefront/README.md); no new remote assets or licensing claims are introduced. Normal image reconciliation applies to unreferenced copies after failed runs.

## API availability

Compared actual registration in `api/gateway.php` and `app/view.php` with the minimum WEB-03 table in [the task guide](../../Docs/commerce/tasks/08-storefront-api.md#web-03). HTML form routes call domain services but do not establish corresponding JSON API availability.

| Method/path | Implementation status |
| --- | --- |
| GET `/api/v1/products` | Implemented public catalog listing, filters/pagination |
| GET `/api/v1/products/{slug}` | Implemented public product detail |
| GET/POST `/api/v1/cart` | Missing registration/controller API actions |
| GET/POST `/api/v1/addresses` | Missing registration/controller API actions |
| GET/POST `/api/v1/orders` | Missing registration/controller API actions |
| GET `/api/v1/orders/{id}` | Missing registration/controller API action |
| GET/POST `/api/admin/products` | Missing registration/controller API actions |
| POST `/api/admin/inventory` | Missing registration/controller API action |
| POST `/api/admin/orders/{id}/status` | Missing registration/controller API action |
| `/api/developer/metrics` | Conditional Developer endpoint; dedicated access guard, separate from commerce |
| GET `/api/developer/knowledge` | Conditional Developer endpoint; dedicated access guard |
| GET `/api/developer/report` | Conditional Developer endpoint; dedicated access guard |

The commerce minimum has **2 of 13 method/path combinations registered**. Customer API auth/CSRF bootstrap, ownership/error/idempotency contracts and API-specific acceptance remain WEB-03 work. Native mobile tokens/integration remain deferred MOB-01. Visualizer job/upload APIs, real payments/webhooks and event/search/recommendation APIs also have no registration in this snapshot. Existing SQL catalog text filtering does not prove completion of planned search/ranking work.

## Verification

Evidence and local seed outcome are recorded after execution in the commerce tracker. Tests use disposable databases:

```sh
php tests/DemoSeederTest.php
php tests/DeveloperAccessTest.php
php tests/RoutingTest.php
python3 tests/storefront_http_test.py
```

`php tests/DemoSeederTest.php --mysql` uses the existing disposable MySQL test harness and `TEST_MYSQL_*` settings. SQLite evidence does not establish MySQL locking or concurrency behavior.

Executed on 2026-10-13 with PHP 8.3.6 / SQLite 3.45.1: seeder **14**, Developer access **19**, routing **50**, and storefront HTTP **96** checks passed. PHP syntax and whitespace checks passed. MySQL-specific execution remains pending; no local MySQL TCP test listener was available during this slice.

Local development seed with staff ID 1 created **8 products, 13 variants, 8 images and 11 opening-stock adjustments**, with three categories and one brand. Immediate rerun created/adjusted nothing. No identities or existing account credentials changed.

Live localhost:8000 probes: listing and seeded detail **200** (listing contains eight products; Makrana has two variants and one image); unknown detail **404**; POST products **405**. All eleven unregistered commerce method/path combinations above returned **404**. All three unauthenticated Developer API reads returned **401**, verifying that they are registered and protected, without claiming authenticated acceptance of the separate in-progress Developer lab. `/shop` and `/product/demo-makrana-white` returned **200**.
