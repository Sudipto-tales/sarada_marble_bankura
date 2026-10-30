<?php

/** A single top role that owns staff-access management. Only `superadmin` may create users or
    change grants; `admin` keeps every operational permission but loses admin.access.*, so no
    other role can mint accounts. The root superadmin is still provisioned by CLI (operator password). */
class StaffSuperadmin extends Migration
{
    public function up()
    {
        $insert = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $this->pdo->exec("$insert INTO roles (name) VALUES ('superadmin')");
        // Superadmin holds every permission, including any added later.
        $this->pdo->exec("$insert INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name = 'superadmin'");
        // Strip staff-access management from every role except superadmin (existing deployments granted it to admin/viewer).
        $this->pdo->exec("DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE name <> 'superadmin')
            AND permission_id IN (SELECT id FROM permissions WHERE permission_key IN ('admin.access.view','admin.access.edit'))");
        $this->verify();
    }
    public function verify(): void
    {
        $superadmin = (int)$this->pdo->query("SELECT COUNT(*) FROM roles WHERE name = 'superadmin'")->fetchColumn();
        if ($superadmin !== 1) throw new RuntimeException('Superadmin role is missing.');
        $grants = (int)$this->pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
            JOIN permissions p ON p.id = rp.permission_id WHERE r.name = 'superadmin' AND p.permission_key = 'admin.access.edit'")->fetchColumn();
        if ($grants !== 1) throw new RuntimeException('Superadmin is missing staff-access management.');
        $leaked = (int)$this->pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
            JOIN permissions p ON p.id = rp.permission_id WHERE r.name = 'admin' AND p.permission_key LIKE 'admin.access.%'")->fetchColumn();
        if ($leaked !== 0) throw new RuntimeException('Admin role still owns staff-access management.');
    }
}
