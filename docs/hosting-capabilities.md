# Hosting capability record

Observed locally on 2026-10-05 for HST-01. No Hostinger account was accessed. Local results do not establish account limits or production readiness.

| Capability | Local observation | Hostinger account |
| --- | --- | --- |
| OS/runtime | Ubuntu 24.04; PHP 8.3.6, 64-bit | Unverified |
| PHP CLI | Not installed system-wide; isolated Ubuntu packages extracted under /tmp/sarada-foundations-runtime | Path/version/access unverified |
| PDO | SQLite and MySQL enabled in isolated runtime | Drivers unverified |
| Database | Disposable SQLite 3.45.1, Oracle MySQL 8.0.46 and MariaDB 10.11.14; MySQL-driver tests on both servers | Product/version/engine unverified |
| MySQL settings | InnoDB, utf8mb4, native prepares tested; non-default local port | Charset/collation/named-lock permissions unverified |
| CLI execution limit | 0 (unlimited CLI default) | Cron/PHP timeout unverified |
| PHP memory limit | 128 MiB in isolated runtime | Unverified |
| Cron | No account cron test | Minimum interval and overlap limits unverified |
| Web entry/rewrite | PHP development server and subdirectory/query-mode tests | Document root and Apache rewrite unverified |
| Private storage | Temporary local paths writable; existing development-router denial checked | Account private path and HTTP deny rules unverified |
| Redis | No Redis extension in isolated runtime; not required | Availability/outbound policy unverified |
| SMTP/outbound | No provider delivery or network policy test | Unverified |
| Secrets | No account credentials supplied or printed | Operator configuration needed |

Migration tests select named-lock serialization through PDO MySQL and a private/local file lock for SQLite. This does not yet select a queue claim algorithm: queue capability probes, runtime deadlines and cache paths belong to their implementation tasks.

Next operator observations: PHP CLI path/version, enabled drivers, DB product/version and allowed named locks, actual cron interval/runtime, private writable path outside public document root, rewrite/denial probes, outbound policy and optional Redis. Record sanitized results here; never paste connection passwords, tokens or SMTP credentials.

HST-01 remains open until account facts are observed. Independent local foundation work can proceed.
