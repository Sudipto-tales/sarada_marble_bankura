<?php

final class RolesAndUsersSeeder
{
    public function run(PDO $pdo): array
    {
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
            ['email' => 'dev@sarada.test', 'role' => 'developer', 'name' => 'Developer'],
        ];

        $password = 'Password123!';
        foreach ($users as $user) {
            $email = $user['email'];
            $role = $user['role'];
            $displayName = $user['name'];
            $parts = explode(' ', $displayName);
            $firstname = $parts[0] ?? $email;
            $lastname = count($parts) > 1 ? $parts[1] : '';

            $stmt = $pdo->prepare(
                "INSERT INTO users_tbl (firstname, lastname, name, email, password, designation, role, email_verify, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([$firstname, $lastname, $displayName, $email, $password, $displayName, $role]);
            $results['users'][] = [
                'email' => $email,
                'role' => $role,
                'id' => $pdo->lastInsertId(),
            ];
        }

        return $results;
    }
}