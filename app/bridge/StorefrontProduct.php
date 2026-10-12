<?php

class StorefrontProduct extends StorefrontController
{
    public function home(): void
    {
        $this->handle(function (): void {
            $products = (new CatalogReadService($this->pdo))->listing(['per_page' => 8]);
            $categories = (new ProductService($this->pdo))->taxonomies()['categories'];
            $this->respond('app/page/storefront/home.php', compact('products', 'categories'));
        });
    }
    public function catalogues(): void
    {
        $this->handle(fn() => $this->respond('app/page/storefront/catalogues.php'));
    }
    public function catalogue(string $slug): void
    {
        $this->handle(function () use ($slug): void {
            if ($slug !== 'kasamood') throw new RequestRejected(404, 'not_found', 'Catalogue not found.');
            $this->respond('app/page/storefront/catalogue.php');
        });
    }
    public function listing(?string $slug = null): void
    {
        $this->handle(function () use ($slug): void {
            $filters = CatalogReadService::filters($slug === null ? $_GET : array_replace($_GET, ['category' => $slug]));
            $taxonomies = (new ProductService($this->pdo))->taxonomies();
            if ($slug !== null && !in_array($slug, array_column($taxonomies['categories'], 'slug'), true)) throw new RequestRejected(404, 'not_found', 'Category not found.');
            $this->respond('app/page/storefront/product_listing.php', ['products' => (new CatalogReadService($this->pdo))->listing($filters),
                'filters' => $filters, 'categories' => $taxonomies['categories'], 'brands' => $taxonomies['brands']]);
        });
    }
    public function detail(string $slug): void
    {
        $this->handle(function () use ($slug): void {
            $product = (new CatalogReadService($this->pdo))->detail($slug);
            $cart = (new CartService($this->pdo))->get(CartService::principal(true));
            $this->respond('app/page/storefront/product_detail.php', compact('product', 'cart'));
        });
    }
}
