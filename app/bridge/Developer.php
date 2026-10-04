<?php

class Developer extends BaseController
{
    private function start(array $methods = ['GET']): bool
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        if (!DEVELOPER_ENABLED) { http_response_code(404); return false; }
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
            http_response_code(405); header('Allow: ' . implode(', ', $methods)); return false;
        }
        DeveloperAccess::session();
        return true;
    }

    private function redirect(string $path): void
    {
        header('Location: ' . base_url($path), true, 303);
    }

    public function login(): void
    {
        if (!$this->start(['GET', 'POST'])) return;
        if (DeveloperAccess::identity()) { $this->redirect('developer'); return; }
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!DeveloperAccess::csrf()) { http_response_code(419); $error = 'Your session expired. Please try again.'; }
            else {
                $error = DeveloperAccess::login(is_string($_POST['email'] ?? null) ? $_POST['email'] : '',
                    is_string($_POST['password'] ?? null) ? $_POST['password'] : '');
                if (!$error) { $this->redirect('developer'); return; }
            }
        }
        $csrf = $_SESSION['developer_csrf'];
        session_write_close();
        $this->respond('/app/page/developer.php', ['page' => 'login', 'error' => $error, 'csrf' => $csrf]);
    }

    public function logout(): void
    {
        if (!$this->start(['POST'])) return;
        if (!DeveloperAccess::csrf()) { http_response_code(419); echo 'Invalid session token'; return; }
        unset($_SESSION['developer_id'], $_SESSION['developer_seen']);
        session_regenerate_id(true);
        $_SESSION['developer_csrf'] = bin2hex(random_bytes(32));
        session_write_close();
        $this->redirect('developer/login');
    }

    public function index(): void
    {
        if (!$this->start()) return;
        $account = DeveloperAccess::identity();
        if (!$account) { session_write_close(); $this->redirect('developer/login'); return; }
        $route = RouteManager::resolveRoute();
        $page = $route === 'developer' ? 'overview' : substr($route, strlen('developer/'));
        $definition = DeveloperAccess::PAGES[$page] ?? null;
        if (!$definition) { http_response_code(404); return; }
        $denied = !in_array($definition[2], $account['permissions'], true);
        if ($denied) http_response_code(403);
        $csrf = $_SESSION['developer_csrf'];
        session_write_close();
        $this->respond('/app/page/developer.php', compact('page', 'account', 'csrf', 'denied'));
    }

    public function metrics(): void
    {
        if (!$this->start()) return;
        header('Content-Type: application/json');
        $account = DeveloperAccess::identity();
        session_write_close();
        if (!$account) { http_response_code(401); echo json_encode(['error' => 'Authentication required']); return; }
        global $apiRoutes;
        $canServer = in_array('monitor.server.read', $account['permissions'], true);
        $load = $canServer && function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        $endpoints = [];
        if (in_array('monitor.apis.read', $account['permissions'], true)) {
            foreach ($apiRoutes as $path => $handler) $endpoints[] = [
                'path' => '/' . $path, 'method' => 'GET', 'module' => 'Developer',
                'permission' => DEVELOPER_PERMISSION, 'state' => 'Registered',
            ];
        }
        echo json_encode([
            'observed_at' => gmdate('c'), 'load_1m' => $load === false ? null : $load[0],
            'php_memory_mb' => $canServer ? round(memory_get_usage(true) / 1048576, 2) : null,
            'registered_apis' => in_array('monitor.apis.read', $account['permissions'], true) ? count($apiRoutes) : null,
            'endpoints' => $endpoints, 'traffic' => null, 'modules' => null,
        ], JSON_THROW_ON_ERROR);
    }
}
