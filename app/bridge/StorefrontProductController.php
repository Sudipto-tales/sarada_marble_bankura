<?php

class StorefrontProductController
{
    public function listing(): void
    {
        $data = [
            'products' => (new \App\Services\CatalogReadService($GLOBALS['pdo']))->listing([
                'page' => isset($_GET['page']) ? (int)$_GET['page'] : 1,
                'per_page' => 12,
                'category' => $_GET['category'] ?? '',
                'sort' => $_GET['sort'] ?? 'featured',
                'q' => $_GET['q'] ?? '',
            ])
        ];
        echo render_view('/app/page/storefront/product_listing.php', $data);
    }

    public function detail(): void
    {
        $data = [
            'product' => (new \App\Services\CatalogReadService($GLOBALS['pdo']))->detail($_GET['slug'] ?? '')
        ];
        echo render_view('/app/page/storefront/product_detail.php', $data);
    }
}