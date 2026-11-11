<?php

/**
 * CLI importer for the bundled Kajaria catalog subset (the 25 products that carry
 * images). Products are created as DRAFT with no variants, so they are hidden from
 * the storefront and not purchasable until an operator sets prices and stock in the
 * admin panel. Idempotent: rerunning skips products already imported and only fills
 * missing images. Unlike the demo seeder this may run in production. It can be invoked
 * either from the CLI (run(), via `php vayu catalog:import`) or from the admin panel
 * (importFromAdmin(), via POST admin/catalog/import). Both paths require an actor holding
 * admin.products.edit; the web path additionally enforces an elevated staff session + CSRF.
 */
final class KajariaCatalogSeeder
{
    public function __construct(private PDO $pdo) {}

    public function run(int $actorId): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Kajaria import requires CLI invocation.');
        }
        return $this->import($actorId);
    }

    /**
     * Admin-panel entry point. Unlike run() this does not require the CLI SAPI, so it can be
     * invoked from an authenticated, CSRF-protected staff request. The admin.products.edit
     * permission is still enforced inside import(), and the web controller re-checks the
     * elevated staff session before calling this.
     */
    public function importFromAdmin(int $actorId): array
    {
        return $this->import($actorId);
    }

    private function import(int $actorId): array
    {
        CommerceAccess::requirePermission('admin.products.edit', $actorId);
        $dir = __DIR__ . '/kajaria';
        $manifestPath = $dir . '/catalog.json';
        if (!is_file($manifestPath)) throw new RuntimeException('Kajaria catalog manifest is missing.');
        $manifest = json_decode((string)file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        $products = $manifest['products'] ?? null;
        if (!is_array($products) || $products === []) throw new RuntimeException('Kajaria catalog manifest is empty.');
        $root = dirname(__DIR__, 2);

        // Validate every bundled webp before any catalog write; no network dependency.
        $sources = [];
        foreach ($products as $product) {
            foreach ($product['images'] as $image) {
                $source = $dir . '/images/' . $image['file'];
                $size = is_file($source) ? getimagesize($source) : false;
                if (!$size || $size['mime'] !== 'image/webp') {
                    throw new RuntimeException('Bundled Kajaria image is missing or invalid: ' . $image['file']);
                }
                $sources[$image['file']] = [$source, $size[0], $size[1]];
            }
        }

        $catalog = new ProductService($this->pdo);
        $result = ['created_products' => 0, 'skipped_products' => 0, 'created_images' => 0, 'products' => []];

        $brand = $this->find('brands', $manifest['brand_slug'])
            ?: $catalog->saveBrand($actorId, ['slug' => $manifest['brand_slug'], 'name' => $manifest['brand_name']], 'kajaria:v1:brand');

        $categories = [];
        foreach ($products as $product) {
            $slug = $product['category_slug'];
            if (!isset($categories[$slug])) {
                $categories[$slug] = $this->find('categories', $slug)
                    ?: $catalog->saveCategory($actorId, ['slug' => $slug, 'name' => $product['category_name']], 'kajaria:v1:category:' . $slug);
            }
        }

        if (!is_dir($root . '/assets/products') && !mkdir($root . '/assets/products', 0755, true) && !is_dir($root . '/assets/products')) {
            throw new RuntimeException('Unable to create product image directory.');
        }

        foreach ($products as $product) {
            $slug = $product['slug'];
            $productId = $this->find('products', $slug);
            if (!$productId) {
                $saved = $catalog->save($actorId, [
                    'slug' => $slug,
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'tags' => $product['tags'] ?? '',
                    'category_id' => $categories[$product['category_slug']],
                    'brand_id' => $brand,
                    'status' => 'draft',
                    'variants' => [],
                ], 0, 'kajaria:v1:product:' . $slug);
                $productId = $saved['id'];
                $result['created_products']++;
            } else {
                $existingBrand = (int)CommerceDatabase::query($this->pdo, 'SELECT brand_id FROM products WHERE id=?', [$productId])->fetchColumn();
                if ($existingBrand !== $brand) {
                    throw new RuntimeException('Product slug "' . $slug . '" already exists under a different brand; import aborted.');
                }
                $result['skipped_products']++;
            }

            foreach ($product['images'] as $index => $image) {
                [$source, $width, $height] = $sources[$image['file']];
                $path = 'assets/products/' . substr(hash('sha256', 'sarada-kajaria-v1:' . $slug . ':' . $index), 0, 32) . '.webp';
                if (!is_file($root . '/' . $path) && !copy($source, $root . '/' . $path)) {
                    throw new RuntimeException('Unable to copy Kajaria image: ' . $image['file']);
                }
                $exists = CommerceDatabase::query($this->pdo, 'SELECT id FROM product_images WHERE product_id=? AND path=?', [$productId, $path])->fetchColumn();
                if (!$exists) {
                    $catalog->addImage($actorId, $productId, [
                        'path' => $path, 'mime' => 'image/webp', 'width' => $width, 'height' => $height,
                        'position' => $index, 'alt_text' => $product['alt'],
                    ], 'kajaria:v1:image:' . $slug . ':' . $index . ':' . bin2hex(random_bytes(8)));
                    $result['created_images']++;
                }
            }
            $result['products'][] = ['id' => $productId, 'slug' => $slug];
        }
        return $result;
    }

    private function find(string $table, string $slug): int
    {
        if (!in_array($table, ['products', 'categories', 'brands'], true)) throw new LogicException('Unknown table.');
        return (int)CommerceDatabase::query($this->pdo, "SELECT id FROM $table WHERE slug=?", [$slug])->fetchColumn();
    }
}
