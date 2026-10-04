# Vayu Framework Feature Reference

## What this framework provides

- Lightweight frontend routing using `app/view.php`.
- API route definition using `api/gateway.php`.
- Reusable controller abstractions through `core/BaseController.php`.
- Remote API helper functions in `core/Helpers.php`.
- Database support for SQLite, MySQL, and MongoDB.
- Environment-driven configuration via `config/env.php`.
- Simple view rendering through `load_view()`.

## Available helper functions

### `env(string $key, $default = null)`

Reads environment variables from:

- `$_ENV`
- `$_SERVER`
- `getenv()`

### `view_data(array $data = [], array $extras = [])`

Merges primary view data with additional data.

### `render_view(string $path, array $data = [])`

Loads a view file and passes data to it.

### `api_request(string $url, string $method = 'GET', array $options = [])`

Fetches an API response using cURL or fallback file streams.

### `api_get(string $url, array $query = [], array $headers = [])`

Helper for GET API calls.

### `api_post(string $url, array $payload = [], array $headers = [])`

Helper for POST API calls.

## BaseController methods

`core/BaseController.php` exposes the following protected methods:

- `viewData(array $data = [], array $extras = []): array`
- `respond(string $view, array $data = [])`
- `apiGet(string $url, array $query = [], array $headers = [])`
- `apiPost(string $url, array $payload = [], array $headers = [])`
- `defaultAppName(): string`

These methods are intended for frontend bridge controllers.

## Adding a new frontend feature

1. Create a controller file in `app/bridge/`.
2. Extend `BaseController`.
3. Add public methods for the new page.
4. Use `$this->viewData()` to prepare view variables.
5. Call `$this->respond('/app/page/yourpage.php', $data)`.
6. Add the route to `app/view.php`.
7. Create the page view in `app/page/`.

### Example: new page route

In `app/view.php`:
```php
return [
    'default' => ['Welcome', 'index'],
    'hello' => ['Welcome', 'hello'],
    'about' => ['About', 'index'],
];
```

In `app/bridge/About.php`:
```php
<?php
class About extends BaseController
{
    public function index()
    {
        $data = $this->viewData(['title' => 'About Us']);
        return $this->respond('/app/page/about.php', $data);
    }
}
?>
```

In `app/page/about.php`:
```php
<h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
```

## Adding a new API route

1. Add an entry in `api/gateway.php`.
2. Create an API controller or handler file.
3. Use `api_get()` or `api_post()` for remote APIs.

### Sample API route

In `api/gateway.php`:
```php
return [
    'api/status' => ['StatusApi', 'index'],
];
```

In `api/StatusApi.php` (example location):
```php
<?php
class StatusApi
{
    public function index()
    {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
    }
}
?>
```

## Data flow best practices

- Keep presentation data inside the controller and pass only the final variables to the view.
- Build the data array using `view_data()` to keep the view variable list clean.
- For API-backed pages, fetch remote data in the bridge controller and render it safely in the view.

## Recommended feature extensions

- Add `app/page/components/` for reusable HTML blocks.
- Add a request helper for input sanitization.
- Add middleware-style routing in `core/RouteManager.php`.
- Add a public `public/` webroot and move static assets there.

## Current framework limitations

- Router is route-key based and does not automatically parse dynamic path segments.
- API routing is defined manually in `api/gateway.php`.
- There is no authentication or session helper built into this codebase yet.
- The default `app/page/welcome.php` is a documentation page rather than a functional application page.
