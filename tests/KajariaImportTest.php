<?php
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open();
require dirname(__DIR__) . '/database/seeders/KajariaCatalogSeeder.php';
$check = [CommerceTest::class, 'check'];

$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/database/seeders/kajaria/catalog.json'), true);
$expectedProducts = count($manifest['products']);
$expectedImages = array_sum(array_map(static fn($p) => count($p['images']), $manifest['products']));

$admin = CommerceAccess::provision('kajaria-import-admin@example.test', bin2hex(random_bytes(24)));
$customer = CommerceTest::user('kajaria-import-customer@example.test');
$seeder = new KajariaCatalogSeeder($pdo);
$root = dirname(__DIR__);

// Track copied image files for cleanup; the import writes into assets/products.
$createdPaths = [];
foreach ($manifest['products'] as $product) {
    foreach ($product['images'] as $i => $image) {
        $path = $root . '/assets/products/' . substr(hash('sha256', 'sarada-kajaria-v1:' . $product['slug'] . ':' . $i), 0, 32) . '.webp';
        if (!is_file($path)) $createdPaths[$path] = true;
    }
}
register_shutdown_function(static function () use ($createdPaths): void {
    foreach (array_keys($createdPaths) as $path) if (is_file($path)) unlink($path);
});

CommerceTest::rejects(fn() => $seeder->run($customer), 403, 'Customer cannot import the catalog.');
$check((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0, 'Denied import writes no products.');

$first = $seeder->run($admin);
$check($first['created_products'] === $expectedProducts && $first['created_images'] === $expectedImages && $first['skipped_products'] === 0,
    'All Kajaria products and images import on the first run.');
$check((int)$pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn() === 1, 'A single Kajaria brand is created.');
$check((int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='draft'")->fetchColumn() === $expectedProducts, 'Every product is hidden (draft).');
$check((int)$pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn() === 0, 'No variants, so no price is published.');
$check((int)$pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn() === $expectedImages, 'Each manifest image is recorded.');
$check((int)$pdo->query("SELECT COUNT(*) FROM product_images WHERE mime='image/webp'")->fetchColumn() === $expectedImages, 'All imported images are webp.');
$check((int)$pdo->query('SELECT COUNT(*) FROM users_tbl')->fetchColumn() === 2, 'Import creates no extra identities (only the test admin and customer).');

// Draft products are invisible to the public storefront and not purchasable.
$check((new ProductService($pdo))->listing()['total'] === 0, 'Draft import is absent from the public shop.');
CommerceTest::rejects(fn() => (new ProductService($pdo))->detail($manifest['products'][0]['slug']), 404, 'Draft product detail is not public.');

// An operator edit survives a rerun; rerun creates no duplicates.
$one = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
CommerceDatabase::transaction($pdo, static function () use ($pdo, $one): void {
    CommerceDatabase::query($pdo, 'UPDATE products SET name=? WHERE id=?', ['Operator renamed', $one]);
    ProductService::advanceVersion($pdo);
});
$version = $pdo->query("SELECT version FROM catalog_versions WHERE namespace='catalog'")->fetchColumn();
$second = $seeder->run($admin);
$check($second['created_products'] === 0 && $second['skipped_products'] === $expectedProducts && $second['created_images'] === 0, 'Rerun is a no-op.');
$name = $pdo->prepare('SELECT name FROM products WHERE id=?'); $name->execute([$one]);
$check($name->fetchColumn() === 'Operator renamed', 'Rerun preserves operator edits.');
$check($version === $pdo->query("SELECT version FROM catalog_versions WHERE namespace='catalog'")->fetchColumn(), 'No-op rerun does not churn the cache version.');

// Resume after an interrupted run restores a missing image row.
$img = $pdo->query('SELECT * FROM product_images ORDER BY id LIMIT 1')->fetch();
$pdo->prepare('DELETE FROM product_images WHERE id=?')->execute([$img['id']]);
$resumed = $seeder->run($admin);
$check($resumed['created_products'] === 0 && $resumed['created_images'] === 1, 'Interrupted image slice resumes.');

// The CLI command runs in a simulated production environment (unlike demo:seed).
[$status, $out, $err] = CommerceTest::command(['vayu', 'catalog:import', '--actor-id=' . $admin], '', ['APP_ENV' => 'production']);
$check($status === 0 && $err === '' && json_decode($out, true)['skipped_products'] === $expectedProducts, 'Production CLI import returns a repeatable summary.');

CommerceTest::finish('Kajaria catalog import');
