# Commerce operations runbook (OPS-01)

Scope: CLI/cron scheduling, least-privilege environment, cleanup schedules, structured
logs, queue/backlog metrics, and backup/restore/recovery for the Vayu commerce store.
Task: `OPS-01` (deps INF-02, ORD-02, EVT-02). Guide: [`tasks/10-operations.md`](../../Docs/commerce/tasks/10-operations.md).

Every command, queue, path, table and log file named here was verified against the
current source (`Website/vayu`, `app/Jobs/JobRegistry.php`, `core/Queue.php`,
`core/QueueWorker.php`, `core/PrivateStorage.php`, `database/migrations/0009_durable_queue.php`,
`Tools/deployment/remote.sh`). **Account-specific values** (cron minimum interval, PHP CLI
path/version, memory and runtime limits, actual private path, outbound mail policy) are
**pending HST-01**; see [`hosting-capabilities.md`](hosting-capabilities.md) and the
placeholders flagged `⟨operator⟩` below. Do not treat the examples as observed account facts.

---

## 1. Conventions and absolute paths

Cron runs with an empty environment and no working directory, so **every path is absolute**
and each command `cd`s into the application root first. Define these once per host; the
examples below reuse them.

| Placeholder | Meaning | How to resolve |
| --- | --- | --- |
| `⟨APP⟩` | Application root containing `vayu` | e.g. `/home/⟨user⟩/public_html` or the deploy target in `remote.sh` |
| `⟨PHP⟩` | PHP **CLI** binary, 8.3+ | Hostinger: often `/opt/alt/php83/usr/bin/php` (DEPLOY-01 found default CLI was 8.2). `⟨PHP⟩ -v` must report 8.3+ |
| `⟨PRIVATE⟩` | `PRIVATE_STORAGE_PATH` — absolute, **outside** `⟨APP⟩` | default `dirname(⟨APP⟩)/var`; set explicitly on shared hosting |
| `⟨DEPLOY_STATE⟩` | Deploy/backup state dir | `~/.sarada-deploy/⟨domain⟩` (created by `remote.sh`, mode 700) |

`PrivateStorage` refuses any path inside the document root, so `⟨PRIVATE⟩` must not live
under `⟨APP⟩`. Private areas created on demand at mode `0700`:
`cache/`, `logs/`, `maintenance/`, `monitoring/`, `sessions/`, `image-staging/`, `test-mail/`.

> **Secrets never in URLs or logs.** `.env` holds all credentials; it is excluded from
> publication (`remote.sh` `--exclude=/.env`) and denied over HTTP. Never pass secrets as
> CLI arguments, query strings or worker URLs — they leak into process lists and logs.

---

## 2. CLI command surface (verified)

All commands dispatch from `Website/vayu` (run `⟨PHP⟩ vayu --help`). CLI-only; the file 404s
under a web SAPI except as the dev-server router.

| Command | Purpose | Bounds |
| --- | --- | --- |
| `vayu migrate` | Apply pending migrations (serialized by DB named lock / SQLite file lock) | idempotent; reruns with 0 pending |
| `vayu queue:work --queues=… --max=N --max-seconds=S` | Bounded worker | `max` 1–100 (def 20), `max-seconds` 1–300 (def 30); leases 45s, ≤5 attempts |
| `vayu queue:retry --failed-id=ID` | Replay one `job_failed` row | operator-audited |
| `vayu analytics:aggregate [--batch=N] [--max-seconds=S]` | Incremental daily metrics | `batch` 1–1000 (def 200), `max-seconds` 1–300 (def 20) |
| `vayu analytics:purge --retention-days=N [--batch=N] [--max-seconds=S]` | Prune processed events | `retention-days` 1–3650, `batch` 1–1000 (def 500) |
| `vayu recommend:rebuild` | Rebuild bounded recommendations | — |
| `vayu catalog:images:clean [--delete]` | Reconcile orphan product images | inspect first, then `--delete` |
| `vayu developer:report [--date=YYYY-MM-DD]` | Daily UTC health snapshot (JSON) | completed UTC date only |
| `vayu commerce:create EMAIL [role]` | Provision staff (password on stdin, hidden) | operator-supplied password |
| `vayu developer:create EMAIL` | Provision Developer console account | separate identity domain |

**Enabled queues** (`JobRegistry::enabledQueues()`): `emails`, `maintenance`,
`purchase_events`. A queue with no registered/enabled handler stays **held** — the worker
must not select it, or held intents burn retries as unknown jobs. The `purchase_events`
queue was held until EVT-01 registered `PurchaseEventJob`; select it only where that handler
is wired.

Producers that enqueue durable work (reservation expiry, booking/verify windows, refunds,
low-stock alerts, order notifications, purchase events) are enqueued **inside the business
transaction** by the services; cron only needs to run the **worker** and the **analytics**
commands plus any bounded maintenance scan the release wires in.

---

## 3. Cron schedule

Scheduling algorithm (from the guide): bounded maintenance/producers → bounded enabled-queue
worker → incremental aggregation/rebuild at measured intervals → monitor oldest-pending age
vs arrival/drain rate → **catch up through repeated short invocations**, never by raising a
single run's runtime past the hosting limit. Every entry is overlap-tolerant: workers claim
under lease with `innodb_lock_wait_timeout=1` and short budgets, so a late run is a no-op, not
a corruption. Keep each invocation **under the account cron/PHP runtime limit** (⟨operator⟩,
pending HST-01) — prefer more frequent short runs over one long run.

Example crontab (replace placeholders; `⟨MIN_INTERVAL⟩` pending the account's minimum):

```cron
# m h dom mon dow   command
# Transactional queues (email + maintenance): drain frequently, short budget.
* * * * *   cd ⟨APP⟩ && ⟨PHP⟩ vayu queue:work --queues=emails,maintenance --max=50 --max-seconds=50 >> ⟨PRIVATE⟩/logs/cron-worker.log 2>&1
# Analytics ingestion (purchase events feed metrics): keep separate from money queues.
*/5 * * * * cd ⟨APP⟩ && ⟨PHP⟩ vayu queue:work --queues=purchase_events --max=100 --max-seconds=50 >> ⟨PRIVATE⟩/logs/cron-events.log 2>&1
# Daily metric aggregation (incremental, checkpointed — safe to rerun).
*/15 * * * * cd ⟨APP⟩ && ⟨PHP⟩ vayu analytics:aggregate --batch=200 --max-seconds=20 >> ⟨PRIVATE⟩/logs/cron-analytics.log 2>&1
# Recommendation rebuild (bounded): off-peak.
30 2 * * *  cd ⟨APP⟩ && ⟨PHP⟩ vayu recommend:rebuild >> ⟨PRIVATE⟩/logs/cron-reco.log 2>&1
# Event retention prune (configurable window).
45 3 * * *  cd ⟨APP⟩ && ⟨PHP⟩ vayu analytics:purge --retention-days=90 --batch=500 --max-seconds=20 >> ⟨PRIVATE⟩/logs/cron-purge.log 2>&1
# Orphan image reconcile: inspect weekly; run with --delete only after reviewing output.
0 4 * * 0   cd ⟨APP⟩ && ⟨PHP⟩ vayu catalog:images:clean >> ⟨PRIVATE⟩/logs/cron-images.log 2>&1
```

**Reservation expiry / booking windows / refunds** ride the `maintenance` queue, so the
one-minute worker above drains them; no separate producer cron is required beyond what the
services enqueue. If a dedicated bounded scan is added later, schedule it ahead of the worker
line and keep it overlap-tolerant.

Redirect stdout/stderr to `⟨PRIVATE⟩/logs/` (mode 0700), **not** a web-reachable path. Do not
email raw worker output containing IDs to unverified addresses.

---

## 4. Least-privilege environment

Base the production `.env` on [`.env.example`](../.env.example) and keep it mode `0600`,
owned by the app user, outside the document root's served set. Minimum safe production shape:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_TYPE=mysql                 # MariaDB/InnoDB is authoritative for money/orders/inventory
DB_HOST=127.0.0.1
DB_DATABASE=⟨schema⟩          # schema name, [A-Za-z0-9_]
DB_USERNAME=⟨app_user⟩        # least-privilege: DML + required DDL for migrate only
DB_PASSWORD=⟨rotated⟩         # rotate the previously-embedded credential before go-live
PRIVATE_STORAGE_PATH=⟨PRIVATE⟩
SESSION_SECURE_COOKIE=true
CACHE_DRIVER=file             # Redis optional; never authoritative for money/stock/perms
MAIL_TRANSPORT=disabled       # set to smtp only after operator configures + rotates creds
AUTH_OTP_ENABLED=false        # enable with a working mail transport + running worker
AUTH_VERIFICATION_TRANSPORT=disabled
CHECKOUT_RAZORPAY_ENABLED=false   # OPT-04 gate: live keys + webhook not verified here
CHECKOUT_COD_ENABLED=false        # enable only after COD/delivery/tax policy approved
```

Principles:
- **DB grants**: the app user needs DML on all tables plus the DDL that `vayu migrate` runs;
  it does **not** need `SUPER`, `FILE`, or account-admin rights. Backups use a separate
  read path (`mysqldump --single-transaction`, see §7).
- **Redis** (if used) is cache-only. Money, orders, inventory and permissions read from SQL.
- **Secrets** (Razorpay, SMTP, WhatsApp/MSG91 OTP) live in `.env` or the DB `store_settings`
  (write-only in the admin UI), never in Markdown, fixtures, logs or URLs.
- **Transports stay disabled** until the operator supplies and rotates credentials. OTP and
  mail require `MAIL_TRANSPORT=smtp` + a running `emails` worker; otherwise keep `disabled`
  (or `test`, which writes to `⟨PRIVATE⟩/test-mail/` and never sends).

---

## 5. Structured logs

Workers and traces write under `⟨PRIVATE⟩` only. Existing writers:

| File | Writer | Content |
| --- | --- | --- |
| `⟨PRIVATE⟩/monitoring/capture-status.log` | `core/DeveloperTrace.php` | bounded span status; operation/classifier only — no args, bodies or PII |
| `⟨PRIVATE⟩/logs/payments.log` | `app/Services/PaymentService.php` | gateway/webhook operation + classifier, mode 0600; never secrets, signatures, raw bodies or PII |
| `⟨PRIVATE⟩/logs/cron-*.log` | cron redirection (§3) | worker JSON stats + stderr |

Each log line should carry, where the writer has them: **UTC timestamp, request/job/order ID,
operation and error classifier, elapsed ms, attempt count, queue, and counts.** PII, secrets
and provider payloads are redacted at the source — preserve that when adding writers. A
logging failure must never change a business result (payment/money paths already enforce this).

The worker itself emits a JSON summary per run, e.g.
`{"claimed":N,"completed":N,"failed":N,"lease_lost":N,"reaped":N}` — capture it (the cron
redirect does) for the drain-rate metric in §6.

---

## 6. Queue / backlog metrics

Durable state lives in `jobs` and `job_failed` (`0009_durable_queue.php`). Timestamps are
**epoch seconds** (`CommerceDatabase::clock`). `status` is `ready` or `running`; a ready job
is due when `available_at <= now`. Monitor with read-only SQL against the authoritative DB
(no secrets in these queries):

```sql
-- Oldest due (pending) age per queue, in seconds — the primary alert signal.
SELECT queue, COUNT(*) AS due, MAX(? - available_at) AS oldest_pending_s
FROM jobs WHERE status='ready' AND available_at <= ? GROUP BY queue;   -- bind now twice

-- In-flight / stuck leases (reaper should reclaim expired ones each run).
SELECT queue, COUNT(*) AS running, SUM(lease_expires_at < ?) AS expired_leases
FROM jobs WHERE status='running' GROUP BY queue;                        -- bind now

-- Dead-letter backlog and recent failures.
SELECT queue, error_code, COUNT(*) AS failed, MAX(failed_at) AS last_failed_at
FROM job_failed GROUP BY queue, error_code ORDER BY failed DESC;
```

Metrics to track (guide §17): **oldest pending/held age**, jobs **drained/failed/retried**,
**expired leases**, **reservation cleanup lag**, **event backlog** (pending `purchase_events`),
**aggregate age** (last analytics checkpoint vs now), query latency, cache failure rate, and
private-storage disk usage.

Alerting rule: when `oldest_pending_s` exceeds a threshold (set from measured arrival vs drain
rate — start conservative, e.g. 15 min for `emails`/`maintenance`), raise an **operational
alert**. The first remedy is **more frequent short invocations or added parallel workers**,
not a longer per-run runtime that risks the hosting timeout. Repeated `lease_lost`/`reaped`
counts indicate runs exceeding their budget or overlapping unhealthily — shorten budgets or
stagger schedules. Low-stock alerts are separate: set `INVENTORY_LOW_STOCK_THRESHOLD_MILLI`
and `OPS_ALERT_EMAIL`, delivered via the `emails` queue.

The Developer console surfaces real metrics once a service is wired; a sample UI is **not**
telemetry evidence — read the SQL/worker stats above for truth.

---

## 7. Backup, restore and recovery

**Source of truth**: the MySQL/InnoDB database (money, orders, inventory, permissions, queue
state). **Private assets**: uploaded product images under `⟨APP⟩/assets/products/` and the
`⟨PRIVATE⟩` tree (logs/monitoring are operational, not authoritative). Public cache is
regenerable and need not be backed up.

### 7.1 Backup (operator-authorized)

`Tools/deployment/remote.sh` already takes a mode-700 private backup per deploy into
`⟨DEPLOY_STATE⟩/backups/<UTCstamp>-<run>/`:

- `website.tar.gz` — `tar -czf` of the published tree.
- `database.sql` — `mysqldump --defaults-extra-file=⟨cnf⟩ --single-transaction --quick
  --skip-lock-tables --no-tablespaces --hex-blob ⟨schema⟩` (consistent snapshot on InnoDB),
  mode 0600. The `⟨cnf⟩` client file is generated by `Tools/deployment/database-backup.php`
  from `.env` (credentials never echoed) and deleted after use.

For a standalone backup outside a deploy, run the same `mysqldump` against the app DB user's
read path into a mode-0700 private directory; never write dumps under the document root, and
never pass the password on the command line (use the generated `--defaults-extra-file`).

### 7.2 Restore into an isolated target (rehearsal)

Never restore onto the live database. Recovery rehearsal:

1. Provision a **disposable** MySQL schema on an isolated host/port.
2. Load `database.sql` into it; extract `website.tar.gz` into an isolated app root with its
   own `.env` pointing at the disposable schema.
3. **Disable all provider sends** during rehearsal: `MAIL_TRANSPORT=disabled` (or `test`),
   `CHECKOUT_RAZORPAY_ENABLED=false`, OTP channels off. A restored DB can contain
   already-delivered notification/payment intents; replaying them must not re-send.
4. Verify integrity: row counts for `orders`/`order_items`/`payments`, order status-history
   consistency, inventory movements/reservations balance, idempotency/dedupe keys intact, and
   queue state (`jobs`/`job_failed`) sane. Run `vayu migrate` — it must report **0 pending**
   on a current backup.
5. Drain the queue with sends disabled and confirm handlers are idempotent (at-least-once, so
   duplicates must be absorbed, not doubled).

### 7.3 Recovery policy

- **Reconciliation before enabling sends is mandatory.** After a real restore, reconcile
  payments against the Razorpay dashboard and mark already-delivered notifications before any
  worker with live transports runs — handlers are at-least-once, never exactly-once.
- Document, per incident, the **restoring service order**: DB first → verify migrations →
  reconcile external intents → re-enable `emails`/`maintenance` workers → re-enable payment
  capture/webhooks last.
- Keep the maintenance gate (`remote.sh`) engaged until verification passes; `remote.sh`
  already leaves the site in maintenance with the private backup path on a failed deploy.

---

## 8. Pending probes (HST-01) — do not mark observed

These stay **pending** until an operator records them (see `hosting-capabilities.md`); none
are assumed by this runbook:

- Account **cron** minimum interval, overlap policy and per-run **PHP/runtime limit**.
- PHP **CLI path/version** and `memory_limit` for cron (`⟨PHP⟩`, `⟨APP⟩`).
- Actual writable **private path** outside the document root and its **HTTP-deny** rule
  (verify `.env`, `database.sql`, `*.log` return 403/404 over HTTP).
- **Outbound SMTP** policy and provider delivery (operator must configure + rotate creds).
- Optional **Redis** availability/outbound policy (not required).

Record sanitized results in `hosting-capabilities.md`; never paste connection passwords,
tokens or SMTP credentials. OPS-02 acceptance (disposable-MySQL concurrency/queue/cache/load
review) remains separate and depends on these probes.
