<?php

/**
 * One-time production bootstrap installer (GET/POST /setup).
 *
 * SAFE BY CONSTRUCTION — it is inert unless BOTH hold:
 *   1. COMMERCE_SETUP_TOKEN is set in the server environment (.env). Absent/empty => 404.
 *   2. No commerce staff account exists yet. The first staff grant closes it permanently (410).
 *
 * When enabled it provisions the first admin — the operator types the password here; it is
 * never embedded or seeded — and then imports the bundled Kajaria catalog as hidden drafts.
 * The "no staff yet" guard means it can never be replayed to add or hijack an admin, so it is
 * not a public backdoor. It grants nothing on its own and is independent of Admin/Auth.
 *
 * After the first run: remove COMMERCE_SETUP_TOKEN from the server .env. The route then 404s.
 */
class Setup extends StorefrontController
{
    public function show(): void
    {
        $this->handle(function(): void {
            $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
            $this->guard($token);
            RequestSecurity::session();
            $this->page(['token' => $token, 'csrf' => RequestSecurity::csrfToken(), 'action' => self::url('setup')]);
        });
    }

    public function run(): void
    {
        $this->handle(function(): void {
            RequestSecurity::requireMutation();
            $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
            $this->guard($token);
            $email = (string)($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['confirm'] ?? '');
            $redisplay = function (string $message, int $status) use ($token, $email): void {
                RequestSecurity::session();
                http_response_code($status);
                $this->page(['token' => $token, 'csrf' => RequestSecurity::csrfToken(),
                    'action' => self::url('setup'), 'error' => $message, 'email' => $email]);
            };
            if ($password !== $confirm) { $redisplay('The two passwords do not match.', 422); return; }
            @set_time_limit(180);
            try {
                $adminId = CommerceAccess::provision($email, $password, 'admin');
            } catch (CommerceProvisioningRejected $e) {
                $redisplay($e->getMessage(), 422); return;
            }
            require_once dirname(__DIR__, 2) . '/database/seeders/KajariaCatalogSeeder.php';
            try {
                $result = (new KajariaCatalogSeeder($this->pdo))->importFromAdmin($adminId);
            } catch (Throwable $e) {
                // The admin was created; surface the import error but tell the operator they can
                // now sign in and run the import from the admin panel instead.
                $redisplay('Admin "' . $email . '" was created, but the catalog import failed: '
                    . $e->getMessage() . ' You can sign in at /admin and use "Import Kajaria catalog".', 503);
                return;
            }
            $this->page(['done' => true, 'email' => $email, 'result' => $result]);
        });
    }

    /** Shared precondition: configured token present, no staff yet, token matches. */
    private function guard(string $token): void
    {
        $configured = (string)env('COMMERCE_SETUP_TOKEN', '');
        if ($configured === '') throw new RequestRejected(404, 'not_found', 'Not found.');
        if ($this->staffExists()) throw new RequestRejected(410, 'setup_closed',
            'Setup has already been completed; this installer is permanently disabled. Sign in at /admin instead, and remove COMMERCE_SETUP_TOKEN from the server .env.');
        if ($token === '' || !hash_equals($configured, $token)) throw new RequestRejected(404, 'not_found', 'Not found.');
    }

    private function staffExists(): bool
    {
        try {
            return (bool)$this->pdo->query('SELECT EXISTS(SELECT 1 FROM user_roles)')->fetchColumn();
        } catch (Throwable $e) {
            throw new RequestRejected(503, 'not_ready', 'The database is not migrated yet. Apply migrations, then retry.');
        }
    }

    private function page(array $data): void
    {
        $this->respond('app/page/setup.php', $data);
    }
}
