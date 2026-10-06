<?php

/** Commerce grants never derive from users_tbl.role or developer_accounts. */
class CommerceIdentity extends Migration
{
    public const PERMISSIONS = [
        'admin.products.view' => 'View catalog administration', 'admin.products.edit' => 'Edit products and categories',
        'admin.inventory.view' => 'View stock and movements', 'admin.inventory.adjust' => 'Adjust inventory',
        'admin.orders.view' => 'View customer orders', 'admin.orders.edit' => 'Change order status',
        'admin.customers.view' => 'View customer profiles', 'admin.settings.view' => 'View store settings',
        'admin.settings.edit' => 'Change store settings', 'admin.analytics.view' => 'View aggregate analytics',
        'admin.pricing.view' => 'View pricing previews', 'admin.pricing.edit' => 'Manage bounded pricing rules',
        'admin.access.view' => 'View staff grants', 'admin.access.edit' => 'Manage staff grants',
    ];

    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS roles (id $id, name VARCHAR(40) NOT NULL)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS permissions (id $id, permission_key VARCHAR(80) NOT NULL, description VARCHAR(190) NOT NULL)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (id $id, user_id INT NOT NULL, role_id INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users_tbl(id) ON DELETE CASCADE,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (id $id, role_id INT NOT NULL, permission_id INT NOT NULL,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE)$suffix");
        foreach ([['roles', 'idx_roles_name', ['name']], ['permissions', 'idx_permissions_key', ['permission_key']],
            ['user_roles', 'idx_user_roles_pair', ['user_id', 'role_id']],
            ['role_permissions', 'idx_role_permissions_pair', ['role_id', 'permission_id']]] as [$table, $name, $columns]) {
            MigrationSchema::ensureIndex($this->pdo, $table, $name, $columns, true);
        }
        $this->verify();
        $insert = $mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $roles = ['customer' => [], 'admin' => array_keys(self::PERMISSIONS),
            'catalog_manager' => ['admin.products.view', 'admin.products.edit', 'admin.inventory.view', 'admin.pricing.view'],
            'operations' => ['admin.inventory.view', 'admin.inventory.adjust', 'admin.orders.view', 'admin.orders.edit', 'admin.customers.view'],
            'viewer' => array_values(array_filter(array_keys(self::PERMISSIONS), static fn(string $key): bool => str_ends_with($key, '.view')))];
        foreach (self::PERMISSIONS as $key => $description) {
            $this->pdo->prepare("$insert INTO permissions (permission_key, description) VALUES (?, ?)")->execute([$key, $description]);
        }
        foreach ($roles as $name => $grants) {
            $this->pdo->prepare("$insert INTO roles (name) VALUES (?)")->execute([$name]);
            foreach ($grants as $key) {
                $this->pdo->prepare("$insert INTO role_permissions (role_id, permission_id)
                    SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name = ? AND p.permission_key = ?")->execute([$name, $key]);
            }
        }
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'roles', ['id' => 'id', 'name' => 'varchar(40)'], [[['name'], true]]);
        MigrationSchema::verifyDefinition($this->pdo, 'permissions', ['id' => 'id', 'permission_key' => 'varchar(80)', 'description' => 'varchar(190)'], [[['permission_key'], true]]);
        MigrationSchema::verifyDefinition($this->pdo, 'user_roles', ['id' => 'id', 'user_id' => 'int', 'role_id' => 'int'], [[['user_id', 'role_id'], true]],
            [['user_id', 'users_tbl', 'id', 'CASCADE'], ['role_id', 'roles', 'id', 'CASCADE']]);
        MigrationSchema::verifyDefinition($this->pdo, 'role_permissions', ['id' => 'id', 'role_id' => 'int', 'permission_id' => 'int'], [[['role_id', 'permission_id'], true]],
            [['role_id', 'roles', 'id', 'CASCADE'], ['permission_id', 'permissions', 'id', 'CASCADE']]);
    }
}
