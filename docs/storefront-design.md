# PHP storefront design

The core HTML ecommerce design is now rendered inside Vayu. Start with `php vayu run --host=127.0.0.1 --port=8000` from `Website/`, then open `http://localhost:8000/`.

| URL | Implemented presentation |
| --- | --- |
| `/` | Dark stone hero, category shortcuts, inspiration panels, database material cards and catalogue section |
| `/shop`, `/category/{slug}` | Filter form, search/sort, paginated material cards and empty states |
| `/product/{slug}` | Image gallery, description, actual variants/units/availability and CSRF-protected cart forms |
| `/cart` | Real owned cart, quantity/update/remove controls and server subtotal in the bag layout |
| `/catalogues` | Original KasaMood library and PDF download |
| `/catalogue/kasamood` | Document reader, full-screen link, download and PDF fallback |
| `/account`, `/checkout`, `/order/{id}` | Existing secure workflows with the shared storefront shell |

Views are in `app/page/storefront/`. The controller calls `CatalogReadService`, `ProductService` and `CartService` directly. Shared header/footer/card templates escape values. `assets/storefront/` contains self-contained styles, progressive JavaScript, font/branding and selected local inspiration assets; see its [provenance](../assets/storefront/README.md). `Html/` remains a reference, with no runtime dependency.

A fresh catalog correctly shows no published products. Populate actual categories/products/images/inventory through the authorized commerce workflow; prototype product prices, stock, reviews and browser carts are not imported as store data. Inspiration panels illustrate materials/rooms without identifying them as purchasable products.

JavaScript enhances the mobile menu and gallery. Server-rendered content, GET filters, cart forms, account/checkout flows and catalogue download remain usable without it. Existing checkout configuration and payment policy are unchanged.

PAR-UI-02 also completes `/calculator`, `/compare`, `/wishlist`, `/visualizer`, `/saved-designs` and `/visualizer/help`. Calculator quantities use fixed-precision server arithmetic and real variants; four-product comparison is browser-session bounded. Signed-in wishlist associations persist in the database while guest selections remain session-local. Photo designs stay in account/guest-separated browser namespaces, with a 25-design limit. The photo editor needs JavaScript/WebGL. ADM-WEB-01 connects published store product photographs alongside labelled planning samples, with product detail/deep-selection links; it does not automatically upload room photos or run SAM in PHP.

Account registration and `/auth/verify_email` use the existing guarded Auth service when verification delivery is configured. Registration remains default-disabled. Address edit/archive and `/orders` reuse existing ownership/revision services. Checkout/order presentation retains stable review keys and actual snapshots/history. Admin routes now provide a separate logo-colour responsive shell, server-side DataTables and protected catalog/taxonomy/image/stock/order forms. See `Docs/admin-panel-design.md` for setup, image capability and test evidence.

Full account/order parity, private APIs, provider setup and server AI/3D integration remain delivery slices in the [parity plan](../../Docs/website-parity-integration-plan.md). The [completion report](../../Docs/website-completion-report.md) lists all eleven converted pages, exact evidence, visualizer instructions and launch priorities.

Verification and exact outcomes are recorded under PAR-01 in the [tracker](../../Docs/commerce/tracking.md). Production database/hosting/deployment checks remain outside this local presentation change.
