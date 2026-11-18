<?php

final class RolesAndUsersSeeder
{
    public function run(PDO $pdo): array
    {
        if (env('APP_ENV', 'production') === 'production') throw new RuntimeException('Dev seeder refused in production.');

        $results = [];

        // --- Roles (conceptual; framework uses role column on users_tbl) ---
        $roles = ['superadmin', 'admin', 'catalog_manager', 'operations', 'viewer', 'customer', 'developer'];
        foreach ($roles as $role) {
            $pdo->exec("INSERT OR IGNORE INTO roles (name) VALUES ('{$role}')");
        }
        $results['roles'] = $roles;

        // --- Users ---
        $users = [
            ['email' => 'superadmin@sarada.test', 'role' => 'superadmin', 'name' => 'Super Admin'],
            ['email' => 'admin@sarada.test', 'role' => 'admin', 'name' => 'Admin User'],
            ['email' => 'catalog@sarada.test', 'role' => 'catalog_manager', 'name' => 'Catalog Manager'],
            ['email' => 'ops@sarada.test', 'role' => 'operations', 'name' => 'Operations'],
            ['email' => 'viewer@sarada.test', 'role' => 'viewer', 'name' => 'Viewer'],
            ['email' => 'customer@sarada.test', 'role' => 'customer', 'name' => 'Customer'],
        ];

        foreach ($users as [$email, $role, $name]) {
            // provision() hashes, verifies, inserts user_roles, and refuses duplicates.
            try { $results[] = ['email' => $email, 'role' => $role, 'id' => CommerceAccess::provision($email, 'Password123!dev', $role, $name)]; }
            catch (CommerceProvisioningRejected $e) { $results[] = ['email' => $email, 'skipped' => $e->getMessage()]; }
        }

        return $results;
    }
}