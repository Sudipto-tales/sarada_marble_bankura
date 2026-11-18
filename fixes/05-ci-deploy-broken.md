# 05: CI and Hostinger deploy fail on every push since Nov 15

## Evidence (GitHub Actions)
- Every `CI` and `Deploy website to Hostinger` run fails. The last green deploy was **2026-11-14** (run 43605734318, commit `0ffdcdb`). **Production still runs that old code.**
- Error: `Could not open input file: tests/FoundationTest.php` → exit 1.
- The "chore(website): sync pending changes" commits **deleted 36 test files** in `Website/tests/` (only `DeveloperAccessTest.php` is left), including `admin_http_test.py` and `AuthSecurityTest.php`. Both workflows still call them.
- Warning: `session_regenerate_id(): Session ID cannot be regenerated when there is no active session` (`core/DeveloperAccess.php:73`).

## Fix A: restore the tests (recommended)
```bash
git checkout 0ffdcdb -- Website/tests
git checkout 0ffdcdb -- Website/database/seeders/KajariaCatalogSeeder.php Website/database/seeders/kajaria Website/database/seeders/DemoCatalogSeeder.php Website/database/seeders/catalog.php
```
Then run the tests locally. Several **will** fail because of the variant → product schema change (fix 02). Update each failing test to the new schema. **Don't delete them.** They are the only proof that money and stock still work.

(Fallback only, if a test truly covers a removed feature: delete that one test **and** its line in `.github/workflows/ci.yml` and `hostinger-deploy.yml` in the same commit.)

## Fix B: `php vayu migrate` hard-crashes after migrating
`Website/vayu` (migrate command) does `require_once __DIR__ . '/database/seeders/KajariaCatalogSeeder.php';`, but that file was deleted. A failed `require_once` is a **fatal** error (not catchable), so migrate exits 255 and the deploy aborts. Fix A restores the file. Otherwise guard it:
```php
$seeder = __DIR__ . '/database/seeders/KajariaCatalogSeeder.php';
if (!is_file($seeder)) { echo "Catalog seed skipped: seeder not bundled.\n"; exit(0); }
require_once $seeder;
```
`demo:seed` and `catalog:import` need the same guard.

## Fix C: warning in `DeveloperAccess::login()`
`Website/core/DeveloperAccess.php` line 73. Make sure a session exists first:
```php
            self::session();
            session_regenerate_id(true);
```

## Fix D: `composer.lock` is git-ignored
`Website/.gitignore` ignores `composer.lock`, so CI resolves "latest" each run (seen in the log: "No composer.lock file present"). Remove the `composer.lock` line from `.gitignore`, run `composer update` once, and commit the lock file. Builds become repeatable and the cache key works.

## Fix E: Node 20 action deprecation (warning only)
Bump `actions/checkout@v4` → `@v5` and `actions/cache@v4` → `@v5` when convenient.

## Test
```bash
cd Website
for t in tests/*Test.php; do php "$t" || echo "FAIL $t"; done
python3 tests/storefront_http_test.py && python3 tests/admin_http_test.py
```
Push to a branch, open a PR, and the **CI** workflow goes green. After merge, **Deploy** goes green and production gets the fixes from 01–04.

## Done when
Both workflows pass. **STOP. Report and ask for "next".**
