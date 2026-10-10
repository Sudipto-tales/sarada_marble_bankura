# Maa Sarada HTML design preview

Open `maa-sarada.html` directly, or serve this folder from the repository root:

```sh
python3 -m http.server 8080 --directory Html
```

Visit http://localhost:8080/maa-sarada.html. These are buildless HTML/CSS/JavaScript pages; no framework, install, CDN or API is needed. JavaScript renders each page using the shared local data. Enable JavaScript in your browser.

The home page follows the supplied storefront image: a dark marble hero, category shortcuts, promotional tiles, compact product rows, bestseller strip, app panel and customer stories. It uses Flutter's actual logo and Stone Atelier palette: limestone `#F6F3EC`, sage `#647158`, deep green `#49553F`, clay `#AD573C`, ink `#292D29`.

| Page | Design and interactions |
| --- | --- |
| `maa-sarada.html` | Reference-led shopping home, manual hero carousel, eight categories, promotional tiles, six-product offer row, featured materials, dark bestseller strip, planning tools, app panel and sample review |
| `all-products.html` | Sidebar filters, search, colour/finish/price filters, sorting and paginated material grid |
| `product-details.html?id=p_carrara_white` | Slab gallery, texture/tile/room views, image enlargement, specifications, app reviews, coverage quantity and related products |
| `catalogues.html` | Editorial library with featured cover, brand information and PDF download |
| `catalog-details.html?id=kajaria-kasamood` | Separate document-reader layout with edition details, PDF embed, full-screen link and download |
| `wishlist.html` | Browser-local saved materials |
| `compare.html` | Up to three materials compared by price, origin, finish, dimensions and applications |
| `cart.html` | Browser-local bag with square-foot quantities, subtotal and local enquiry draft |
| `calculator.html` | Feet/metres/inches, surface count, wastage, slab count, polishing, installation, GST estimate and add coverage to bag |
| `visualizer.html` | Seven app room images, stone texture selection, floor/wall overlay and saved combinations |
| `saved-designs.html` | Reopen or remove saved room/material combinations |

The visualizer is an illustrative 2D overlay, with approximate placement. It does not reproduce Flutter's 3D engine. Calculator defaults mirror `Mobile/lib/features/calculator/services/calculator_service.dart`: polishing ₹28/sq.ft, installation ₹65/net sq.ft and sample GST 18%. Whole-slab purchases can change the final quote.

This is an offline frontend design, using the app's bundled demo prices, stock and reviews. Bag, wishlist, comparison, room designs and enquiry drafts stay in browser storage. The enquiry form saves a draft locally; it does not send it. Accounts, checkout, payment and order fulfilment are not connected. Browser storage may be unavailable or cleared by browser settings, and `file://` persistence varies between browsers; use the local server for reliable page-to-page persistence.

## Data and asset provenance

- `storefront-data.js`: 20 products, eight categories and 28 review excerpts mirrored from `Mobile/lib/data/static/static_products.dart`, `static_categories.dart`, `static_reviews.dart`. Source values are preserved; category card counts show the actual matching product count.
- Brand logo, product galleries, category images, room previews, textures and fonts are copied from the existing local app assets. No newly generated stock/product images are used.
- The Kajaria Eternity KasaMood cover and original 49-page PDF are copied unchanged from `Mobile/assets/catalogs/kasamood/`, as listed by `BundledBrandCatalogRepository`. Brand document ownership and licensing remain with the original publisher. No new redistribution permission is implied.
- The shared base stylesheet preserves styling from the repository's original HTML reference. Inline SVG icons are the repository's existing original line drawings. No external template or remote dependency was added.
- Legacy reference assets remain in place, including existing Unsplash photos (`assets/interior.jpg`, `assets/bathroom.jpg`, `assets/kitchen.jpg`) under the [Unsplash license](https://unsplash.com/license). They are retained from the earlier design; the new pages use the Flutter assets.

All changes are confined to the HTML design and its documentation. PHP/Developer behavior and Flutter networking are unchanged.

See [HOME-DESIGN.md](HOME-DESIGN.md) for the section-by-section reference mapping, proposed live APIs and future page designs. Homepage-specific styles are in `home.css`. The ten companion pages now use `storefront-pages.css` for the same compact navigation, dark sage banners, clay actions, dense cards and dark footer, with layouts tailored to each task.


## Companion page styling

All ten existing companion pages share the updated home design language. The product collection adds category shortcuts and collapsible mobile filters; product details pair a large gallery with a purchase panel and separate specification/review cards. Catalogues use a dark cover presentation, document library cards and a dedicated reader workspace. The bag and calculator use dark estimate summaries; comparison uses a horizontal comparison table; wishlist and saved designs use compact cards and designed empty states. The room visualizer retains its room/material controls in a workspace layout. Planning shortcuts link the pages together above the shared footer.

Data and calculations are unchanged. The home-specific stylesheet remains separate, and the HTML preview still uses browser-local state with no new API or deployment.
