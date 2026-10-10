# Home page design and future integration

The supplied storefront image is the layout reference. `maa-sarada.html` uses the existing Flutter branding and data, with homepage styling isolated in `home.css`. The ten companion pages now extend this visual language through `storefront-pages.css`, with task-specific product, catalogue, shopping and planning layouts.

| Reference section | Maa Sarada adaptation |
| --- | --- |
| Utility bar and shopping navigation | Existing Bankura location, search, catalogue and planning links, wishlist and bag |
| Dark hero with illuminated product display | Three manually controlled slides; layered existing marble textures, sage lighting and clay action button |
| Compact category shortcuts | Existing eight categories plus all-materials shortcut |
| Four promotional tiles | Existing Statuario and Honey Onyx products, room visualizer and calculator |
| Featured collections | Existing Italian, Indian, granite and premium collections |
| Flash deals row | Material offers using unchanged original/current app prices; discounts computed from those values |
| New arrivals with a larger lead card | Featured materials with existing featured/trending flags; no invented arrival dates |
| Dark ranked bestseller row | Existing bestseller flags, product images, prices and ratings |
| Service benefits | Existing product discovery, calculator, visualizer, wishlist and PDF tools |
| App promotion | HTML phone composition using existing logo, product and room assets; no fabricated app-store URL or QR code |
| Reviews, signup and statistics | Existing sample review, local project-enquiry draft, actual product/category/catalogue counts |
| Dark multi-column footer | Existing discover, planning and saved-collection destinations |

Product/category/review/catalogue records in `storefront-data.js` are unchanged. Existing assets are used without image edits. No invented shipping, payment, warranty or countdown claims were added. Every visible offer is an app sample price comparison, not a newly configured campaign.

## APIs needed later

No APIs are required for this HTML preview. These are proposed integration contracts, not implemented or verified runtime endpoints. Only product listing/detail routes are currently registered in `Website/api/gateway.php`.

| Data or action | Suggested endpoint | Purpose |
| --- | --- | --- |
| Product listing and detail | Existing registered `GET /api/v1/products`, `GET /api/v1/products/{slug}` | Reuse authoritative products, images, prices and stock; extend supported listing filters for featured/trending/bestseller selections if needed |
| Homepage sections | Optional `GET /api/v1/home` | Hero slides, promotional tile configuration, ordered product selections and app links; this can instead be server-rendered by Vayu |
| Categories | `GET /api/v1/categories` | Category names, images and actual published-product counts |
| Brand documents | `GET /api/v1/catalogues`, `GET /api/v1/catalogues/{id}` | Published cover, edition and safe original document URL |
| Customer stories | `GET /api/v1/products/{slug}/reviews` | Published reviews and review counts; replace bundled demo reviews |
| Project enquiries | `POST /api/v1/enquiries` | Validate and save a project request and enqueue bounded notification delivery; the current HTML only saves a local draft |
| Cross-device collection | Later authenticated cart/wishlist/saved-design endpoints | Replace browser-local persistence when accounts and that milestone are authorized |

The calculator can remain browser-local for an indicative estimate. Production checkout must recalculate prices, stock, taxes and totals on the server using the commerce service contracts. Cookie-authenticated mutations need CSRF protection and ownership checks. Flutter remains offline until its separately authorized integration milestone.

## Additional pages

The redesigned home page needs no additional pages to navigate: product list/detail, catalogue list/reader, wishlist, bag, comparison, calculator, room visualizer and saved designs already exist.

For a complete live store, future design candidates are sign-in/account, addresses, checkout, order confirmation/history/tracking, showroom/contact, about, delivery/returns/privacy/terms, and an app-download page once genuine release links exist. Those are future scope, not fabricated footer destinations in this preview.
