<?php

require_once __DIR__ . '/RequestSecurity.php';

/** Dedicated console identities; customer sessions never grant console access. */
final class DeveloperAccess
{
    public const PAGES = [
        'overview' => ['Overview', 'System health and recent observations', 'developer.view'],
        'apis' => ['API registry', 'Endpoints, contracts and request history', 'monitor.apis.read'],
        'server' => ['Server load', 'Runtime samples and infrastructure capacity', 'monitor.server.read'],
        'workflows' => ['Module flows', 'Commerce dependencies and correlated execution', 'monitor.workflows.read'],
        'incidents' => ['Incidents', 'Failures, investigations and recovery', 'monitor.incidents.read'],
        'activity' => ['Live activity', 'Request events and operational changes', 'monitor.events.read'],
        'access' => ['Roles & permissions', 'Console capabilities and access policy', 'monitor.access.read'],
        'settings' => ['Settings', 'Monitoring configuration and collection roadmap', 'monitor.settings.read'],
    ];

    public static function session(): void
    {
        RequestSecurity::session();
        if (empty($_SESSION['developer_csrf'])) $_SESSION['developer_csrf'] = bin2hex(random_bytes(32));
    }

    public static function csrf(): bool
    {
        return is_string($_POST['csrf'] ?? null)
            && hash_equals($_SESSION['developer_csrf'], $_POST['csrf']);
    }

    public static function identity(): ?array
    {
        global $pdo;
        $id = $_SESSION['developer_id'] ?? null;
        if (!$id) return null;
        if (time() - ($_SESSION['developer_seen'] ?? 0) > 1800) {
            unset($_SESSION['developer_id']);
            return null;
        }
        try {
            $query = $pdo->prepare('SELECT id, email, role, permissions, enabled FROM developer_accounts WHERE id = ?');
            $query->execute([$id]);
            $account = $query->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return null; }
        if (!$account || !$account['enabled'] || !in_array($account['role'], DEVELOPER_ROLES, true)) return null;
        $account['permissions'] = json_decode($account['permissions'], true) ?: [];
        if (!in_array(DEVELOPER_PERMISSION, $account['permissions'], true)) return null;
        $_SESSION['developer_seen'] = time();
        return $account;
    }

    public static function login(string $email, string $password): string
    {
        global $pdo;
        $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
        try {
            // Persistent throttling across sessions, keyed by direct client address.
            $query = $pdo->prepare('SELECT COUNT(*) FROM developer_login_attempts WHERE client_key = ? AND attempted_at > ?');
            $query->execute([$key, time() - 900]);
            if ((int) $query->fetchColumn() >= 5) return 'Too many attempts. Try again in 15 minutes.';
            $query = $pdo->prepare('INSERT INTO developer_login_attempts (client_key, attempted_at) VALUES (?, ?)');
            $query->execute([$key, time()]);
            $pdo->prepare('DELETE FROM developer_login_attempts WHERE attempted_at < ?')->execute([time() - 86400]);
            $query = $pdo->prepare('SELECT * FROM developer_accounts WHERE email = ?');
            $query->execute([strtolower(trim($email))]);
            $account = $query->fetch(PDO::FETCH_ASSOC);
            $valid = password_verify($password, $account['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (!$valid || !$account || !$account['enabled'] || !in_array($account['role'], DEVELOPER_ROLES, true)
                || !in_array(DEVELOPER_PERMISSION, json_decode($account['permissions'], true) ?: [], true)) {
                return 'Invalid credentials or access unavailable.';
            }
            session_regenerate_id(true);
            $_SESSION['developer_id'] = $account['id'];
            $_SESSION['developer_seen'] = time();
            $_SESSION['developer_csrf'] = bin2hex(random_bytes(32));
            return '';
        } catch (Throwable $e) {
            return 'Console account setup is required. Ask your system administrator.';
        }
    }

    public static function provision(string $email, string $password): void
    {
        global $pdo;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            throw new RuntimeException('Use a valid email and a password of at least 12 characters.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = $driver === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS developer_accounts (
            id $id, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL, permissions TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1)");
        $pdo->exec('CREATE TABLE IF NOT EXISTS developer_login_attempts (client_key VARCHAR(64) NOT NULL, attempted_at BIGINT NOT NULL)');
        $permissions = array_values(array_unique(array_merge([DEVELOPER_PERMISSION], array_column(self::PAGES, 2))));
        $query = $pdo->prepare('INSERT INTO developer_accounts (email, password_hash, role, permissions) VALUES (?, ?, ?, ?)');
        $query->execute([strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), 'developer', json_encode($permissions)]);
    }
}
