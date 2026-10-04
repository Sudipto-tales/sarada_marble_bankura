# Product cards and brand catalogs implementation plan

Status: the first bundled catalog implementation is complete using the supplied
`/home/luv/Downloads/KasaMood.pdf` (49 pages, Kajaria Eternity). Square product
cards, the Home section, library, exact-brand product action, and PDF page-turn
viewer are implemented. The viewer includes previous/next controls, page
selection, zoom, reduced-motion button navigation, and versioned reading position.
Static analysis passed; no tests or builds were run.

The upload/admin/API portions below remain future integration work. Current data
is intentionally bundled/offline. Existing demo products have Maa Sarada brands,
so they are not relabeled as Kajaria. Set `brandId: 'kajaria'` on actual Kajaria
products to expose their catalog action. The viewer uses `PdfDocument` and
`PdfPageView` with `TurnablePage`, since the package's PDF wrapper does not expose
zoom configuration.

## 1. Agreed experience

- Use the supplied reference for straight card edges, a prominent image, a quiet background, and compact spacing.
- Keep existing product names, prices, discounts, ratings, availability, wishlist behavior, and product navigation.
- Add **Brand catalogs** near the bottom of Home, immediately before the support card.
- Show uploaded, published catalogs there. Open the same full-screen viewer from a matching brand's product details page.
- Support forward and backward page turns through gestures and visible buttons, with page position and zoom.

## 2. Current project constraints

`Mobile` is a Flutter app with static repository implementations. Its current `CatalogScreen` is the shopping product list, not a document library. Keep that route and introduce separate brand-catalog routes.

`ProductCard` currently applies `AppDimens.stoneCurve` to its outer material, image frame, and image. Product rail and grid heights depend on formulas in `AppDimens`; changing only the radius or image proportions would leave spacing inconsistencies.

Products currently have a brand display string. There is no brand-catalog repository in the mobile repository contracts, and the inspected website commerce screens describe planned modules. A working upload-to-app pipeline must be implemented; it must not be represented as already connected.

## 3. Product and document card design

### Shared visual language

- Zero corner radius on card surfaces, image frames, and loading placeholders.
- Neutral light surface (theme-appropriate in dark mode), no thick decorative frame or large shadow.
- Start with 8 px between cards, 6–8 px internal padding, and 6 px between image and title. Retain 16 px screen gutters.
- Retain accessible control hit areas even though visual spacing becomes tighter.
- Scope these changes to product/document cards; do not change the global radius token used by unrelated screens.

### Product cards

- Preserve current image proportions initially so stone images remain useful; borrow the reference's framing and spacing.
- Keep existing name and price content and behavior. Tighten surrounding whitespace, without hiding useful metadata.
- Apply the shared component consistently to Home product rails, the shopping grid, wishlist, and discovery rails. Inspect search's separate list tile for square image corners too.
- Update card height calculations, grid gaps, compact variants, rail containers, and skeleton dimensions together. Account for low-stock text and enlarged system text.

### Brand catalog cards

- Use a separate `BrandCatalogCard` with a portrait cover area, approximately 2:3, and `BoxFit.contain` so logos and printed titles are not cropped.
- Show the uploaded cover artwork as supplied, including embedded branding. Do not recreate the reference's Kajaria logo or apply that brand to unrelated catalogs.
- Place the catalog title below the image, up to two lines, with a small brand label. No product price on a document card.
- The reference's angled accent may be used subtly behind the cover, without adding a large empty header area.
- Tap anywhere on the card to open that catalog.

## 4. Home and product navigation

### Home → Brand catalogs

- Compact horizontal cover rail near the bottom of Home, showing up to six published catalogs, with a **View all** action.
- View all opens a dedicated library with a two-column phone grid, additional columns at larger widths, and a brand filter.
- Sort by explicit display priority, then newest publication. Fetch remaining catalogs with pagination in the library.
- Give this section its own loading/retry state so catalog service errors do not prevent the rest of Home from loading.
- Hide the Home section when no published catalogs exist. A directly opened empty library explains that no catalogs are available.

### Product details → View brand catalog

- Add a secondary **View [Brand] catalog** action near the brand/product summary, before the quantity block.
- Match using a stable `brandId`, not substring search or display-name comparison.
- One published catalog: open its viewer directly.
- Multiple published catalogs: open the library prefiltered to that brand, with the brand visible in its heading.
- No published catalog: omit the action. A fetch failure gets a compact retry state rather than being treated as an empty result.
- Returning from the viewer restores the originating product screen or Home/library position.
- Brand catalog availability does not depend on the product being in stock.

## 5. Full-screen catalog viewer

- Header: close/back, catalog title, and brand.
- Content: uncropped pages on a neutral background, filling the available reading area.
- Footer: Previous, `Page 3 of 24` (or `Pages 4–5 of 24` for a spread), Next, and a page selector.
- Leftward page-turn gesture advances; rightward gesture returns. Keep explicit controls available for discoverability and accessibility.
- Disable Previous at the beginning and Next at the end. Serialize navigation while an animation is in progress.
- Single page on narrow screens; responsive two-page spread on sufficiently wide layouts. Preserve the logical reading position on rotation.
- Start with approximately 650–850 ms page turns. Respect reduced-motion preferences using immediate navigation where appropriate.
- Pinch/double-tap to zoom; while zoomed, gestures pan the page. Reset zoom before a button-driven page turn.
- Save the last completed page locally by catalog ID and content version. Reopening resumes reading; replacing the document resets or clamps the stored position.
- Show initial loading, unavailable document, offline-without-cache, and retry states. Cache viewed content with bounded storage and release viewer resources on close.

### Package integration

Use an internal viewer adapter around `turnable_page` so package details stay out of Home and product screens. Pin a compatible version during implementation and review its native dependencies against the app's actual Flutter toolchain and target platforms.

The publisher's currently documented API uses `PageFlipController`, `pageCount`, a builder with `(context, index, constraints)`, `nextPage()`, `previousPage()`, `animateToPage()`, and `currentPageIndex`. Animation duration is configured through `FlipSettings.flippingTime`. The pasted `TurnablePageController`, `itemCount`, `next()`, `previous()`, and `flipDuration` example should not be copied unchanged.

The package documents both `TurnablePdf` for PDF sources and `TurnablePage` for image/widget pages. Prefer the PDF viewer for original PDF uploads, with its documented loader initialization; use the page widget for ordered image catalogs. Confirm the PDF wrapper's exact controller/configuration surface when implementing the adapter. Avoid adding an extra zoom wrapper around built-in zoom.

Source: [turnable_page publisher documentation](https://pub.dev/packages/turnable_page).

## 6. Upload, publication, and shared data

Assumption: staff upload catalogs through the website/admin area; customers browse them in Mobile. This is planned scope, not an existing upload capability.

### Data model

- `Brand`: stable ID, display name, optional logo.
- `Product`: add `brandId`, retain brand display text for presentation, and explicitly map existing demo brands.
- `BrandCatalog`: ID, brand ID, title, cover source, source type, PDF source or ordered page manifest, page count, content version, publication state/date, display priority, and update timestamp.
- Optional category/collection metadata can refine the library later; brand linkage is sufficient for the requested experience.

### Staff workflow

1. Select brand and enter the catalog title.
2. Upload a PDF or an ordered image set. Allow a custom cover; default to the first page.
3. Validate file type/size and readability, obtain the page count, generate a cover thumbnail, and show processing progress or a useful failure reason.
4. Preview the cover and page ordering, then publish. Only ready, published documents appear in the customer API.
5. Support replacement, unpublishing, and display ordering. Publish replacements atomically after processing; increment the content version so caches refresh correctly.

### Proposed read API and repositories

- `GET /api/brand-catalogs` with optional `brand_id` and pagination; customer responses contain published entries only.
- `GET /api/brand-catalogs/{id}` returns source metadata needed by the viewer.
- Return cover thumbnails in list responses; do not download full documents to render a rail.
- Add a `BrandCatalogRepository` with list, brand-filtered list, and detail lookup methods; inject it through `AppDependencies`.
- A bundled repository can support offline development using real supplied catalog assets. The live repository and upload backend are required for actual uploaded catalogs to appear; do not populate the UI with invented branded documents.
- Refresh catalog metadata on Home refresh and library entry, using content versions for cache invalidation. Keep catalog loading independent from product/cart data.

## 7. Implementation order and file map

1. **Card styling:** update `features/catalog/widgets/product_card.dart`, relevant grid/rail spacing, and `core/theme/app_dimens.dart` sizing helpers and skeletons.
2. **Data foundation:** introduce brand/catalog models, brand IDs on products, repository contracts/implementations, dependency injection, and read route arguments.
3. **Upload connection:** add admin upload/processing/publication and customer read endpoints, then connect the mobile repository. Agree concrete upload limits with the deployment storage/processing configuration.
4. **Library UI:** add `features/brand_catalogs/` with catalog card, library screen, and shared loading/empty/error states.
5. **Viewer:** add a viewer adapter and screen, dependency/native setup, navigation state, zoom, and bounded caching.
6. **Entry points:** add the Home section in `home_screen.dart` and brand action in `product_details_screen.dart`; register separate library/viewer routes in `routes.dart` and `router.dart`.

## 8. Completion criteria

- Product and document cards have straight edges and compact spacing; existing product names/prices remain available.
- Published uploads appear in Home and the matching brand's product details flow from one data source.
- Multiple catalogs for one brand are selectable; unrelated brand catalogs never appear in that product's filtered list.
- Both directions work through gestures and controls, with correct first/last-page behavior and readable zoom interaction.
- Empty, failed, replaced, and unpublished documents have intentional behavior; catalog failures do not break shopping.
- This document is the planning deliverable. Implementation, package installation, builds, and tests are not performed as part of this planning request.
