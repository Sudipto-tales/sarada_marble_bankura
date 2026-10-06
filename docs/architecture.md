# Vayu Framework Architecture

## Folder structure

- `index.php` — application entry point.
- `config/` — framework configuration, bootstrapping, database, and environment helpers.
- `core/` — reusable framework helpers, base controller, route manager, and route provider.
- `app/` — frontend route definitions, bridge controllers, and page views.
- `api/` — API route definitions and backend endpoint registration.
- `assets/` — CSS, JavaScript, images, and static assets.
- `vendor/` — Composer dependencies.

## Routing architecture

### Route definitions

- `app/view.php` defines frontend routes in the shape:
  ```php
  'route-key' => ['ControllerClass', 'method']
  ```
- `api/gateway.php` defines API routes using the same shape.

### Route loading

- `config/config.php` loads both route sets:
  - frontend routes from `app/view.php`
  - API routes from `api/gateway.php`
- Duplicate keys across providers are rejected; the merged route array is available to the dispatcher.

### Dispatching

- `config/route.php` loads bootstrap files and the route manager.
- `core/RouteManager.php` resolves the current path and dispatches the matching controller.
- Exact routes take priority over typed `{slug}`/`{id}` templates. Unknown paths return an HTML or JSON 404; method maps enforce 405 with an Allow header.
- `config/classes.php` registers fixed paths for controllers/services through `ClassLoader`; HTTP requests never choose a PHP filename.
- Legacy handlers keep their controller-level HTTP-method guards.
- See [implemented foundations](foundations.md) for registration, validation and checks.

## Controller layer

- Controllers live in `app/bridge/`.
- All frontend controllers should extend `core/BaseController`.
- `BaseController` provides:
  - `viewData()` — merges view variables with extra data.
  - `respond()` — renders a view.
  - `apiGet()` / `apiPost()` — remote API helpers.
  - `defaultAppName()` — returns `APP_NAME` or controller default.

## View layer

- Views live in `app/page/`.
- The `load_view()` helper in `config/bootstarp.php` loads a view file and extracts passed data into variables.
- Example: `['name' => 'vayu']` becomes `$name` inside the view.

## Helper layer

### Core helpers

- `core/Helpers.php` contains reusable functions for:
  - data merging
  - view rendering
  - API requests

### Environment helper

- `config/env.php` defines `env()`.
- It reads environment variables from `$_ENV`, `$_SERVER`, or `getenv()`.

### Database helper

- `config/db.php` selects the database driver and creates a shared PDO connection for SQLite or MySQL. Unsupported drivers fail explicitly.
- It also defines reusable prepared SQL helpers.

## Feature function patterns

### Add a new frontend page

1. Add a route in `app/view.php`.
2. Create a bridge controller in `app/bridge/` and register its globally unique name in `config/classes.php`.
3. Create a page view in `app/page/`.
4. Pass required data from the controller to the view.

### Add a new API route

1. Add a route in `api/gateway.php`.
2. Create an API controller and register its globally unique name in `config/classes.php`.
3. Use helper functions or custom logic inside the API handler.

## Current sample features

- `Welcome::index()` renders `app/page/welcome.php`.
- `Welcome::hello()` renders `app/page/hello.php` and demonstrates remote API data fetching.
- `app/page/hello.php` shows how controller data is passed into the view.
- `app/page/welcome.php` contains documentation-style HTML for release notes and architecture.

## Best practices

- Keep frontend controllers lean.
- Keep business logic in reusable helpers or service classes.
- Keep views focused on presentation.
- Use `htmlspecialchars()` when rendering user-facing values.
- Keep route definitions in `app/view.php` and `api/gateway.php`.

## CLI and migrations

`config/cli.php` loads shared runtime configuration/PDO without web dispatch. `php vayu migrate` runs ordered, serialized, driver-aware migrations and forward repairs; it never seeds known passwords. See [foundations](foundations.md) and [hosting capabilities](hosting-capabilities.md).

## Request and identity foundations

[RequestSecurity](../core/RequestSecurity.php) supplies strict private-storage sessions, cookie/CSRF policy and bounded JSON parsing. [Auth](../core/Auth.php) uses the existing PDO for current commerce identity checks and hashed, expiring and rotating credentials. Commerce logout preserves the separate Developer identity; staff grants remain IAM-01/02 work. [Runtime foundations](foundations.md#request-session-and-auth-protections) documents the explicit auth upgrade, default-disabled signup/test-only verification transport, private-path/Apache policy and pending operator/provider checks.
