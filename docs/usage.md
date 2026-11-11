# Vayu Framework Usage

## Quick start

1. Copy `.env.example` to `.env`.
2. Set your environment values in `.env`.
3. Run `composer install` to install dependencies.
4. Start the application using a local server, for example:
   ```bash
   php vayu run
   ```
5. Open the app in your browser at `http://127.0.0.1:8000`. Stop with Ctrl+C.

Choose another port with `php vayu run --port=8080`.

## Key entry points

- `index.php`
  - Main application entry point.
  - Loads `config/route.php`.
- `config/route.php`
  - Loads bootstrap files and dispatches routes.
- `config/bootstarp.php`
  - Loads environment, configuration, framework settings, database, and core helpers.
  - Defines `load_view()` and `base_url()`.

## Environment configuration

Use `.env` to configure:

- `APP_NAME`
- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `DB_TYPE` (`sqlite` or `mysql`)
- `DB_DATABASE`
- `DB_HOST`
- `DB_PORT`
- `DB_USERNAME`
- `DB_PASSWORD`

The `env()` helper is defined in `config/env.php` and reads from `$_ENV`, `$_SERVER`, or `getenv()`.

## Routing

Frontend and API routes are defined separately:

- Frontend routes: `app/view.php`
- API routes: `api/gateway.php`

The route definitions are loaded in `config/config.php` and dispatched by `core/RouteManager.php`.

## Frontend flow

1. `app/view.php` returns a route map.
2. `RouteManager::dispatch()` resolves the current route.
3. The controller class is instantiated and the requested method is called.
4. Controllers use `render_view()` and `load_view()` to render view pages.

## Views

- Frontend views live in `app/page/`.
- Each view receives variables extracted from the controller.
- Use `<?= htmlspecialchars($variable, ENT_QUOTES, 'UTF-8') ?>` to escape output.

Example in `app/page/hello.php`:
```php
<h1>Hello, <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>!</h1>
```

## Controllers and bridge files

- Frontend controller code lives in `app/bridge/`.
- A controller extends `core/BaseController` to access reusable methods.
- Example: `app/bridge/Welcome.php`.

### Example controller
```php
class Welcome extends BaseController
{
    public function hello($app_name = null)
    {
        $app_name = $app_name ?: $this->defaultAppName();

        $data = $this->viewData(
            ['name' => $app_name],
            ['api' => $this->apiGet('https://jsonplaceholder.typicode.com/todos/1')]
        );

        return $this->respond('/app/page/hello.php', $data);
    }
}
```

## API helpers

Core helper functions are in `core/Helpers.php`:

- `view_data(array $data, array $extras)`
- `render_view(string $path, array $data)`
- `api_request(string $url, string $method, array $options)`
- `api_get(string $url, array $query, array $headers)`
- `api_post(string $url, array $payload, array $headers)`

Use `api_get()` and `api_post()` from controllers to fetch external JSON APIs.

## Database support

`config/db.php` supports:

- SQLite
- MySQL

Reusable SQL helpers:

- `db_query($sql, $params)`
- `db_fetch_all($sql, $params)`
- `db_fetch_one($sql, $params)`
- `db_execute($sql, $params)`
- `db_last_insert_id()`

## Composer

The project requires:

- `phpmailer/phpmailer`
- `vlucas/phpdotenv`

Use `composer install` to create `vendor/` and install dependencies.

> `composer.lock` is only useful after `composer.json` is present. In production, always run `composer install` to install packages from `composer.json` and lock versions from `composer.lock`.

## Migrations and parameter routes

Run `php vayu migrate` before working with customer tables. SQLite relative paths resolve from Website; MySQL applies DB_PORT and utf8mb4. New method maps and `{slug}`/`{id}` routes require a fixed controller registration in `config/classes.php`. See [implemented foundations](foundations.md) for full contracts, recovery and verification commands.
