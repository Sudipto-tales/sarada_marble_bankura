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

Enable SSH and authorize the matching public key. Hostinger documents SSH availability for Premium Web and higher plans. Configure DNS/HTTPS and create your database/user before deploying. Confirm CLI PHP 8.3+ on a 64-bit runtime, PDO MySQL, mbstring, fileinfo, OpenSSL, Composer, rsync, tar, flock, mysqldump, curl, and realpath. Product uploads additionally need GD in the web PHP runtime. Web and CLI PHP configuration can differ.

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
