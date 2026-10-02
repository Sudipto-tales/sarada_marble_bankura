# Maa Sarada — Marble & Sanitation
## Premium Marble E-Commerce Mobile App (Flutter)

> Static-data prototype. No backend, no API, no payment gateway, no cloud DB.
> Architecture is API-ready: swap repositories, UI untouched.

---

## 0. Brand

Derived from the client logo (`MS` emblem, "MAA SARADA — MARBLE & SANITATION").

### 0.1 Palette

| Token | Hex | Use |
|---|---|---|
| `brandIce` | `#D0F0F8` | highlights, chips, pale surfaces |
| `brandCyan` | `#48D0D8` | gradient top, glow, active state |
| `brandTeal` | `#2BB8CC` | **primary seed** — buttons, accents |
| `brandDeep` | `#0E6C86` | gradient bottom, pressed state |
| `brandInk` | `#061826` | headers, dark surfaces, text |
| `brandInkSoft` | `#0E2534` | dark elevated surface |
| `neutralWall` | `#707070` | logo backdrop grey, dividers |
| `surface` | `#F6F9FB` | light app background |
| `gold` | `#C9A24B` | premium accent (ratings, badges) |

### 0.2 Brand Gradient
`LinearGradient(#7FE7F2 → #2BB8CC → #0E6C86)` — used on hero, CTA, 3D entry, splash.

### 0.3 App Icon
Generated from the logo PNG: emblem isolated, centered on brand-ink rounded canvas,
exported to all Android mipmap densities + iOS AppIcon set + web + adaptive icon.

### 0.4 Typography
- Display / headings: serif-ish premium feel via `letterSpacing` + weight 300/600 contrast
- Body: system sans, generous line height (1.4)
- Numerals: tabular for prices

---

## 1. Module Map (hard boundaries)

```
                 ┌──────────────────────────┐
                 │      core/ (theme,       │
                 │  routes, widgets, utils) │
                 └────────────┬─────────────┘
                              │
   ┌──────────────┬───────────┴───────────┬──────────────────┐
   │              │                       │                  │
E-COMMERCE     USER                 VISUALIZATION        CALCULATOR
 catalog       auth                  3D room              dimensions
 search        profile               camera               area
 cart          address               textures             wastage
 wishlist                            hotspots             quantity
 checkout                            customizer           cost
 orders
 reviews
   │              │                       │                  │
   └──────────────┴───────────┬───────────┴──────────────────┘
                              │
                    data/ repositories (interfaces)
                              │
                    static_data/ (local JSON/Dart)
```

### 1.1 Coupling rules (enforced)
1. `features/visualization/**` must NOT import `features/cart/**`, `features/checkout/**`, `features/orders/**`.
2. `features/calculator/**` must NOT import `features/visualization/**`.
3. Both sub-modules emit a **result object** only:
   - `VisualizationResult { productId, surfaceId, estimatedSqFt }`
   - `CalculationResult { productId, requiredSqFt, estimatedCost }`
4. A thin `core/bridge/module_bridge.dart` translates results → cart intents.
   Deleting `visualization/` or `calculator/` breaks only the bridge call sites (guarded by feature flags in `core/constants/feature_flags.dart`).
5. UI never calls `static_data` directly — always via a repository interface.

### 1.2 Feature flags
```dart
class FeatureFlags {
  static const bool visualizationEnabled = true;
  static const bool calculatorEnabled    = true;
}
```
Home/Explore hide those entry points when false → app still fully shoppable.

---

## 2. Directory Structure

```
lib/
  main.dart
  app.dart
  core/
    constants/    app_constants.dart, feature_flags.dart, asset_paths.dart
    theme/        app_colors.dart, app_theme.dart, app_typography.dart, app_spacing.dart
    routes/       app_router.dart, route_names.dart
    utils/        formatters.dart, validators.dart, debouncer.dart, result.dart
    state/        app_state_scope.dart  (InheritedNotifier DI root)
    widgets/      primary_button.dart, brand_gradient.dart, rating_stars.dart,
                  section_header.dart, shimmer_box.dart, empty_state.dart,
                  error_state.dart, loading_state.dart, quantity_stepper.dart,
                  price_text.dart, network_safe_image.dart, glass_card.dart
    bridge/       module_bridge.dart
  data/
    models/       product.dart, category.dart, brand.dart, review.dart, user.dart,
                  address.dart, order.dart, cart_item.dart, coupon.dart, offer.dart,
                  room.dart, room_surface.dart, marble_texture.dart,
                  saved_design.dart, notification_item.dart, sample_request.dart,
                  quote_request.dart, return_request.dart, ticket.dart
    static_data/  products_data.dart, categories_data.dart, brands_data.dart,
                  reviews_data.dart, users_data.dart, orders_data.dart,
                  rooms_data.dart, textures_data.dart, offers_data.dart,
                  coupons_data.dart, notifications_data.dart, faq_data.dart
    repositories/ product_repository.dart (abstract) + static_product_repository.dart
                  category_repository.dart, order_repository.dart,
                  user_repository.dart, room_repository.dart, review_repository.dart,
                  cart_repository.dart, wishlist_repository.dart,
                  design_repository.dart, support_repository.dart
  features/
    splash/  onboarding/  home/
    catalog/ { products/, categories/, search/, filters/, compare/ }
    product/ cart/ wishlist/ checkout/ orders/ account/ reviews/ support/
    visualization/ { rooms/, camera/, textures/, hotspots/, room_customizer/, api/ }
    calculator/ { models/, services/, screens/ }
    designs/ { saved_designs/ }
  assets/  (see §4)
```

State: `ChangeNotifier` controllers exposed through an `AppScope` InheritedWidget.
No third-party DI/state package required → zero external runtime deps beyond Flutter SDK.

---

## 3. Data Models (fields)

### Product
`id, name, slug, description, pricePerSqFt, originalPrice, discountPercent, rating,
reviewCount, color, colorHex, finish, thicknessOptions[], origin, dimensions,
categoryId, brandId, image, gallery[], textureId, stock, isFeatured, isTrending,
isBestSeller, isNewArrival, applications[], tags[], specifications{}`

### Room
`id, name, description, thumbnail, panoramaAsset, roomType, surfaces[], defaultTextures{}`

### RoomSurface
`surfaceId, surfaceName, type(floor|wall|counter|accent|stairs), defaultTextureId,
allowedCategories[], hotspot(yaw,pitch), quadUv, defaultAreaSqFt`

### MarbleTexture
`textureId, name, assetPath, thumbnailPath, tileScale, colorHex, productIds[]`

Product ↔ texture linked **only** by `textureId`. Renderer swap ≠ product data change.

### Order
`orderId, userId, items[], address, subtotal, discount, delivery, tax, total,
paymentMethod, status, placedAt, estimatedDelivery, timeline[]`

### SavedDesign
`id, name, roomId, surfaceTextureMap{}, createdAt, previewNote, estimatedSqFt, estimatedCost`

---

## 4. Assets

```
assets/
  brand/        logo_full.png, logo_mark.png, logo_wordmark.png
  images/
    banners/    hero_1..5.jpg
    categories/ 8 tiles
    products/   20 products × 4 gallery images
    rooms/      5+ room thumbnails
    inspiration/
  textures/marble/  20 seamless tiles + thumbnails
  3d/rooms/     5 equirect panoramas (2048×1024)
  icons/
```

**Media strategy:** no runtime downloads. All imagery is generated offline into the
repo by `tool/generate_assets.py` — a procedural marble synthesizer (Perlin-style
vein turbulence + gradient base + speckle) plus panorama compositor. Deterministic
seeds per product ⇒ each marble looks distinct and consistent everywhere it appears
(card, gallery, texture tile, 3D room surface).

---

## 5. Screens (37)

Splash · Onboarding · Home · Categories · Category Products · Product List ·
Filters · Sort · Search · Search Results · Product Details · Image Gallery (zoom) ·
Compare · Wishlist · Cart · Save-for-later · Login · Register · Forgot Password ·
Address List · Address Form · Checkout (4 steps) · Payment · Order Confirmation ·
Order Tracking · My Orders · Order Details · Return Request · Return Status ·
Account · Profile Edit · Notifications · Coupons · My Reviews · Write Review ·
Help Center · FAQ · Raise Ticket · Request Sample · Request Quote ·
Explore · Room List · **3D Room (immersive)** · Room Customizer · Before/After ·
Saved Designs · Share Design · Calculator · Calculator Result · Collections

---

## 6. Immersive 3D Room — design

**Renderer choice:** custom pure-Dart panoramic engine behind an interface, so no
native/WebGL dependency in v1 but a `three_dart`/`model_viewer`/WebGL backend can be
dropped in later.

```
abstract class RoomRenderer {
  Future<void> load(Room room);
  void setCamera(double yaw, double pitch, double fov);
  void applyTexture(String surfaceId, String textureId);
  Widget build(BuildContext ctx);
  RoomSnapshot snapshot();
}
```
v1 implementation: `PanoramaRoomRenderer`
- Equirectangular panorama sampled by a `CustomPainter` doing per-column
  perspective reprojection → real look-around (yaw ±180°, pitch ±70°, FOV zoom 40–100°).
- Surfaces are UV-quads baked into the panorama's alpha masks; applying a marble
  texture re-tiles the marble image through the quad's perspective transform with
  a lighting multiply layer, so it reads as "the floor is now Carrara White".
- Hotspot markers projected from (yaw,pitch) into screen space, tap → texture picker.
- Gestures: 1-finger drag = look, 2-finger pinch = FOV zoom, double-tap = reset.
- Optional gyro path stubbed behind `GyroCameraSource` (not enabled v1).

Emits `VisualizationResult` only. Knows nothing about Cart.

---

## 7. Calculator — design

Pure functions in `calculator/services/marble_calculator.dart`:

```
areaSqFt   = f(application, length, width, wallHeight, rooms, deductions)
wastage    = area * wastagePercent/100
required   = area + wastage
cost       = required * pricePerSqFt
```
Applications: Floor · Wall · Kitchen Counter · Bathroom · Staircase — each with its
own input schema and formula (wall uses perimeter×height − openings; stairs uses
tread/riser count).

Emits `CalculationResult` only. Zero knowledge of Cart internals.

---

## 8. Buy-Now Flow (exact)

```
Product Details → Buy Now → AuthGuard
   ├ not logged in → Login/Register → Address
   └ logged in     → Saved Addresses → Select
                         → Delivery Options
                         → Order Summary
                         → Payment (UPI/Card/COD/NetBanking, simulated)
                         → Order Confirmation
                         → Track Order
```
Auth is local: `StaticUserRepository` with seeded credentials +
`demo@maasarada.com / demo1234`. Session held in memory + persisted to disk file.

Persistence: `shared_preferences`-free — a tiny `LocalStore` writing JSON to the
app documents dir via `dart:io` (cart, wishlist, recently viewed, designs, session,
addresses, orders).

---

## 9. Demo Data Volume

20 products · 8 categories · 6 brands · 5 rooms (7 listed) · 20 textures ·
18 reviews · 5 orders · 5 offers · 5 coupons · 6 notifications · 12 FAQ ·
5 recently viewed · 5 wishlist seeds.

---

## 10. Build Phases

| # | Phase | Output |
|---|---|---|
| 1 | Scaffold + brand | flutter create, theme, colors, typography, icon generation |
| 2 | Assets | procedural marble/panorama/banner generator, pubspec wiring |
| 3 | Data layer | models, static data, repositories, LocalStore |
| 4 | Core UI kit | buttons, cards, states, shimmer, section headers |
| 5 | Shell | router, bottom nav, splash |
| 6 | Home | all 15 home sections |
| 7 | Catalog | list/grid, filters, sort, search, categories |
| 8 | Product | details, gallery, reviews, related, compare |
| 9 | Commerce | cart, wishlist, buy-now, auth, address, checkout, payment |
| 10 | Orders | confirmation, tracking timeline, my orders, returns |
| 11 | Account & support | profile, notifications, coupons, help, tickets, sample, quote |
| 12 | Visualization | panorama engine, hotspots, customizer, before/after, save/share |
| 13 | Calculator | flows, formulas, result, add-to-cart bridge |
| 14 | Polish | animations, empty/error states, responsive pass, analyze+build |
| 15 | Verification | test suite: data, pricing, renderer, routes, module boundaries |

All 15 phases are complete.

---

## 11. Quality Bar

- `flutter analyze` clean.
- Every list screen has loading / empty / error / retry states.
- No hard-coded business logic in widgets — all in services/repositories.
- No runtime network calls anywhere.
- Removing `features/visualization/` or `features/calculator/` + flipping its flag
  leaves a compiling, fully functional store.

---

## 12. Verification

`flutter analyze` → no issues. `flutter build apk --debug` → builds.
`flutter test` → 100 tests, all passing:

| File | Covers |
|---|---|
| `test/calculator_service_test.dart` | unit conversion, wastage, slab rounding, GST, bridge result |
| `test/cart_pricing_test.dart` | line merging, coupons, delivery threshold, tax, persistence |
| `test/static_data_test.dart` | demo-data minimums, cross-references, **every asset file exists**, no remote URLs |
| `test/repositories_test.dart` | search/filter/sort, demo auth, address book, order place/cancel/JSON |
| `test/room_renderer_test.dart` | camera projection, all 7 rooms build + paint + re-texture, texture cache |
| `test/app_smoke_test.dart` | splash → shell, all 5 tabs, all 30 routes render without layout errors |
| `test/module_boundaries_test.dart` | import-level proof that the shop and the optional modules stay decoupled |

The smoke test drives every route at 390x844 and fails on any `RenderFlex`
overflow, so the responsive pass is enforced rather than eyeballed.

---

## 13. Bundle size

| | Before | After |
|---|---|---|
| bundled assets | 8.0 MB | 3.1 MB |
| fat release APK | 60.0 MB | 57.0 MB |
| split APK (arm64) | 26.0 MB | 20.9 MB |
| arm64 + `--obfuscate` | — | 19.0 MB |

What changed:

- **WebP for every runtime image** (`tool/generate_assets.py` writes `.webp` at
  q82, q92 with alpha for the logo lockups) — 41% off the photo set with no
  visible loss on stone or room renders.
- **Store artwork out of the bundle** — the 1024px `app_icon.png`, the master
  logo lockup and the wordmark now go to `tool/brand_src/`, not `assets/`.
  `logo_full_light.png` was a pixel-duplicate of `logo_full.png` and is gone.
- **Right-sized brand assets** — `logo_mark` 243px→264px WebP (117 KB→18 KB),
  `logo_full` 900px→560px WebP (673 KB→105 KB).
- **Product tiles/macros 900px→768px**, still 2x for the gallery view.
- **Per-ABI builds** — a fat APK ships three copies of the engine (49 MB of
  native libs); `--split-per-abi` or an App Bundle ships one.

The remaining 15.7 MB in the arm64 APK is `libflutter.so` + `libapp.so`. That is
the framework floor and cannot be reduced without a custom engine build.
