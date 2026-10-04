# Mobile ecommerce frontend audit and redesign roadmap

Reviewed: 2 October 2026.

Scope: source review of the Flutter app in `Mobile/`: routing, shopping screens, account/support flows, visualizer/calculator entry points, state, product models and repository contracts. This is not a pixel-level or device-tested audit. Flutter was not available on PATH; no emulator run, screenshots, performance measurements or test results are claimed. The website/backend was not audited for integration readiness.

## Recommended direction

Build a Maa Sarada branded ecommerce experience with familiar shopping patterns: prominent search, selectable delivery location, clear categories, informative product cards, predictable checkout and visible order progress. Keep the stone visualizer, samples and project calculator as differentiators.

Assumption: Maa Sarada sells its own catalog. Independent sellers, commissions and seller payouts belong to a separate marketplace expansion, if wanted. Amazon-style customer experience does not by itself require a multi-seller business.

The app already covers much of the shopping screen map. The largest gap is completion and consistency: static/local flows need real services, several actions are placeholders, and the purchase journey needs correctness fixes before a visual refresh is released.

## Existing capability and missing work

“Present” below means implemented in this offline prototype, not production-ready.

| Area | Present | Missing or incomplete |
| --- | --- | --- |
| Navigation | Home, Catalog, 3D Room, Cart and Account tabs; cart badge | Commerce-first navigation; working home location selector; consistent search access |
| Home | Banners, categories, featured/trending/bestseller rails, offers, inspiration, recent views, support | Personalized feed, accurate collection destinations, reactive recent views, content scheduling |
| Search | Debounced local search, recent/popular terms, results, route to filters | Live autocomplete, token/synonym/typo matching, relevant query ranking, paging; optional voice/image search later |
| Catalog | Product grid, comparison tray, category/price/colour/finish/origin/brand/rating/stock filters, sort | Category landing pages, subcategories, size/thickness/application filters, real newest date, paginated results |
| Product details | Gallery with zoom, price/discount, stock label, specifications, reviews, similar products, quantity, sticky purchase actions | Selectable SKU variants, actual stock limits, meaningful delivery quote, product sharing, Q&A, visible sample/quote actions, videos |
| Reviews | Read, sort and write text reviews | Purchase-backed verification, persistent review submission, photo/video upload, moderation/reporting, helpful votes |
| Wishlist | Add/remove and product grid | Named project lists, sharing, stock/price alerts, account sync |
| Comparison | Up to four products | Variant-aware comparison and useful mobile presentation validation |
| Cart | Quantity changes, removal, move to wishlist, coupon, totals | Separate save-for-later section, selected-item checkout, variant stock validation, price-change messages, synced carts |
| Checkout | Login gate, saved addresses, delivery option, summary | Exact Buy Now quantity, checkout-scoped discounts, actual delivery availability/date picker, final payable total throughout, business billing details |
| Payments | UPI/card/netbanking/EMI/COD choices | Gateway connection; pending, failure, cancellation and retry flows; confirmed payment status; eligibility-driven methods |
| Orders | List/detail, timeline, local cancellation, reorder | Live fulfillment, shipment details, order search, real invoice download, item-level actions, refund progress |
| Returns | Reason/notes form and replacement toggle | Persist replacement choice, item/quantity selection, evidence upload, eligibility, pickup and refund/replacement tracking |
| Account | Local sign-in/register/profile, addresses, dark mode | Real authentication, OTP/recovery, session expiry handling, language and notification settings, account deletion, policy pages |
| Support | FAQs, notifications list, sample/quote forms | Real submissions with references/history, support tickets/chat/callback, functional contact actions, push notifications |
| Room visualizer | Room/surface selection, texture changes, before/after, saved designs, cart handoff | Actual share/export, project sharing; room-photo upload or AR as later expansions |
| Calculator | Multiple surfaces, units, cutouts, wastage, slab estimate, polish/install options, cart handoff | Saved project estimates, downloadable quotations, consistent service pricing through cart/checkout |
| UI foundations | Shared colours/spacing/components, light/dark themes, loading/error/empty views, responsive grid breakpoints | Device validation, text scaling, icon labels, consistent contrast/touch targets, localization, real offline/reconnect states |

## Specific source findings to fix first

1. **Location looks selectable but is not interactive.** `lib/features/home/widgets/home_header.dart`: the delivery label and dropdown arrow are a plain row/column without a tap handler. Open a location/address sheet and apply the selection across the app.
2. **Buy Now includes an existing cart quantity.** `lib/features/product/product_details_screen.dart` adds quantity to the cart before navigating. `lib/features/checkout/checkout_screen.dart` then selects the entire matching cart line and does not use `buyNowSqFt`. Example: 100 sq.ft already in cart plus Buy Now for 50 produces checkout for 150. Use a separate checkout draft; preserve the existing cart.
3. **Buy Now discounts use the whole cart.** `lib/features/checkout/payment_screen.dart` uses `cart.couponDiscount` while computing subtotal only for checkout items. A discount earned by other cart items can be applied to this purchase. Recompute eligibility, discount, tax and totals against one checkout snapshot; validate again on the server.
4. **Stock is displayed but quantity is not capped to availability.** `lib/core/state/cart_controller.dart` accepts additions/updates without stock checks. The product page checks only whether any stock exists. Validate minimums, increments and available quantity for the selected variant, then reserve/revalidate server-side.
5. **Delivery estimates are artificial.** The product page gives faster/slower delivery based on whether the PIN code is even or odd. Checkout has fixed delivery choices and payment creates a fixed ETA. Use one serviceability/quote response, including unavailable areas and actual charges.
6. **Sample/quote submissions are acknowledgements only.** `lib/features/support/request_screens.dart` waits briefly, shows success and closes without saving or sending a request. Add submission APIs, failure recovery, reference numbers and request status screens.
7. **Return versus replacement is not stored.** `lib/features/orders/return_request_screen.dart` sends only order ID and reason; the toggle changes the toast message. Include requested resolution, affected items and quantities in the request model.
8. **Review verification is not purchase verification.** `lib/features/product/reviews_screen.dart` sets `verified` whenever a user exists. Derive verification from an eligible order on the server. `StaticReviewRepository` keeps new reviews in memory.
9. **Featured “See all” does not select featured products.** `home_screen.dart` passes only a title; `catalog_screen.dart` does not apply a featured filter. Add explicit collection IDs/filters and check every banner/collection destination.
10. **Recently viewed is a home-load snapshot.** Home fetches the list once and passes it to a rail; clearing or viewing another item does not itself reload that feed. Observe browsing changes or refresh the relevant section on return.
11. **Several visible actions are placeholders.** Invoice Download explicitly reports it is disabled, password recovery shows a message, support messaging is a toast, and visualizer sharing opens an internal preview. Connect each to its intended workflow.
12. **Async purchase failures need recovery.** Payment order placement lacks a try/catch/finally recovery path. Apply busy-state recovery and clear error handling to network mutations; use an idempotent server order operation to prevent duplicate purchases.
13. **Catalog search is a literal substring match.** `lib/data/models/filters.dart` does not tokenize queries or include `applications` directly; relevance sort uses product flags/rating. “Newest” sorts IDs instead of a release date. Replace these with actual searchable fields and ordering metadata.

These are source-level findings; reproduce purchase cases in tests as part of implementation.

## Proposed design

### Navigation and home

Suggested bottom tabs: **Home / Categories / Projects / Cart / Account**. Projects groups the existing visualizer, calculator, saved designs, samples and quotes. Put My Orders at the top of Account and add a compact active-order card on Home. This keeps specialist tools easy to find without crowding shopping.

Home order:

1. Compact brand header with notifications and wishlist.
2. Prominent search field and a working delivery-location strip.
3. Short category row: actual stocked categories, including sanitation only when supported by the catalog.
4. One principal campaign banner.
5. Relevant offers and recommended products.
6. Shop by room/use: flooring, kitchen, bathroom, stairs and commercial projects.
7. Project tools: visualize, calculate, request sample, request quote.
8. Recently viewed and active-order progress when applicable.
9. Additional inspiration and support lower down.

Use fewer competing horizontal rails above the fold. Keep Maa Sarada's teal identity, neutral surfaces and a restrained warm accent for purchase actions. Use actual slab/detail/installed-room photos with consistent crops and clear image labels. Generated imagery can remain illustrative, but actual product and lot photos should support a purchase decision.

### Product cards and browsing

- Consistent image ratio and card layout; readable two-line name.
- Price with explicit unit, discount when valid, rating with review count, availability/delivery summary.
- Wishlist action with accessible label; variant products open selection before adding.
- Category screen with subcategories, sticky filter/sort controls, removable applied filters and visible result count.
- Preserve scroll/filter state when returning from a product.
- Product-dependent filters: marble thickness/finish; tile dimensions/coverage; sanitation type/brand/finish.

### Product detail hierarchy

Gallery -> name/rating -> unit price and cost explanation -> variant selection -> quantity/coverage and estimated total -> delivery availability/charges -> primary purchase actions -> sample/quote/project tools -> specifications and care -> reviews/Q&A -> related products.

Keep sticky Add to Cart and Buy Now. Put minimum order, stock, delivery constraints and return eligibility near the buying controls. Do not hide essential costs in long descriptive sections.

### Cart, checkout and orders

- Cart: selected items, exact variants/units, editable quantity, stock warnings, save for later, applicable coupons, transparent total and sticky checkout action.
- Checkout: address -> delivery -> review/pay, with editable summaries and preserved input on failure. Show final payable total once address and delivery are known.
- Payments: separate pending, successful, failed and cancelled states; resume pending payment safely.
- Orders: item images, status and ETA, shipment events, invoice, reorder and item-specific help/return actions.
- Returns: select items/quantities, reason, photos, preferred resolution, confirmation/reference and status timeline.

### Design quality acceptance

- Test 320/360/390/430 logical-pixel phone widths, tablets, keyboard-open forms, light/dark mode and enlarged text. Fixed-height rails and fixed-width checkout buttons are risk areas, not confirmed visual failures.
- Provide accessible names for icon-only actions and useful screen-reader reading order.
- Aim for at least 48 x 48 dp Android touch targets, following [Google's guidance](https://support.google.com/accessibility/android/answer/7101858?hl=en-GB).
- Cover loading, empty, offline, expired session, image failure, unavailable product, failed request and retry states.
- Add Bengali/English first for the local market; extend to Hindi if the service area warrants it. Translate validation, dates/units and accessibility labels too.

## Feature backlog by priority

### P0: purchase correctness and functional foundations

- Fix the concrete issues above; make every visible action work or clearly unavailable.
- Real authentication, account persistence and recovery.
- Live products, variants, pricing, inventory, serviceability and checkout quote.
- Payment/order lifecycle, retries and duplicate-order protection.
- Real sample/quote submission and invoice retrieval.
- Shared checkout state so product/cart/payment totals agree.

### P1: complete customer experience and redesign

- New home/categories/product/cart/checkout/account/order layouts and shared components.
- Better search suggestions, filters and pagination.
- Variant selectors, multiple selling units and product sharing/deep links.
- Selected-item checkout, save for later and synchronized wishlist/cart.
- Actual scheduled delivery selection and business/site addresses.
- Live tracking, cancellations, item-level returns and refund status.
- Review photos, helpful voting and product questions.
- Notification preferences, order push notifications and support tickets.
- Localization, accessibility and device validation.

### P2: features especially useful for marble and sanitation

- Product units: sq.ft, slab, tile box and piece; coverage conversions and minimum order rules.
- Thickness, size, finish, shade and lot/batch selection with corresponding stock/prices/photos.
- Sample ordering/tracking; show sample availability on product details.
- Project quotation: attachments/drawings, room-wise quantities, quote history, accept quote -> checkout.
- Contractor/business profiles and approved bulk price tiers.
- Site measurement and installation booking, based on actual service coverage.
- Weight/site-access-aware delivery quotes, unloading responsibilities and lifting/stair charges.
- Store pickup, showroom appointments and available appointment slots.
- Named projects combining saved designs, materials, estimates and reusable lists.
- Downloadable project estimate; separately identified material, polishing, installation and delivery costs.

### P3: advanced shopping and retention

- Stock and price-drop alerts with user preferences.
- Personalized recommendations and complementary-product bundles.
- Shared project wishlists, referrals and loyalty rewards if business economics support them.
- Voice search and photo-based stone matching; consider only after catalog/search quality is strong. Amazon documents photo/barcode shopping in its [Lens overview](https://www.aboutamazon.in/news/retail/how-to-use-amazon-lens-for-faster-shopping).
- Room-photo visualization/AR and assisted material selection.
- Optional financing/EMI only when provided by an integrated eligible service.

### Separate expansion: multi-seller marketplace

Only if independent sellers are part of the intended business: seller onboarding, storefronts, competing offers, seller ratings, seller-specific inventory/delivery/returns, split orders, commissions, settlements and seller support. This changes data models and checkout substantially; decide before committing to marketplace implementation.

## Supporting services required

The mobile app currently wires static repositories. Production completion needs catalog/content administration, variant inventory, promotions, customer accounts, order/fulfillment operations, payments/refunds, invoice generation, request/support handling, notification delivery and media storage.

Audit the existing Website APIs before choosing reuse versus new services. Define API contracts for paging, variants, stock, checkout quotes, payment state, shipments and returns. Existing repository interfaces are useful starting points, but they need expansion; changing the dependency binding alone will not implement these missing workflows.

## Delivery sequence and completion gates

| Phase | Output | Completion gate |
| --- | --- | --- |
| 1. Define and repair | Confirm own-store scope, catalog units/variants and service area; repair purchase and navigation defects | Buy Now preserves old cart quantities; checkout discounts apply only to purchased items; requests/resolution choices persist |
| 2. Design | Shared design system plus phone layouts for main journey and exceptional states | Reviewable Home, Categories, Product, Cart, Checkout, Orders and Account designs at small/large text sizes |
| 3. Implement frontend | Updated shopping and project screens, complete state handling using explicit contracts | Every visible action has a working flow; loading/empty/error/offline states are reviewable |
| 4. Connect commerce | Account/catalog/stock/quote/payment/order/support integrations | Sandbox purchase -> fulfillment -> cancellation/return/refund journey; recover from interruptions without duplicate orders |
| 5. Validate release | Device/accessibility/performance checks and business content verification | Small-screen and screen-reader checks, payment resume, out-of-stock race, unavailable PIN, price change and relaunch persistence pass |
| 6. Grow | P2/P3 features based on actual usage | Track search success, product-to-cart conversion, checkout completion and support issues |

Do not treat an attractive screen or simulated success toast as completion of a customer workflow. Maintain separate frontend-complete and service-integrated statuses in the implementation backlog.
