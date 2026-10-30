# Hyper admin template assets (vendored)

Source: **Hyper – Responsive Bootstrap 5 Admin Dashboard** by Coderthemes
(`Hyper/` export at repo root). Kept local per repository template-licensing policy.

Only the files the admin panel actually uses are vendored here:

- `css/app-saas.min.css`, `css/icons.min.css` — theme + icon fonts (MDI, Remix, Unicons, Nunito)
- `fonts/` — all icon/text font formats referenced by the two CSS files
- `js/hyper-config.js` — theme bootstrap (light theme, vertical dark sidebar)
- `js/vendor.min.js` — bundles jQuery, Bootstrap 5, SimpleBar
- `js/app.min.js` — Hyper layout behaviours (sidebar toggle, dropdowns)
- `vendor/apexcharts/` — charts (sales reports)
- `vendor/datatables.net*` — reserved for a future bs5 DataTables swap (the admin
  currently uses the server-side DataTable in `assets/admin/vendor/` + `admin.js`)

The theme is Bootstrap 5.2-based: `*-subtle` colour utilities do **not** exist; use
Hyper's `bg-*-lighten` / `avatar-title` helpers instead.
