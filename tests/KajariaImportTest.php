<?php
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open();
require dirname(__DIR__) . '/database/seeders/KajariaCatalogSeeder.php';
$check = [CommerceTest::class, 'check'];

$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/database/seeders/kajaria/catalog.json'), true);
$expectedProducts = count($manifest['products']);
$expectedImages = array_sum(array_map(static fn($p) => count($p['images']), $manifest['products']));

$check(KajariaCatalogSeeder::autoActor($pdo) === null, 'Auto actor resolves to nobody before any staff exists.');
$admin = CommerceAccess::provision('kajaria-import-admin@example.test', bin2hex(random_bytes(24)));
$customer = CommerceTest::user('kajaria-import-customer@example.test');
$seeder = new KajariaCatalogSeeder($pdo);
$check(KajariaCatalogSeeder::autoActor($pdo) === $admin, 'Auto actor ignores customers and resolves the catalog-edit staff account.');
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

// Deployment path: --actor-id=auto imports as the eligible staff account and prints counts only.
[$status, $out, $err] = CommerceTest::command(['vayu', 'catalog:import', '--actor-id=auto'], '', ['APP_ENV' => 'production']);
$auto = json_decode($out, true);
$check($status === 0 && $err === '' && $auto['skipped_products'] === $expectedProducts && $auto['created_products'] === 0 && !isset($auto['products']),
    'Auto-actor CLI import is a repeatable no-op with a compact summary.');

// `migrate` runs the same idempotent catalog seed after applying/validating schema migrations.
[$status, $out, $err] = CommerceTest::command(['vayu', 'migrate']);
$check($status === 0 && $err === '' && str_contains($out, 'Catalog seed:') && str_contains($out, '"skipped_products":' . $expectedProducts),
    'Migration command invokes the catalog seed when an eligible staff actor exists. ' . json_encode(compact('status', 'out', 'err')));

// With no eligible staff (disabled account) the deployment step skips successfully and writes nothing.
$pdo->prepare('UPDATE users_tbl SET status=0 WHERE id=?')->execute([$admin]);
$before = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
[$status, $out, $err] = CommerceTest::command(['vayu', 'catalog:import', '--actor-id=auto'], '', ['APP_ENV' => 'production']);
$check($status === 0 && $err === '' && str_starts_with($out, 'Kajaria import skipped: no staff yet')
    && (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === $before, 'Auto-actor CLI import skips cleanly without eligible staff.');
[$status, $out, $err] = CommerceTest::command(['vayu', 'migrate']);
$check($status === 0 && $err === '' && str_contains($out, 'Catalog seed skipped: no staff account')
    && (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === $before, 'Migrate leaves catalog data untouched until staff is provisioned.');
[$status] = CommerceTest::command(['vayu', 'catalog:import', '--actor-id=' . $admin], '', ['APP_ENV' => 'production']);
$check($status === 1, 'An explicitly named ineligible actor still fails.');
$pdo->prepare('UPDATE users_tbl SET status=1 WHERE id=?')->execute([$admin]);
[$status] = CommerceTest::command(['vayu', 'catalog:import', '--actor-id=0'], '', ['APP_ENV' => 'production']);
$check($status === 1, 'Malformed actor arguments are refused.');

// URL manifest entries store a canonical public Drive image URL without downloading it.
$manifestPath = dirname(__DIR__) . '/database/seeders/kajaria/catalog.json';
$originalManifest = file_get_contents($manifestPath);
$urlManifest = $manifest;
$urlManifest['products'][0]['images'][] = ['url' => 'https://drive.google.com/file/d/abcdefghijklmnopqrstuvwx/view',
    'mime' => 'image/webp', 'width' => 800, 'height' => 573];
try {
    file_put_contents($manifestPath, json_encode($urlManifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    $urlResult = $seeder->run($admin);
    $remoteUrl = ProductService::driveImage($urlManifest['products'][0]['images'][count($urlManifest['products'][0]['images']) - 1]['url']);
    $check($urlResult['created_images'] === 1 && (int)$pdo->query('SELECT COUNT(*) FROM product_images WHERE path=' . $pdo->quote($remoteUrl))->fetchColumn() === 1,
        'Seeder stores a normalized public Drive URL directly without fetching image bytes.');
} finally {
    file_put_contents($manifestPath, $originalManifest);
}

CommerceTest::finish('Kajaria catalog import');
