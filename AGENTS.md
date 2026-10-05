# Repository agent instructions

## Scope and sources

The application sources are `Website/` (Vayu PHP) and `Mobile/` (Flutter). Commerce work follows:

- [Implementation plan](Docs/commerce/implementation-plan.md)
- [Task tracker](Docs/commerce/tracking.md)
- [Agent briefs](Docs/commerce/agents.md)
- [Implementation prompt](Docs/commerce/prompt.md)
- [Task-specific guides](Docs/commerce/tasks/README.md)
- [Verified source map](Docs/commerce/code-map.md)
- [Shared contracts](Docs/commerce/contracts.md)
- [Data flows](Docs/commerce/data-flow.md)

These instructions guide implementation when requested; the planning delivery itself does not authorize executing the entire backlog. User instructions take precedence. Inspect the current code and preserve unrelated changes.

## Implementation constraints

- Keep the Vayu framework and existing Developer console working. Keep Flutter offline until a specifically authorized API integration milestone.
- MySQL/InnoDB is the production data store; SQLite remains a practical local option. Handle SQL dialect differences explicitly. Do not infer concurrency correctness from SQLite tests.
- Use short-lived database-queue workers invoked by CLI/cron. No daemon or mandatory Redis dependency. Redis may optimize cache reads; it is not authoritative for money, orders, inventory, or permissions.
- Keep `developer_accounts` and Developer sessions separate from commerce identities and grants.
- Use prepared SQL, server-side permission/ownership checks, CSRF protection for cookie-authenticated mutations, escaped output, and safe uploads. Store private cache/log/queue files outside public access.
- Use fixed-precision money and explicit units. Order totals, reservation changes, idempotency records, and durable job enqueue must be transactionally consistent.
- Use the canonical integer money/quantity arithmetic and state/lock contracts; implement task algorithms rather than generic scaffolds. Render with actual full `app/page/...php` paths; HTML/API controllers call services directly, not their own HTTP endpoints.
- Use bounded retries, lease tokens, and idempotent handlers; never promise exactly-once external delivery.
- Advance durable cache versions transactionally with catalog/inventory writes; external cache eviction runs after commit. Checkout must validate authoritative database prices and availability.
- Do not commit secrets or seed usable production passwords. Production admin provisioning must require an operator-supplied password.
- Preserve template licensing. Keep third-party template assets local and document their origin.

## Task and delegation workflow

1. Read the plan and tracker, select a dependency-ready task, and claim it before editing. Open its task guide and referenced source symbols before inventing new classes or APIs.
2. Use stable task IDs. Update status and evidence after every completed slice.
3. Delegate bounded work using `Docs/commerce/agents.md` when the user requested parallel agents for the active task or implementation session. Otherwise work sequentially unless separately authorized.
4. Give each agent non-overlapping file ownership. The coordinator owns shared routing, bootstrap, CLI, migration ordering, the tracker, and integration.
5. Agents report results and proposed tracker changes to the coordinator. They must not independently mark integrated work complete or overwrite another agent's edits.
6. If a required capability or credential is absent, record the specific blocker; continue independent tasks within scope. Do not mark blocked work done.

## Verification and release

- Run checks appropriate to the changed behavior. Existing Developer regression command, from `Website/`: `php tests/DeveloperAccessTest.php` (requires PDO SQLite).
- Add meaningful checks for authorization, migration compatibility, concurrent checkout, queue lease recovery, and cache failure handling when implementing those features.
- Use disposable databases for migration/destructive checks. Validate MySQL-specific locking and search against an actual supported MySQL/MariaDB target.
- Record actual command outcomes, environment versions, and pending external checks in the tracker. Planned commands are not evidence of passing tests.
- Follow `Docs/releases.md`: develop source on root `main`; do not edit generated `website`, `deploy`, or `mobile_app` branches.
- Sync scripts can stage, commit, push, and deploy pending changes. Run them only when the requested publishing/release action authorizes those effects.
- Do not run production migrations, deploy, or change external accounts merely to verify local implementation.
