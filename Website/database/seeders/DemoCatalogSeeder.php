<?php

/** CLI-only demo fixtures; domain services retain grants, validation, audit and cache versions. */
final class DemoCatalogSeeder
{
    public function __construct(private PDO $pdo) {}

    public function run(int $actorId): array
    {
        if (PHP_SAPI !== 'cli' || !in_array(APP_ENV, ['development', 'test'], true)) {
            throw new RuntimeException('Demo seeding requires CLI and APP_ENV=development or test.');
        }
        CommerceAccess::requirePermission('admin.products.edit', $actorId);
        CommerceAccess::requirePermission('admin.inventory.adjust', $actorId);
        $fixtures = require __DIR__ . '/catalog.php';
        $root = dirname(__DIR__, 2);
        // Validate all bundled images before any catalog write; no network or GD dependency.
        $images = [];
        foreach ($fixtures as [$slug]) {
            $source = $root . '/assets/storefront/assets/textures/marble/' . str_replace('-', '_', $slug) . '.webp';
            $size = is_file($source) ? getimagesize($source) : false;
            if (!$size || $size['mime'] !== 'image/webp') throw new RuntimeException('Bundled demo texture is missing or invalid.');
            $images[$slug] = [$source, $size];
        }
        $catalog = new ProductService($this->pdo);
        $stock = new InventoryService($this->pdo);
        $result = ['created_products' => 0, 'skipped_products' => 0, 'created_images' => 0, 'stock_adjustments' => 0, 'products' => []];
        $categories = [];
        foreach (['marble' => 'Marble', 'granite' => 'Granite', 'tiles' => 'Tiles'] as $slug => $name) {
            $categories[$slug] = $this->find('categories', 'demo-' . $slug)
                ?: $catalog->saveCategory($actorId, ['slug' => 'demo-' . $slug, 'name' => 'Demo ' . $name], 'demo:v1:category:' . $slug);
        }
        $brand = $this->find('brands', 'demo-sarada')
            ?: $catalog->saveBrand($actorId, ['slug' => 'demo-sarada', 'name' => 'Demo Sarada Collection'], 'demo:v1:brand');
        foreach ($fixtures as [$slug, $name, $category, $unit, $price, $quantity, $finish]) {
            $demoSlug = 'demo-' . $slug;
            $productId = $this->find('products', $demoSlug);
            $sku = 'DEMO-' . strtoupper($slug) . '-' . strtoupper($unit);
            if (!$productId) {
                $variants = [['sku' => $sku, 'sell_unit' => $unit, 'finish' => $finish,
                    'qty_increment_milli' => $unit === 'sqft' ? 500 : 1000, 'unit_price_minor' => $price,
                    'coverage_sqft_milli' => $unit === 'slab' ? 40000 : ($unit === 'box' ? 16000 : null),
                    'thickness_mm_milli' => $unit === 'box' ? 10000 : 18000]];
                if ($unit === 'sqft') {
                    $variants[] = array_replace($variants[0], ['sku' => $sku . '-ALT',
                        'finish' => $finish === 'Honed' ? 'Polished' : 'Honed', 'unit_price_minor' => $price + 1500]);
                }
                $saved = $catalog->save($actorId, ['slug' => $demoSlug, 'name' => 'Demo — ' . $name,
                    'description' => 'DEMO FIXTURE v1. Sample material, image, price and stock for local testing only; not a live sales offer.',
                    'tags' => 'demo sample ' . $category, 'category_id' => $categories[$category], 'brand_id' => $brand,
                    'status' => 'active', 'featured' => in_array($slug, ['makrana-white', 'black-galaxy'], true) ? 1 : 0,
                    'variants' => $variants], 0, 'demo:v1:product:' . $slug);
                $productId = $saved['id'];
                $result['created_products']++;
            } else {
                $description = CommerceDatabase::query($this->pdo, 'SELECT description FROM products WHERE id=?', [$productId])->fetchColumn();
                if (!str_starts_with($description, 'DEMO FIXTURE v1.')) throw new RuntimeException('Demo slug conflicts with an existing non-demo product.');
                $result['skipped_products']++;
            }
            // Resume interrupted runs without refilling consumed or manually adjusted inventory.
            $variants = CommerceDatabase::query($this->pdo, 'SELECT id,sku FROM product_variants WHERE product_id=? ORDER BY id', [$productId])->fetchAll();
            foreach ($variants as $variant) {
                if (!in_array($variant['sku'], [$sku, $sku . '-ALT'], true)) continue;
                $history = CommerceDatabase::query($this->pdo, 'SELECT id FROM inventory_movements WHERE variant_id=? LIMIT 1', [$variant['id']])->fetchColumn();
                if ($quantity > 0 && !$history) {
                    $stock->adjust($actorId, (int)$variant['id'], $quantity, 'Demo opening stock', 'demo:v1:stock:' . $variant['sku'], 1);
                    $result['stock_adjustments']++;
                }
            }
            [$source, $size] = $images[$slug];
            $path = 'assets/products/' . substr(hash('sha256', 'sarada-demo-v1:' . $slug), 0, 32) . '.webp';
            if (!is_dir($root . '/assets/products') && !mkdir($root . '/assets/products', 0755, true) && !is_dir($root . '/assets/products')) {
                throw new RuntimeException('Unable to create demo image directory.');
            }
            if (!is_file($root . '/' . $path) && !copy($source, $root . '/' . $path)) throw new RuntimeException('Unable to copy demo image.');
            $image = CommerceDatabase::query($this->pdo, 'SELECT id FROM product_images WHERE product_id=? AND path=?', [$productId, $path])->fetchColumn();
            if (!$image) {
                $catalog->addImage($actorId, $productId, ['path' => $path, 'mime' => 'image/webp', 'width' => $size[0],
                    'height' => $size[1], 'alt_text' => 'Demo sample texture: ' . $name], 'demo:v1:image:' . $slug . ':' . bin2hex(random_bytes(8)));
                $result['created_images']++;
            }
            $result['products'][] = ['id' => $productId, 'slug' => $demoSlug];
        }
        return $result;
    }

    private function find(string $table, string $slug): int
    {
        if (!in_array($table, ['products', 'categories', 'brands'], true)) throw new LogicException('Unknown fixture table.');
        return (int)CommerceDatabase::query($this->pdo, "SELECT id FROM $table WHERE slug=?", [$slug])->fetchColumn();
    }
}
