<?php

class Admin extends BaseController
{
    private function start(): bool
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        if (!DEVELOPER_ENABLED) { http_response_code(404); return false; }
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST', 'PUT'], true)) {
            http_response_code(405); header('Allow: GET, POST, PUT'); return false;
        }
        CommerceAccess::session();
        return true;
    }
    
    private function redirect(string $path): void
    {
        header('Location: ' . base_url($path), true, 303);
    }
    
    public function index(): void
    {
        if (!$this->start()) return;
        $account = CommerceAccess::identity();
        if (!$account) { session_write_close(); $this->redirect('admin/login'); return; }
        
        // Check staff grant
        $hasStaffGrant = in_array('admin.products.view', $account['permissions'] ?? [], true);
        if (!$hasStaffGrant) { http_response_code(403); return; }
        
        $route = RouteManager::resolveRoute();
        $page = $route === 'admin' ? 'products' : substr($route, strlen('admin/'));
        $definition = CommerceAccess::PAGES[$page] ?? null;
        if (!$definition) { http_response_code(404); return; }
        $denied = !in_array($definition[2], $account['permissions'], true);
        if ($denied) http_response_code(403);
        $csrf = $_SESSION['commerce_csrf'];
        session_write_close();
        $this->respond('/app/page/admin.php', compact('page', 'account', 'csrf', 'denied'));
    }
    
    public function products(): void
    {
        if (!$this->start()) return;
        $account = CommerceAccess::identity();
        if (!$account) { session_write_close(); $this->redirect('admin/login'); return; }
        
        // Check grant
        $hasGrant = in_array('admin.products.view', $account['permissions'] ?? [], true);
        if (!$hasGrant) { http_response_code(403); return; }
        
        $productService = new \App\Services\ProductService($this->pdo);
        $products = $productService->administration($account['id'] ?? 0, [
            'page' => isset($_GET['page']) ? (int)$_GET['page'] : 1,
            'per_page' => 20,
        ]);
        
        $data = $this->viewData(
            [],
            ['products' => $products, 'account' => $account, 'csrf' => $_SESSION['commerce_csrf'] ?? '']
        );
        session_write_close();
        $this->respond('/app/page/admin.php', $data);
    }
    
    public function productForm(): void
    {
        if (!$this->start()) return;
        $account = CommerceAccess::identity();
        if (!$account) { session_write_close(); $this->redirect('admin/login'); return; }
        
        $hasGrant = in_array('admin.products.edit', $account['permissions'] ?? [], true);
        if (!$hasGrant) { http_response_code(403); return; }
        
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $data = $this->viewData(
            ['product' => $id > 0 ? (new \App\Services\ProductService($this->pdo))->detail('test-product') : []],
            ['product_id' => $id, 'account' => $account, 'csrf' => $_SESSION['commerce_csrf'] ?? '']
        );
        session_write_close();
        $this->respond('/app/page/admin.php', $data);
    }
    
    public function logout(): void
    {
        if (!$this->start(['POST'])) return;
        if (!CommerceAccess::csrf()) { http_response_code(419); echo 'Invalid session token'; return; }
        unset($_SESSION['commerce_id'], $_SESSION['commerce_seen']);
        session_regenerate_id(true);
        $_SESSION['commerce_csrf'] = bin2hex(random_bytes(32));
        session_write_close();
        $this->redirect('admin/login');
    }
}