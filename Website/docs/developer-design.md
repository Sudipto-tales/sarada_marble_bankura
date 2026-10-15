# Developer console design — DEV-UI-01

Owner: coordinator. Claimed and completed 2026-10-13. Status: done.

Dependency: existing Developer login, permission-protected pages and runtime API.
This standalone presentation slice is outside the commerce MVP task counts.

Guide and source review: `docs/ecommerce-developer-console.md`,
`app/page/developer.php`, `assets/developer/console.css`,
`assets/developer/console.js`, `app/bridge/Developer.php` and
`tests/DeveloperAccessTest.php`. Commerce plan/tracker and release rules reviewed.

Scope: redesign login and shared console presentation; readable responsive panels,
consistent icons, keyboard-accessible mobile navigation and explicit sample states.
Keep dedicated Developer authentication, CSRF and runtime collection behavior.

Delivered: dark teal sidebar with lime active navigation, inline SVG navigation
icons, larger typography, clearer panels/tables, a refreshed split login layout,
responsive layouts and a mobile overlay with keyboard focus handling. The
presentation layer lives in `assets/developer/design.css`; the original base
stylesheet continues to provide structural styles. No external assets added.

Actual verification on PHP 8.3.6:

- `php -l app/page/developer.php`: passed.
- `php tests/DeveloperAccessTest.php`: 19 checks passed with in-memory SQLite.
- `git diff --check`: passed.
- Headless Chrome: all eight console pages plus login checked at 1440px and
  390px widths; no document horizontal overflow; design stylesheet loaded.
- Browser interactions: sample preview/reset, pause, API search empty state,
  mobile menu overlay/focus/Escape, and password visibility passed.
- Dashboard and login screenshots visually inspected at both widths.

Browser verification used a temporary local PHP rendering fixture and synthetic
runtime data, outside the application. Real account provisioning and live runtime
collectors were not part of this design slice. No production changes or release.
