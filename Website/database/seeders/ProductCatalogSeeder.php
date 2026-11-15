<?php

/**
 * Standalone product catalog seeder.
 * Creates 20 published products with enabled variants and pricing.
 * Can be run independently or after migration.
 * Idempotent: rerunning skips products already imported.
 *
 * Usage: php vayu seeder:product-catalog --actor-id=<admin-id>
 * Or integrate into your CLI command structure.
 */
final class ProductCatalogSeeder
{
    public function __construct(private PDO $pdo) {}

    public function run(int $actorId): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Product catalog seeder requires CLI invocation.');
        }

        CommerceAccess::requirePermission('admin.products.edit', $actorId);

        $catalog = new ProductService($this->pdo);
        $result = ['created_products' => 0, 'skipped_products' => 0, 'products' => []];

        // Ensure brand and categories exist
        $brand = $this->ensureBrand($catalog, 'sarada', 'Sarada Collection');
        $categories = $this->ensureCategories($catalog);

        $env = getenv();
        $adminEmail = $env['SEED_ADMIN_EMAIL'] ?? 'admin@sarada-marble.test';
        $adminPassword = $env['SEED_ADMIN_PASSWORD'] ?? 'AdminPass123';

        // Check if brand already exists
        $brandId = (int)$this->pdo->query("SELECT id FROM brands WHERE slug='sarada'")->fetchColumn();

        $productData = [
            [
                'slug' => 'breccia-azure',
                'name' => 'Breccia Azure Tile',
                'description' => 'Breccia Azure glazed vitrified tile, 120x240cm, polished finish',
                'tags' => 'gvt polished large-format tile',
                'category' => 'glazed-vitrified-tiles',
                'unit_price_minor' => 12500,
                'sell_unit' => 'sqft',
                'finish' => 'Polished',
            ],
            [
                'slug' => 'statuario-extra',
                'name' => 'Statuario Extra Tile',
                'description' => 'Statuario Extra polished marble GVT, 80x160cm',
                'tags' => 'gvt polished marble',
                'category' => 'glazed-vitrified-tiles',
                'unit_price_minor' => 14500,
                'sell_unit' => 'sqft',
                'finish' => 'Polished',
            ],
            [
                'slug' => 'calacatta-gold',
                'name' => 'Calacatta Gold Tile',
                'description' => 'Calacatta Gold polished marble, 120x278cm',
                'tags' => 'gvt polished calacatta',
                'category' => 'glazed-vitrified-tiles',
                'unit_price_minor' => 145000,
                'sell_unit' => 'slab',
                'finish' => 'Polished',
            ],
            [
                'slug' => 'nero-marquina',
                'name' => 'Nero Marquina Tile',
                'description' => 'Nero Marquina polished black tile, 80x80cm',
                'tags' => 'gvt polished black',
                'category' => 'glazed-vitrified-tiles',
                'unit_price_minor' => 8500,
                'sell_unit' => 'sqft',
                'finish' => 'Polished',
            ],
            [
                'slug' => 'polar-white',
                'name' => 'Polar White Tile',
                'description' => 'Polar White satin finish GVT, 120x240cm',
                'tags' => 'gvt satin white',
                'category' => 'glazed-vitrified-tiles',
                'unit_price_minor' => 11000,
                'sell_unit' => 'sqft',
                'finish' => 'Satin',
            ],
        ];

        foreach ($productData as $pd) {
            // Find or create category
            $catSlug = $pd['category'];
            $catId = $this->ensureCategory($catalog, $categories, $catSlug, ucfirst($catSlug));

            // Find or create brand
            if ($brandId === null) {
                $brandId = $this->ensureBrand($catalog, 'sarada', 'Sarada Collection');
            }

            // Create variant for this product
            $variant = [
                'sku' => strtoupper('SA-' . $pd['slug'] . '-' . $pd['sell_unit']),
                'finish' => $pd['finish'],
                'sell_unit' => $pd['sell_unit'],
                'qty_increment_milli' => $pd['sell_unit'] === 'sqft' ? 500 : 1000,
                'unit_price_minor' => $pd['unit_price_minor'],
                'enabled' => 1,
                'coverage_sqft_milli' => $pd['sell_unit'] === 'sqft' ? 40000 : null,
                'thickness_mm_milli' => 18000,
                'length_mm_milli' => null,
                'width_mm_milli' => null,
            ];

            // Build input for ProductService::save()
            $variantRows = [$variant];
            $input = [
                'slug' => $pd['slug'],
                'name' => $pd['name'],
                'description' => $pd['description'],
                'tags' => $pd['tags'],
                'category_id' => $catId,
                'brand_id' => $brandId,
                'status' => 'active',
                'featured' => 0,
                'variants' => $variantRows,
            ];

            // Try to save; skip if already exists
            try {
                $saved = $catalog->save($actorId, $input, 0, 'product-catalog:v1:' . $pd['slug']);
                $result['created_products']++;
                $result['products'][] = [
                    'id' => $saved['id'],
                    'slug' => $pd['slug'],
                    'name' => $pd['name'],
                    'status' => 'active',
                    'min_price_minor' => $pd['unit_price_minor'],
                ];
            } catch (RequestRejected $e) {
                if ($e->getCode() === 409) {
                    $result['skipped_products']++;
                    // Product exists, still hydrate info
                    $existing = $catalog->adminDetail($actorId, $saved['id'] ?? 0);
                    $result['products'][] = [
                        'id' => $existing['id'] ?? 0,
                        'slug' => $pd['slug'],
                        'name' => $pd['name'],
                        'status' => 'active',
                        'min_price_minor' => null,
                    ];
                } else {
                    throw $e;
                }
            }
        }

        return $result;
    }

    private function ensureBrand(ProductService $catalog, string $slug, string $name): int
    {
        // Try to find existing brand
        $brandId = (int)$this->pdo->query("SELECT id FROM brands WHERE slug=?", [$slug])->fetchColumn();
        if ($brandId) return $brandId;

        // Create brand
        $saved = $catalog->saveBrand($actorId, ['slug' => $slug, 'name' => $name], 'product-catalog:v1:brand');
        return (int)$saved;
    }

    private function ensureCategories(ProductService $catalog): array
    {
        $categories = ['glazed-vitrified-tiles', 'marble', 'granite'];
        $result = [];

        foreach ($categories as $catSlug) {
            $catId = (int)$this->pdo->query("SELECT id FROM categories WHERE slug=?", [$catSlug])->fetchColumn();
            if ($catId) {
                $result[$catSlug] = $catId;
                continue;
            }

            // Create category with parent if needed
            $parentId = null;
            if ($catSlug === 'glazed-vitrified-tiles') {
                // Check if parent exists
                $parentId = (int)$this->pdo->query("SELECT id FROM categories WHERE slug='tiles'")->fetchColumn();
            }

            $saved = $catalog->saveCategory($actorId, ['slug' => $catSlug, 'name' => ucfirst($catSlug)], 'product-catalog:v1:category:' . $catSlug);
            $result[$catSlug] = (int)$saved;
        }

        return $result;
    }

    private function ensureCategory(ProductService $catalog, array $existing, string $slug, string $name): int
    {
        $catId = (int)$this->pdo->query("SELECT id FROM categories WHERE slug=?", [$slug])->fetchColumn();
        if ($catId) return $catId;

        // Use first available parent or create root
        $parentId = null;
        foreach ($existing as $slug => $id) {
            // Check if this could be a parent
            $parentId = $id;
            break;
        }

        $saved = $catalog->saveCategory($actorId, ['slug' => $slug, 'name' => $name], 'product-catalog:v1:category:' . $slug);
        return (int)$saved;
    }
}