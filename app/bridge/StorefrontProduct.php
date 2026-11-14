<?php

class StorefrontProduct extends StorefrontController
{
    public function home(): void
    {
        $this->handle(function (): void {
            $catalog = new CatalogReadService($this->pdo);
            $products = $catalog->listing(['per_page' => 50]);
            $productService = new ProductService($this->pdo);
            $taxonomies = $productService->taxonomies();
            $categories = $taxonomies['categories']; // real categories power the footer shortcuts
            // CAT-TYPE-01: the home "shop by" cards browse by category type, each linking to its type page.
            $categoryCards = [];
            foreach ($taxonomies['category_types'] as $type) {
                $preview = $catalog->listing(['type'=>$type['slug'], 'per_page'=>1]);
                $categoryCards[] = $type + ['material_count'=>$preview['total'], 'preview'=>$productService->categoryTypeImage($type['slug'])];
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
    public function terms(): void
    {
        $this->handle(fn() => $this->respond('app/page/storefront/terms.php'));
    }
    public function privacy(): void
    {
        $this->handle(fn() => $this->respond('app/page/storefront/privacy.php'));
    }
    public function offers(): void
    {
        $this->handle(function (): void {
            $catalog = new CatalogReadService($this->pdo);
            $products = $catalog->listing(['per_page' => 50]);
            $productService = new ProductService($this->pdo);
            $categoryCards = [];
            foreach (array_slice($productService->taxonomies()['category_types'], 0, 4) as $type) {
                $preview = $catalog->listing(['type' => $type['slug'], 'per_page' => 1]);
                $categoryCards[] = $type + ['material_count' => $preview['total'], 'preview' => $productService->categoryTypeImage($type['slug'])];
            }
            $this->respond('app/page/storefront/offers.php', compact('products', 'categoryCards'));
        });
    }
    public function catalogue(string $slug): void
    {
        $this->handle(function () use ($slug): void {
            if ($slug !== 'kasamood') throw new RequestRejected(404, 'not_found', 'Catalogue not found.');
            $this->respond('app/page/storefront/catalogue.php');
        });
    }
    public function listing(?string $slug = null): void { $this->renderListing($slug, 'category'); }
    /** CAT-TYPE-01: browse a whole category type (/type/<slug>); reuses the shop template and facets. */
    public function typeListing(string $slug): void { $this->renderListing($slug, 'type'); }
    private function renderListing(?string $slug, string $dimension): void
    {
        $this->handle(function () use ($slug, $dimension): void {
            $input = $slug === null ? $_GET : array_replace($_GET, [$dimension => $slug]);
            // Shop default page size is 20 materials; the customer cannot override it from the form.
            if (!isset($input['per_page']) || $input['per_page'] === '') $input['per_page'] = 20;
            $filters = CatalogReadService::filters($input);
            $taxonomies = (new ProductService($this->pdo))->taxonomies();
            // A path slug must name a real category/type (404); a bogus query filter just returns no results.
            if ($slug !== null && $dimension === 'category' && !in_array($slug, array_column($taxonomies['categories'], 'slug'), true)) throw new RequestRejected(404, 'not_found', 'Category not found.');
            if ($slug !== null && $dimension === 'type' && !in_array($slug, array_column($taxonomies['category_types'], 'slug'), true)) throw new RequestRejected(404, 'not_found', 'Category type not found.');
            $products = (new CatalogReadService($this->pdo))->listing($filters);
            // Progressive enhancement: product-listing.js fetches with ?partial=1 to swap just the
            // results block. Everything else serves the full page, so JS-off customers are unaffected.
            if (!empty($_GET['partial'])) {
                $this->respond('app/page/storefront/product_listing_fragment.php', compact('products', 'filters'));
                return;
            }
            $this->respond('app/page/storefront/product_listing.php', ['products' => $products,
                'filters' => $filters, 'categories' => $taxonomies['categories'], 'types' => $taxonomies['category_types'],
                'brands' => $taxonomies['brands'], 'pageScripts' => ['product-listing.js']]);
        });
    }
    public function detail(string $slug): void
    {
        $this->handle(function () use ($slug): void {
            $product = (new CatalogReadService($this->pdo))->detail($slug);
            $cart = (new CartService($this->pdo))->get(CartService::principal(true));
            // REC-01: published recommendations for this product, falling back to live category similarity.
            $related = (new RecommendationService($this->pdo))->forProduct((int)$product['id'], 4);
            $brands = (new ProductService($this->pdo))->taxonomies()['brands'];
            $detailService = new ProductDetailService($this->pdo);
            $details = $detailService->get((int)$product['id']);
            $delivery = isset($_GET['postal_code']) ? (new DeliveryService($this->pdo))->forProduct($details, CommerceValues::text($_GET['postal_code'], 6, 'postal_code')) : null;
            $actor = CommerceAccess::actor(); $reviewService = new ReviewService($this->pdo);
            $reviews = $reviewService->publicPage((int)$product['id'], 1, $actor['id'] ?? null);
            $own = $actor ? $reviewService->own($actor['id'], (int)$product['id']) : null;
            $companion = $details['companion_product_id'] ? ((new ProductService($this->pdo))->selection([$details['companion_product_id']])[0] ?? null) : null;
            // Advance (booking) rate to advertise on the page: product override, else category, else store default.
            $advanceBps = (int)($settings = (new SettingsService($this->pdo))->effective())['advance_bps'];
            $this->respond('app/page/storefront/product_detail.php', compact('product', 'cart', 'related', 'brands', 'details', 'delivery', 'reviews', 'companion', 'actor', 'own', 'advanceBps'));
        });
    }
    public function reviews(string $slug): void
    {
        $api=$this->wantsJson();
        $this->handle(function()use($slug,$api):void {
            $product=(new ProductService($this->pdo))->detail($slug);$service=new ReviewService($this->pdo);$actor=CommerceAccess::actor();
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
                RequestSecurity::requireMutation();$actor=CommerceAccess::requireActor();
                $service->submit($actor['id'],(int)$product['id'],$_POST,CommerceValues::integer($_POST['revision']??0,0,PHP_INT_MAX,'revision'));
                if($api){echo json_encode(['ok'=>true,'reviews'=>self::jsonValues($service->publicPage((int)$product['id'],1,$actor['id'])),'request_id'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR);return;}
                $this->redirect('product/'.$slug.'/reviews');return;
            }
            $reviews=$service->publicPage((int)$product['id'],CommerceValues::integer($_GET['page']??1,1,1000,'page'),$actor['id']??null);
            $own=$actor?$service->own($actor['id'],(int)$product['id']):null;
            $eligible=$actor&&$service->eligible($actor['id'],(int)$product['id']);
            $this->respond('app/page/storefront/reviews.php',compact('product','reviews','own','eligible','actor'));
        },$api);
    }
    public function enquiry(): void
    {
        $this->handle(function():void {
            RequestSecurity::requireMutation();
            (new EnquiryService($this->pdo))->submit($_POST);
            echo json_encode(['ok'=>true,'request_id'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR);
        },true);
    }
    public function reply(): void
    {
        $this->handle(function():void {
            RequestSecurity::requireMutation();$actor=CommerceAccess::requireActor();
            $reply=(new ReviewService($this->pdo))->reply($actor['id'],CommerceValues::id($_POST['review_id']??0,'review_id'),(string)($_POST['body']??''));
            echo json_encode(['ok'=>true,'reply'=>self::jsonValues($reply),'request_id'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR);
        },true);
    }
    public function react(): void
    {
        $this->handle(function():void {
            RequestSecurity::requireMutation();$actor=CommerceAccess::requireActor();
            $result=(new ReviewService($this->pdo))->react($actor['id'],(string)($_POST['target_type']??''),CommerceValues::id($_POST['target_id']??0,'target_id'));
            echo json_encode(['ok'=>true]+$result+['request_id'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR);
        },true);
    }
    /** Read-only PIN serviceability for the product page (progressive enhancement over the GET form). */
    public function deliveryCheck(string $slug): void
    {
        $this->handle(function()use($slug):void {
            $pin=CommerceValues::text($_GET['postal_code']??'',6,'postal_code');
            $product=(new ProductService($this->pdo))->detail($slug);
            $details=(new ProductDetailService($this->pdo))->get((int)$product['id']);
            $result=(new DeliveryService($this->pdo))->forProduct($details,$pin);
            echo json_encode(['ok'=>true,'delivery'=>$result,'request_id'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR);
        },true);
    }
    private function wantsJson(): bool { return str_contains($_SERVER['HTTP_X_REQUESTED_WITH']??'','XMLHttpRequest'); }

}
