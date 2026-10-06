# Implementation prompt

Copy the following prompt into a coding-agent session opened at the repository root.

---

Implement the Stage 1 commerce backend and storefront for `sarada_marble_bankura`, using the existing Vayu PHP framework in `Website/`. Target Hostinger shared hosting with MySQL/InnoDB, a database queue, short-lived cron commands, and optional Redis caching. Keep the Flutter app in `Mobile/` offline until the API and its contracts are stable.

Read these files before editing:

- `AGENTS.md`
- `Docs/commerce/implementation-plan.md`
- `Docs/commerce/tracking.md`
- `Docs/commerce/agents.md`
- `Docs/commerce/tasks/README.md` and the guide for your selected task
- `Docs/commerce/contracts.md` (relevant shared contracts)
- `Docs/commerce/code-map.md` (verified source symbols/line anchors)
- `Docs/commerce/data-flow.md` (the flow relevant to your task)
- `Website/docs/architecture.md`
- `Website/docs/ecommerce-developer-console.md`
- `Docs/releases.md`

Inspect the current code as well as the documents. FND-01/02 implemented driver-aware migrations, `php vayu migrate`, method/parameter routing and fixed class-map loading. FND-03 implemented shared request/session/private-path protections and hashed expiring auth tokens; production signup remains disabled until queued verification delivery is integrated. `php vayu queue:work` and commerce modules do not exist yet. Follow tracker evidence and [runtime foundations](../../Website/docs/foundations.md) before adding dependent modules.

## Working procedure

1. Inspect the working tree and preserve existing user changes. Read applicable `AGENTS.md` instructions.
2. Select the earliest dependency-ready tracker task; claim it with an owner and status before coding. Read that task's focused guide and linked existing code first; use the specified algorithm and failure vectors. Work in small, reviewable slices.
3. Resolve routine design choices using the plan. Request missing external credentials or hosting facts only when required; continue independent local tasks meanwhile.
4. Use the agent briefs for bounded delegation when the active session authorizes parallel agents and agent tools are available. Coordinate shared files centrally. Otherwise follow the same role boundaries sequentially.
5. Implement the task completely, including necessary authorization, validation, failure handling, and verification.
6. Update the tracker with paths, exact commands/results, limitations, and a handoff. Mark `done` only after the task's acceptance criteria pass.
7. Continue through the authorized milestone. For a request without a milestone limit, target the Stage 1 MVP gate (`OPS-02`); do not silently include deferred Drive, optional marketing features, real payments, or Flutter networking.
8. Report completed behavior and verification. Do not publish, push release branches, or run production migrations unless the user authorized those actions.

## Architecture requirements

- Preserve Vayu, existing routes, the Developer console, and the mobile repository interfaces. Do not introduce Laravel, service daemons, Kafka, OpenSearch, Kubernetes, or a new frontend framework for the MVP.
- Use `users_tbl` for commerce customer/staff identities with explicit roles and permissions. Keep existing `developer_accounts` and Developer sessions separate. Console access must never imply commerce administration access or vice versa.
- Use ordered, driver-aware migrations. MySQL is the production target; maintain practical SQLite development support with explicit fallbacks, not claims of equivalent locking or FULLTEXT behavior.
- Use canonical integer minor-unit prices and milli-unit quantities from contracts.md; define currency, tax, units and integer rounding. Stone products need square-foot and slab/box unit rules. The server calculates checkout totals.
- Stock reservation, order creation, checkout idempotency, and durable job enqueue happen transactionally. Reconcile reservations and cancellations without overselling or double release.
- Keep MySQL jobs as the initial durable queue even if Redis is available. Use leased, bounded workers, retries, failed-job visibility, allowlisted handlers, and idempotent business effects. Hold queues for future handlers until those handlers are registered, then replay in bounded batches.
- Redis is an optional cache optimization. A Redis outage must fall back to a documented local cache or uncached database reads without affecting order correctness.
- Advance durable cache versions in the same transaction as catalog/inventory mutations; perform external cache eviction/refresh after commit. Async refresh must not be the only protection against stale availability or prices.
- Protect state-changing forms/APIs with the correct authentication and CSRF/ownership checks. Protect uploads, private files, sessions, logs, and cron entry points.
- Use a Sneat-style Bootstrap admin layout with local assets. If incorporating third-party template files, verify the license and preserve notices; do not claim an original layout is an official Sneat template.
- Build server-rendered catalog, product, cart, and checkout pages plus versioned APIs. Simulated payments must be clearly labeled and disabled in production checkout; COD may be enabled only through explicit store configuration.
- Keep events append-only during normal use. Limit payloads, remove secrets, deduplicate server-side purchase events, and define retention before analytics rollout.
- Add simple MySQL search/ranking and category/brand recommendations after commerce correctness. Drive imports run through admin-triggered jobs/cron, never customer requests.

## Completion requirements

For the selected milestone, provide working changes, relevant regression checks, updated documentation, and accurate tracker evidence. Test MySQL locking and FULLTEXT behavior against MySQL; SQLite-only checks cannot close those requirements. Do not claim hosting deployment, scale capacity, provider delivery, or production readiness without evidence. Record unavailable external checks as pending verification.

The next foundation slice is `FND-03` request/session protections. FND-01 migration/CLI and FND-02 routing/loading have runtime implementations. Hosting discovery (`HST-01`) is concurrent discovery, not a reason to leave independent foundation work unfinished.

---

For a smaller session, prepend: “Implement only task(s) `<IDs>` and their unmet prerequisites; update the tracker and stop after the slice is verified.”

Do not generate boilerplate classes, generic CRUD factories or placeholders. Implement the required algorithm in the existing framework with only necessary new files. Task guides include source references, boundaries, inputs/outputs and behavioral checks so you do not have to infer missing APIs. Re-locate the named symbols if snapshot line anchors have moved.
