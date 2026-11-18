# 07: Code and logic optimizations (after 01–06 are green)

Do these one at a time, in this order. Run the full test suite (05) after each one.

## 7.1 Never edit applied migrations again
The 0005/0006/0007/0008/0013 rewrite caused fix 02. Rule: a migration that has reached any shared DB is frozen. Schema changes go in a new `00NN_*.php`. Add this to `AGENTS.md`:
```
Migrations are append-only. Never modify a migration that exists on main; add a new one.
```

## 7.2 `CommerceAccess::actor()` runs on every check
`elevated()`, `actor()`, `render()` and `requirePermission()` each re-run `Auth::identity()` plus the permission join. That's 3–5 identical query pairs per admin request. Memoize per request:
```php
private static ?array $cached = null;
public static function actor(?int $userId = null): ?array
{
    if ($userId === null && self::$cached !== null && (self::$cached['id'] ?? null) === ($_SESSION['user_id'] ?? null)) return self::$cached;
    // …existing body…
    if ($userId === null) self::$cached = $user;
    return $user;
}
public static function forget(): void { self::$cached = null; }  // call from Auth::createSession/logout
```
DB state is still checked once per request, which keeps the "never trust the session snapshot" design.

## 7.3 Duplicate permission check in `Admin`
`handle()` → `elevated()` already confirms an `admin.*` permission. `actor()` and `render()` check it again. Once 7.2 is done this costs almost nothing, so you can leave it. Don't add more layers.

## 7.4 Remove the dead `base_url()` / `load_view()` in `config/bootstarp.php`
`StorefrontController::url()` / `respond()` replace them. `grep -rn "base_url(\|load_view(" Website/app Website/core`. If there are no callers, delete both functions. Also drop the trailing `?>` from config files (see 04, root cause 3).

## 7.5 Fix the filename typo `bootstarp.php` → `bootstrap.php`
Two-step: create `bootstrap.php`, and make `bootstarp.php` a one-line `require __DIR__.'/bootstrap.php';` shim. Update the callers (`route.php`, `vayu`, tests), then delete the shim.

## 7.6 Login throttle does a write and three reads per bucket
`Auth::allowAttempt()` runs upsert → select → select again for each key. The second loop re-reads the same values. Keep the first loop's results:
```php
$allowed = true;
foreach ($keys as $key) {
    self::query($sql, [$key, $now, $now - 900, $now - 900]);
    $allowed = $allowed && (int) self::query('SELECT attempts FROM auth_login_limits WHERE bucket_key = ?', [$key])->fetchColumn() <= 5;
}
```
(This still increments both buckets, which is needed so the email and IP limits stay accurate.)

## 7.7 Admin overview `COUNT(*)` on big tables
`overview()` counts every row of `orders`, `products` and `users_tbl` on each dashboard load, and the customer count runs a correlated `NOT EXISTS` over four joins. It's fine now, but once tables pass 100k rows, cache it for 60 s with the existing cache layer (`CACHE_DRIVER`), or keep counters.

## 7.8 Deploy safety
`hostinger-deploy.yml` runs migrations straight against production. The DB backup step already exists in `Tools/deployment/hostinger.py`. Confirm that it **aborts the deploy when the backup fails** before you run fix 02-C on production.

## 7.9 Workflow duplication
`ci.yml` and the `validate` job in `hostinger-deploy.yml` install PHP and run the same tests twice per push. Make deploy depend on CI instead:
```yaml
on:
  workflow_run: { workflows: [CI], types: [completed], branches: [main] }
jobs:
  deploy:
    if: github.event.workflow_run.conclusion == 'success'
```

Skipped: a framework swap, an ORM, or a new test framework. The current custom code is fine once the schema drift is fixed.
