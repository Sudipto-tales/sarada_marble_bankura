# Mobile appearance and top deals

The home feed now places repository-provided promo cards in a swipeable carousel at the top. Rounded corners and a curved lower inset echo the supplied reference. Pagination supports tapping as well as swiping; there is no automatic motion. Cards grow with accessibility text scaling.

## Promotion contract

`PromoRepository.banners()` supplies `PromoBanner` records. `fromJson` and `toJson` support future service integration:

- `id`, `image`: required identity and bundled image asset path.
- `imageOnly`: false for a composed card; true for a full-card image with an accessible tappable destination.
- `title`, `subtitle`, `badge`, `ctaLabel`: optional strings; an empty CTA hides the button.
- `backgroundColor`: integer ARGB color, default `0xFFDCEAB5`. Foreground contrast is selected from the background brightness.
- `productId`, `categoryId`: optional destination; product takes priority, otherwise opens the category or catalog.

Demo content lives in `Mobile/lib/data/static/static_promos.dart`. The current application remains asset-only and uses static repositories. A production admin editor, image upload/hosting, authenticated API, publication scheduling and remote image loading are future backend work. Do not describe the current UI as connected to an admin service.

## Appearance

Account → Settings → Appearance offers light, dark and device modes plus six accent colors. Changes apply immediately through the app theme and persist in the existing local store. Its existing web/read-only fallback is memory-only. Primary controls, navigation selection and shared action gradients use the selected color. Promotional art and semantic status colors retain their independent palettes.

Subtle highlights, gradients and tinted elevation are used across shared action buttons, product cards, account groups, navigation and Material cards. These avoid full-screen blur costs and keep text opaque.

## Verification

Run `flutter analyze` and `flutter test` from `Mobile`. Coverage includes preference restoration, promo serialization, narrow-screen carousel swiping and enlarged text, plus existing screen and commerce tests.
