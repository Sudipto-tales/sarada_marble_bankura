# Documentation maintenance, cron runbook and acceptance

Scope: DOC-01, OPS-01/02. References: [vayu command dispatch](../../../Website/vayu#L29), [environment defaults](../../../Website/.env.example#L1), [Developer test](../../../Website/tests/DeveloperAccessTest.php#L1), [release instructions](../../releases.md). Read [queue](05-queue.md), [data flows](../data-flow.md) and [tracker](../tracking.md).

## DOC-01

Each tracker ID must map to a focused task section, existing code symbols and concrete algorithm/checks. Keep proposed file paths distinct from existing references. After edits verify relative links, `#L` line ranges/symbol targets, Markdown section anchors, matching task IDs/dependencies, no cycles, valid statuses and honest evidence. Add any new task to both plan/tracker/index before claiming it.

Do not copy provider secrets from source inspection into Markdown or fixtures. Refresh references after implementation moves symbols. A successful docs check does not prove the proposed SQL or algorithms are implemented/tested.

## OPS-01

Produce `Website/docs/commerce-operations.md` during implementation with the actual account capability record, absolute PHP/script paths, config variable names, schedule/timeout/batch limits, enabled/held queues and deployment/private storage probes. Proposed commands are not usable until their CLI tasks are merged.

Scheduling algorithm: run bounded maintenance producers → run bounded enabled-queue worker → run incremental event aggregation/rebuild tasks at measured intervals → monitor oldest pending age vs arrival/drain rates → catch up through repeated short invocations. Each producer/consumer must tolerate cron overlap. Jobs older than a threshold trigger an operational alert; do not increase runtime beyond hosting limits as the first fix.

Logs: UTC/request/job/order IDs, operation/error classifier, elapsed time, attempts, queue and counts; redact PII/secrets/provider content. Metrics: oldest pending/held age, jobs drained/failed/retried, expired leases, reservation cleanup lag, event backlog, aggregate age, query latency/cache failure rate and disk archive usage. Developer UI may show real metrics once a service is wired; sample UI is not telemetry evidence.

Backup/recovery algorithm: identify source of truth/private assets → take operator-authorized backup → restore into an isolated target → verify counts/order snapshots/movements/idempotency/queue state → disable provider sends during rehearsal → document restoring service and replay policy. A restored database may contain already-delivered external intents; reconciliation/idempotency policy is mandatory before enabling sends. No restore test on the live database.

Record real SMTP/private-file/cron probes as pending until available. Require operator credential rotation/configuration before real mail. Private `.env` and files must be inaccessible via HTTP; don't print settings in logs or worker URLs.

## OPS-02

Use a disposable MySQL target and independent connections/processes for concurrency tests. Existing command from Website: `php tests/DeveloperAccessTest.php` requires PDO SQLite. Proposed new tests should exercise these behaviors rather than matching implementation details:

| Boundary | Required evidence |
| --- | --- |
| Migrations/routes | Fresh/upgrade/rerun/interrupted migrations; existing routes; 404/405/subdirectory |
| Auth/access | Disabled/locked/revoked users, CSRF, remember expiry, session regeneration, principal ownership |
| Catalog/files | SKU/slug constraints, variants/units, stale revisions, unsafe upload denial, private-file HTTP denial |
| Checkout | Two buyers/last stock, duplicate/conflicting keys, stale carts, expiry, all-write rollback |
| Stock lifecycle | Reserve/consume/release/cancel replay and concurrent state races; no negative counters |
| Queue | Both supported selected claim paths, stale token, crash/final-attempt reaper, retry/dead-letter atomicity |
| Cache | Outage/corrupt/expired values, old writer/new version, crash after mutation, no private cache leakage |
| Analytics | Late commit/replay/cancel reversals, checkpoint atomicity, bounded archive/rebuild |
| Search/pricing/reco | Actual MySQL FULLTEXT/query plans; fixed-precision factors; sparse-data/generation fallback |
| Runtime | Measured mixed request concurrency, latency/resource/queue-drain behavior under configured limits |

Keep environment, exact commands/results and unperformed account/provider checks in the tracker. If required MySQL checks cannot run, keep OPS-02 pending; report completed local work separately. Do not invent a throughput/user-capacity claim from passing unit tests.

Production release requires separate authorization plus completed real-account probes, configured payments/COD policy, no simulation/demo credentials, backup/recovery readiness and verified private storage. Follow existing release workflow; sync scripts can commit/push/deploy all pending changes and must not be invoked merely as local verification.
