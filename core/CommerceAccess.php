<?php

require_once __DIR__ . '/Auth.php';

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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($password) < 12 || strlen($password) > 72
            || $name === '' || strlen($name) > 190 || !preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f]/', $name)
            || !in_array($role, ['admin', 'catalog_manager', 'operations', 'viewer'], true)) {
            throw new InvalidArgumentException('Use a valid email, staff role, name and password of 12 to 72 bytes.');
        }
        if ($pdo->inTransaction()) throw new RuntimeException('Provisioning requires its own transaction.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare('SELECT id FROM roles WHERE name = ?'); $query->execute([$role]);
            $roleId = $query->fetchColumn();
            if (!$roleId) throw new RuntimeException('Apply commerce migrations before provisioning.');
            $pdo->prepare("INSERT INTO users_tbl (firstname, lastname, name, email, password, role, email_verify, status)
                VALUES (?, '', ?, ?, ?, 'user', 1, 1)")->execute([$name, $name, $email, $hash]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $roleId]);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new RuntimeException('Staff account could not be created. Check the email, role, password and migration state.');
        }
    }
}
