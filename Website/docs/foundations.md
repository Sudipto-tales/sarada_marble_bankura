# Implemented Vayu foundations

Implemented for FND-01/FND-02 on 2026-10-05 and FND-03 on 2026-10-06. Commerce grants, domain tables, queues and storefront modules still follow the [tracker](../../Docs/commerce/tracking.md).

## Database and migration CLI

From Website, run:

```bash
php vayu migrate
php vayu developer:create you@example.com
php vayu run
```

Install Composer dependencies to load a local .env file. Exported environment variables also work without Composer. The [CLI bootstrap](../config/cli.php) shares [runtime configuration](../config/runtime.php) with HTTP without dispatching routes or starting a session.

[database_connect](../config/db.php#L4) supports SQLite and MySQL; other drivers fail explicitly. SQLite paths are relative to Website unless absolute or :memory:. SQLite enables foreign keys and a 5,000 ms busy timeout. MySQL applies DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, utf8mb4 and native prepares. Fetches default to associative arrays on both drivers. Services must share this PDO for atomic work.

The [runner](../config/migrate.php#L8) acquires a connection-level MySQL named lock or a SQLite file lock before reading the ledger. Lock wait is bounded to 10 seconds. Do not unlink a live SQLite lock file: all processes must refer to the same inode.

UsersTable remains the first legacy ledger identity. New filenames require numeric ordering prefixes, for example 0001_users_foundation.php defines UsersFoundation. Every migration extends Migration and implements actual rerunnable up behavior and a verify method that checks its required schema. Registration rejects missing/duplicate classes before executing DDL. The ledger has a unique migration identity.

[UsersFoundation](../database/migrations/0001_users_foundation.php) explicitly repairs indexes for already-recorded user migrations. Existing identities and hashes are preserved. Incompatible columns, identity types, indexes, engines or collations cause a safe failure rather than a silent data rewrite. There are no seeded demo accounts.

SQLite migration DDL and its ledger insertion share a transaction. MySQL migration DDL can survive a failure, so each pending migration must inspect and repair missing compatible changes before recording success. Completed migrations are verified after forward repairs run. CLI failures return a nonzero status without SQL, DSNs or row values; inspect the named migration and schema, repair the incompatibility, and rerun. Do not delete ledger entries to force production reruns.

## Routes and class loading

Legacy route entries remain [ControllerClass, method]; their controller guards still decide allowed HTTP methods. New entries can use explicit method maps:

```php
'product/{slug}' => ['GET' => ['StorefrontProductController', 'show']],
'api/v1/cart' => [
    'GET' => ['StorefrontCartController', 'read'],
    'POST' => ['StorefrontCartController', 'change'],
],
```

These commerce names illustrate registration; their modules do not exist yet. Add each real controller/service to [config/classes.php](../config/classes.php) with a fixed Website-relative PHP file. Names must be globally unique, including case-insensitive collisions; classes in Admin and Storefront folders need distinct names. Requests never select a filename.

[RouteManager](../core/RouteManager.php) validates route definitions before dispatch. Exact routes take priority over templates. Only {slug} and {id} are allowed, at whole segment boundaries, passed in declared order. A slug is lowercase letters/digits separated by single hyphens, at most 120 characters. An ID is a positive decimal integer with no leading zero and must fit PHP_INT_MAX.

The default root, ?route= mode and subdirectory installation are preserved. Deployment prefixes are removed only at a complete segment boundary. Malformed encoding, encoded separators/NUL, double encoding, dot segments, controls and oversized paths are rejected. Template ambiguity is rejected during registration. Unknown paths return 404; new method maps return 405 and Allow. API dispatch errors return error.code, error.message and request_id as JSON.

No HTTP method is implicitly granted, including HEAD/OPTIONS. Register those methods explicitly when needed. Views still require the complete app/page/...php path.

## Request, session and auth protections

[RequestSecurity](../core/RequestSecurity.php) starts strict cookie-only sessions before output, stores session files outside Website, and uses host-only HttpOnly/SameSite=Lax cookies. Production always uses Secure cookies. On development/staging, direct HTTPS, SESSION_SECURE_COOKIE or an exact TRUSTED_PROXIES client IP with X-Forwarded-Proto=https can enable Secure cookies; untrusted forwarded headers are ignored. Configure the account's real HTTPS/proxy policy before launch.

[PrivateStorage](../core/PrivateStorage.php) accepts an absolute PRIVATE_STORAGE_PATH outside the Website document root, resolves symlink targets, and creates restricted directories (0700). The local default is the ignored repository-root var directory. Configure a genuinely private account path on hosting; do not assume its document root matches this checkout. Sessions and local test mail use this path. [.htaccess](../.htaccess) requires Apache 2.4 with AllowOverride and denies private directories, hidden files, database/log/config files and PHP scripts except index.php before existing-file bypass. Local Apache tests include root and subdirectory installs. Other web servers require equivalent configuration. Real-account probes remain HST-01/OPS acceptance work.

[Auth](../core/Auth.php) requires a mutation HTTP method and session-bound CSRF for login, register, resend and logout. Call RequestSecurity::csrfToken() after resolving the identity, because remember authentication rotates the session and token. Cookie-authenticated JSON mutations can use X-CSRF-Token; form mutations use csrf. RequestSecurity::requireMutation() is shared by future controllers. jsonBody() enforces JSON objects, content type, nesting and a bounded body; these parsers do not replace business validation or authorization. Rejected requests expose safe status/code/message; index.php sanitizes infrastructure errors as HTTP 503 without SQL/provider details.

Auth uses the current bootstrapped PDO; it never opens another connection. Login normalizes email and validates password, enabled status, verification and UTC lock expiry. Persistent atomic counters limit attempts by direct client IP and normalized email across sessions, saturate at six, and reset after a 15-minute window. Cleanup per accepted attempt is indexed and bounded. Existing mixed-case emails work on both drivers; SQLite gains a NOCASE unique email index in the explicit auth upgrade. Case-colliding legacy SQLite identities require operator repair before migration can complete.

Successful auth regenerates the session ID and commerce CSRF. The commerce session stores user_id as its identity reference, without a legacy role/email authority snapshot; protected requests re-read current user state. Developer identities and their CSRF keys remain separate. Commerce logout clears only commerce keys and revokes remember proofs, preserving Developer access. Staff grants are still IAM-01/02 work; neither a legacy users_tbl.role nor Developer access is a commerce grant.

[0002_auth_protections](../database/migrations/0002_auth_protections.php) explicitly revokes old users_tbl raw remember/verification tokens. New remember credentials contain a random selector and validator; only the validator hash is stored, with a server-side absolute 30-day expiry and revocation. A successful restoration rotates the validator with a conditional update; stale/replayed/concurrent proofs cannot restore another session. Rotation does not extend expiry. Verification proofs are hashed, server-expiring after one hour and consumed in a transaction with verification status; a resend replaces the prior proof. New verification links contain just the proof; verifyEmail(null, token) resolves its owner, while existing ID-plus-token callers must match that owner. Verification links are the explicit CSRF exception, authorized by a single-use proof. No public customer auth routes are introduced in FND-03.

Signup and verification delivery default disabled. Before INF-02 integrates queued delivery, only APP_ENV=development (or another non-production environment), AUTH_REGISTRATION_ENABLED=true and AUTH_VERIFICATION_TRANSPORT=test permit local signup. This writes private test mail files (0600) with the configured APP_URL verification link and performs no network delivery. Production rejects this test transport. Client role/designation input is ignored; signup always creates an unverified customer. Private test mail is staged before DB locks; setup failure leaves no user/token. A failed DB write rolls back user/token and removes staged mail after rollback. Test outbox files contain private verification proofs and must remain outside public access.

[Mailer](../core/Mailer.php) is a worker transport adapter, default disabled. SMTP requires explicit MAIL_TRANSPORT=smtp and operator-supplied environment settings, TLS/SSL, finite deadlines and sanitized errors. Auth never calls SMTP in the request. The previously embedded mail credential was removed from source; the operator must rotate/revoke it and provision a replacement before real delivery. Local adapter checks do not prove provider acceptance. INF-02 owns queue integration and live delivery testing.

## Verification commands

```bash
php tests/DeveloperAccessTest.php
php tests/AuthSecurityTest.php
python3 tests/auth_http_test.py
php tests/FoundationTest.php
php tests/RoutingTest.php
python3 tests/routing_http_test.py
TEST_MYSQL_PORT=3306 php tests/FoundationTest.php --mysql
TEST_MYSQL_PORT=3306 php tests/AuthSecurityTest.php --mysql
```

FoundationTest creates a random vayu_foundation_test_* schema for MySQL and drops only that schema in its cleanup. TEST_MYSQL_HOST, TEST_MYSQL_PORT, TEST_MYSQL_USER and TEST_MYSQL_PASSWORD configure a disposable local server; the test user needs permission to create/drop test databases. Do not point tests at production. SQLite tests use temporary files.

The HTTP test uses temporary SQLite, local ephemeral ports and stops its test servers. Set TEST_PHP_BINARY when PHP is not on PATH. Actual versions/results and pending hosting checks are recorded in the [tracker](../../Docs/commerce/tracking.md) and [capability record](hosting-capabilities.md).

PDO options follow the [PHP manual](https://www.php.net/manual/en/pdo.setattribute.php); migration locking follows the [MySQL locking-functions manual](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html). Connection-level migration serialization does not establish future checkout/queue concurrency correctness.

AuthSecurityTest also uses random disposable schemas/private storage and independent child processes to race remember restoration and persistent throttles. auth_http_test.py copies sources into a temporary fixture, runs ephemeral local servers and cleans up. For Apache checks set TEST_APACHE_BINARY, TEST_APACHE_MODULES and TEST_APACHE_PHP_MODULE; otherwise only the PHP server is tested. No production databases or accounts are contacted.
