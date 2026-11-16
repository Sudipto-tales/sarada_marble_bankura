<?php

final class CatalogSeeder
{
    private PDO $pdo;
    private string $assetsDir;
    private int $productImagePos = 1;

    public function __construct(PDO $pdo, string $assetsDir = 'assets/products')
    {
        $this->pdo = $pdo;
        $this->assetsDir = __DIR__ . '/../../../../' . $assetsDir;
        if (!is_dir($this->assetsDir)) {
            mkdir($this->assetsDir, 0755, true);
        }
    }

    private function insertCategories(): array
    {
        $names = [
            'Bathroom Marble',
            'Kitchen Countertops',
            'Bedroom & Living Flooring',
            'Granite Collection',
            'Marble Tiles',
            'Luxury Designer Marble',
            'Outdoor & Wall Cladding',
        ];

        $categories = [];
        foreach ($names as $i => $name) {
            $slug = strtolower(preg_replace('/\s+/', '-', $name));
            $stmt = $this->pdo->prepare("INSERT INTO categories (slug, name, enabled) VALUES (?, ?, 1)");
            $stmt->execute([$slug, $name]);
            $categories[$name] = $this->pdo->lastInsertId();
        }
        return $categories;
    }

    private function insertBrands(): array
    {
        $names = [
            'Statuario Italia',
            'Makrana Heritage',
            'Rajasthan Stone Works',
            'Carrara Natural',
            'Black Galaxy Granite',
            'Kajaria Surfaces',
        ];

        $brands = [];
        foreach ($names as $i => $name) {
            $slug = strtolower(preg_replace('/\s+/', '-', $name));
            $stmt = $this->pdo->prepare("INSERT INTO brands (slug, name, enabled) VALUES (?, ?, 1)");
            $stmt->execute([$slug, $name]);
            $brands[$name] = $this->pdo->lastInsertId();
        }
        return $brands;
    }

    private function downloadProductImage(string $searchQuery): string
    {
        $unsplashUrl = "https://unsplash.it/800/600?random=" . md5($searchQuery);
        $filename = md5($searchQuery) . '.jpg';
        $savePath = $this->assetsDir . '/' . $filename;

        $content = @file_get_contents($unsplashUrl);
        if ($content !== false) {
            file_put_contents($savePath, $content);
        } else {
            $fallback = 'https://via.placeholder.com/800x600/CCCCCC/666666?text=' . urlencode($searchQuery);
            $content = @file_get_contents($fallback);
            if ($content !== false) {
                file_put_contents($savePath, $content);
            }
        }

        return '/' . ltrim(str_replace(__DIR__ . '/..', '', $savePath), '/');
    }

    private function insertProducts(array $categories, array $brands): array
    {
        $products = [];

        $productData = [
            // Bathroom Marble (category 1)
            ['name' => 'Calacatta Gold Bathroom Slab', 'category' => 'Bathroom Marble', 'brand' => 'Statuario Italia', 'price_minor' => 299900, 'thickness_mm' => 20000, 'width_mm' => 1000000, 'length_mm' => 2000000, 'sell_unit' => 'slab', 'tags' => 'calacatta gold bathroom slab luxury marble'],
            ['name' => 'Pure White Makrana Vanity', 'category' => 'Bathroom Marble', 'brand' => 'Makrana Heritage', 'price_minor' => 149900, 'thickness_mm' => 15000, 'width_mm' => 800000, 'length_mm' => 1600000, 'sell_unit' => 'slab', 'tags' => 'makrana white vanity bathroom'],
            ['name' => 'Soft Grey Bathroom Tile', 'category' => 'Bathroom Marble', 'brand' => 'Kajaria Surfaces', 'price_minor' => 89900, 'thickness_mm' => 10000, 'width_mm' => 300000, 'length_mm' => 600000, 'sell_unit' => 'box', 'tags' => 'grey bathroom tile ceramic']

            // Kitchen Countertops (category 2)
            ,['name' => 'Statuario Kitchen Countertop', 'category' => 'Kitchen Countertops', 'brand' => 'Statuario Italia', 'price_minor' => 349900, 'thickness_mm' => 30000, 'width_mm' => 1200000, 'length_mm' => 3000000, 'sell_unit' => 'slab', 'tags' => 'statuario kitchen countertop premium marble']
            ,['name' => 'Black Galaxy Kitchen Island', 'category' => 'Kitchen Countertops', 'brand' => 'Black Galaxy Granite', 'price_minor' => 219900, 'thickness_mm' => 20000, 'width_mm' => 1000000, 'length_mm' => 1500000, 'sell_unit' => 'slab', 'tags' => 'black galaxy kitchen island granite']
            ,['name' => 'Rainforest Green Counter', 'category' => 'Kitchen Countertops', 'brand' => 'Kajaria Surfaces', 'price_minor' => 189900, 'thickness_mm' => 20000, 'width_mm' => 1000000, 'length_mm' => 2500000, 'sell_unit' => 'slab', 'tags' => 'rainforest green counterstone']

            // Bedroom & Living Flooring (category 3)
            ,['name' => 'Carrara Flooring Tile', 'category' => 'Bedroom & Living Flooring', 'brand' => 'Carrara Natural', 'price_minor' => 169900, 'thickness_mm' => 10000, 'width_mm' => 600000, 'length_mm' => 600000, 'sell_unit' => 'box', 'tags' => 'carrara flooring tile living']
            ,['name' => 'Emperador Dark Flooring', 'category' => 'Bedroom & Living Flooring', 'brand' => 'Kajaria Surfaces', 'price_minor' => 199900, 'thickness_mm' => 10000, 'width_mm' => 600000, 'length_mm' => 600000, 'sell_unit' => 'box', 'tags' => 'emperor dark flooring stone']
            ,['name' => 'Ivory Cream Living Slab', 'category' => 'Bedroom & Living Flooring', 'brand' => 'Statuario Italia', 'price_minor' => 279900, 'thickness_mm' => 20000, 'width_mm' => 1000000, 'length_mm' => 2000000, 'sell_unit' => 'slab', 'tags' => 'ivory cream living slab marble']

            // Granite Collection (category 4)
            ,['name' => 'Absolute Black Granite', 'category' => 'Granite Collection', 'brand' => 'Black Galaxy Granite', 'price_minor' => 229900, 'thickness_mm' => 30000, 'width_mm' => 2000000, 'length_mm' => 3000000, 'sell_unit' => 'slab', 'tags' => 'absolute black granite premium']
            ,['name' => 'Tan Brown Granite', 'category' => 'Granite Collection', 'brand' => 'Rajasthan Stone Works', 'price_minor' => 189900, 'thickness_mm' => 30000, 'width_mm' => 1800000, 'length_mm' => 2800000, 'sell_unit' => 'slab', 'tags' => 'tan brown granite classic']
            ,['name' => 'Steel Grey Granite', 'category' => 'Granite Collection', 'brand' => 'Rajasthan Stone Works', 'price_minor' => 169900, 'thickness_mm' => 30000, 'width_mm' => 1500000, 'length_mm' => 2500000, 'sell_unit' => 'slab', 'tags' => 'steel grey granite modern']

            // Marble Tiles (category 5)
            ,['name' => 'Royal White Marble Tile', 'category' => 'Marble Tiles', 'brand' => 'Makrana Heritage', 'price_minor' => 99900, 'thickness_mm' => 8000, 'width_mm' => 300000, 'length_mm' => 600000, 'sell_unit' => 'box', 'tags' => 'royal white marble tile']
            ,['name' => 'Noble Gold Marble', 'category' => 'Marble Tiles', 'brand' => 'Statuario Italia', 'price_minor' => 319900, 'thickness_mm' => 20000, 'width_mm' => 1000000, 'length_mm' => 2000000, 'sell_unit' => 'slab', 'tags' => 'noble gold marble luxury']
            ,['name' => 'Classic Black Granite', 'category' => 'Granite Collection', 'brand' => 'Black Galaxy Granite', 'price_minor' => 209900, 'thickness_mm' => 30000, 'width_mm' => 1800000, 'length_mm' => 3000000, 'sell_unit' => 'slab', 'tags' => 'classic black granite']

            // Luxury Designer Marble (category 6)
            ,['name' => 'Premium Calacatta', 'category' => 'Luxury Designer Marble', 'brand' => 'Statuario Italia', 'price_minor' => 369900, 'thickness_mm' => 30000, 'width_mm' => 1200000, 'length_mm' => 2800000, 'sell_unit' => 'slab', 'tags' => 'premium calacatta designer marble']
            ,['name' => 'Nordic White', 'category' => 'Luxury Designer Marble', 'brand' => 'Kajaria Surfaces', 'price_minor' => 129900, 'thickness_mm' => 10000, 'width_mm' => 600000, 'length_mm' => 600000, 'sell_unit' => 'box', 'tags' => 'nordic white flooring']
            ,['name' => 'Golden Cream Marble', 'category' => 'Luxury Designer Marble', 'brand' => 'Rajasthan Stone Works', 'price_minor' => 289900, 'thickness_mm' => 20000, 'width_mm' => 1000000, 'length_mm' => 2000000, 'sell_unit' => 'slab', 'tags' => 'golden cream marble luxury']

            // Outdoor & Wall Cladding (category 7)
            ,['name' => 'White Alabaster', 'category' => 'Outdoor & Wall Cladding', 'brand' => 'Carrara Natural', 'price_minor' => 159900, 'thickness_mm' => 15000, 'width_mm' => 800000, 'length_mm' => 1600000, 'sell_unit' => 'slab', 'tags' => 'alabaster white wall cladding']
            ,['name' => 'Midnight Blue', 'category' => 'Outdoor & Wall Cladding', 'brand' => 'Black Galaxy Granite', 'price_minor' => 309900, 'thickness_mm' => 30000, 'width_mm' => 2000000, 'length_mm' => 3500000, 'sell_unit' => 'slab', 'tags' => 'midnight blue granite cladding']
        ];

        foreach ($productData as $data) {
            $catName = $data['category'];
            $brandName = $data['brand'];
            $catId = $categories[$catName] ?? 1;
            $brandId = $brands[$brandName] ?? 1;

            $slug = strtolower(preg_replace('/\s+/', '-', $data['name']));
            $imagePath = $this->downloadProductImage($data['tags']);

            $stmt = $this->pdo->prepare(
                "INSERT INTO products (slug, name, description, tags, category_id, brand_id, price_minor, thickness_mm_milli, width_mm_milli, length_mm_milli, sell_unit, qty_increment_milli, coverage_sqft_milli, status, featured, availability_status, revision, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1000, NULL, 'active', 0, 'in_stock', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([
                $slug,
                $data['name'],
                $data['name'] . ' premium marble/granite surface',
                $data['tags'],
                $catId,
                $brandId,
                $data['price_minor'],
                $data['thickness_mm'],
                $data['width_mm'],
                $data['length_mm'],
                $data['sell_unit'],
            ]);

            $productId = $this->pdo->lastInsertId();

            // Insert product image
            $imgStmt = $this->pdo->prepare(
                "INSERT INTO product_images (product_id, path, mime, width, height, position, alt_text) VALUES (?, ?, 'image/jpeg', 800, 600, 1, ?)"
            );
            $imgStmt->execute([$productId, $imagePath, $data['name'] . ' - premium marble/granite surface']);
            $products[$data['name']] = [
                'id' => $productId,
                'slug' => $slug,
                'category' => $catName,
                'brand' => $brandName,
                'price_minor' => $data['price_minor'],
                'image' => $imagePath,
            ];
        }

        return $products;
    }

    public function run(): array
    {
        $categories = $this->insertCategories();
        $brands = $this->insertBrands();
        $products = $this->insertProducts($categories, $brands);

        return [
            'categories' => array_keys($categories),
            'brands' => array_keys($brands),
            'products_count' => count($products),
            'products' => $products,
        ];
    }
}