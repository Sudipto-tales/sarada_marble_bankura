<?php

class StorefrontProduct extends BaseController
{
    public function listing(array $filters = []): void
    {
        $data = $this->viewData(
            [],
            ['products' => (new \App\Services\CatalogReadService($this->pdo))->listing($filters)]
        );
        $this->respond('/app/page/storefront/product_listing.php', $data);
    }

    public function detail(string $slug): void
    {
        $data = $this->viewData(
            [],
            ['product' => (new \App\Services\CatalogReadService($this->pdo))->detail($slug)]
        );
        $this->respond('/app/page/storefront/product_detail.php', $data);
    }
}