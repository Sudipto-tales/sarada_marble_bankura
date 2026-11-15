                        'category_id' => $categories[$definition['category']],
                        'brand_id' => $brandId,
                        'status' => 'active',
                        'featured' => $definition['featured'],
                        'variants' => $definition['variants'],
                    ],
                    0,
                    'product-seed:v1:product:' . $slug
                );

                $productId = (int)$saved['id'];
                $result['created_products']++;
                $result['created_variants'] += count($definition['variants']);
            } else {
                // Never overwrite a product that may have been edited by an administrator.
                $result['skipped_products']++;
                $this->assertOwnedDemoProduct($productId, $slug);
            }

            // Resume safely: positions already occupied are left untouched.
            $existingPositions = CommerceDatabase::query(
                $this->pdo,
                'SELECT position FROM product_images WHERE product_id = ? ORDER BY position',
                [$productId]
            )->fetchAll(PDO::FETCH_COLUMN);
            $existingPositions = array_map('intval', $existingPositions);

            foreach ($definition['images'] as $position => $url) {
                if (in_array($position, $existingPositions, true)) {
                    $result['skipped_images']++;
                    continue;
                }

                try {
                    $this->images->uploadFromUrl(
                        $actorId,
                        $productId,
                        $url,
                        [
                            'alt_text' => $definition['name'] . ' - sample image ' . ($position + 1),
                            'position' => $position,
                        ],
                        'product-seed:v1:image:' . $slug . ':' . $position
                    );
                    $result['created_images']++;
                } catch (Throwable $error) {
                    $result['failed_images'][] = [
                        'product' => $slug,
                        'position' => $position,
                        'message' => $error->getMessage(),
                    ];
                }
            }

            $result['products'][] = [
                'id' => $productId,
                'slug' => $slug,
                'name' => $definition['name'],
                'variants' => count($definition['variants']),
            ];
        }

        if ($result['failed_images'] !== []) {
            throw new RuntimeException(
                'Catalogue records were seeded, but one or more images failed. Review failed_images in the seeder output and rerun to retry missing image positions.'
            );
        }

        return $result;
    }

    private function findId(string $table, string $slug): int
    {
        if (!in_array($table, ['products', 'categories', 'brands'], true)) {
            throw new LogicException('Unsupported seeder lookup table.');
        }

        return (int)CommerceDatabase::query(
            $this->pdo,
            "SELECT id FROM {$table} WHERE slug = ?",
            [$slug]
        )->fetchColumn();
    }

    private function assertOwnedDemoProduct(int $productId, string $slug): void
    {
        $row = CommerceDatabase::query(
            $this->pdo,
            'SELECT description FROM products WHERE id = ?',
            [$productId]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$row || !str_contains((string)$row['description'], 'Development sample')) {
            throw new RuntimeException(
                'Seeder slug conflict for "' . $slug . '". Existing product was not modified.'
            );
        }
    }
