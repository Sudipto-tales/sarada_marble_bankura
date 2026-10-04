# Glossy shopping design

The storefront lives in `Mobile/` and supports Flutter web. `Website/` currently serves a separate welcome page and developer console.

## Design

- Shared pearl-to-stone backgrounds, reflective panel gradients, fine highlights and soft shadows; colors follow the selected accent and light/dark appearance.
- Rounded product cards with a consistent image frame. Images use `BoxFit.cover` to fill the frame without stretching; original proportions are preserved with centered cropping.
- Duotone navigation drawings and a raised gradient treatment for the selected tab.
- Shared glossy panels in home, account, cart, checkout summaries and product pairings.

## Product galleries

- Start with the primary photo, then cycle through the gallery in order.
- Remove duplicate and blank image paths. Zero or one unique photo never starts a rotation timer.
- Each card owns a random timer, with a fresh delay of 1,000–3,000ms for every change. Cards are not driven by a shared interval.
- Slide left over 550ms and show a small photo-position indicator. Decode the next local photo during the preceding dwell.
- Pause while hovered, when the app is backgrounded, when the tab's TickerMode is disabled, or when reduced motion is requested. Cancel timers when disposed; reset the gallery when its photos change.

## Verification

Run from `Mobile/`:

```sh
flutter analyze
flutter test --concurrency=1 test/product_image_gallery_test.dart test/stone_design_test.dart test/app_smoke_test.dart
flutter build web --no-pub --no-wasm-dry-run
```

Verified: static analysis clean, all 42 targeted tests passed, mobile home/catalog previews inspected, and the JavaScript web release built successfully. The optional WebAssembly compatibility dry run was disabled after the initial build process was terminated.
