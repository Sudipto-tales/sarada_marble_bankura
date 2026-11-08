<?php

require_once __DIR__ . '/Auth.php';

/** Safe operator-facing failures; never include SQL, credentials or raw input. */
final class CommerceProvisioningRejected extends RuntimeException {}

final class CommerceAccess
{
    /** Identity and grants are reloaded for every protected operation, never session roles. */
    public static function actor(?int $userId = null): ?array
    {
        global $pdo;
        if ($userId === null) {
            $user = Auth::identity();
        } else {
            $query = $pdo->prepare('SELECT id, email, name, status, email_verify, locked_until FROM users_tbl WHERE id = ?');
            $query->execute([$userId]);
            $user = $query->fetch(PDO::FETCH_ASSOC);
        }
        if (!$user || (int) $user['status'] !== 1 || (int) $user['email_verify'] !== 1
            || ($user['locked_until'] && $user['locked_until'] > gmdate('Y-m-d H:i:s'))) return null;
        $query = $pdo->prepare('SELECT DISTINCT p.permission_key FROM user_roles ur
            JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions p ON p.id = rp.permission_id
            WHERE ur.user_id = ? ORDER BY p.permission_key');
        $query->execute([$user['id']]);
        $user['id'] = (int) $user['id'];
        $user['permissions'] = $query->fetchAll(PDO::FETCH_COLUMN);
        return $user;
    }

    public static function requireActor(?int $userId = null): array
    {
        $actor = self::actor($userId);
        if (!$actor) throw new RequestRejected($userId === null ? 401 : 403, 'unauthenticated', 'An active verified account is required.');
        return $actor;
    }

    public static function requirePermission(string $permission, ?int $userId = null): array
    {
        $actor = self::requireActor($userId);
        if (!in_array($permission, $actor['permissions'], true)) {
            throw new RequestRejected(403, 'forbidden', 'Permission is required.');
        }
        return $actor;
    }

    public static function can(string $permission, ?int $userId = null): bool
    {
        $actor = self::actor($userId);
        return $actor && in_array($permission, $actor['permissions'], true);
    }

    /** The single top role that may create staff and grant/revoke roles, including other superadmins. */
    public static function isSuperadmin(int $userId): bool
    {
        global $pdo;
        $query = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = 'superadmin'");
        $query->execute([$userId]);
        return (bool)$query->fetchColumn();
    }

    /** Guard an already ownership-scoped SQL result; another owner's record is a 404. */
    public static function requireOwned($record, int $actorId): array
    {
        self::requireActor($actorId);
        if (!is_array($record) || !isset($record['user_id']) || (int)$record['user_id'] !== $actorId) {
            throw new RequestRejected(404, 'not_found', 'Record not found.');
        }
        return $record;
    }

    /** Operator only. No reusable passwords or implicit promotion of legacy identities. */
    public static function provision(string $email, string $password, string $role = 'admin', string $name = 'Store Administrator'): int
    {
        global $pdo;
        $email = strtolower(trim($email)); $name = trim($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)
            throw new CommerceProvisioningRejected('Supply a valid email address of at most 190 bytes.');
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0"))
            throw new CommerceProvisioningRejected('Password must contain 12 to 72 bytes and no null bytes. Retry and enter a longer password if needed; input is hidden.');
        if ($name === '' || strlen($name) > 190 || !preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f]/', $name))
            throw new CommerceProvisioningRejected('Supply a valid staff name of at most 190 bytes.');
        if (!in_array($role, ['superadmin', 'admin', 'catalog_manager', 'operations', 'viewer'], true))
            throw new CommerceProvisioningRejected('Role must be superadmin, admin, catalog_manager, operations or viewer.');
        if ($pdo->inTransaction()) throw new RuntimeException('Provisioning requires its own transaction.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare('SELECT id FROM roles WHERE name = ?'); $query->execute([$role]);
            $roleId = $query->fetchColumn();
            if (!$roleId) throw new CommerceProvisioningRejected('Staff role is missing. Run php vayu migrate, then retry.');
            $existing = $pdo->prepare('SELECT id FROM users_tbl WHERE email = ?');
            $existing->execute([$email]);
            if ($existing->fetchColumn())
                throw new CommerceProvisioningRejected('That email already exists. Use a unique email; existing accounts are not automatically promoted.');
            $pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password, role, email_verify, status)
                VALUES (?, '', ?, ?, ?, 'user', 1, 1)")->execute([$name, $name, $email, $hash]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $roleId]);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof CommerceProvisioningRejected) throw $e;
            throw new RuntimeException('Staff account could not be created. Check database availability and run php vayu migrate before retrying.');
        }
    }
}
