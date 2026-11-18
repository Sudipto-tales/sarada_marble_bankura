# 01: Make the 503 show its real cause

## Problem
`Website/app/bridge/StorefrontController.php` line 18 (`handle()`) and `Website/index.php` line 5 catch **every** `Throwable`. They send status 503 and the message "temporarily unavailable", and they **log nothing**. A missing table, an unwritable session directory and a bad DB password all look the same.

## Fix
Log non-`RequestRejected` errors server-side. Users still see the generic message, so no information leaks.

### `Website/app/bridge/StorefrontController.php`
Old:
```php
        catch (Throwable $error) {
            $status = $error instanceof RequestRejected ? $error->status : 503;
```
New:
```php
        catch (Throwable $error) {
            if (!$error instanceof RequestRejected) {
                error_log(sprintf('[503] %s %s: %s at %s:%d', $_SERVER['REQUEST_URI'] ?? '-', get_class($error),
                    $error->getMessage(), $error->getFile(), $error->getLine()));
            }
            $status = $error instanceof RequestRejected ? $error->status : 503;
```

### `Website/index.php`
Old:
```php
} catch (Throwable $error) {
    // SQL, filesystem and provider exceptions must never reach a public response.
    $rejected = $error instanceof RequestRejected;
```
New:
```php
} catch (Throwable $error) {
    // SQL, filesystem and provider exceptions must never reach a public response.
    $rejected = $error instanceof RequestRejected;
    if (!$rejected) error_log(sprintf('[503] %s: %s at %s:%d', get_class($error), $error->getMessage(), $error->getFile(), $error->getLine()));
```

## Where to read the log
- Local (`php vayu run`): the terminal running the server.
- Hostinger: hPanel → Websites → Advanced → **PHP error logs**, or `~/logs/` / `error_log` next to `index.php` over SSH.

## Test
1. `php -l Website/app/bridge/StorefrontController.php && php -l Website/index.php`. Expect "No syntax errors".
2. Log in as admin and open `/admin`.
3. The log now has a line like `[503] /admin PDOException: SQLSTATE[HY000]: General error: 1 no such table: product_variants at …AdminReadService.php:…`.
   Copy that line into your report. It confirms fix 02.

## Done when
The 503 cause is visible in the log. **STOP. Report the logged line and ask for "next".**
