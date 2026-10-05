# Commerce implementation tracker

Last updated: 2026-10-05. Coordinator: root agent. Documentation milestone (`DOC-01`) complete; foundation implementation is underway. Board: 1 done, 25 todo, 3 in_progress, 5 deferred.

## Status rules

Allowed statuses: `todo`, `in_progress`, `blocked`, `in_review`, `done`, `deferred`.

- Claim a task with an owner before coding. Only the coordinator changes integrated status.
- `done` requires the acceptance criteria in [implementation-plan.md](implementation-plan.md) and a linked evidence entry.
- `blocked` must identify the missing prerequisite and next action. Unknown external facts do not block independent local work.
- `deferred` marks optional milestones excluded from the current MVP.
- Track partial implementation in notes/evidence; do not count a skeleton as completed behavior.
- Required-but-unrun MySQL/hosting checks stay pending. A test command listed in a plan is not a passing result.

## Task board

| ID | Task | Dependencies | Owner | Status | Evidence / next action | Guide |
| --- | --- | --- | --- | --- | --- | --- |
| DOC-01 | Planning pack and agent instructions | None | Coordinator | done | See DOC-01 evidence and expanded task-guide validation; 21 Markdown files | [Document maintenance and evidence](tasks/10-operations.md#doc-01) |
| HST-01 | Actual hosting capability record | DOC-01 | Coordinator | in_progress | Record account PHP/DB/cron/private paths/optional Redis; facts not supplied | [Host capability discovery](tasks/01-foundations.md#hst-01) |
| FND-01 | Migration compatibility, PDO and CLI | DOC-01 | Coordinator | in_progress | Repair SQLite-specific user/ledger SQL first | [PDO, migration ordering and CLI](tasks/01-foundations.md#fnd-01) |
| FND-02 | Method/parameter routing and class loading | DOC-01 | Coordinator | in_progress | Preserve legacy exact routes/subdirectory behavior | [Method/parameter routing and loading](tasks/01-foundations.md#fnd-02) |
| FND-03 | Request/session/auth/private-path protections | FND-01, FND-02 | Unassigned | todo | Harden customer auth with token upgrade migration before exposure | [Session/request/secret protections](tasks/02-identity.md#fnd-03) |
| IAM-01 | Commerce identity grants/provisioning | FND-01, FND-03 | Unassigned | todo | Keep Developer identities separate | [Commerce roles and provisioning](tasks/02-identity.md#iam-01) |
| IAM-02 | HTML/API guards and audit contract | IAM-01 | Unassigned | todo | Deny grants/ownership violations | [Guards, ownership and audit](tasks/02-identity.md#iam-02) |
| DAT-01 | Catalog schema and product service | FND-01, IAM-02 | Unassigned | todo | Define units/money and ordered migrations; guard contract integrated first | [Catalog and variants](tasks/03-catalog-carts-schema.md#dat-01) |
| DAT-02 | Inventory/reservations/movements | DAT-01 | Unassigned | todo | Test competing reservations on MySQL | [Inventory and reservations](tasks/04-inventory-orders.md#dat-02) |
| DAT-03 | Owned addresses/carts and guest merge | IAM-02, DAT-01 | Unassigned | todo | Opaque hashed guest token; authoritative totals | [Addresses/carts and guest merge](tasks/03-catalog-carts-schema.md#dat-03) |
| DAT-04 | Orders/history/idempotency schema | DAT-02, DAT-03 | Unassigned | todo | Snapshots and explicit status machine | [Order snapshots and idempotency schema](tasks/04-inventory-orders.md#dat-04) |
| INF-01 | Durable DB queue schema/API | FND-01 | Unassigned | todo | Same-transaction enqueue and rollback | [Durable queue producer/schema](tasks/05-queue.md#inf-01) |
| INF-02 | Bounded worker/retries/transport adapters | INF-01 | Unassigned | todo | Leases, crash recovery, stale ack and retry checks; order handlers in ORD-02 | [Claims, worker and retries](tasks/05-queue.md#inf-02) |
| INF-03 | File/optional Redis cache and invalidation | FND-01, DAT-01 | Unassigned | todo | Cache failure and stale-writer checks | [Cache and invalidation algorithms](tasks/06-cache.md#inf-03) |
| ADM-01 | Staff admin layout/navigation | FND-02, FND-03, IAM-02 | Unassigned | todo | Local assets; license/source record | [Protected local admin layout](tasks/07-admin.md#adm-01) |
| ADM-02 | Product/variant/image/category administration | ADM-01, DAT-01, INF-03 | Unassigned | todo | Real CRUD, uploads, grants and cache invalidation | [Product CRUD and upload lifecycle](tasks/07-admin.md#adm-02) |
| ADM-03 | Inventory/order/customer/settings administration | ADM-01, DAT-04, ORD-01 | Unassigned | todo | Audited mutations and supported screens only | [Inventory/order/customer admin](tasks/07-admin.md#adm-03) |
| ORD-01 | Atomic checkout/order service | DAT-04, IAM-02, INF-01, INF-03 | Unassigned | todo | Last-item race, duplicate checkout, rollback | [Transactional order placement](tasks/04-inventory-orders.md#ord-01) |
| ORD-02 | Order notifications/expiry/cancel/low-stock jobs | ORD-01, INF-02 | Unassigned | todo | Register order handlers; idempotent stock effects and bounded cleanup | [Expiry/cancel and notification jobs](tasks/04-inventory-orders.md#ord-02) and [queue handlers](tasks/05-queue.md#ord-02) |
| WEB-01 | Public catalog/product pages | FND-02, DAT-01, INF-03 | Unassigned | todo | Pagination/filtering and inactive-item behavior | [Catalog/product SSR](tasks/08-storefront-api.md#web-01) |
| WEB-02 | Cart/account/address/checkout pages | WEB-01, DAT-03, ORD-01, FND-03 | Unassigned | todo | Auth, server totals, payment mode restrictions | [Cart/account and checkout flow](tasks/08-storefront-api.md#web-02) |
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
| Writable private storage and HTTP deny rules | FND-03, INF-03, OPS | Unverified | Choose private path and verify denied HTTP probes on target |
| Optional Redis availability/client extension | INF-03 | Unverified; not required | Probe only when configured; retain file/uncached fallback |
| Store units, tax/shipping, rounding, cancellation/COD policy | DAT, ORD, PRC | Proposed defaults need store confirmation before launch | Document configuration and business rules during service design |
| SMTP/provider credentials and delivery policies | INF-02, OPT-04 | Not supplied | Implement/test adapters without secrets; provider smoke tests pending configuration |
| Template selection/license | ADM-01 | Not selected | Use original Sneat-style layout or verify license before incorporation |
| Drive folder/account and import schema | OPT-02/03 | Deferred | Choose only when optional milestone is requested |
| Payment provider and native token policy | OPT-04, MOB-01 | Deferred | Design/verify before connecting external providers or Flutter |

## Current handoff

The Markdown pack includes task-specific source references, concrete algorithms/queue design and data flows. Select one task through the task index or its tracker guide link. Code implementation has not started. The next implementation slice is `FND-01` and `FND-02`; start `HST-01` discovery alongside independent local work. Optional integrations and Flutter networking remain deferred.

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
