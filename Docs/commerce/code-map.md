# Verified source map

Inspected and refreshed after foundation implementation on 2026-10-05. These references describe existing code, not implemented commerce features. Line anchors are navigation hints; locate the named symbol again after source edits. Read [tasks/README.md](tasks/README.md) to select one task guide, [tracking.md](tracking.md) to confirm its prerequisites, and [implementation-plan.md](implementation-plan.md) for acceptance criteria. Root [AGENTS.md](../../AGENTS.md) remains authoritative for agent workflow.

## Website request path

```text
index.php
  → config/route.php
  → config/bootstarp.php
      → optional Composer + dotenv
      → runtime.php: env.php → config.php → db.php
      → framework.php → ClassLoader + config/classes.php + Helpers.php
           config.php merges app/view.php + api/gateway.php route arrays
  → fixed controller/service class-map autoload
  → RouteManager::dispatch($routes)
  → controller method → BaseController::respond(path, data)
  → render_view → load_view → PHP template
```

| Existing source and exact symbols | Current behavior | Required change / task |
| --- | --- | --- |
| [index.php](../../Website/index.php#L2); [config/route.php](../../Website/config/route.php#L2) | Single web entry; bootstraps then dispatches with fixed class-map autoload | Preserve entry and legacy routes; FND-02 implemented |
| [config/bootstarp.php](../../Website/config/bootstarp.php#L3); [runtime](../../Website/config/runtime.php#L3); [classes](../../Website/config/classes.php#L4) | Shared environment/PDO, explicit helper load and trusted controller/service map; misspelled filename preserved | Register each new controller/service in config/classes.php; FND-01/02 implemented |
| [config/config.php](../../Website/config/config.php#L4) | Defines `__BASEDIR__`, app/Developer constants; merges HTML/API routes into `$routes` | Shared registration/configuration edits belong to coordinator; do not conflate Developer roles with commerce grants |
| [config/env.php](../../Website/config/env.php#L3): env(); [example environment](../../Website/.env.example#L1) | Reads $_ENV, $_SERVER, getenv(), then default; example now selects SQLite explicitly | Keep production/private configuration outside public access (FND-03, OPS-01) |
| [app/view.php](../../Website/app/view.php#L5); [api/gateway.php](../../Website/api/gateway.php#L5); [RouteProvider](../../Website/core/RouteProvider.php#L3) | Current legacy routes remain [class, method]; new route registrations support HTTP-method maps and typed templates | Commerce API surface remains future WEB-03 work |
| [RouteManager](../../Website/core/RouteManager.php#L5): dispatch(), compile(), resolveRoute() | Exact routes precede typed templates; validates encodings/segments, query routes and subdirectory boundaries; API dispatch errors use JSON | FND-02 implemented; new routes must declare method maps and approved parameters |
| [.htaccess](../../Website/.htaccess#L1) | Rewrites missing files/directories to `index.php?route=...`; existing files bypass that rule | Add/verify private-file denial; rewrite alone is not protection for secrets, DB/cache/import files (`FND-03`, `OPS-02`) |
| [BaseController](../../Website/core/BaseController.php#L5): `viewData()`, `respond()`, `apiGet()`, `apiPost()` | Thin helper wrappers; no built-in middleware, ownership guard, automatic view extension, or escaping | Controllers must invoke shared services/access validation and escape output; extend common helpers only as needed |
| [Helpers](../../Website/core/Helpers.php#L4): `view_data()`, `render_view()`, `api_request()`, `api_get()`, `api_post()`; [bootstrap rendering](../../Website/config/bootstarp.php#L10): `load_view()`, `base_url()` | `view_data` merges arrays; render calls `load_view`, which extracts data and includes path relative to `Website/`; HTTP helpers use cURL or streams | Use known template paths only; do not pass user input as a view filename; outbound integrations need explicit timeouts and sanitized failures |
| [Welcome](../../Website/app/bridge/Welcome.php#L6): `index()`, `hello()`; [Developer](../../Website/app/bridge/Developer.php#L23): `login()`, `logout()`, `index()`, `metrics()` | Existing examples of controller rendering and Developer-specific method/session checks; `Welcome::hello()` fetches an external demo API | Preserve existing behavior; do not copy demo HTTP calls into commerce request loops |

**Rendering contract:** the existing call is `$this->respond('/app/page/welcome.php', $data)`. For a new template, use a full Website-relative path such as `/app/page/admin/products/index.php`. `respond('admin/products/index', ...)` does not resolve that path with today's helpers. Proposed admin/storefront files do not exist yet.

**Service contract:** HTML controllers and API handlers call the same PHP business services directly. Do not have a PHP catalog or checkout controller HTTP-call its own `/api/...` routes. That adds network overhead, duplicates authentication, and breaks transaction sharing on the PDO connection.

## Persistence, authentication, workers and CLI

| Existing source and exact symbols | Current behavior | Required change / task |
| --- | --- | --- |
| [config/db.php](../../Website/config/db.php#L4): database_connect(), prepared global helpers | Shared PDO for SQLite/MySQL; SQLite FK/busy timeout; MySQL port/utf8mb4/native prepares; associative fetches | FND-01 implemented; atomic order/job writes use this PDO (ORD-01, INF-01) |
| [MigrationRunner](../../Website/config/migrate.php#L8); [Migration/Schema](../../Website/config/migration.php#L3) | Locked ordered runner; driver-aware unique ledger; fatal registration errors; rerunnable DDL and verification; CLI-safe bootstrap | FND-01 implemented; future migrations require schema verification and recovery checks |
| [UsersTable](../../Website/database/migrations/UsersTable.php#L4); [forward repair](../../Website/database/migrations/0001_users_foundation.php#L6) | Driver-aware users DDL and schema/index verification; no seed passwords; recorded users gain explicit index repair | Existing users/hashes preserved; commerce provisioning remains IAM-01 work |
| [Auth](../../Website/core/Auth.php#L7): `identity()`, `login()`, `logout()`, `register()`, `verifyEmail()`, `resendVerificationEmail()` | Current DB account-state checks, normalized email, persistent bounded throttles, regenerated sessions and identity-only `user_id`; commerce logout preserves Developer keys | FND-03 implemented. Signup defaults disabled; private test verification only before INF-02 queued delivery. Staff grants remain IAM-01/02 work |
| [Auth remember flow](../../Website/core/Auth.php#L144); [token upgrade](../../Website/database/migrations/0002_auth_protections.php) | Hashed selector/validator records, absolute expiry, compare-and-swap rotation, revocation; explicit upgrade revokes legacy raw tokens; SQLite gains a case-insensitive unique email index | FND-03 implemented and checked on SQLite/MySQL including competing restorations; never preserve legacy unlimited lifetimes |
| [DeveloperAccess](../../Website/core/DeveloperAccess.php#L6): `session()`, `csrf()`, `identity()`, `login()`, `provision()`, `PAGES` | Separate `developer_accounts`, session `developer_id`, idle expiry, DB-rechecked enabled/role/grants, persistent login throttling; provisioning creates its own tables | Preserve this boundary and current tests. Commerce staff/customer roles need their own grants; never treat Developer authorization as commerce access (`IAM-01/02`) |
| [Mailer](../../Website/core/Mailer.php#L6): `getMailer()`, `send()` | Default-disabled SMTP adapter with environment configuration, finite deadlines and sanitized failures; no embedded credential or placeholder verification helper | FND-03 removed credential use. Operator rotation remains required; INF-02 integrates queued delivery and provider tests |
| [composer.json](../../Website/composer.json#L2) | Only PHPMailer and dotenv declared; no queue, cache client or commerce framework | Keep dependencies minimal; optional Redis adapter must not become mandatory (`INF-03`) |
| [vayu](../../Website/vayu#L4): development router; [migrate](../../Website/vayu#L35); [developer:create](../../Website/vayu#L52) | Existing run/help/account prompt preserved; shared CLI bootstrap adds php vayu migrate | Queue/retry/maintenance commands remain proposed INF-02/OPS-01 work |
| [DeveloperAccessTest](../../Website/tests/DeveloperAccessTest.php#L2): `check()` | Standalone CLI test uses in-memory SQLite, checks provisioning/auth/CSRF/revocation/throttling | From `Website/`: `php tests/DeveloperAccessTest.php`. Requires PDO SQLite; passing it does not prove MySQL migration, locking, FULLTEXT or commerce behavior |

[Foundation tests](../../Website/tests/FoundationTest.php), [routing tests](../../Website/tests/RoutingTest.php) and [HTTP checks](../../Website/tests/routing_http_test.py) plus [auth security tests](../../Website/tests/AuthSecurityTest.php) and [auth HTTP/Apache checks](../../Website/tests/auth_http_test.py) cover the implemented foundations. `core/Queue.php`, `core/Cache.php`, `app/Services/`, commerce controllers/templates and commerce migration files are **planned output paths**. Inspect again before creating them; this map does not link them as existing implementation.

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
sed -n '144,209p' Website/core/Auth.php
```

For credentials, record only presence and required remediation; never paste values. Re-read the named symbols if line numbers drift. Keep new file names, method signatures, dependency claims and actual verification results synchronized through the coordinator and tracker.
