# Hostinger automatic deployment

Implemented 2026-10-16 under DEPLOY-01. Application sources remain on root `main`; `.github/workflows/hostinger-deploy.yml` deploys only committed `Website/` sources. Generated branches are not edited. Deployment/account evidence is recorded in `Docs/commerce/tracking.md`; implementation is not proof that the production run succeeded.

## GitHub setup

Use the repository Environment named `production`. Its existing application variables are explicitly mapped in the workflow. Database passwords and the SSH private key stay in Environment secrets. The combined `PRODUCTION_ENV` secret is not used.

Required secrets:

| Secret | Purpose |
| --- | --- |
| `HOSTINGER_SSH_PRIVATE_KEY` | Dedicated incoming SSH key; matching public key must be authorized on Hostinger |
| `DB_PASSWORD` | Production database password |
| `MAIL_PASSWORD` | Optional later; email is currently disabled |

Required connection variables: `HOSTINGER_HOST`, `HOSTINGER_PORT`, `HOSTINGER_USER`, `HOSTINGER_KNOWN_HOSTS`, and `APP_URL`. The known-hosts entry must be verified against trusted Hostinger information; scanning a key alone is insufficient. The workflow enforces strict host-key checking. No Hostinger API token or GitHub personal access token is needed.

Application variables: `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, `DEVELOPER_ENABLED`, `DB_TYPE=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES`, queue/cache settings, and the mail/checkout settings explicitly listed in the workflow. Unset optional fields use the deployment script's documented defaults. Registration, SMTP, simulation, and COD remain disabled until their separate production acceptance is implemented; deployment refuses accidental activation.

`HOSTINGER_WEB_ROOT` is deliberately not required. The SSH bootstrap takes the hostname from the HTTPS `APP_URL` and requires exactly `$HOME/domains/<domain>/public_html`. It does not fall back to another website folder. Actual HOME and the target's resolved path are checked on the server. If your hosting uses another layout, adapt and review this script before deploying.

Private runtime storage is created at `$HOME/.sarada-runtime/<domain>`, outside public_html, and written as `PRIVATE_STORAGE_PATH` into the generated `.env`. GitHub does not automatically export settings into Hostinger PHP; the workflow generates and securely transfers the dotenv file explicitly. Dotenv escaping is round-trip tested with quotes, backslashes, dollar expressions, line breaks, and Unicode. The SSH key, generated configuration, and transfer archive are never uploaded as Actions artifacts or printed to logs.

## Server prerequisites

Enable SSH and authorize the matching public key. Hostinger documents SSH availability for Premium Web and higher plans. Configure DNS/HTTPS and create your database/user before deploying. Confirm CLI PHP 8.3+ on a 64-bit runtime, PDO MySQL, mbstring, fileinfo, OpenSSL, Composer, rsync, tar, flock, mysqldump, curl, and realpath. Deployment keeps a compatible default PHP or selects an installed Hostinger PHP 8.3/8.4 binary for this process only; Composer and migrations share that PATH. This does not change hPanel/web PHP settings. Product uploads additionally need GD in the web PHP runtime. Web and CLI PHP configuration can differ.

Disconnect Hostinger's built-in Git auto-deployment for this website. Actions is the single deployment owner. The remote script stops if public_html still contains `.git`; move that metadata outside the document root after disconnecting the old integration. Do not run two deployment systems against one folder.

## Every push / deployment

1. A push to `main` touching the website, deployment tools, or workflow starts validation. Manual runs on main are also supported.
2. Validation lints PHP, runs deployment configuration/failure tests, all PHP application test scripts on disposable SQLite, storefront HTTP checks, and fresh/rerun migration plus demo-seeder checks on a disposable MariaDB 10.11 service. CI test passwords are disposable and unrelated to production.
3. The production job checks that its triggering commit is still main's latest commit. Older runs are skipped. GitHub serializes deployment workflows; a server flock serializes remote changes, and a stored run number rejects older publication attempts.
4. Git-tracked application files are packaged, excluding local dotenv files, databases, vendor, logs, tests, and documentation. Uncommitted/untracked files do not deploy. New Composer dependencies are installed on the server in private staging.
5. SSH verifies the pinned server key and authenticates with the Environment secret. The exact domain folder must already exist. Staging and backups are private under `$HOME/.sarada-deploy`.
6. The server installs dependencies, checks platform requirements, tests the database connection, and verifies private storage before touching the live website.
7. The existing website is archived privately. A temporary `.htaccess` maintenance gate returns HTTP 503; deployment verifies that response through HTTPS before making database changes.
8. A consistent database snapshot is taken with credentials in a private defaults file, never command arguments. Backup failure stops deployment. Pending migrations then run from private staging.
9. New bundled product images are copied without replacing existing uploads. Remaining source files are copied without unrestricted deletion. The generated `.env` is installed owner-readable; the committed `.htaccess` is installed last to end maintenance.
10. The runner verifies the home, shop, and admin login pages, denies public dotenv/config/database access, and confirms the published commit through `deployment.json`.

Server commands include:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
php vayu migrate
```

A migration failure stops publication and leaves maintenance enabled. MySQL DDL may already have partially committed: inspect the failing migration before repairing/rerunning. Do not reset/drop/import the live schema as an automatic retry. A post-publication HTTP failure marks the Actions run failed; it does not automatically roll back code or database. Inspect private backups and the actual site before recovery.

Backups contain sensitive configuration and database records, remain outside public access, and are retained. Review disk space and establish a retention policy. This implementation uses a maintenance window and in-place publication rather than assuming symlink switching works on shared hosting. It deliberately preserves unrelated files; review stale files when removing old application modules. It does not claim a rehearsed database restore, provider delivery, throughput, or complete MVP acceptance.

## Seeders and first-time accounts

`php vayu migrate` initializes required commerce roles/permissions and applies only pending schema changes, then verifies schemas. The existing demo seeder refuses production and is never invoked during deployment. Real products/prices/stock need approved admin entry/import; a database schema update does not import the local development catalog.

Create your admin once through a private interactive SSH terminal in public_html:

```bash
php vayu commerce:create your@email.com admin
```

Supply your own password at the prompt. Developer console accounts are separate and can be provisioned once with `php vayu developer:create your@email.com`; that legacy command currently displays its password input. No default usable admin passwords are seeded.

## Queues / cron

Email and registration are disabled. No cron job is installed by this deployment. Before enabling them, verify SMTP and configure a recurring Hostinger cron command using the actual PHP and website paths:

```bash
cd /actual/domain/public_html && /actual/php vayu queue:work --queues=emails --max=20 --max-seconds=30 >> /actual/private/queue-cron.log 2>&1
```

Choose the hosting plan's supported schedule and confirm overlap, runtime limits, delivery, and private log rotation. This is a short-lived worker, not a daemon, and Redis is not required.

## Deployment status and recovery

Inspect GitHub Actions → Deploy website to Hostinger. A successful push is not deployment success. A missing `HOSTINGER_KNOWN_HOSTS`, rejected SSH key, unavailable command, backup error, migration failure, or HTTP failure produces a failed run with safe context. Correct the prerequisite and rerun the latest main workflow. Never paste secret values in chat or logs.

For a failure after the maintenance gate: private backups are under `$HOME/.sarada-deploy/<domain>/backups/<timestamp>-<run>/`; retain the failed run's staging for inspection. Restore/reconcile code, database, uploads, and external delivery state deliberately. The saved original `.htaccess` can restore routing only after schema/application compatibility has been established. Account runtime checks and restore rehearsal remain pending until actually executed.

## References

- [Hostinger SSH](https://support.hostinger.com/en/articles/1583645-how-to-enable-ssh-access)
- [Hostinger database host/port](https://support.hostinger.com/en/articles/1583223-what-mysql-port-is-used-at-hostinger)
- [GitHub deployment environments](https://docs.github.com/en/actions/concepts/workflows-and-actions/deployment-environments)

## Recorded first run

Commit `831c54f` was pushed to main. [Run 38795355452](https://github.com/Sudipto-tales/sarada_marble_bankura/actions/runs/38795355452) passed validation, including MariaDB 10.11.19 foundation (31 checks), MySQL demo-seeder (14 checks), all 18 SQLite PHP scripts, 99 storefront HTTP checks, and 7 deployment tests. The production job stopped before SSH because `HOSTINGER_KNOWN_HOSTS` is absent. No server files or production database were changed. Confirm the SSH server fingerprint through Hostinger, configure the verified known-hosts entry, then manually run the workflow on latest main. Actual hosting capabilities and deployment completion remain unverified.

## Retry 2026-10-16

User-requested latest-main [run 38799297547](https://github.com/Sudipto-tales/sarada_marble_bankura/actions/runs/38799297547), commit `40563439544544ed830d4ebc83c697d5d1ea92e4`, passed validation but failed before SSH with `Missing SSH private key`. The named production secret exists, but the validator did not receive private-key-format contents. Replace it with the complete dedicated private key through GitHub, not a public key or file path. The user-supplied public known-hosts entry was saved as a production variable. It addresses `46.202.161.3`, whereas configured HOSTINGER_HOST is `46.202.161.33`; reconcile these against Hostinger's actual SSH endpoint and verified host identity. No production changes or migrations occurred during this run.

Attempt 2 of the same run was retried after the operator updated the private-key secret. The configured `.33` endpoint presented exactly the previously supplied ED25519 key; its known-hosts hostname was corrected to `.33` without replacing the trusted key or weakening checking. Deployment again stopped before SSH with `Missing SSH private key`: the secret's name/timestamp exist but its supplied contents still fail the private-key marker validation. No production changes occurred.

Attempt 3 resolved the private-key marker failure and reached Hostinger SSH with strict host verification. Authentication failed for `u402700136@46.202.161.33` with `Permission denied (publickey,password)`. Ensure the matching deployment public key is authorized for that SSH account and that the configured SSH username matches hPanel. Remote bootstrap/publication/migrations did not execute.

Attempt 4 authenticated successfully but stopped at the default PHP 8.2.34 CLI check, before maintenance/backups/migrations/publication. Read-only account checks verified PHP 8.3.35 at `/opt/alt/php83/usr/bin/php` with all required extensions. The deployment runtime selector was added and locally verified with eight deployment tests, including shared Composer/migration runtime and failure when no compatible binary is available.

The PHP repair run `38802051189` passed validation and remote dependency/database checks. It stopped before the database snapshot/migrations because the maintenance `.htaccess` inherited mode 0600 and HTTPS returned 403. Changing that exact generated gate to 0644 returned 503; previous routing was then restored before retry. Deployment now explicitly makes public maintenance rules readable (0644), while staging, credentials and backups remain private. Failure tests enforce this distinction.
