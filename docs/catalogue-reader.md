# Catalogue reader (CAT-READER-01)

The existing `/catalogue/kasamood` route renders `app/page/storefront/catalogue.php`.
It progressively enhances the original PDF object with a locally hosted StPageFlip
reader. The PDF download and Open PDF links remain available. JavaScript disabled,
a missing library, or initialization failure leaves the original PDF viewer visible.
Page-image failures show an instruction to reopen or use the PDF.

The reader supports a single page on narrow layouts, spreads on wider layouts,
previous/next buttons, keyboard arrows while focused, page selection, a scrollable
large-page dialog, and native full screen where supported. Reduced motion disables
pointer-driven animations and makes button navigation immediate. Browser-local
reading position is keyed by the PDF SHA-256 version. At most nine page image
sources are assigned around the current spread; browser HTTP cache policy still
controls network caching. Pages are 1400 pixels tall; use the PDF for original
vector detail, selectable text, and document search.

## Asset provenance

- `assets/storefront/vendor/page-flip/page-flip.browser.js`: **page-flip 2.0.7**,
  downloaded unchanged from `https://registry.npmjs.org/page-flip/-/page-flip-2.0.7.tgz`
  (`package/dist/js/page-flip.browser.js`). Upstream: [Nodlik/StPageFlip](https://github.com/Nodlik/StPageFlip),
  [API documentation](https://nodlik.github.io/StPageFlip/). MIT license and copyright
  are preserved in the adjacent `LICENSE`. No CDN or npm is required at runtime.
- `assets/storefront/assets/catalogs/kasamood/pages/page-01.jpg` through `page-49.jpg`:
  derived only from the existing supplied `KasaMood.pdf` in that parent directory
  using Poppler `pdftoppm` and Pillow. The 47 landscape PDF spreads are split
  left to right; the two portrait covers remain whole, yielding 96 reading pages. Original Kajaria artwork/branding is preserved. Existing
  PDF publication permissions apply to these derivatives. No Kajaria player code
  or FlippingBook commercial assets were copied.
- `pages/manifest.json`: ordered page filenames, page count, and the first sixteen
  hex characters of the original PDF's SHA-256 hash plus a conversion version. The PHP page reads this local,
  trusted manifest; it does not accept a user-controlled file path.

## Regenerating the bundled pages

From the repository root, with Poppler and Python Pillow installed, use the
bundled staging tool. The output directory must not already exist:

```sh
python3 Tools/catalogue_reader/build_pages.py \
  Website/assets/storefront/assets/catalogs/kasamood/KasaMood.pdf \
  /tmp/sarada-catalogue-pages-new
```

Inspect the staged JPEGs and manifest before replacing the committed `pages/`
directory. The tool splits landscape source spreads left to right, preserves
portrait covers, and records each reading page's source PDF page in the manifest.
It emits 1400-pixel-tall images. It is specific to this document's spread layout;
inspect page geometry before using it for other catalogues.

Keep the PDF, cover, page images, manifest, library PDF page count, and edition
metadata consistent when replacing this bundled document. This slice adds no
staff upload pipeline, new catalog records, remote requests, or Flutter API
integration.
