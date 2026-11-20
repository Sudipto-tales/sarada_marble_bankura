# Maa Sarada admin HTML preview

Serve the existing buildless preview from the repository root:

```sh
python3 -m http.server 8080 --directory Html
```

Open `http://localhost:8080/admin/index.html`.

- The existing Hyper layout, styles, icons, cards, charts, navigation and sample rows are retained. Material names and store labels now refer to Maa Sarada Marble. INR labels accompany sample amounts; these are demonstration data, not live transactions.
- **Products → Add Product**, or the existing Add Product button, opens `apps-ecommerce-products-add.html`. Required fields include name, slug, category, SKU, price and minimum increment. A slug is suggested from the name; it remains editable. Stock has explicit selling units. Duplicate browser-local slugs and SKUs are rejected.
- Saving stores the complete form locally, returns to Products and displays the new material using the existing DataTable. Local rows link back to the form for editing. Draft/published labels apply only to the local preview. Photo uploads require the live admin and are not simulated.
- The avatar dropdown's **Profile** item opens `pages-profile.html`. Its Settings item opens account details. Saving a display name/email updates the preview profile and the admin name on subsequent pages.
- Data stays in this browser under `maa-sarada.admin.products.v1` and `maa-sarada.admin.profile.v1`. It is separate from the storefront and PHP commerce database. Clearing browser storage removes it. Storage failure displays an error without claiming a save. Use HTTP for reliable persistence across pages.

`assets/js/admin-preview.js` adds these interactions without changing the theme styles. No authentication, production publication or backend API integration is provided by these static pages. Other retained template demo links/actions may require pages or plugins that were absent from the supplied folder.

## Template provenance

The supplied admin pages and local assets originate from the repository's `Hyper/` template by **Coderthemes** (Hyper Bootstrap 5 admin dashboard). This adaptation retains those assets and existing upstream links/metadata. Existing publisher ownership and licensing continue to apply; this work does not grant a new template redistribution license. Business content and the browser-local interaction script are project adaptations.
