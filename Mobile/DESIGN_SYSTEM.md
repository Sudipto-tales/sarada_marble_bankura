# Stone Atelier mobile UI

The Flutter app uses a warm showroom palette: ivory surfaces, sage navigation,
charcoal text and terracotta actions. The logo and existing local product imagery
are retained. Product surrounds use the catalog color metadata, without altering
the photographs.

## Shared design

- Asymmetric stone cards: 32/16/32/16 corner radii; 24px controls and 32px panels.
- Rounded forms, sheets, checkout trays and account/support surfaces share theme tokens.
- Home uses an organic curved hero, floating photography and rounded material samples.
- Bottom navigation expands the selected destination over 320ms, retaining all five tabs.
- Product cards lift into view and compress gently on touch; only hero imagery loops.
- Hidden tabs stop animation tickers. New motion and shimmer respect reduced motion.
- Light and dark palettes, responsive product heights, stock labels and existing actions remain supported.

## Loading

Home, catalog, wishlist, search and product details use shaped skeletons. Search
uses row placeholders; product grids use the same dimensions as loaded cards.
Local images also show a placeholder until their first decoded frame. Unknown
products use a neutral stone tint; known images can use the product's tint.
Existing demo repository latency is unchanged; no extra loading delay is added.

## Verification and previews

Run `flutter analyze` and `flutter test` from `Mobile`.
`test/stone_design_test.dart` adds checks for 320/390px layouts, light/dark modes,
product-detail loading and reduced-motion skeletons. The existing route smoke
tests cover the rest of the app.

On Windows, optional preview capture uses local Segoe UI/Georgia fonts (not
redistributed) and the app's bundled Material icons:

```powershell
flutter test test/stone_design_test.dart --dart-define=CAPTURE_PREVIEWS=true
```

Rendered PNGs are written to `Docs/previews/mobile-stone-{home,catalog,product,loading}.png`.
These are Flutter widget renders; device fonts and system insets can differ.
Commerce remains the existing offline prototype with simulated payments.
