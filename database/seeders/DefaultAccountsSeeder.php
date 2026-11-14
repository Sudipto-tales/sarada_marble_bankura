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
        $devEmail = 'dev@sarada-marble.test';
        $devPassword = 'DevPass123';

        try {
            \core\DeveloperAccess::provision($devEmail, $devPassword);
            $query = $this->pdo->prepare('SELECT id FROM developer_accounts WHERE email = ?');
            $query->execute([$devEmail]);
            $devId = (int) $query->fetchColumn();
            $result['created'][] = [
                'type' => 'developer',
                'email' => $devEmail,
                'password' => $devPassword,
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
        $adminEmail = 'admin@sarada-marble.test';
        $adminPassword = 'AdminPass123';

        try {
            \core\CommerceAccess::provision($adminEmail, $adminPassword, 'admin', 'Store Administrator');
            $result['created'][] = [
                'type' => 'admin',
                'email' => $adminEmail,
                'password' => $adminPassword,
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

        return $result;
    }
}