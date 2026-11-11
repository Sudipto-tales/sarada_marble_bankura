# Storefront asset provenance

Styles are copied from `Html/storefront-base.css`, `storefront.css`, `home.css` and `storefront-pages.css`. The original HTML design remains a reference; the PHP application serves only its own copies here. `php.css` and `php.js` adapt the design to server-rendered Vayu views and progressively enhance navigation and the product gallery.

Brand logos, Inter font, room photographs and the Carrara texture were already bundled in the Flutter/HTML repository. Room and texture images in promotional panels are inspiration images, not a representation of a database product or availability. KasaMood cover and original 49-page PDF are unchanged copies of `Html/assets/catalogs/kasamood`, originally from `Mobile/assets/catalogs/kasamood`. Rights and licensing remain with the original publishers; copying does not grant new redistribution rights. Preserve existing repository/template licensing. The Syne display font (`assets/fonts/font-1.ttf`, variable 400–800) is added for storefront headings and self-hosted from Google Fonts (SIL Open Font License); Inter remains the body/UI face. No remote fonts, CDN, demo product data or prototype browser-cart scripts are loaded.


The remaining design conversion copies all eight existing room previews/thumbnails, twenty existing sample texture pairs and the two prepared SAM selection manifests/masks. `visualizer-materials.json` contains only reference IDs, names and texture keys; it contains no demo prices, stock, reviews or cart records. The photo renderer is copied from the original project implementation; the editor is adapted for PHP asset URLs, PHP destinations, separate browser storage namespaces and a 25-design bound. `visualizer-entry.js` mounts it only on photo-editor/saved-design pages. Photos are processed locally in the browser, never automatically uploaded. The Python SAM source/models remain outside the public Website tree. Sample textures are not associated with real sellable catalog variants until an operator supplies an approved mapping.

The PAR-UI-03 PHP adapters increase card typography and spacing, use content-sized promotional rows and wrap long names without truncation. The editor exposes room-photo and original-marble-photo selection; custom marble pixels/names are stored with each surface in the existing local design format. Processing feedback follows image decoding and canvas rendering, supports latest-selection ownership and retries failed sample downloads. No upload/API integration is introduced.

## HTML design restoration (PAR-HTML-01)

`html-parity.css` restores the local HTML reference palette and compact layout after the PHP integration stylesheet. `home.js` implements the original manual carousel using escaped, server-supplied slide JSON. The PHP SVG helper `app/page/storefront/icons.php` contains the unchanged original drawings from `Html/storefront-icons.js`; no external icon package or third-party template was added. Home product images/prices come from the published catalog, while original bundled stone/room pictures remain decorative inspiration. Project enquiry notes save only to browser storage and are never sent. See `Website/docs/html-design-restoration.md` for scope and evidence.

## Inspired refresh layer

`refresh.css` loads last on every storefront page and gently refreshes shared primitives (lifted cards, pill buttons, accented section headings) plus a trust/benefits strip and a call-to-action band on the home page, in the generic modern-ecommerce rhythm. It is original CSS written against the existing Stone Atelier tokens and reuses the bundled SVG icons — nothing is copied from any third-party or commercial template, and no new assets, fonts, CDN or backend were added.

## Vendored libraries

- `vendor/lottie.min.js` — lottie-web (light build) 5.12.2, MIT licensed, from cdnjs (https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie_light.min.js). Kept local; loaded only on a freshly placed order to play `order-success.json` (an in-house checkmark animation).
