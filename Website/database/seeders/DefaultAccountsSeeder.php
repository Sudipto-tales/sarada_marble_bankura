<?php

final class DefaultAccountsSeeder
{
    public function __construct(private PDO $pdo) {}

    public function run(int $actorId): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Default accounts seeder requires CLI invocation.');
        }

        $result = ['created' => [], 'skipped' => []];

        // 1. Create default developer account
        $devEmail = getenv('SEED_DEVELOPER_EMAIL') ?: 'dev@sarada-marble.test';
        $devPassword = getenv('SEED_DEVELOPER_PASSWORD') ?: 'DevPass123';

        try {
            \core\DeveloperAccess::provision($devEmail, $devPassword);
            $query = $this->pdo->prepare('SELECT id FROM developer_accounts WHERE email = ?');
            $query->execute([$devEmail]);
            $devId = (int) $query->fetchColumn();
            $result['created'][] = [
                'type' => 'developer',
                'email' => $devEmail,
                'role' => 'developer',
                'user_id' => $devId,
            ];
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                $result['skipped'][] = ['type' => 'developer', 'email' => $devEmail, 'reason' => 'already exists'];
            } else {
                throw $e;
            }
        }

        // 2. Create default admin account (commerce user with admin role)
        $adminEmail = getenv('SEED_ADMIN_EMAIL') ?: 'admin@sarada-marble.test';
        $adminPassword = getenv('SEED_ADMIN_PASSWORD') ?: 'AdminPass123';

        try {
            \core\CommerceAccess::provision($adminEmail, $adminPassword, 'admin', 'Store Administrator');
            $result['created'][] = [
                'type' => 'admin',
                'email' => $adminEmail,
                'role' => 'admin',
                'user_id' => null,
            ];
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                $result['skipped'][] = ['type' => 'admin', 'email' => $adminEmail, 'reason' => 'already exists'];
            } else {
                throw $e;
            }
        }

        // 3. Create default customer account (commerce user with customer role)
        $customerEmail = getenv('SEED_CUSTOMER_EMAIL') ?: 'customer@sarada-marble.test';
        $customerPassword = getenv('SEED_CUSTOMER_PASSWORD') ?: 'CustomerPass123';

        try {
            \core\CommerceAccess::provision($customerEmail, $customerPassword, 'customer', 'Store Customer');
            $query = $this->pdo->prepare('SELECT id FROM users_tbl WHERE email = ?');
            $query->execute([$customerEmail]);
            $customerId = (int) $query->fetchColumn();
            $result['created'][] = [
                'type' => 'customer',
                'email' => $customerEmail,
                'role' => 'customer',
                'user_id' => $customerId,
            ];
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                $result['skipped'][] = ['type' => 'customer', 'email' => $customerEmail, 'reason' => 'already exists'];
            } else {
                throw $e;
            }
        }

        return $result;
    }
}