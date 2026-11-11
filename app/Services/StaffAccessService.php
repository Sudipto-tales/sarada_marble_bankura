<?php

/** Superadmin-only staff administration: create accounts and set a single commerce role each.
    Separate from developer_accounts. Only `superadmin` holds admin.access.edit, so only a
    superadmin reaches these operations; granting or removing `superadmin` additionally requires
    the actor to be one, and the final superadmin cannot be demoted. */
final class StaffAccessService
{
    public const ROLES = ['superadmin', 'admin', 'catalog_manager', 'operations', 'viewer'];
    public function __construct(private PDO $pdo) {}
    private function query(string $sql, array $args = []): PDOStatement { return CommerceDatabase::query($this->pdo, $sql, $args); }

    /** Staff accounts (one row per granted commerce role), newest first. */
    public function roster(int $actorId): array
    {
        CommerceAccess::requirePermission('admin.access.view', $actorId);
        return $this->query("SELECT u.id, u.name, u.email, u.status, u.email_verify, r.name AS role
            FROM users_tbl u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
            WHERE r.name <> 'customer' ORDER BY u.id DESC LIMIT 500")->fetchAll();
    }

    private function roleId(string $role): int
    {
        if (!in_array($role, self::ROLES, true)) CommerceValues::invalid('role');
        $id = $this->query('SELECT id FROM roles WHERE name = ?', [$role])->fetchColumn();
        if (!$id) throw new RuntimeException('Staff role is missing. Run php vayu migrate, then retry.');
        return (int)$id;
    }

    private function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) CommerceValues::invalid('email');
        return $email;
    }

    /** Create a staff account with one role. Password is set by the superadmin (no reusable default). */
    public function createStaff(int $actorId, array $input, string $key): int
    {
        CommerceAccess::requirePermission('admin.access.edit', $actorId); $key = CommerceValues::key($key);
        $email = $this->email((string)($input['email'] ?? ''));
        $name = CommerceValues::text($input['name'] ?? null, 190, 'name');
        $role = (string)($input['role'] ?? '');
        if (!in_array($role, self::ROLES, true)) CommerceValues::invalid('role');
        $password = (string)($input['password'] ?? '');
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) CommerceValues::invalid('password');
        if ($role === 'superadmin' && !CommerceAccess::isSuperadmin($actorId))
            throw new RequestRejected(403, 'forbidden', 'Only a superadmin can create a superadmin.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        return CommerceDatabase::transaction($this->pdo, function () use ($actorId, $email, $name, $role, $hash, $key): int {
            CommerceAccess::requirePermission('admin.access.edit', $actorId);
            $roleId = $this->roleId($role); $lock = CommerceDatabase::lock($this->pdo);
            $prior = $this->query('SELECT target_id FROM admin_activity_log WHERE operation_key=?' . $lock, [$key])->fetchColumn();
            if ($prior) return (int)$prior; // Replayed submit must not create a second account.
            if ($this->query('SELECT id FROM users_tbl WHERE email=?' . $lock, [$email])->fetchColumn())
                throw new RequestRejected(409, 'email_exists', 'That email already exists. Existing accounts are not promoted automatically.');
            $this->query("INSERT INTO users_tbl (firstname, lastname, name, email, password, role, email_verify, status)
                VALUES (?, '', ?, ?, ?, 'user', 1, 1)", [$name, $name, $email, $hash]);
            $id = (int)$this->pdo->lastInsertId();
            $this->query('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $roleId]);
            StaffAudit::record($this->pdo, $actorId, 'access.assign', 'user', $id, $key, ['role' => $role, 'email' => $email]);
            return $id;
        });
    }

    /** Replace a staff user's role with exactly one role (or remove staff access with 'customer'). */
    public function setRole(int $actorId, int $userId, string $role, string $key): void
    {
        CommerceAccess::requirePermission('admin.access.edit', $actorId);
        $userId = CommerceValues::id($userId); $key = CommerceValues::key($key);
        $allowed = array_merge(self::ROLES, ['customer']);
        if (!in_array($role, $allowed, true)) CommerceValues::invalid('role');
        CommerceDatabase::transaction($this->pdo, function () use ($actorId, $userId, $role, $key): void {
            CommerceAccess::requirePermission('admin.access.edit', $actorId);
            $lock = CommerceDatabase::lock($this->pdo);
            if ($this->query('SELECT id FROM admin_activity_log WHERE operation_key=?' . $lock, [$key])->fetchColumn()) return;
            if (!$this->query('SELECT id FROM users_tbl WHERE id=?' . $lock, [$userId])->fetchColumn())
                throw new RequestRejected(404, 'not_found', 'Staff account not found.');
            $current = $this->query("SELECT r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                WHERE ur.user_id=? AND r.name<>'customer' ORDER BY r.name" . $lock, [$userId])->fetchAll(PDO::FETCH_COLUMN);
            if (!$current) throw new RequestRejected(404, 'not_found', 'Staff account not found.');
            $wasSuper = in_array('superadmin', $current, true); $willSuper = $role === 'superadmin';
            // Only a superadmin can grant or revoke the superadmin role.
            if (($wasSuper || $willSuper) && !CommerceAccess::isSuperadmin($actorId))
                throw new RequestRejected(403, 'forbidden', 'Only a superadmin can change a superadmin.');
            // Never leave the store without a superadmin.
            if ($wasSuper && !$willSuper) {
                $supers = (int)$this->query("SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                    WHERE r.name='superadmin'" . $lock)->fetchColumn();
                if ($supers <= 1) throw new RequestRejected(409, 'last_superadmin', 'The last superadmin cannot be demoted.');
            }
            $this->query('DELETE FROM user_roles WHERE user_id=?', [$userId]);
            if ($role !== 'customer') $this->query('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $this->roleId($role)]);
            StaffAudit::record($this->pdo, $actorId, 'access.assign', 'user', $userId, $key, ['role' => $role]);
        });
    }
}
