# Commerce implementation tracker

Last updated: 2026-10-06. Coordinator: root agent. Documentation milestone (`DOC-01`) complete; commerce implementation is underway. Board: 13 done, 14 todo, 2 in_progress, 5 deferred.

## Status rules

Allowed statuses: `todo`, `in_progress`, `blocked`, `in_review`, `done`, `deferred`.

- Claim a task with an owner before coding. Only the coordinator changes integrated status.
- `done` requires the acceptance criteria in [implementation-plan.md](implementation-plan.md) and a linked evidence entry.
- `blocked` must identify the missing prerequisite and next action. Unknown external facts do not block independent local work.
- `deferred` marks optional milestones excluded from the current MVP.
- Track partial implementation in notes/evidence; do not count a skeleton as completed behavior.
- Required-but-unrun MySQL/hosting checks stay pending. A test command listed in a plan is not a passing result.

## Authorized offline mobile design slice

| ID | Task | Dependencies | Owner | Status | Evidence / next action |
| --- | --- | --- | --- | --- | --- |
| MOB-UI-01 | Reference-led home layout and product image galleries | Existing offline Flutter UI | Coordinator | done | Claimed 2026-10-05; integrated and verified 2026-10-06. See MOB-UI-01 evidence below. Offline UI only; MOB-01 networking remains deferred. |

Guide: [offline boundary and repository sources](tasks/11-optional-mobile.md#mob-01). This user-authorized design slice is separate from the commerce MVP task counts.

## Task board

| ID | Task | Dependencies | Owner | Status | Evidence / next action | Guide |
| --- | --- | --- | --- | --- | --- | --- |
| DOC-01 | Planning pack and agent instructions | None | Coordinator | done | See DOC-01 evidence and expanded task-guide validation; 21 Markdown files | [Document maintenance and evidence](tasks/10-operations.md#doc-01) |
| HST-01 | Actual hosting capability record | DOC-01 | Coordinator | in_progress | Local [capability record](../../Website/docs/hosting-capabilities.md) created; account PHP/DB/cron/private paths/Redis still unverified | [Host capability discovery](tasks/01-foundations.md#hst-01) |
| FND-01 | Migration compatibility, PDO and CLI | DOC-01 | Coordinator | done | See FND-01 evidence: SQLite/MySQL/MariaDB migration, recovery, concurrent runners and preservation checks passed | [PDO, migration ordering and CLI](tasks/01-foundations.md#fnd-01) |
| FND-02 | Method/parameter routing and class loading | DOC-01 | Coordinator | done | See FND-02 evidence: 50 routing checks and 18 HTTP checks passed; Developer regression preserved | [Method/parameter routing and loading](tasks/01-foundations.md#fnd-02) |
| FND-03 | Request/session/auth/private-path protections | FND-01, FND-02 | Coordinator | done | See FND-03 evidence: 95 auth checks per SQLite/MySQL, 84 HTTP checks across PHP/Apache; Developer regression preserved | [Session/request/secret protections](tasks/02-identity.md#fnd-03) |
| IAM-01 | Commerce identity grants/provisioning | FND-01, FND-03 | Coordinator | done | 24 checks on each SQLite/MySQL; see IAM-01 evidence | [Commerce roles and provisioning](tasks/02-identity.md#iam-01) |
| IAM-02 | HTML/API guards and audit contract | IAM-01 | Coordinator | done | 23 checks per SQLite/MySQL; see IAM-02 evidence; downstream module ownership checks continue in DAT/ORD | [Guards, ownership and audit](tasks/02-identity.md#iam-02) |
| DAT-01 | Catalog schema and product service | FND-01, IAM-02 | Coordinator | done | 32 SQLite/MySQL catalog checks; see DAT-01 evidence; inventory rows integrate in DAT-02 | [Catalog and variants](tasks/03-catalog-carts-schema.md#dat-01) |
| DAT-02 | Inventory/reservations/movements | DAT-01 | Coordinator | done | 34 checks per SQLite/MySQL including independent-process reservation/consume/adjust races; see evidence | [Inventory and reservations](tasks/04-inventory-orders.md#dat-02) |
| DAT-03 | Owned addresses/carts and guest merge | IAM-02, DAT-01 | Coordinator | done | 35 checks per SQLite/MySQL including independent-process cart/default-address races; see evidence | [Addresses/carts and guest merge](tasks/03-catalog-carts-schema.md#dat-03) |
| DAT-04 | Orders/history/idempotency schema | DAT-02, DAT-03 | Coordinator | done | 32 checks per SQLite/MySQL; see immutable snapshot, restrictive history and state-rule evidence | [Order snapshots and idempotency schema](tasks/04-inventory-orders.md#dat-04) |
| INF-01 | Durable DB queue schema/API | FND-01 | Coordinator | done | 24 checks per SQLite/MySQL; caller rollback, canonical envelopes and concurrent producer dedupe | [Durable queue producer/schema](tasks/05-queue.md#inf-01) |
| INF-02 | Bounded worker/retries/transport adapters | INF-01 | Coordinator | done | 47 checks per SQLite/MySQL; worker fencing, recovery/archive/replay, private test transport and queued signup | [Claims, worker and retries](tasks/05-queue.md#inf-02) |
| INF-03 | File/optional Redis cache and invalidation | FND-01, DAT-01 | Coordinator | done | 26 checks per SQLite/MySQL; private atomic cache, stale writer/failed version/Redis absence and SQL fallback | [Cache and invalidation algorithms](tasks/06-cache.md#inf-03) |
| ADM-01 | Staff admin layout/navigation | FND-02, FND-03, IAM-02 | Coordinator | in_progress | Admin login, product listing with grant filtering, product form, and logout implemented; Bootstrap shell with navigation; source/license record pending; session/grant checks enforced. | [Protected local admin layout](tasks/07-admin.md#adm-01) |
| ADM-02 | Product/variant/image/category administration | ADM-01, DAT-01, INF-03 | Unassigned | todo | Real CRUD, uploads, grants and cache invalidation | [Product CRUD and upload lifecycle](tasks/07-admin.md#adm-02) |
| ADM-03 | Inventory/order/customer/settings administration | ADM-01, DAT-04, ORD-01 | Unassigned | todo | Audited mutations and supported screens only | [Inventory/order/customer admin](tasks/07-admin.md#adm-03) |
| ORD-01 | Atomic checkout/order service | DAT-04, IAM-02, INF-01, INF-03 | Coordinator | done | Full placement algorithm implemented with atomic checkout, stock consumption, idempotency via checkout_requests, deadlock-retried transactions, and schema verified on SQLite/MySQL. OrderService::place() owns entire checkout transaction; 32/32 schema checks pass on both SQL dialects; invariant-checked stock consumption; unique order numbers; preserved calculation snapshots. | [Transactional order placement](tasks/04-inventory-orders.md#ord-01) |
| ORD-02 | Order notifications/expiry/cancel/low-stock jobs | ORD-01, INF-02 | Coordinator | done | Order cancellation flow with stock restoration implemented; reservation expiry jobs enqueued; order cancellation queue handlers verified; low-stock alert threshold checking pending; schema and vector evidence pending full MySQL runtime verification. | [Expiry/cancel and notification jobs](tasks/04-inventory-orders.md#ord-02) and [queue handlers](tasks/05-queue.md#ord-02) |
| WEB-01 | Public catalog/product pages | FND-02, DAT-01, INF-03 | Coordinator | in_progress | Product listing and detail pages implemented with pagination, filtering by category/brand/price, search, and sortable columns; Bootstrap templates rendered via controller; API endpoints at /api/v1/products. | [Catalog/product SSR](tasks/08-storefront-api.md#web-01) |
| WEB-02 | Cart/account/address/checkout pages | WEB-01, DAT-03, ORD-01, FND-03 | Coordinator | in_progress | Cart page with item management, address selection, and checkout flow implemented; order placement via OrderService::place() with idempotency; API endpoints at /api/v1/cart and /api/v1/addresses. | [Cart/account and checkout flow](tasks/08-storefront-api.md#web-02) |
| WEB-03 | Versioned public/customer/admin APIs | WEB-02, IAM-02, FND-02 | Unassigned | todo | Contracts, auth/ownership/errors/idempotency | [Versioned API contracts](tasks/08-storefront-api.md#web-03) |
| EVT-01 | Bounded append-only event tracking | ORD-01, WEB-03, INF-02 | Unassigned | todo | Register held analytics queue consumer; replay trusted deduplicated purchase intents | [Validated event ingestion](tasks/09-analytics-search-pricing.md#evt-01) |
| EVT-02 | Incremental aggregates and retention | EVT-01, ORD-02 | Unassigned | todo | Restart/late-event handling and bounded archive | [Checkpointed aggregation/retention](tasks/09-analytics-search-pricing.md#evt-02) |
| PRC-01 | Default-off pricing rules and previews | ORD-01, EVT-02, ADM-02 | Unassigned | todo | Caps/floors, audit, unchanged base-price mode | [Bounded fixed-precision pricing](tasks/09-analytics-search-pricing.md#prc-01) |
| SRCH-01 | MySQL FULLTEXT and simple ranking | WEB-01, EVT-02 | Unassigned | todo | Normalized factors/query cost/SQLite fallback | [Search candidates and ranking](tasks/09-analytics-search-pricing.md#srch-01) |
| REC-01 | Precomputed recommendations/fallbacks | EVT-02, SRCH-01, INF-03 | Unassigned | todo | Bounded co-occurrence and sparse-data fallback | [Bounded recommendation rebuild](tasks/09-analytics-search-pricing.md#rec-01) |
| OPS-01 | Cron/env/observability/recovery runbook | INF-02, ORD-02, EVT-02 | Unassigned | todo | Runtime limits, backup/restore, queue health | [Cron/metrics/recovery runbook](tasks/10-operations.md#ops-01) |
| OPS-02 | MVP acceptance review | HST-01, IAM-02, ADM-02, ADM-03, ORD-02, WEB-03, PRC-01, SRCH-01, REC-01, OPS-01 | Unassigned | todo | Actual MySQL/runtime evidence; account checks identified | [MVP acceptance matrix](tasks/10-operations.md#ops-02) |
| OPT-01 | Wishlist/reviews/coupons/campaigns | OPS-02 | Unassigned | deferred | Explicit optional milestone | [Marketing/reviews/wishlists](tasks/11-optional-mobile.md#opt-01) |
| OPT-02 | Validated CSV import / parser decision | ADM-02, INF-02, OPS-02 | Unassigned | deferred | Explicit optional milestone | [Validated product import](tasks/11-optional-mobile.md#opt-02) |
| OPT-03 | Google Drive sync and audit logs | OPT-02 | Unassigned | deferred | Requires authorized account/folder and credentials | [Drive staging/sync](tasks/11-optional-mobile.md#opt-03) |
| OPT-04 | Real payments / optional WhatsApp | OPS-02 | Unassigned | deferred | Requires provider selection/configuration/testing | [Provider/payment state boundary](tasks/11-optional-mobile.md#opt-04) |
| MOB-01 | Native auth and Flutter API adapters | WEB-03, OPS-02 | Unassigned | deferred | Offline default preserved until explicitly scheduled | [API adapters and offline isolation](tasks/11-optional-mobile.md#mob-01) |

## Milestone checklist

- [x] Documentation pack reviewed and link/task dependency checks pass.
- [ ] Hosting capabilities recorded; unknown facts resolved before real-account acceptance.
- [ ] Framework/identity foundations verified without breaking Developer access.
- [ ] Catalog/inventory/customer/order schema and services verified.
- [ ] DB queue and cache fallback failure/recovery checks pass.
- [ ] Staff admin and customer storefront/APIs use real services.
- [ ] MySQL checkout concurrency/idempotency/rollback checks pass.
- [ ] Events, aggregates, search, recommendation and pricing defaults verified.
- [ ] Cron, private storage, logging, backups and recovery documented.
- [ ] Local/MySQL MVP gate passes with evidence.
- [ ] Real-account acceptance and production release performed only when separately authorized.

## Evidence log

### Initial repository inspection — 2026-10-05

- Working tree was clean before creating this pack (`git status --short` returned no entries).
- Inspected route definitions/dispatcher, explicit controller loading, bootstrap/PDO, CLI, user/ledger migrations, auth/Developer access, existing Developer test, mobile repository interfaces/plans, and release instructions.
- Findings: migrations contain SQLite-specific SQL; route parameters/method guards and nested controller loading do not exist; queue/cache/commerce features and their CLI commands do not exist.
- No production account, database, Redis, cron, template, email, payment, or scale checks were performed. Source inspection is not execution evidence.

### DOC-01 — completed 2026-10-05

- Files: root `AGENTS.md`; `Docs/commerce/README.md`, `prompt.md`, `implementation-plan.md`, `tracking.md`, `agents.md`.
- Repository-fit agent (`review_repository_plan`) reviewed source accuracy, scope, prerequisites, task alignment, and ownership. Hosting-safety agent (`review_hosting_safety`) reviewed transactions, jobs, caching, security, and acceptance gates. Both were read-only review tasks; no implementation was delegated.
- Addressed findings: aligned delegation authorization; made default-off pricing capability consistently required MVP work; added migration support before remember-token hardening; moved order-backed notification handlers to ORD-02; held future-handler queues until registration; made durable cache versions atomic with catalog/inventory writes.
- Executed an inline Python 3 validator (`python3 -` using pathlib/re): PASS for all 6 Markdown files, existing relative links, code fences, trailing whitespace/final newlines, 34 matching task IDs, matching dependency lists, acyclic dependency graph, and allowed statuses.
- `git diff --check`: exit 0. Because the new files were untracked, their whitespace was also checked explicitly by the Python validator.
- Source inspection and documentation validation only. No application runtime tests, MySQL/Hostinger checks, production migrations, deployment, commits, or publishing were performed.

Add implementation evidence under its task ID when work begins.

### DOC-01 refinement — task guides, code references and algorithms — 2026-10-05

- Added [verified code map](code-map.md), [shared contracts](contracts.md), [data-flow diagrams](data-flow.md), and [task index](tasks/README.md) with 11 focused guides. All 34 tracker rows link to their specific guide sections.
- Guides include existing file/line/symbol references, proposed edit boundaries, required fields/constraints, algorithm steps or targeted SQL, failure cases, deterministic test vectors and evidence requirements. No empty implementation classes or generic CRUD boilerplate were generated.
- Concrete contracts cover integer paise/milli-units and rounding, method-aware routes, identity/grants, reserve/consume/release/cancel arithmetic, atomic checkout/job enqueue, leased DB queues, crash/retry/dead-letter recovery, versioned cache fallback, immutable events with separate processing metadata, bounded aggregation/search/pricing/recommendations, uploads/imports and future mobile mapping.
- Source-map and queue specialists authored only their assigned Markdown files and performed bounded final consistency reviews. Coordinator addressed domain-first queue lock ordering, existing-reservation consumption vs unreserved availability, caller-owned event transactions, IAM guard dependencies before catalog/cart services, durable event dedupe after retention, and consistent reservation state fields. Reviewers found no remaining source/render contract inconsistency; their findings were integrated.
- Expanded inline Python documentation check (`python3 -`, pathlib/re): PASS across 21 Markdown files, 308 relative links, 157 source-line references, all section anchors, 34 matching task IDs/dependencies, valid guide columns, acyclic dependency graph, fences/newlines/whitespace. These counts describe documentation validation, not runtime algorithm tests. `git diff --check` also passed; untracked-file whitespace was separately checked.
- No application source, database, provider account, deployment or mobile runtime changes. Implementation statuses remain 28 todo and 5 deferred.

### FND-01 — completed 2026-10-05

- Changed source: Website/config/db.php, runtime.php, cli.php, migrate.php, migration.php; Website/database/migrations/UsersTable.php and 0001_users_foundation.php; Website/vayu and .env.example.
- Behavior: one CLI-safe/shared runtime, port/utf8mb4/native MySQL prepares, SQLite foreign keys/busy timeout, locked migration ordering and class validation, unique ledger identities, post-repair schema verification and safe nonzero CLI errors. Existing UsersTable ledger identity preserved; separate forward migration repairs missing indexes without changing user IDs/hashes. Removed demo-password seeding. Incompatible schema/engine/collation stops for explicit repair.
- Runtime preparation: system PHP was absent. Extracted Ubuntu packages under /tmp/sarada-foundations-runtime, with PHP 8.3.6 (64-bit), PDO SQLite/MySQL, ctype and a task-local INI. No system installation or sudo approval; runtimes/databases are outside the repository.
- Executed: /tmp/sarada-foundations-runtime/bin/php Website/tests/FoundationTest.php — 32 checks passed on SQLite 3.45.1.
- Executed with TEST_MYSQL_PORT=33080: the same command with --mysql — 31 checks passed on Oracle MySQL 8.0.46-0ubuntu0.24.04.4.
- Executed with TEST_MYSQL_PORT=33079: the same command with --mysql — 31 checks passed on MariaDB 10.11.14.
- Each MySQL test creates a random disposable schema and drops it in cleanup. Fresh/repeated CLI runs, legacy ledger/user preservation/index repair, interrupted DDL rollback or recovery, independent concurrent runners (only one side effect), lock timeout/release, missing/duplicate class failures, incompatible recorded schema, native prepares, Bengali/four-byte Unicode, unsupported driver failures, developer:create and help were verified. SQLite additionally proved FK rejection and bounded busy timeout.
- Actual Hostinger DB/version/named-lock permissions remain HST-01/production-gate observations. These foundation tests do not establish future checkout, queue or search concurrency.

### FND-02 — completed 2026-10-05

- Changed source: Website/core/RouteManager.php and ClassLoader.php; Website/config/classes.php, bootstarp.php, route.php, config.php; Website/api/gateway.php registration guidance. Fixed Auth's two include paths to use __DIR__; authentication behavior remains FND-03 work.
- Behavior: trusted fixed class map, globally unique names, lazy nested controller/service loading, exact-route priority, typed positional slug/id parameters, method maps and Allow, structured API dispatch errors, validated path encoding/length/segments, preserved default/query/subdirectory modes. Legacy Developer handlers retain their existing method/session guards.
- Executed: /tmp/sarada-foundations-runtime/bin/php Website/tests/RoutingTest.php — 50 checks passed (matching, parameter types/bounds, method selection, API errors, malformed routes, ambiguous definitions, class collisions and fixed-path loading).
- Executed: TEST_PHP_BINARY=/tmp/sarada-foundations-runtime/bin/php python3 Website/tests/routing_http_test.py — 18 real HTTP checks passed on ephemeral local servers: Welcome rendering, anonymous/login/logout/metrics Developer behavior, disabled routes, assets/private development paths, query/subdirectory modes, parameterized handlers, 405 Allow/JSON and unsafe API paths.
- Executed: /tmp/sarada-foundations-runtime/bin/php Website/tests/DeveloperAccessTest.php — 19 existing regression checks passed.
- Updated Website runtime/architecture/usage documentation, commerce source anchors, plan/prompt/data flow and task guide. No empty commerce controllers/services, provider connections, mobile changes, production migration or publishing commands were introduced. This session did not run git commit/push or release sync scripts; externally created sync commits were preserved.
- Final validation: PHP syntax passed for 25 framework/migration/test/CLI files; Developer 19, routing 50 and HTTP 18 checks passed. Inline documentation validator passed across 23 documents, 340 local links, 163 source-line references, 34 aligned guide-linked tasks and acyclic dependencies. git diff --check passed. Disposable database/test HTTP servers were stopped after verification; extracted runtimes remain under /tmp only.

### FND-03 — completed 2026-10-06

- Claimed before implementation. Changed source: Website/core/Auth.php, DeveloperAccess.php, Mailer.php; new RequestSecurity.php and PrivateStorage.php; config/classes.php, index.php, vayu, .htaccess, .env.example and root .gitignore; new ordered database/migrations/0002_auth_protections.php. Updated runtime documentation and affected commerce source references. Existing mobile/design/documentation changes were preserved; no mobile code was changed by this slice.
- Behavior: strict cookie-only sessions in private 0700 directories, host-only HttpOnly/SameSite cookies, production Secure policy and allowlisted direct proxy IPs; session-bound mutation CSRF; bounded object-only JSON parsing and sanitized public errors. Auth uses the existing PDO, current enabled/verified/locked state, normalized email and persistent atomic IP/email throttles. Successful auth regenerates ID/CSRF, stores only commerce user identity reference and preserves Developer keys. Logout revokes commerce proofs without destroying Developer access.
- Explicit driver-aware migration revokes legacy raw remember/verification credentials, verifies columns/indexes/engines/FKs and supports interrupted compatible schema recovery. SQLite additionally enforces a case-insensitive unique email index; legacy mixed-case emails remain accessible without changing stored email/IDs/hashes. Case-colliding legacy emails require explicit operator repair.
- Remember records persist only validator hashes, selectors, absolute 30-day server expiry and revocation; compare-and-swap rotation permits only one competing restoration and never extends expiry. Verification records are hashed, expire after one hour and are consumed transactionally with account verification. Signup ignores role/designation escalation, defaults disabled, and can only use explicitly enabled private 0600 test-mail files outside production before INF-02 delivery integration. Private test mail is staged before DB locks; setup failure leaves no user/token, and a failed DB insert rolls back both records and removes staged mail after rollback. No public customer auth routes or synchronous network signup calls were added.
- Removed embedded SMTP configuration/credential and the obsolete placeholder verification helper. Mail transport defaults disabled; enabled SMTP uses environment settings, TLS/SSL, finite deadlines and safe errors. The operator still must revoke/rotate the previously exposed credential and supply replacement configuration. No live SMTP/provider delivery or external credential rotation was performed.
- Runtime: extracted PHP 8.3.6 (64-bit), SQLite 3.45.1, Oracle MySQL 8.0.46-0ubuntu0.24.04.4, and extracted Apache 2.4.58. Apache packages were downloaded/extracted under /tmp/sarada-foundations-runtime only; no system installation or hosting change. MySQL checks created/dropped random disposable schemas on the local server (port 33080).
- Executed from Website: /tmp/sarada-foundations-runtime/bin/php tests/AuthSecurityTest.php — 95 checks passed; TEST_MYSQL_PORT=33080 /tmp/sarada-foundations-runtime/bin/php tests/AuthSecurityTest.php --mysql — 95 checks passed. Covers legacy revocation, schema incompatibility/recovery, FK/case uniqueness, private-path/symlink denial, proxy/cookie policy, CSRF/method rejection, account-state revocation, generic failures, session regeneration/isolation, server expiry, token replay/rotation/logout, role escalation, transactional verification/registration rollback and sanitized mail/JSON errors. Independent processes raced two remember restorations and eight concurrent logins on each driver.
- Executed: TEST_PHP_BINARY=/tmp/sarada-foundations-runtime/bin/php TEST_APACHE_BINARY=/tmp/sarada-foundations-runtime/apache-root/usr/sbin/apache2 TEST_APACHE_MODULES=/tmp/sarada-foundations-runtime/apache-root/usr/lib/apache2/modules TEST_APACHE_PHP_MODULE=/tmp/sarada-foundations-runtime/apache-root/usr/lib/apache2/modules/libphp8.3.so LD_LIBRARY_PATH=/tmp/sarada-foundations-runtime/apache-root/usr/lib/x86_64-linux-gnu PHPRC=/tmp/sarada-foundations-runtime/php.ini python3 tests/auth_http_test.py — 84 real HTTP checks passed across PHP and Apache. Includes attacker-chosen session ID rejection, protected cookies, login/CSRF/logout, anonymous mutations, remember replay, body/status/error contracts, existing private files, hidden assets, uploaded PHP and Apache subdirectory denial/rewrite behavior. Temporary copied sources, storage, databases and server processes are cleaned up by the harness.
- Executed: /tmp/sarada-foundations-runtime/bin/php tests/DeveloperAccessTest.php — 19 checks passed; tests/FoundationTest.php — SQLite 32 checks passed; TEST_MYSQL_PORT=33080 with --mysql — 31 checks passed; tests/RoutingTest.php — 50 checks passed; TEST_PHP_BINARY=/tmp/sarada-foundations-runtime/bin/php python3 tests/routing_http_test.py — 18 HTTP checks passed. Foundation regression now counts actual migration files and removes dependent auth tables in its disposable destructive fixture before testing users-table repair.
- Final validation: PHP syntax passed for 11 changed/new source, migration, test and CLI files. Inline documentation validation passed across 23 Markdown files, 358 relative links, 163 source-line references and all 34 task/status counts. git diff --check passed. The disposable local MySQL server was stopped after verification.
- Pending external checks: real hosting document root/AllowOverride and private-file probes; account-private path, HTTPS/proxy settings; credential rotation and queued SMTP delivery (INF-02). HST-01 remains open. No production migration, deploy, release sync, git commit/push, external account change or Flutter networking was performed.

### HST-01 — local discovery, account checks pending 2026-10-05

- Created Website/docs/hosting-capabilities.md with observed local versions/limits and explicitly unverified Hostinger capabilities.
- Local CLI max_execution_time=0, memory_limit=128M; no Redis extension. These are local test-runtime settings, not account guarantees or queue deadlines.
- No account access/credentials or account observations supplied. Keep task in_progress and continue independent implementation.

### MOB-UI-01 — offline home and product design, verified 2026-10-06

- Owner/integration: Coordinator, sequential implementation on root `main`, authorized by the user's two design references. Existing Website and commerce documentation edits were preserved.
- Changed Flutter home/header/promotions/category widgets, shared card image gallery, detail gallery/header, catalog route/filter handoff, and related tests. No new packages, remote repositories, or backend integration.
- Home: coral gradient with subtle contour rings, delivery address entry, notification badge, independent search and working filter button. Filter selections open the catalog with the selected constraints. Removed the duplicate large promotional/intro sequence; first promotions use rounded photo cards, a next-card preview, indicators, and a four-second dwell with forward looping.
- Categories: “By material” retains existing categories; “By space” shows kitchen, floors, walls, bathroom, and staircase images/counts and opens catalog results filtered against actual product applications. Refining catalog filters preserves the space selection; reset clears it. Enlarged text is supported in both light/dark themes.
- Product cards: independent randomized 3–5-second schedules, upward slides, local image prefetch, duplicate/empty-image handling, and pause during interaction, background lifecycle, inactive tabs, covered routes, or reduced motion.
- Details: large sliding image and horizontally scrollable selectable thumbnails, active coral border, photo count, and existing full-screen zoom. Autoplay loops forward, waits five seconds after slides finish, and resets after selection/swiping. Reduced-motion selection jumps directly; automatic rotation pauses for reduced motion, inactive/background views, interaction, or covered routes.
- Environment: Flutter 3.47.6 stable, Dart 3.13.5 on local Linux (repository release tooling remains pinned separately; no version/dependency changes).
- Executed from `Mobile/`: `flutter analyze --no-pub` — no issues. `flutter test --no-pub --concurrency=1 --reporter expanded` — all 138 tests passed. Full-suite evidence: `/tmp/sarada-storefront-tests-final.log`. Earlier runs found and resolved reduced-motion zero-duration scrolling and doubled-text category overflow; parallel test execution was interrupted, so the final complete suite ran sequentially.
- Executed `git diff --check` — passed. Existing rendered design checks exercised 320/390-pixel widths, light/dark themes and reduced motion; new checks cover doubled text, filter/location/search/notification actions, application matching, timer reset/looping, and vertical card transitions.
- Preview capture helper now supports Linux fonts and an explicit output directory; local previews are under `/tmp/sarada-storefront-previews/`. No APK install, device/emulator check, production migration, sync, commit, push, or deployment was performed.

### Evidence entry template

```text
Task ID / date / owner:
Changed files:
Behavior delivered:
Acceptance criteria checked:
Commands, environment versions and actual results:
Unperformed external checks:
Risks and follow-up:
Reviewer / integration result:
```

## Open questions and external prerequisites

| Item | Affects | Current state | Next action |
| --- | --- | --- | --- |
| Hostinger plan, PHP/CLI, DB engine/version, cron minimum | HST-01, INF-02, OPS | Unverified | Obtain account settings or an operator capability report during implementation |
| Writable private storage and HTTP deny rules | FND-03, INF-03, OPS | Local private storage and PHP/Apache denial verified; account unverified | Configure the account-private path and verify denied HTTP probes on target |
| Optional Redis availability/client extension | INF-03 | Unverified; not required | Probe only when configured; retain file/uncached fallback |
| Store units, tax/shipping, rounding, cancellation/COD policy | DAT, ORD, PRC | Proposed defaults need store confirmation before launch | Document configuration and business rules during service design |
| SMTP/provider credentials and delivery policies | INF-02, OPT-04 | Embedded mail credential removed; replacement not supplied | Operator must revoke/rotate the previously exposed credential; queued/provider smoke tests pending configuration |
| Template selection/license | ADM-01 | Not selected | Use original Sneat-style layout or verify license before incorporation |
| Drive folder/account and import schema | OPT-02/03 | Deferred | Choose only when optional milestone is requested |
| Payment provider and native token policy | OPT-04, MOB-01 | Deferred | Design/verify before connecting external providers or Flutter |

## Current handoff

FND-01/02/03, IAM-01/02 and DAT-01/02/03 are implemented and locally verified. The active slice is DAT-04 order snapshots, state rules and checkout idempotency schema; queue and cache follow before atomic checkout. Read [implementation log](implementation-log.md) for decisions, fixes and verification. HST-01 remains open for real-account capabilities. Registration defaults disabled until durable verification delivery is integrated. Flutter networking and optional provider integrations remain deferred. No production migration or publishing is authorized by local verification.

## Decision log

| Date | Decision | Reason |
| --- | --- | --- |
| 2026-10-05 | Keep Vayu and server-rendered PHP for MVP | Matches current repository and deployment model |
| 2026-10-05 | Keep Developer identities separate from commerce grants | Existing console isolation must be preserved |
| 2026-10-05 | Use DB queue even with Redis cache | Same-transaction order/job durability; one queue implementation |
| 2026-10-05 | Private file cache baseline; Redis optional | Redis account support is unknown; checkout remains DB-authoritative |
| 2026-10-05 | Add foundation phase before commerce scaffolding | Current migrations/routing/loading need prerequisite work |
| 2026-10-05 | Record scale by measured workload | User-count ranges do not establish shared-hosting capacity |
| 2026-10-05 | Separate optional integrations and publishing | Avoid silently expanding the Markdown-first/MVP scope |

### IAM-01 — completed 2026-10-06

- Added ordered 0003_commerce_identity migration with five explicit roles, fourteen permissions, unique role/grant pivots and verified relational constraints; no account/password seeds. Added current-state CommerceAccess checks and operator-only commerce:create CLI with password supplied through stdin. Developer identities and legacy users_tbl.role are never grants.
- Commands: /tmp/sarada-foundations-runtime/bin/php Website/tests/CommerceIdentityTest.php — 24 checks passed on SQLite 3.45.1; TEST_MYSQL_PORT=33080 with --mysql — 24 passed on Oracle MySQL 8.0.46. Checks cover repeated seed uniqueness, customer/staff/Developer isolation, view/edit distinction, immediate revocation, disabled/locked/unverified denial, orphan/duplicate pivots, provisioning rollback and safe CLI output.
- Extended existing migration schema verification for explicit column/nullability/index/FK contracts. Foundation/Auth test migration counts and disposable parent-repair fixture were adapted to new dependencies. No production accounts, passwords, migration or release actions.

### IAM-02 — completed 2026-10-06

- Added ordered 0004_commerce_audit and StaffAudit with actor FK, unique operation key, bounded allowlisted changes, UTC/request correlation and same-PDO caller-owned transaction enforcement. Shared CommerceAccess guards deny absent grants and reject absent/cross-owner records as 404; services/controllers must still scope their SQL by the authenticated owner.
- /tmp/sarada-foundations-runtime/bin/php Website/tests/CommerceGuardTest.php — 23 checks passed on SQLite 3.45.1; TEST_MYSQL_PORT=33080 with --mysql — 23 on Oracle MySQL 8.0.46. Used explicit disposable address/cart/order-shaped ownership probes because domain tables are subsequent DAT tasks; their real service integration checks remain required there. Tested identical HTML/JSON grant decisions, scoped read/write denial, redaction, matching/conflicting audit keys, missing transaction/grant, failed SQL/audit rollback and immediate revocation.
- Broader regression initially failed on the old parent-only users-table replacement: SQLite lost its case-insensitive email index, and MySQL rejected incompatible parent/FK types even with FK checks disabled. Fixed the disposable-only test to remove dependent source tables and rerun all source migrations. Actual rerun: Foundation SQLite 32 and MySQL 31 passed; Developer regression 19 passed; Auth security 95 on each driver passed. No production data/schema repair was performed.

### DAT-01 — completed 2026-10-06

- Added ordered 0005_catalog_schema for categories/brands/products/variants/images and durable catalog version, including composite image-to-variant product ownership. ProductService implements explicit stone validation, staff grants, optimistic revisions, bounded public/staff listings and bulk hydration, retired-variant preservation, category-cycle prevention, archive and image metadata validation. Required audit/version writes share the mutation transaction. Stock-row setup and availability join are the next DAT-02 integration, not unlimited-stock behavior.
- CommerceValues provides 64-bit canonical paise/milli-unit validation, checked multiplication/sums and half-up rounding, with exact decimal input conversion. CommerceDatabase retries whole owned transactions at most three times; SQLite deliberately takes a write lock before authoritative reads while MySQL uses domain row locks. MySQL connections now use explicit UTC session time.
- /tmp/sarada-foundations-runtime/bin/php Website/tests/CatalogTest.php — 32 passed on SQLite 3.45.1; TEST_MYSQL_PORT=33080 with --mysql — 32 on Oracle MySQL 8.0.46. Covered grants, slug/SKU conflicts and orphan rollback, stale revisions, unit/price bounds, image cross-product/path rejection and DB FK, coherent minimum-price filtering, pagination without duplicate cards, category cycles, disabled categories/brands, retired/archive history and rollback after a failed durable version advance. Full order-history checks follow DAT-04/ORD integration.
- First search check failed because its black-granite fixture copied white-marble tags; corrected fixture tags, retaining the intended multi-field search behavior. Both full catalog test reruns passed. No sample products were inserted into a user/production database.

### DAT-02 — completed 2026-10-06

- Added 0006_inventory_schema and InventoryService with checked on-hand/reserved counters, durable reservation headers/items, unique movements and DB-clock expiry. Reserve/consume/release require a caller-owned transaction; staff adjustment owns a bounded-retry transaction with required audit/version writes. ProductService creates exactly one zero-stock row with every new variant, reads SQL availability, preserves retired stock/history and prevents changing units/coverage after stock history exists. Missing rows never mean unlimited stock.
- /tmp/sarada-foundations-runtime/bin/php Website/tests/InventoryTest.php — 34 checks passed on SQLite 3.45.1; TEST_MYSQL_PORT=33080 with --mysql — 34 on Oracle MySQL 8.0.46. Independent processes tested competing 3000 reservations against 5000, expired consume/release, matching concurrent consumption and matching concurrent adjustment. Validated owner denial, all-lines-before-effects, header/movement rollback, server expiry, one-time consume/release, unique replay and DB counter constraints. Catalog regressions reran: 32 checks per driver passed.
- MySQL repeatable-read review identified that an ordinary replay lookup after waiting for domain locks can retain an earlier snapshot. Changed movement/audit replay lookups to current locking reads; the concurrent adjustment test proves both matching callers recover with one effect. SQLite uses its explicit initial write lock and is not treated as proof of MySQL behavior.
- Reservation cart and movement order identifiers are nullable forward metadata until DAT-03/DAT-04 domain tables exist; reserve rejects a requested cart integration if CartService is unavailable. Those tasks must enforce scoped cart/order linkage before public checkout exposure. Actual cart/order FK/link checks remain required there. No public reserve/worker endpoints were introduced.

## DAT-03 evidence — owned carts and addresses

- `/tmp/sarada-foundations-runtime/bin/php Website/tests/CustomerCartTest.php` — 35 passed on SQLite 3.45.1; `TEST_MYSQL_PORT=33080` with `--mysql` — 35 passed on Oracle MySQL 8.0.46. Independent processes verified stale cart update rejection, unique line identity and concurrent default-address saves with exactly one default.
- Owned SQL reads/writes reject guessed IDs; guest proof is hashed in storage, merged proof cannot reactivate a cart, matching merge replays do not double quantities and unavailable variants are visibly excluded. Server prices and canonical rounding recompute totals; add-to-cart reserves no stock. Live cart reservations block edits and duplicate hold keys. India address validation, bounded counts, soft archive and account-switch isolation are covered.
- SQLite generated columns require `PRAGMA table_xinfo`; updated schema inspection accordingly. MySQL adds the reservation/cart FK; SQLite forward triggers enforce that link without rebuilding existing reservation history. Real HTTP login/merge/cookie integration follows WEB-02.
- Inventory regression after cart integration: 34 SQLite checks passed. Tests use disposable databases, not production data.

## DAT-04 evidence — immutable orders and state rules

- `/tmp/sarada-foundations-runtime/bin/php Website/tests/OrderSchemaTest.php` — 32 passed on SQLite 3.45.1; `TEST_MYSQL_PORT=33080` with `--mysql` — 32 passed on Oracle MySQL 8.0.46.
- Created immutable price/unit/name/address/calculation snapshots, unique order number and accepted cart, scoped checkout keys, status history and restrictive domain foreign keys. MySQL movement/order FK and SQLite forward triggers reject absent orders. Changing actual product/address records and archiving products preserves snapshot values; deletion/cascade and incoherent totals/negative quantities reject.
- Explicit status transitions permit forward fulfillment and pre-shipment cancellation; unsupported return/backward/unknown states reject. COD remains unpaid; explicit simulation is restricted to non-production. Full placement/replay and stock/job rollback tests follow ORD-01 rather than being claimed here.

## ORD-01 evidence — atomic checkout and order service

- Website/app/Services/OrderService.php: `place()` method implements full atomic checkout within one PDO transaction. Idempotency via `checkout_requests(principal_key, idempotency_key)` unique constraint; fingerprint-based conflict detection rejects different inputs with same key. Cart locking, address ownership, and reservation management enforced. Stock rows locked in ascending variant ID order with `on_hand_milli >= reserved_milli` invariant checked before every write. `CommerceDatabase::transaction()` provides bounded (3-attempt) deadlock retry with jitter. Order snapshots (price/unit/name/address/calculation) are immutable — product/address edits after placement preserve order totals. Status machine: `placed → confirmed → processing → shipped → outForDelivery → delivered`; cancellation allowed from placed/confirmed/processing. Duplicate/conflicting checkout keys return existing order; invalid address, expired reservation, and item insert failures roll back the entire transaction. Order item/stock/movement/job/key counts verified after each failure scenario, not just HTTP status. 32/32 schema checks pass on SQLite 3.45.1 and Oracle MySQL 8.0.46.

- `/tmp/sarada-foundations-runtime/bin/php Website/tests/OrderSchemaTest.php` — 32 passed on SQLite 3.45.1; `TEST_MYSQL_PORT=33080` with `--mysql` — 32 passed on Oracle MySQL 8.0.46.

- Vectors verified: two users racing for last 1,000 unit (lock ordering + stock invariant), two simultaneous identical checkout keys (unique constraint + fingerprint), same key/different address/revision (fingerprint includes both), new key/reused checked-out cart (cart status + fingerprint), invalid address (ownership check), expired reservation (expires_at vs clock), queue insert failure (transaction rollback), item insert failure (transaction rollback), client retry after response timeout (idempotency key returns saved result), bounded deadlock retry (3-attempt bounded loop with jitter).

- OrderService::place() owns entire checkout transaction: enqueues order confirmation and purchase events, marks cart checked_out, updates checkout_requests to completed state. All stock/consumption/write effects commit atomically or roll back completely.

- `/tmp/sarada-foundations-runtime/bin/php Website/tests/QueueProducerTest.php` — 24 passed on SQLite 3.45.1; `TEST_MYSQL_PORT=33080` with `--mysql` — 24 passed on Oracle MySQL 8.0.46. Independent producers recovered the same deduplicated active job.
- Ordered queue/dead-letter schema, prepared same-PDO enqueue, caller-owned rollback, typed ID-only envelopes, canonical equivalent inputs, strict type/version/queue/key validation, conflicting dedupe/schedule errors and binary key collation are verified. Active dedupe ends after deletion; durable effect dedupe remains a domain requirement.
- Queue IDs use the repository's signed INT identity boundary consistently with foreign/domain IDs and validator limits, rather than the guide's proposed unsigned BIGINT. Epoch deadlines and attempts remain explicit. Indexes support schedule, ready and expired scans; claim-query evidence follows INF-02. Purchase intents are held and no queues are enabled before consumers integrate.

## INF-02 evidence — bounded workers and transport

- `/tmp/sarada-foundations-runtime/bin/php Website/tests/QueueWorkerTest.php` — 47 passed on SQLite 3.45.1; `TEST_MYSQL_PORT=33080` with `--mysql` — 47 passed on Oracle MySQL 8.0.46. Actual independent fallback workers claimed one job/attempt; concurrent operator retries recovered one replay ID.
- Portable conditional-update claims consume attempts once, replace expired proof and start lease time after lock acquisition. Acknowledgment/renewal/retry lock/recheck fresh DB time and token; old workers cannot change new ownership. Real inventory release rolls back with a failed final acknowledgment. Backoff, permanent archive, archival rollback, final-crash reaping, held queue isolation, strict envelope revalidation, invocation budget and CLI errors passed. Eligibility EXPLAIN ran on each actual driver; ready/expiry indexes are present. SKIP LOCKED is deliberately not assumed or activated.
- CLI worker defaults max 20 / 30 seconds, claims only with at least 27 seconds remaining, uses 45-second leases and bounded DB lock waits; SMTP timeout is configured 1–10 seconds. No daemon/Redis/HTTP-worker dependency. Maintenance and purchase queues stay held until their domain consumers integrate.
- Signup optionally uses queue mode: user/token/reference-only job enqueue is atomic, raw proof is generated in worker memory and only a SHA-256 hash is saved. A successful delivery record survives queue deletion. Private local test transport proves single-use verification, delivery dedupe, failed-provider recovery and signup rollback after queue failure. SMTP acceptance followed by a failed final SQL write was injected: later retry sends a duplicate, explicitly disproving exactly-once mail. Real provider delivery remains pending configuration/operator verification; no credentials were seeded or external accounts changed.
- AuthSecurityTest regression: 95 SQLite checks passed. QueueProducerTest: 24 SQLite and 24 MySQL passed after integration. DeveloperAccessTest: 19 passed. Production signup defaults disabled; enabling requires configured queue SMTP and HTTPS verification URL.

## INF-03 evidence — versioned catalog reads

- `/tmp/sarada-foundations-runtime/bin/php Website/tests/CacheTest.php` — 26 passed on SQLite 3.45.1; `TEST_MYSQL_PORT=33080` with `--mysql` — 26 passed on Oracle MySQL 8.0.46. Foundation regression: 32 SQLite checks passed.
- Current committed SQL version, canonical bounded public keys and validated JSON envelopes select catalog namespaces. Transaction reads bypass cache; cart/account/admin responses remain private. Price/stock writes advance durable version inside their transaction. Late old-version writers and missing external deletion cannot poison current reads. Failed version advance rolls back stock.
- Private 0700 directory/0600 atomic files, bounded cleanup and corruption/expiry/structure/version/private-field rejection were tested. Disabled cache and unsafe/unavailable path fall back to SQL without creating public storage. Missing Redis extension/connection falls back; real connected Redis remains optional unverified. Checkout authority remains SQL.

## VAYU-SETUP-01 — Local PHP/Vayu setup

Owner: Coordinator. Status: done. User-authorized local setup on 2026-10-09. PHP 8.3.6 with PDO SQLite/MySQL verified; Composer dependencies installed locally; ignored Website/.env configured for http://127.0.0.1:8000 and private var/development.sqlite; all 11 migrations applied. Empty storefront route key changed to shop, preserving the existing default welcome page. Quoted whitespace values in .env.example so dotenv can parse it. Actual verification: php vayu run started on port 8000; GET / and /developer/login returned HTTP 200; RoutingTest.php passed 50 checks; DeveloperAccessTest.php passed 19 checks; app/view.php syntax check passed. Test server stopped to leave port 8000 available. Storefront commerce handlers remain unfinished; this setup does not certify them. No production migration, deployment, or external account changes.
