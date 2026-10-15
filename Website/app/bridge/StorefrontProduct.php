<?php

class StorefrontProduct extends StorefrontController
{
    public function home(): void
    {
        $this->handle(function (): void {
            $catalog = new CatalogReadService($this->pdo);
            $products = $catalog->listing(['per_page' => 50]);
            $categories = (new ProductService($this->pdo))->taxonomies()['categories'];
            $categoryCards = [];
            foreach (array_slice($categories, 0, 8) as $category) {
                $preview = $catalog->listing(['category'=>$category['slug'], 'per_page'=>1]);
                $categoryCards[] = $category + ['material_count'=>$preview['total'], 'preview'=>$preview['items'][0]['images'][0]['path'] ?? null];
            }
            $testimonial = null;
            foreach (array_slice($products['items'], 0, 8) as $material) {
                $published = (new ReviewService($this->pdo))->publicPage((int)$material['id']);
                if ($published['items']) { $testimonial = $published['items'][0]; break; }
            }
            $this->respond('app/page/storefront/home.php', compact('products', 'categories', 'categoryCards', 'testimonial'));
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
            $catalog = new CatalogReadService($this->pdo);
            $related = array_slice(array_values(array_filter($catalog->listing([
                'category' => $product['category_slug'], 'per_page' => 5, 'in_stock' => 1,
            ])['items'], static fn(array $item): bool => (int)$item['id'] !== (int)$product['id'])), 0, 4);
            $brands = (new ProductService($this->pdo))->taxonomies()['brands'];
            $detailService = new ProductDetailService($this->pdo);
            $details = $detailService->get((int)$product['id']);
            $delivery = isset($_GET['postal_code']) ? $detailService->delivery($details, CommerceValues::text($_GET['postal_code'], 6, 'postal_code')) : null;
            $reviews = (new ReviewService($this->pdo))->publicPage((int)$product['id']);
            $companion = $details['companion_product_id'] ? ((new ProductService($this->pdo))->selection([$details['companion_product_id']])[0] ?? null) : null;
            $this->respond('app/page/storefront/product_detail.php', compact('product', 'cart', 'related', 'brands', 'details', 'delivery', 'reviews', 'companion'));
        });
    }
    public function reviews(string $slug): void
    {
        $this->handle(function()use($slug):void {
            $product=(new ProductService($this->pdo))->detail($slug);$service=new ReviewService($this->pdo);$actor=CommerceAccess::actor();
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
                RequestSecurity::requireMutation();$actor=CommerceAccess::requireActor();
                $service->submit($actor['id'],(int)$product['id'],$_POST,CommerceValues::integer($_POST['revision']??0,0,PHP_INT_MAX,'revision'));
                $this->redirect('product/'.$slug.'/reviews');return;
            }
            $reviews=$service->publicPage((int)$product['id'],CommerceValues::integer($_GET['page']??1,1,1000,'page'));
            $own=$actor?$service->own($actor['id'],(int)$product['id']):null;
            $eligible=$actor&&$service->eligible($actor['id'],(int)$product['id']);
            $this->respond('app/page/storefront/reviews.php',compact('product','reviews','own','eligible','actor'));
        });
    }

}
