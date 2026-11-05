# Hyper admin template assets (vendored)

Source: **Hyper – Responsive Bootstrap 5 Admin Dashboard** by Coderthemes
(`Hyper/` export at repo root). Kept local per repository template-licensing policy.

Only the files the admin panel actually uses are vendored here:

- `css/app-saas.min.css`, `css/icons.min.css` — theme + icon fonts (MDI, Remix, Unicons, Nunito)
- `fonts/` — all icon/text font formats referenced by the two CSS files
- `js/hyper-config.js` — theme bootstrap (light theme, vertical dark sidebar)
- `js/vendor.min.js` — bundles jQuery, Bootstrap 5, SimpleBar
- `js/app.min.js` — Hyper layout behaviours (sidebar toggle, dropdowns)
- `vendor/apexcharts/` — charts (sales reports, analytics dashboard)
- `vendor/admin-resources/jquery.vectormap/` — jVectorMap for the analytics map (ADM-UI-01)
- `vendor/daterangepicker/` — the analytics dashboard date-range control (ADM-UI-01)
- `vendor/datatables.net*` — **unused**; see "DataTables" below

The theme is Bootstrap 5.2-based: `*-subtle` colour utilities do **not** exist; use
Hyper's `bg-*-lighten` / `avatar-title` helpers instead. The class `table-action` is also
absent from this build — use `action-icon` on the links.

## jVectorMap (ADM-UI-01)

jVectorMap 1.2.2 by Kirill Lebedev, MIT licence — the notice is inline at the top of
`jquery-jvectormap-1.2.2.min.js` and must stay there.

```
vendor/admin-resources/jquery.vectormap/jquery-jvectormap-1.2.2.css
vendor/admin-resources/jquery.vectormap/jquery-jvectormap-1.2.2.min.js
vendor/admin-resources/jquery.vectormap/maps/jquery-jvectormap-in-mill-en.js
vendor/admin-resources/jquery.vectormap/maps/jquery-jvectormap-world-mill-en.js
```

Both map datasets ship. Which one renders is the `analytics_map` store setting
(`in_mill_en` default, `world_mill_en` alternative) — Hyper's card markup and its
`#world-map-markers` id are identical either way, so this is data, not a design change.

**The two `maps/*.js` files carry no inline licence header** — they are generated region-path
data, distributed under jVectorMap's own MIT licence (see the library header above) and derived
from public-domain Natural Earth geometry. Recorded here because the files themselves cannot
state it.

**The India dataset is dated.** It has 34 regions, `IN-AN`…`IN-WB`, and:

- no `IN-TG` — Telangana does not exist in it
- `IN-UT` is named "Uttaranchal", not Uttarakhand
- `IN-OR` is named "Orissa", not Odisha
- Ladakh is inside `IN-JK`

So a state name must be mapped through an alias table, and anything unmatched has to be
reported rather than folded into a neighbouring state. See
`Docs/commerce/tasks/17-admin-hyper-ui.md` §6.2.

## daterangepicker (ADM-UI-01)

daterangepicker 3.1 by Dan Grossman, MIT licence — notice inline at the top of
`daterangepicker.js`. `moment.min.js` (MIT, moment.js contributors) is its required dependency
and is the copy Hyper ships.

```
vendor/daterangepicker/daterangepicker.css
vendor/daterangepicker/daterangepicker.js
vendor/daterangepicker/moment.min.js
```

Only these three are vendored. Upstream also ships `node_modules/`, `demo.html`, `index.html`,
`test.html` and a `website/` folder; none is needed to run the control.

Both plugins are jQuery plugins, so their `<script>` tags must load **after**
`js/vendor.min.js`, which provides jQuery.

## DataTables — vendored but unused, and not swappable as-is

`vendor/datatables.net/`, `vendor/datatables.net-bs5/`, `vendor/datatables.net-responsive/`
and `vendor/datatables.net-responsive-bs5/` were vendored earlier as "reserved for a future
bs5 DataTables swap". **That swap cannot be done with these files.** They are DataTables
**1.x**, and the panel runs DataTables **2.3.4** from `assets/admin/vendor/`. The two
generations share no wrapper class names:

| Generation | Wrapper classes |
| --- | --- |
| 1.x (these files) | `dataTables_wrapper`, `dataTables_length`, `dataTables_paginate` |
| 2.3.4 (in use) | `dt-container`, `dt-length`, `dt-paging`, `dt-search` |

So `datatables.net-bs5/css/dataTables.bootstrap5.min.css` matches nothing the live panel
renders. Hyper's own `assets/js/pages/demo.customers.js` likewise relies on 1.x-only options
(`columns[].checkboxes`, `#products-datatable_length`) and cannot drive a 2.3.4 table.

The panel keeps 2.3.4 deliberately: it carries the server-side search/pagination proven by
`tests/admin_http_test.py`. Hyper's table *appearance* is reproduced through DataTables 2.x
config plus the `.dt-container` overrides in `assets/admin/admin.css`, not by loading these
files.

These four directories (~90 KB incl. licences and sort images) are therefore dead weight.
Removing them is a separate decision — they are left in place, unreferenced, rather than
deleted silently.
