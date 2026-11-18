# 06: Security cleanup (files that must not be in git)

Added in the Nov 15 "sync pending changes" commits:

| File | Risk | Action |
|---|---|---|
| `Website/cookie.txt`, `Website/cookie2.txt` | curl cookie jars. They can hold live `PHPSESSID` / `remember_me` session tokens | `git rm`. Then **log out everywhere**: `UPDATE auth_remember_tokens SET revoked_at=UNIX_TIMESTAMP() WHERE revoked_at IS NULL;` and delete the session files |
| `Website/database.sqlite`, `Website/database/db.sqlite`, `Website/database/database.sqlite.migrate.lock` | Local DB, may contain users and password hashes | `git rm --cached` |
| `Website/debug_migrate.php` | **Drops tables** (`DROP TABLE … products, categories, migrations`) | `git rm`. `.htaccess` blocks direct `.php` access today, but one bad rule change would expose it |
| `Website/download_product_images.php` | Ad-hoc script in the web root | Move to `Tools/` or delete |
| `Website/config/migrate.php` (the stderr dump block) | Prints the raw SQL error and trace in the deploy logs | Revert to the previous single-line message, or keep it only when `APP_DEBUG` is set |

## Commands
```bash
git rm Website/cookie.txt Website/cookie2.txt Website/debug_migrate.php
git rm --cached Website/database.sqlite Website/database/db.sqlite Website/database/database.sqlite.migrate.lock
cat >> .gitignore <<'EOF'
# local artefacts
*.sqlite
*.sqlite.migrate.lock
cookie*.txt
Website/debug_*.php
EOF
```
History still contains these files. If the cookie files ever held **production** cookies, revoking the sessions (above) is enough. Rewriting history is optional.

## Also: dev hint on the login page
`login.php` pre-fills credentials whenever `APP_ENV !== 'production'`. Make sure production `.env` has `APP_ENV=production` (the default is safe). A staging site set to `local` would show the hint publicly.

## Test
- `git ls-files | grep -E 'cookie|\.sqlite|debug_'` prints nothing.
- `curl -o /dev/null -w '%{http_code}' https://HOST/debug_migrate.php` returns 403 or 404.

## Done when
**STOP. Report and ask for "next".**
