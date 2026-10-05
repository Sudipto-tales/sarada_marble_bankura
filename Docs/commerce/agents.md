# Agent roles and delegation briefs

## How these roles are used

These are task briefs for coding agents, not long-running services installed on Hostinger. The coordinator may spawn bounded agents for a user-authorized parallel session, or execute the briefs sequentially. Each agent reads root `AGENTS.md`, the plan, and the tracker before work.

For implementation assignments, include the exact section in [tasks/README.md](tasks/README.md), the relevant [contracts](contracts.md) and [code-map](code-map.md) entries, and the expected [data flow](data-flow.md). Small models should receive one dependency-ready task and only its relevant code, not a request to reconstruct the entire application from a generic role description.

With four available slots, run one coordinator plus at most three specialists. Start implementation agents only after their dependencies are integrated. Early documentation reviewers may run immediately because they only inspect the proposed plan.

## Ownership

| Role | Task ownership | Allowed implementation areas |
| --- | --- | --- |
| Coordinator / integrator | HST-01, foundation tasks, OPS tasks, integration | Root instructions, commerce docs/tracker, routing, bootstrap, `Website/vayu`, migration runner/order, release configuration |
| Data and commerce agent | IAM-01/02, DAT tasks, ORD-01/02, PRC-01 | Assigned migration files, assigned auth/commerce service classes, transaction tests |
| Queue and cache agent | INF-01/02/03 | `Website/core/Queue.php`, `Website/core/Cache.php`, assigned job/cache services and tests |
| Admin UI agent | ADM-01/02/03 | Assigned `app/bridge/Admin/`, `app/page/admin/`, `assets/admin/` files |
| Storefront and API agent | WEB-01/02/03 | Assigned `app/bridge/Storefront/`, `app/page/storefront/`, API handler classes and contract docs |
| Search and analytics agent | EVT-01/02, SRCH-01, REC-01 | Assigned event/search/recommendation services, aggregate migrations and tests |
| Optional integrations agent | OPT tasks, MOB-01 | Explicitly assigned Drive, campaign/review, payment, or mobile adapter files |
| Verification reviewer | OPS-02; reviews across phases | Read-only review first; edit only specifically assigned test/docs files |

Paths above are proposed where absent. Assign specific filenames before spawning an agent. Migration authors may draft files in parallel, but the coordinator assigns ordering and approves FK/dialect choices before integration. Shared files (`app/view.php`, `api/gateway.php`, `config/route.php`, `config/bootstarp.php`, `.env.example`, `vayu`, and `tracking.md`) stay with the coordinator unless exclusive ownership is explicitly reassigned.

## Execution waves

| Wave | Work that can run concurrently | Integration gate |
| --- | --- | --- |
| Planning | Repository-fit review; hosting/transaction safety review | Coordinator resolves findings before final documentation delivery |
| Foundations | Coordinator: FND; hosting discovery alongside independent local work | Compatible migrations, method-aware routes, safe sessions |
| Data | Data agent: IAM and DAT; queue/cache agent: INF; admin agent: ADM-01 layout | Compatible schema/permission and service interfaces agreed first |
| Commerce | Data agent: ORD; admin agent: ADM-02/03; storefront agent: WEB-01/02 | Shared Product/Inventory service contracts integrated before callers |
| Contracts and analytics | Storefront agent: WEB-03; analytics agent: EVT/SRCH/REC; coordinator: OPS-01 | Checkout and inventory behavior verified first |
| MVP review | Reviewer: security/concurrency; coordinator: runtime/runbook; other agents fix assigned findings | OPS-02 acceptance; deployment remains a separate action |
| Optional work | One explicitly authorized integration at a time or distinct assigned files | Stable API/contracts before MOB-01 |

The table is a scheduling guide; tracker dependencies are authoritative. Multiple roles can be fulfilled by the same specialist in different waves.

## Common assignment prompt

```text
Read AGENTS.md and Docs/commerce/{implementation-plan,tracking,agents}.md.
Work only on task IDs: <IDs>.
Read this focused guide section: <Docs/commerce/tasks/file.md#task-id>.
Read the linked existing functions and relevant contracts/data-flow sections.
You own only these files/directories: <exact paths>.
Dependencies confirmed integrated: <IDs and contracts>.
Expected behavior and acceptance: <criteria from plan>.
Implement the specified algorithm; do not generate empty classes or boilerplate.
Do not edit shared routing/bootstrap/CLI/tracker files, run sync, publish,
run production migrations, or change unrelated files.
If an interface/shared-file change is needed, send a concrete proposal to
the coordinator and continue independent work.
Return changed files, actual verification commands/results, open risks,
and proposed task status. Do not claim unperformed MySQL/hosting checks passed.
```

## Specialist briefs

### Data and commerce

Audit existing `UsersTable.php`, migration runner, PDO configuration, and Developer identity boundaries. Produce driver-aware ordered migrations and transaction-oriented services. Define fixed-precision prices, quantity units, keys, foreign-key actions, and deletion policy. Demonstrate duplicate checkout requests, competing reservations, cancel/release retries, and durable job enqueue. Never implement inventory correctness through a cache lock.

### Queue and cache

Implement DB queue first. Agree job envelope/handler contracts with the data agent. Show two workers cannot own the same current lease; old owners cannot acknowledge a new lease; retries/backoff and worker crashes recover safely. Cache must degrade on Redis failure, isolate private data, and prevent stale cache repopulation across version invalidation. Supply CLI registration changes to the coordinator.

### Admin UI

Build a local Bootstrap layout inspired by Sneat or incorporate a verified licensed template. Wire real service-backed product, variant, image, inventory, order, and staff permission flows. Escape output, validate uploads, reject unauthorized mutations, and display real empty/error states. Send explicit route definitions to the coordinator; do not invent completed modules with sample data.

### Storefront and API

Build catalog/product/cart/checkout/account pages using authoritative services. Agree cart guest-token and logged-in merge behavior. Publish versioned request/response/error examples and enforce customer ownership. Cover subdirectory routing. Keep payment simulation visibly restricted to non-production. Supply route/method registrations to the coordinator.

### Search and analytics

Collect bounded events only after commerce works. Purchase events originate from committed orders, not browser claims. Build incremental restartable aggregates, native MySQL FULLTEXT with a documented SQLite fallback, deterministic normalized ranking, and anonymous/category-based recommendation fallbacks. Do not introduce search daemons or ML services.

### Verification reviewer

Check migration recovery, method/permission enforcement, uploads/private-file access, checkout races, job lease recovery, cache invalidation/outages, and hosting runbooks. Separate passing local checks from pending provider/MySQL checks. Report findings with path/line, failure scenario, and corrective action. A review report alone does not complete implementation tasks.

## Handoff template

```text
Task IDs:
Changed files:
Behavior delivered:
Commands and actual results:
Database/runtime versions used:
Acceptance criteria still pending:
Shared-file changes requested:
Blockers or follow-up risks:
Suggested status:
```
