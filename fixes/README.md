# Sarada Marble Bankura: fix plan

Repo: `Sudipto-tales/sarada_marble_bankura`, branch `main`. All paths below are relative to the repo root.
Analysis date: 2026-11-17. It was done by reading files through the GitHub API at `main`, with no clone.

## Rules for the model applying these fixes

1. Do the files **in number order**. Do not start file N+1 until file N's "Test" passes.
2. After each file, **stop and report to the user**: what you changed, the test output, and whether it passed. Then wait for "next".
3. Change only what the file says. No refactors, renames or formatting changes.
4. If an "old" snippet is not found exactly, stop and ask. Don't guess.
5. Never commit `.env`, `cookie*.txt`, `*.sqlite` or passwords.

## Order

| # | File | Symptom it fixes | Severity |
|---|------|------------------|----------|
| 01 | [01-make-503-visible.md](01-make-503-visible.md) | 503 hides the real error | Do first (diagnostic) |
| 02 | [02-admin-dashboard-503-product-variants.md](02-admin-dashboard-503-product-variants.md) | **503 right after admin login** | Critical |
| 03 | [03-admin-account-and-permissions.md](03-admin-account-and-permissions.md) | Login says invalid credentials / 403 "no staff access" | Critical |
| 04 | [04-403-session-cookie.md](04-403-session-cookie.md) | 403 "Invalid session token" / session not kept | Critical |
| 05 | [05-ci-deploy-broken.md](05-ci-deploy-broken.md) | Every CI and deploy run fails since Nov 15 | High |
| 06 | [06-security-cleanup.md](06-security-cleanup.md) | Cookie jars, DB files and debug script committed | High |
| 07 | [07-optimizations.md](07-optimizations.md) | Code and logic cleanups | Medium |

## How a request works (the login flow)

```
Browser ──► .htaccess ──► index.php ──► config/route.php ──► bootstarp.php (env, config, PDO, autoload)
                                                   │
                                     RouteManager::dispatch
                                                   │
             GET  /admin/login ──► Admin::login() ──► RequestSecurity::session()  (session file in PRIVATE_STORAGE_PATH/sessions)
                                                   └► render login.php with hidden csrf = $_SESSION['commerce_csrf']
             POST /admin/login ──► requireMutation() ── CSRF check ──✗──► 403 "Invalid session token."   (fix 04)
                                   Auth::login() ── password_verify ──✗──► 401 "invalid credentials"    (fix 03)
                                   CommerceAccess::requireActor() ── user_roles → role_permissions
                                   no admin.* permission ──────────────✗──► 403 "no staff access"         (fix 03)
                                   $_SESSION['admin_id'], admin_seen ──► 303 redirect /admin
             GET  /admin ──► Admin::index() ──► elevated() ok
                                   AdminReadService::attention() → SELECT … product_variants ──✗──► 503  (fix 02)
```

Every uncaught `Throwable` becomes **503** in two places, `StorefrontController::handle()` and `index.php`. Neither one logs the error (fix 01).
