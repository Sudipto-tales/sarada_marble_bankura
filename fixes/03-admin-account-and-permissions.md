# 03: Admin can't log in (bad credentials, or 403 "no staff access")

## How admin permission works
Admin access does **not** come from `users_tbl.role`. It comes from:
`users_tbl` → `user_roles` → `roles` → `role_permissions` → `permissions` (`admin.*` keys).
`Admin::login()` returns 403 *"This account does not have staff access."* when the user has no `admin.*` permission.
The user also needs `status=1`, `email_verify=1`, and must not be locked (`locked_until`).

## Root causes
`Website/database/seeders/RolesAndUsersSeeder.php` (new on Nov 15) is broken in three ways:
1. **It stores the password in plain text.** It inserts `'Password123!'` as-is. `Auth` uses `password_verify()`, so login always fails with "invalid credentials".
2. **It never inserts a `user_roles` row.** It only fills `users_tbl.role`, which permissions ignore. Even with a working password you get **403 no staff access**.
3. **`INSERT OR IGNORE` works only on SQLite.** On MySQL (Hostinger) it is a syntax error.

In addition, `Website/app/page/admin/login.php` pre-fills `admin@test.local` / `Admin@Bankura2026` in non-production. Nothing creates that account, so the hint misleads.

Also note: in the old deployed code `Admin::elevated()` returned `true` for a signed-in user **without** admin permissions. Any customer could reach admin pages (privilege escalation). The current `return false` is correct. Keep it.

## Fix A: create staff the supported way (works now, no code change)
```bash
cd Website
php vayu commerce:create you@yourdomain.com superadmin   # prompts for a 12–72 byte password
```
`CommerceAccess::provision()` hashes the password, sets it verified and active, and inserts `user_roles`.
Production: run it over SSH in the deployed `Website/` directory.

## Fix B: repair the seeder (dev only)
Replace the body of `run()` in `RolesAndUsersSeeder.php`:
```php
public function run(PDO $pdo): array
{
    $users = [
        ['superadmin@sarada.test', 'superadmin', 'Super Admin'],
        ['admin@sarada.test', 'admin', 'Admin User'],
        ['catalog@sarada.test', 'catalog_manager', 'Catalog Manager'],
        ['ops@sarada.test', 'operations', 'Operations'],
        ['viewer@sarada.test', 'viewer', 'Viewer'],
        ['customer@sarada.test', 'customer', 'Customer'],
    ];
    $results = [];
    foreach ($users as [$email, $role, $name]) {
        // provision() hashes, verifies, inserts user_roles, and refuses duplicates.
        try { $results[] = ['email' => $email, 'role' => $role, 'id' => CommerceAccess::provision($email, 'Password123!dev', $role, $name)]; }
        catch (CommerceProvisioningRejected $e) { $results[] = ['email' => $email, 'skipped' => $e->getMessage()]; }
    }
    return $results;
}
```
Also add at the top of the file: `if (env('APP_ENV', 'production') === 'production') throw new RuntimeException('Dev seeder refused in production.');`.
(Roles and permissions are already created by migrations 0003 and 0023, so the seeder must not insert roles. The `developer` role belongs to `developer_accounts` and is created with `php vayu developer:create`.)

## Fix C: dev hint in `login.php`
Change `admin@test.local` → `superadmin@sarada.test` and `Admin@Bankura2026` → `Password123!dev`, to match the seeder.

## Check an existing account directly in the DB
```sql
SELECT u.id,u.email,u.status,u.email_verify,u.locked_until,r.name role,
       (SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id
         WHERE rp.role_id=r.id AND p.permission_key LIKE 'admin.%') admin_perms
FROM users_tbl u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id
WHERE u.email='you@yourdomain.com';
```
Expected: status 1, email_verify 1, locked_until NULL or in the past, role superadmin/admin, admin_perms > 0.
If the row exists but has no role, grant one (as a superadmin, from the Staff page, or with SQL):
```sql
INSERT INTO user_roles (user_id, role_id) SELECT u.id, r.id FROM users_tbl u, roles r WHERE u.email='you@yourdomain.com' AND r.name='superadmin';
```
If you are locked out after 5 attempts in 15 minutes: `DELETE FROM auth_login_limits;`

## Test
1. Run `php vayu commerce:create t1@local.test admin`, then log in at `/admin/login`. You get a 303 to `/admin`.
2. Log in as a customer-only account. You get 403 "This account does not have staff access.", and `/admin` redirects to login.
3. Run the SQL check above for the account. It passes.

## Done when
A provisioned admin logs in and lands on `/admin`. **STOP. Report and ask for "next".**
