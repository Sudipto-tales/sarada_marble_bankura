<?php

final class ProductImageDownloader
{
    private PDO $pdo;
    private string $assetsDir;

    public function __construct(PDO $pdo, string $assetsDir = 'assets/products')
    {
        $this->pdo = $pdo;
        // Resolve relative to Website root
        $this->assetsDir = __DIR__ . '/' . ltrim($assetsDir, '/');
        // Directory should already exist; create only if missing (fallback)
        if (!is_dir($this->assetsDir)) {
            @mkdir($this->assetsDir, 0755, true);
        }
    }

    private function downloadImage(string $searchQuery): ?string
    {
        $unsplashUrl = "https://unsplash.it/800/600?random=" . md5($searchQuery);
        $filename = md5($searchQuery) . '.jpg';
        $savePath = $this->assetsDir . '/' . $filename;

        $content = @file_get_contents($unsplashUrl);
        if ($content !== false) {
            $filename = md5($searchQuery) . '.jpg';
            $savePath = $this->assetsDir . '/' . $filename;
            file_put_contents($savePath, $content);
            return $savePath;
        }

        $fallback = 'https://via.placeholder.com/800x600/CCCCCC/666666?text=' . urlencode($searchQuery);
        $content = @file_get_contents($fallback);
        if ($content !== false) {
            $filename = md5($searchQuery) . '.jpg';
            $savePath = $this->assetsDir . '/' . $filename;
            file_put_contents($savePath, $content);
            return $savePath;
        }

        return null;
    }

    private function sanitizeFilename(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9]/', '-', $name);
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        $sanitized = trim($sanitized, '-');
        if (empty($sanitized)) {
            $sanitized = 'product';
        }
        return $sanitized;
    }

    public function processAllProducts(): void
    {
        $stmt = $this->pdo->query('SELECT id, name, tags, slug FROM products');
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as $product) {
            $productId = $product['id'];
            $productName = $product['name'];
            $tags = $product['tags'];

            echo "Processing product ID {$productId}: {$productName}" . PHP_EOL;

            // Download image using product name as search query
            $imagePath = $this->downloadImage($productName);

            if ($imagePath === null) {
                echo "  ! Failed to download image for {$productName}" . PHP_EOL;
                continue;
            }

            // Check if product already has an image
            $existingImg = $this->pdo->prepare(
                "SELECT id, path FROM product_images WHERE product_id = ? LIMIT 1"
            );
            $existingImg->execute([$productId]);

            $existingRow = $existingImg->fetch(PDO::FETCH_ASSOC);

            if ($existingRow) {
                // Update existing image
                $imgId = $existingRow['id'];
                $updateStmt = $this->pdo->prepare(
                    "UPDATE product_images SET path = ?, mime = 'image/jpeg', width = 800, height = 600, alt_text = ? WHERE id = ?"
                );
                $altText = $productName . ' - premium marble/granite surface';
                $updateStmt->execute([$imagePath, $altText, $imgId]);
                echo "  Updated image ID {$imgId} for {$productName}" . PHP_EOL;
            } else {
                // Insert new image
                $insertStmt = $this->pdo->prepare(
                    "INSERT INTO product_images (product_id, path, mime, width, height, position, alt_text) VALUES (?, ?, 'image/jpeg', 800, 600, 1, ?)"
                );
                $altText = $productName . ' - premium marble/granite surface';
                $insertStmt->execute([$productId, $imagePath, $altText]);
                echo "  Inserted new image for {$productName}" . PHP_EOL;
            }
        }

        echo PHP_EOL . "Done processing {$products} products." . PHP_EOL;
    }
}

$pdo = new PDO('sqlite:database/database.sqlite');
$downloader = new ProductImageDownloader($pdo);
$downloader->processAllProducts();