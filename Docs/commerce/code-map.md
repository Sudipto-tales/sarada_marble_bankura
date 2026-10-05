# Verified source map

Inspected on 2026-10-05. These references describe existing code, not implemented commerce features. Line anchors are navigation hints; locate the named symbol again after source edits. Read [tasks/README.md](tasks/README.md) to select one task guide, [tracking.md](tracking.md) to confirm its prerequisites, and [implementation-plan.md](implementation-plan.md) for acceptance criteria. Root [AGENTS.md](../../AGENTS.md) remains authoritative for agent workflow.

## Website request path

```text
index.php
  → config/route.php
  → config/bootstarp.php
      → optional Composer + dotenv
      → env.php → config.php → framework.php → db.php → core/*.php
           config.php merges app/view.php + api/gateway.php route arrays
  → explicit Welcome/Developer controller includes
  → RouteManager::dispatch($routes)
  → controller method → BaseController::respond(path, data)
  → render_view → load_view → PHP template
```

| Existing source and exact symbols | Current behavior | Required change / task |
| --- | --- | --- |
| [index.php](../../Website/index.php#L2); [config/route.php](../../Website/config/route.php#L2) | Single web entry; route bootstrap explicitly includes `Welcome` and, when enabled, `Developer` | Preserve entry and legacy routes; load nested controllers deliberately (`FND-02`) |
| [config/bootstarp.php](../../Website/config/bootstarp.php#L4) | Optional vendor/dotenv load, then configuration/PDO and a glob of top-level core files; actual filename is `bootstarp.php` | Preserve filename compatibility; add deterministic service loading and a CLI-safe bootstrap (`FND-01/02`) |
| [config/config.php](../../Website/config/config.php#L4) | Defines `__BASEDIR__`, app/Developer constants; merges HTML/API routes into `$routes` | Shared registration/configuration edits belong to coordinator; do not conflate Developer roles with commerce grants |
| [config/env.php](../../Website/config/env.php#L3): `env()`; [example environment](../../Website/.env.example#L1) | Reads nonempty `$_ENV`, then `$_SERVER`, then `getenv()`, then default; example has DB host/port but no `DB_TYPE` | Document explicit database selection and non-secret configuration; production/private settings belong outside public access (`FND-01`, `OPS-01`) |
| [app/view.php](../../Website/app/view.php#L5): `ViewRouteProvider::routes()`; [api/gateway.php](../../Website/api/gateway.php#L5): `ApiGatewayProvider::routes()`; [RouteProvider](../../Website/core/RouteProvider.php#L3) | Arrays keyed by exact path, values `[class, method]`; public root maps to `default`; API currently only Developer metrics | Introduce method/parameter registration preserving existing arrays and `default` behavior (`FND-02`); commerce API surface is future `WEB-03` work |
| [RouteManager](../../Website/core/RouteManager.php#L5): `dispatch()`, `resolveRoute()` | Uses `?route=` first, otherwise strips script directory from URI; exact lookup, no method/parameter dispatch; unknown path calls the 404 template | Validate route parameters, support methods/405, preserve query routes and subdirectory installation (`FND-02`) |
| [.htaccess](../../Website/.htaccess#L1) | Rewrites missing files/directories to `index.php?route=...`; existing files bypass that rule | Add/verify private-file denial; rewrite alone is not protection for secrets, DB/cache/import files (`FND-03`, `OPS-02`) |
| [BaseController](../../Website/core/BaseController.php#L5): `viewData()`, `respond()`, `apiGet()`, `apiPost()` | Thin helper wrappers; no built-in middleware, ownership guard, automatic view extension, or escaping | Controllers must invoke shared services/access validation and escape output; extend common helpers only as needed |
| [Helpers](../../Website/core/Helpers.php#L4): `view_data()`, `render_view()`, `api_request()`, `api_get()`, `api_post()`; [bootstrap rendering](../../Website/config/bootstarp.php#L25): `load_view()`, `base_url()` | `view_data` merges arrays; render calls `load_view`, which extracts data and includes path relative to `Website/`; HTTP helpers use cURL or streams | Use known template paths only; do not pass user input as a view filename; outbound integrations need explicit timeouts and sanitized failures |
| [Welcome](../../Website/app/bridge/Welcome.php#L6): `index()`, `hello()`; [Developer](../../Website/app/bridge/Developer.php#L23): `login()`, `logout()`, `index()`, `metrics()` | Existing examples of controller rendering and Developer-specific method/session checks; `Welcome::hello()` fetches an external demo API | Preserve existing behavior; do not copy demo HTTP calls into commerce request loops |

**Rendering contract:** the existing call is `$this->respond('/app/page/welcome.php', $data)`. For a new template, use a full Website-relative path such as `/app/page/admin/products/index.php`. `respond('admin/products/index', ...)` does not resolve that path with today's helpers. Proposed admin/storefront files do not exist yet.

**Service contract:** HTML controllers and API handlers call the same PHP business services directly. Do not have a PHP catalog or checkout controller HTTP-call its own `/api/...` routes. That adds network overhead, duplicates authentication, and breaks transaction sharing on the PDO connection.

## Persistence, authentication, workers and CLI

| Existing source and exact symbols | Current behavior | Required change / task |
| --- | --- | --- |
| [config/db.php](../../Website/config/db.php#L2): `$pdo`, `db_query()`, `db_fetch_all()`, `db_fetch_one()`, `db_execute()`, `db_last_insert_id()` | Defaults to SQLite; MySQL DSN includes host/database but omits configured port and charset; prepared helpers use global PDO; exception error mode enabled | Apply port/utf8mb4, enable SQLite foreign keys, agree connection/transaction ownership; all atomic order/job writes use the same PDO (`FND-01`, `ORD-01`, `INF-01`) |
| [config/migrate.php](../../Website/config/migrate.php#L8); [Migration](../../Website/config/migration.php#L3): `up()`, `down()` | Runner creates SQLite-specific ledger, reads applied names, globs files, derives classes, runs `up()` and inserts ledger row; assumes web variables for a base-directory constant | Driver-aware ledger, deterministic ordering/class resolution, CLI bootstrap, interrupted-DDL recovery and rerun checks (`FND-01`) |
| [UsersTable](../../Website/database/migrations/UsersTable.php#L3): `up()`, `down()`, `seed()` | Does not extend `Migration`; SQLite-specific users/index SQL; seed contains usable demo passwords | Repair fresh/upgrade paths without losing existing users; prohibit automatic production demo seeding; use operator-supplied provisioning (`FND-01`, `IAM-01`) |
| [Auth](../../Website/core/Auth.php#L5): `isAuthenticated()`, `login()`, `logout()`, `register()`, `verifyEmail()`, `resendVerificationEmail()` | Customer identities in `users_tbl`; session keys include `user_id`; login verifies password/email but does not enforce status/lockout; registration sends email synchronously; logout clears the shared session | Fix includes/bootstrap, account-state checks, validation/throttling, session regeneration/cookie policy, and bounded notifications; preserve Developer session isolation (`FND-03`, `IAM-01/02`, `WEB-02`) |
| [Auth remember flow](../../Website/core/Auth.php#L176): `setRememberToken()`, `validateRememberToken()` | Persists raw token; expiry exists only on browser cookie; a matching token recreates session without server expiry/status validation | Upgrade token storage for hash/expiry/rotation/revocation and test stale/disabled access (`FND-03`) |
| [DeveloperAccess](../../Website/core/DeveloperAccess.php#L4): `session()`, `csrf()`, `identity()`, `login()`, `provision()`, `PAGES` | Separate `developer_accounts`, session `developer_id`, idle expiry, DB-rechecked enabled/role/grants, persistent login throttling; provisioning creates its own tables | Preserve this boundary and current tests. Commerce staff/customer roles need their own grants; never treat Developer authorization as commerce access (`IAM-01/02`) |
| [Mailer](../../Website/core/Mailer.php#L7): `getMailer()`, `send()`, `VerifyMail()` | PHPMailer transport configuration includes a hardcoded provider credential; `send()` performs synchronous external I/O; verification helper has a placeholder URL | Remove source credential use; add environment-supplied transport settings, configurable verification URL, deadlines and sanitized errors (`INF-02`, `OPS-01`). Operator must rotate/revoke the exposed credential and provision a replacement; do not copy the value into docs, tests or logs |
| [composer.json](../../Website/composer.json#L2) | Only PHPMailer and dotenv declared; no queue, cache client or commerce framework | Keep dependencies minimal; optional Redis adapter must not become mandatory (`INF-03`) |
| [vayu](../../Website/vayu#L4): CLI-server branch; [CLI commands](../../Website/vayu#L32) | Development router restricts private paths and serves approved assets; CLI supports help, `run`, `developer:create`; provision path prompts for an operator password | Coordinator adds `migrate`, bounded queue/retry and maintenance commands. `queue:work` and migration commands are proposed, not currently runnable (`FND-01`, `INF-02`, `OPS-01`) |
| [DeveloperAccessTest](../../Website/tests/DeveloperAccessTest.php#L2): `check()` | Standalone CLI test uses in-memory SQLite, checks provisioning/auth/CSRF/revocation/throttling | From `Website/`: `php tests/DeveloperAccessTest.php`. Requires PDO SQLite; passing it does not prove MySQL migration, locking, FULLTEXT or commerce behavior |

`core/Queue.php`, `core/Cache.php`, `app/Services/`, commerce controllers/templates and commerce migration files are **planned output paths**. Inspect again before creating them; this map does not link them as existing implementation.

## Mobile composition and future API boundary

```text
main → LocalStore.init → AppDependencies.static
  → Static*Repository implementations + controllers
  → MaaSaradaApp → AppScope → screens
  → CartController quantities/pricing → simulated checkout
  → StaticOrderRepository.place → LocalStore JSON
```

| Existing source and exact symbols | Current behavior | Required change / task |
| --- | --- | --- |
| [main](../../Mobile/lib/main.dart#L8): `main()`; [app](../../Mobile/lib/app.dart#L10): `MaaSaradaApp` | Initializes local store and static dependencies; wraps app in scope with theme/routing | Preserve offline default throughout Website MVP |
| [app_scope](../../Mobile/lib/core/state/app_scope.dart#L17): `AppDependencies.static()`, `AppScope.of()`, `AppScope.read()` | Composition root supplies repository interfaces and state controllers; brand catalog repository remains bundled | Future API adapters are selected here behind an explicit flag; constructor/controller assumptions also need review (`MOB-01`) |
| [repository interfaces](../../Mobile/lib/data/repositories/repositories.dart#L18): `ProductRepository`, `OrderRepository`, `UserRepository` | Async contracts include product queries, order placement/cancel/return and user/address actions; `OrderRepository.place(Order)` accepts a fully formed local order | Design contract adaptation before networking; server creates authoritative totals/status/IDs rather than trusting this client object (`WEB-03`, `MOB-01`) |
| [static repositories](../../Mobile/lib/data/repositories/static_repositories.dart#L32): `StaticProductRepository`; [local orders](../../Mobile/lib/data/repositories/static_repositories.dart#L180): `StaticOrderRepository` | Products come from bundled lists with simulated delays; orders seed demo data when local list is empty and persist placement/cancel/return locally | No remote order/stock guarantees; do not upload demo orders as customer purchase history |
| [local store](../../Mobile/lib/core/store/local_store.dart#L12): `LocalStore.init()`, `read()`, `readList()`, `write()` | JSON file persistence with in-memory fallback; corrupt data resets local map; delayed flush | Remains prototype persistence; not a production token vault or server identity boundary |
| [cart controller](../../Mobile/lib/core/state/cart_controller.dart#L11): `CartController.add()`, `setQuantity()`, `updateSqFt()`, `lineTotal()`, `total` | Product-ID lines merge locally; quantities and totals use `double`; local config controls tax/delivery | Future adapter needs variant identity, explicit units and server quotes; demo floating-point totals/tax constants are not backend money rules |
| [cart item](../../Mobile/lib/data/models/cart_item.dart#L2): `CartItem`; [order model](../../Mobile/lib/data/models/order.dart#L4): `OrderStatus`, `OrderEvent`, `PaymentMethod`, `Order` | Cart records product ID/area; order contains client totals/address/timeline and permissive JSON fallbacks | Define a server-to-client mapping and unknown-state behavior; server state machine and ownership remain authoritative |
| [payment screen](../../Mobile/lib/features/checkout/payment_screen.dart#L45): `_placeOrder()`; [flags](../../Mobile/lib/core/config/feature_flags.dart#L4): `FeatureFlags` | Constructs local order and invokes local repository; payment is explicitly simulated; current flags do not provide an API backend switch | Native auth/token lifecycle, server checkout idempotency and offline/API separation are deferred `MOB-01` work; real payment integration is separate `OPT-04` |
| [repository tests](../../Mobile/test/repositories_test.dart#L1); [cart tests](../../Mobile/test/cart_pricing_test.dart#L1); [module boundaries](../../Mobile/test/module_boundaries_test.dart#L1) | Existing local behavior checks | Run relevant Flutter regressions when `MOB-01` is authorized; Website planning does not modify Flutter |

## Bounded source-reading recipes

Run from repository root. Read the selected task's source list first; avoid dumping the whole repository or environment/credential files.

```bash
rg --files Website/core Website/config Website/database Website/tests
rg -n 'function (dispatch|resolveRoute)|class RouteManager' Website/core/RouteManager.php
sed -n '1,85p' Website/core/RouteManager.php
rg -n 'function (respond|viewData)|function (render_view|load_view)' Website/core/BaseController.php Website/core/Helpers.php Website/config/bootstarp.php
rg -n 'class (AppDependencies|CartController)|abstract class (ProductRepository|OrderRepository)' Mobile/lib/core/state Mobile/lib/data/repositories/repositories.dart
sed -n '176,215p' Website/core/Auth.php
```

For credentials, record only presence and required remediation; never paste values. Re-read the named symbols if line numbers drift. Keep new file names, method signatures, dependency claims and actual verification results synchronized through the coordinator and tracker.
