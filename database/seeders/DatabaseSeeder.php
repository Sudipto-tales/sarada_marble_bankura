<?php

final class DatabaseSeeder
{
    public function run(PDO $pdo): array
    {
        $results = [];

        echo "Running RolesAndUsersSeeder...\n";
        $rolesSeeder = new RolesAndUsersSeeder();
        $results['roles_users'] = $rolesSeeder->run($pdo);

        echo "Running CatalogSeeder...\n";
        $catalogSeeder = new CatalogSeeder($pdo);
        $results['catalog'] = $catalogSeeder->run();

        echo "Database seeding complete.\n";
        return $results;
    }
}