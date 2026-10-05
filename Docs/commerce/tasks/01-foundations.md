# Hosting, PDO, migrations and routing

Scope: HST-01, FND-01, FND-02 only. Read [contracts](../contracts.md) and the selected tracker row. Do not start product/UI modules to hide missing framework prerequisites.

## HST-01

Existing references: [env()](../../../Website/config/env.php#L3), [database configuration](../../../Website/config/db.php#L2), [CLI command dispatcher](../../../Website/vayu#L29), [release process](../../releases.md).

Create a capability record under `Website/docs/` during implementation, separating local observations from account observations. Collect PHP version/architecture, PDO drivers, CLI path, DB version/engine, charset/collation, cron minimum and timeout, document root/rewrite, private writable path, outbound connection policy, SMTP configuration and optional Redis extension. Redact connection/password values. Test capabilities in a disposable database, not with a production schema reset.

Algorithm: gather available settings → mark each capability observed/unverified → select MySQL locking path and file-cache baseline → calculate worker deadline below measured PHP limit → document unsupported optional features. If account access is absent, keep HST-01 open while FND-01/02 proceed locally. Do not infer Hostinger plan limits from a user-count table.

## FND-01

### Open these functions first

- [PDO driver switch](../../../Website/config/db.php#L4), `db_query()` at [line 36](../../../Website/config/db.php#L36).
- [Migration runner](../../../Website/config/migrate.php#L8), `Migration` at [line 3](../../../Website/config/migration.php#L3).
- [UsersTable.up()](../../../Website/database/migrations/UsersTable.php#L12), existing seed method in that file.
- [vayu command branch](../../../Website/vayu#L29), [CLI developer provisioning](../../../Website/vayu#L35), [bootstrap](../../../Website/config/bootstarp.php#L14).

### Edit boundary and algorithm

Edit `config/db.php`, `config/migrate.php`, `database/migrations/UsersTable.php`, `vayu`, and agreed CLI bootstrap files. Introduce upgrade migrations only when needed; never silently change what an already-recorded migration means for an existing DB.

1. Build one CLI bootstrap that loads dotenv/env/config/PDO without HTTP dispatch, redirects, request-required data, or HTML output. Keep existing `run` and `developer:create` behavior.
2. For MySQL honor `DB_HOST`, `DB_PORT`, `DB_DATABASE`, utf8mb4, exception mode, associative fetches and native prepared statements. For SQLite enable `PRAGMA foreign_keys = ON` and a bounded busy timeout. Report unsupported commerce DB drivers explicitly.
3. Driver-select ledger/user DDL: MySQL `AUTO_INCREMENT`, index creation compatible with target version; SQLite `INTEGER PRIMARY KEY AUTOINCREMENT`. Existing user ID type is the FK reference type; do not choose incompatible unsigned child columns.
4. Acquire a migration-runner lock (MySQL connection-level named lock with bounded wait; local file lock for SQLite). Release in `finally`. This is for deployment migration serialization, not commerce stock.
5. Preserve existing `UsersTable` ledger identity; run that legacy migration first when absent. New files use ordered numeric prefixes and distinct class names derived predictably from filename; fail the run if a class/file is missing or duplicated.
6. For each pending migration: inspect required schema → perform only missing compatible changes → verify expected tables/columns/indexes → insert unique ledger row after success. On failure stop with nonzero exit, safe context, and recovery instructions.
7. Do not seed known passwords automatically. Separate disposable test fixtures from operator provisioning.

MySQL DDL is not a reliable transaction rollback boundary. Each migration must be rerunnable after a partially completed DDL sequence; an existing table check alone is insufficient if its indexes/columns are missing. See the official [implicit-commit reference](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html). Verify behavior on the actual target version.

### Evidence required

Fresh SQLite; existing SQLite with `UsersTable` ledger/data; fresh disposable MySQL; repeated migrate; failed midway then repaired/rerun; two concurrent runners; incompatible schema stops instead of reporting success. Existing identities/password hashes remain intact. Record versions, commands and actual outcomes. `php vayu migrate` is a proposed command until this task is implemented.

## FND-02

### Existing references

- [RouteManager.dispatch()](../../../Website/core/RouteManager.php#L5), [resolveRoute()](../../../Website/core/RouteManager.php#L31).
- [View routes](../../../Website/app/view.php#L5), [API routes](../../../Website/api/gateway.php#L5), [merged routes](../../../Website/config/config.php#L24).
- [Explicit controller includes](../../../Website/config/route.php#L3), [BaseController.respond()](../../../Website/core/BaseController.php#L10).

### Route representation

Keep legacy entries `[ControllerClass, method]`. New entries use a method map at a trusted route key: `product/{slug}` → `GET: [StorefrontProductController, show]`; `api/v1/cart` → `GET: read`, `POST: change`. This is specification notation, not an existing API. Coordinator updates both route providers and dispatcher consistently.

### Matching algorithm

1. Resolve path from existing query-route mode or request URI, remove the deployment base only at a complete path-segment boundary (`/shop` must not strip `/shopper`), and normalize `/` to `default`.
2. Reject malformed escapes, encoded separators/NUL, dot-segment/traversal and oversized paths rather than translating them into controller names.
3. Check exact route first. For templates, escape literal segments and replace only registered `{slug}`/`{id}` with approved captures. Anchor both ends. Reject duplicate/ambiguous templates at registration.
4. If a path matches but no handler permits the method, return 405 and `Allow`; unknown paths return 404. For API 404/405 return structured JSON, not an HTML page.
5. Match typed parameters (positive ID/limited slug); pass values in declared order, not request-selected PHP named argument keys. Do not assume the current zero-argument dispatch already supports them.
6. Resolve a registered class through a fixed class-to-file map with globally unique names. Register service classes through an equally explicit loader or agreed Composer autoload map. Never require a file based on the raw URL.
7. Call the controller; preserve legacy controller-level method guards. Do not widen Developer methods merely to make new route registration simpler.

Checks: `/`, existing Welcome/Developer routes, disabled Developer flag, `/product/valid-slug`, bad ID/slug, unknown route, wrong method, query-route mode, root/subdirectory deployment, `/shopper` prefix collision, duplicate template rejection and identical controller short names in different folders. New view arguments must be full `app/page/...php` paths.
