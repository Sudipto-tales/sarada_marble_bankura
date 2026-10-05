# Cache reads, file fallback and transactionally versioned invalidation

## INF-03

References: [PDO](../../../Website/config/db.php#L31), [env()](../../../Website/config/env.php#L3), [bootstrap core loading](../../../Website/config/bootstarp.php#L19), [current environment example](../../../Website/.env.example#L1). Proposed files: `core/Cache.php`, catalog version migration/service integration and focused failure tests. No existing Redis support is assumed.

### Key and version contract

Version metadata is a durable single-row namespace counter initially (`catalog`), updated inside every committed product/price/availability mutation. A global counter is intentionally simple; finer per-category counters are optional after profiling. Recommendations also include their published generation ID. Transactional version update must exist before public cache is enabled.

Public key input: namespace/version, route/API contract version, normalized filter whitelist, stable sort, bounded page/page-size, currency/locale. Canonicalize keys in a fixed order and hash them. No user identity, arbitrary request query dump, CSRF token, credential, or client filename in public keys. Cart/account/admin responses remain uncached/private.

### Read algorithm

1. Obtain current version using an authoritative committed DB read, not a long-lived transaction snapshot or Redis-only copy.
2. Construct versioned canonical key; attempt configured cache with a short timeout.
3. Validate payload structure/TTL/version. Bad/corrupt entries become misses; logs carry key hash, not customer content.
4. On miss read active catalog/availability from SQL, create the public DTO and write under the version captured before that read.
5. If a mutation happened during the read, this old-version entry is harmless because later requests use a new version. Optionally re-read version before returning/writing to avoid a stale current response; checkout always revalidates independently.
6. Failure of cache read/write falls back to SQL. Failure of version lookup means bypass cache; DB failure itself returns a safe infrastructure error, not an invented product record.

### Write/invalidation algorithm

Product/Inventory/Order/Pricing service begins → perform mutation → increment durable namespace version on the same PDO → audit/jobs as required → commit. Failed version update rolls back the mutation. External Redis eviction, file cleanup and warmup are post-commit optimizations. Do not implement invalidation solely as an asynchronous delete job: committed mutation must already select a new namespace even if the process crashes immediately after commit.

### Private file backend

Hash validated keys into fixed filenames below a configured private directory. Encode a bounded JSON envelope (`version`, expires_at, payload). Write a temporary file in the same directory, finish/close it, then atomic rename. Use restrictive permissions, validate decoded envelope and expiry, and remove corrupt files safely. Never use PHP `unserialize` or include cache files as executable PHP. Bound cleanup by file count/runtime and guard concurrent cleanup/temp writes.

### Optional Redis backend

Select only when extension/client and credentials are configured. Set short connect/read deadlines; use SET with expiration; isolate namespace; fail over to private file or uncached DB reads when unavailable. Do not retry every missing cache key with a full multi-second connection budget. Never store inventory reservations/idempotency/grants only in Redis. Startup failure must not break all PHP requests.

### Vectors and closure

- Warm product detail, change price and stock, then next read sees new version.
- A begins cache-miss read at version 7; B commits version 8; A writes version 7; next reader does not hit it.
- Crash after commit before Redis deletion still reads namespace 8.
- Simulated Redis outage/bad JSON/expired file becomes SQL miss without checkout changes.
- Failed version increment rolls back product mutation.
- User A/B carts never share public cache; HTTP probes cannot fetch cache/log files.

Record actual MySQL/file/optional Redis results. A Redis adapter that has not been tested against an available connection remains explicitly pending optional verification.
