# Developer console design

The initial design work is tracked as DEV-UI-01 (completed 2026-10-13). The
current presentation is a Sneat-inspired light refresh layered over the same
Vayu console shell.

Dependency: existing Developer login, permission-protected pages and runtime API.
This standalone presentation slice is outside the commerce MVP task counts.

Guide and source review: `docs/ecommerce-developer-console.md`,
`app/page/developer.php`, `assets/developer/console.css`,
`assets/developer/console.js`, `app/bridge/Developer.php` and
`tests/DeveloperAccessTest.php`. Commerce plan/tracker and release rules reviewed.

Scope: redesign login and shared console presentation; readable responsive panels,
consistent icons, keyboard-accessible mobile navigation and explicit sample states.
Keep dedicated Developer authentication, CSRF and runtime collection behavior.

The console uses a fixed light sidebar, white top navbar, indigo active state,
soft-shadow cards, and a pale gray content background. The shared layout adds
Sneat-familiar class names (`layout-wrapper`, `layout-container`, `layout-menu`,
`layout-page`, `layout-navbar`, `content-wrapper`, and `container-p-y`) without
depending on Bootstrap or remote assets. Existing Vayu selectors remain in
place so the console pages, permissions, and JavaScript hooks keep working.

The shell lives in `app/page/developer.php`; `assets/developer/console.css`
continues to provide the base component structure, and
`assets/developer/design.css` supplies the theme tokens and responsive visual
overrides. `assets/developer/console.js` retains the mobile menu overlay,
keyboard handling, polling, charts, and sample preview behavior.

To reuse the shell for future protected admin pages, keep the `layout-wrapper`
and `layout-container` around a permission-filtered `layout-menu` and a
`layout-page`. Put the navbar first in the page, then render content inside
`content-wrapper container-xxl flex-grow-1 container-p-y`. Reuse `panel` for
white cards, `stat-grid` / `stat-card` for summaries, and the existing table
styles for lists. Admin routes must keep their own authorization, CSRF, and
content logic; this stylesheet provides presentation only.

No external assets were added. The login flow and its development-only hint are
unchanged; login styling now shares the indigo and gray palette.

The original DEV-UI-01 verification (before this visual refresh) was performed
on PHP 8.3.6:

- `php -l app/page/developer.php`: passed.
- `php tests/DeveloperAccessTest.php`: 19 checks passed with in-memory SQLite.
- `git diff --check`: passed.
- Headless Chrome: all eight console pages plus login checked at 1440px and
  390px widths; no document horizontal overflow; design stylesheet loaded.
- Browser interactions: sample preview/reset, pause, API search empty state,
  mobile menu overlay/focus/Escape, and password visibility passed.
- Dashboard and login screenshots visually inspected at both widths.

This refresh was checked with `php -l app/page/developer.php` and
`git diff --check`; both passed. Browser screenshots and interactions have not
yet been rechecked for the refreshed theme. Real account provisioning and live
runtime collectors are outside this presentation work. No production changes
or release.
