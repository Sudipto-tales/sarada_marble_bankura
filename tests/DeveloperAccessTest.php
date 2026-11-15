<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/DeveloperAccess.php';
require_once __DIR__ . '/../core/RequestSecurity.php';

$pdo = null;

function newPdo(): \PDO
{
    $pdo = new \PDO('sqlite:' . sys_get_temp_dir() . '/developer_access_test.db');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS developer_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(40) NOT NULL,
        permissions TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS developer_login_attempts (
        client_key VARCHAR(64) NOT NULL,
        attempted_at BIGINT NOT NULL)');
    return $pdo;
}

function closePdo(): void
{
    global $pdo;
    $pdo = null;
    foreach (glob(sys_get_temp_dir() . '/developer_access_test*.db') as $db) {
        @unlink($db);
    }
}

final class DeveloperAccessTest
{
    public static function main(): void
    {
        $errors = [];

        try { self::testCanProvisionDeveloperAccount(); } catch (Throwable $e) { $errors[] = 'testCanProvisionDeveloperAccount: ' . $e->getMessage(); }
        try { self::testCanLoginWithProvisionedAccount(); } catch (Throwable $e) { $errors[] = 'testCanLoginWithProvisionedAccount: ' . $e->getMessage(); }
        try { self::testLoginReturnsErrorForInvalidCredentials(); } catch (Throwable $e) { $errors[] = 'testLoginReturnsErrorForInvalidCredentials: ' . $e->getMessage(); }
        try { self::testLoginReturnsErrorWhenDisabled(); } catch (Throwable $e) { $errors[] = 'testLoginReturnsErrorWhenDisabled: ' . $e->getMessage(); }
        try { self::testCsrfValidation(); } catch (Throwable $e) { $errors[] = 'testCsrfValidation: ' . $e->getMessage(); }

        if (empty($errors)) {
            echo "All tests passed!\n";
        } else {
            echo "Failed tests:\n";
            foreach ($errors as $e) { echo "  - $e\n"; }
            exit(1);
        }
    }

    private static function testCanProvisionDeveloperAccount(): void
    {
        global $pdo;
        $pdo = newPdo();
        $email = 'test_' . uniqid() . '@example.com';
        $password = 'SecurePassword123!';
        DeveloperAccess::provision($email, $password);

        $query = $pdo->prepare('SELECT id, email, role, permissions, enabled FROM developer_accounts WHERE email = ?');
        $query->execute([$email]);
        $account = $query->fetch(PDO::FETCH_ASSOC);

        if ($account === false) throw new \RuntimeException('Account not found');
        if ($account['email'] !== $email) throw new \RuntimeException('Email mismatch');
        $permissions = json_decode($account['permissions'], true) ?: [];
        if (!is_array($permissions)) throw new \RuntimeException('Permissions not array');
        if ($account['enabled'] !== 1) throw new \RuntimeException('Enabled should be 1');

        closePdo();
    }

    private static function testCanLoginWithProvisionedAccount(): void
    {
        global $pdo;
        $pdo = newPdo();
        $email = 'test2_' . uniqid() . '@example.com';
        $password = 'AnotherSecurePass123!';
        DeveloperAccess::provision($email, $password);

        $result = DeveloperAccess::login($email, $password);
        if ($result !== '') throw new \RuntimeException('Login should return empty string, got: ' . $result);

        closePdo();
    }

    private static function testLoginReturnsErrorForInvalidCredentials(): void
    {
        global $pdo;
        $pdo = newPdo();
        $email = 'test3_' . uniqid() . '@example.com';
        DeveloperAccess::provision($email, 'DifferentSecurePass123!');

        $result = DeveloperAccess::login($email, 'WrongPassword');
        if ($result === '') throw new \RuntimeException('Login should fail with wrong password');

        closePdo();
    }

    private static function testLoginReturnsErrorWhenDisabled(): void
    {
        global $pdo;
        $pdo = newPdo();
        $email = 'test4_' . uniqid() . '@example.com';
        $password = 'SecurePassword123!';
        DeveloperAccess::provision($email, $password);

        $query = $pdo->prepare('UPDATE developer_accounts SET enabled = 0 WHERE email = ?');
        $query->execute([$email]);

        $result = DeveloperAccess::login($email, $password);
        if ($result === '') throw new \RuntimeException('Login should fail for disabled account');

        closePdo();
    }

    private static function testCsrfValidation(): void
    {
        global $pdo;
        $pdo = newPdo();
        $email = 'test6_' . uniqid() . '@example.com';
        $password = 'SecurePassword123!';
        DeveloperAccess::provision($email, $password);
        DeveloperAccess::login($email, $password);

        $csrf = $_SESSION['developer_csrf'] ?? '';
        if (!$csrf) throw new \RuntimeException('CSRF token not set');

        if (DeveloperAccess::csrf()) throw new \RuntimeException('csrf() should return false when called with no POST data after login');

        closePdo();
    }
}

DeveloperAccessTest::main();