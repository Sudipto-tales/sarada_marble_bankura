<?php
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open();
require dirname(__DIR__) . '/database/seeders/DemoCatalogSeeder.php';
$check = [CommerceTest::class, 'check'];
$admin = CommerceAccess::provision('demo-seed-admin@example.test', bin2hex(random_bytes(24)));
$customer = CommerceTest::user('demo-seed-customer@example.test');
$seeder = new DemoCatalogSeeder($pdo);
$root = dirname(__DIR__);
$createdPaths = [];
foreach (require $root . '/database/seeders/catalog.php' as [$slug]) {
    $path = $root . '/assets/products/' . substr(hash('sha256', 'sarada-demo-v1:' . $slug), 0, 32) . '.webp';
    if (!is_file($path)) $createdPaths[] = $path;
}
register_shutdown_function(static function () use ($createdPaths): void {
    foreach ($createdPaths as $path) if (is_file($path)) unlink($path);
});
CommerceTest::rejects(fn() => $seeder->run($customer), 403, 'Customer cannot seed catalog.');
$check((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0, 'Denied seeding writes no products.');
$first = $seeder->run($admin);
$check($first['created_products'] === 8 && $first['created_images'] === 8 && $first['stock_adjustments'] === 11, 'Taxonomy, products, variants, images and opening stock seed.');
$check((int)$pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn() === 13, 'Multiple finishes and sqft/slab/box variants exist.');
$check((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 3 && (int)$pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn() === 1, 'Expected demo taxonomies exist.');
$check((int)$pdo->query('SELECT COUNT(*) FROM users_tbl')->fetchColumn() === 2, 'Seeder creates no identities or passwords.');
$product = (new ProductService($pdo))->detail('demo-makrana-white');
$check((int)$product['variants'][0]['unit_price_minor'] === 12500, 'Fixed precision sample price is correct.');
$variant = (int)$product['variants'][0]['id'];
(new InventoryService($pdo))->adjust($admin, $variant, -500, 'Test consumption', 'demo:test:consume');
CommerceDatabase::transaction($pdo, static function () use ($pdo, $product): void {
    CommerceDatabase::query($pdo, 'UPDATE products SET name=? WHERE id=?', ['Operator edited demo', $product['id']]);
    ProductService::advanceVersion($pdo);
});
$version = $pdo->query("SELECT version FROM catalog_versions WHERE namespace='catalog'")->fetchColumn();
$audit = $pdo->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn();
$second = $seeder->run($admin);
$check($second['created_products'] === 0 && $second['skipped_products'] === 8 && $second['stock_adjustments'] === 0 && $second['created_images'] === 0, 'Rerun creates no duplicate fixtures.');
$check((new ProductService($pdo))->detail('demo-makrana-white')['name'] === 'Operator edited demo', 'Rerun preserves operator edits.');
$check((int)CommerceDatabase::query($pdo, 'SELECT on_hand_milli FROM inventory WHERE variant_id=?', [$variant])->fetchColumn() === 249500, 'Rerun does not replenish used stock.');
$check($version === $pdo->query("SELECT version FROM catalog_versions WHERE namespace='catalog'")->fetchColumn()
    && $audit === $pdo->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn(), 'No-op rerun changes neither cache version nor audit.');
// Emulate an interrupted run after a product committed, before its image/stock slice.
$image = $pdo->query('SELECT * FROM product_images ORDER BY id LIMIT 1')->fetch();
$pdo->prepare('DELETE FROM product_images WHERE id=?')->execute([$image['id']]);
$pdo->prepare('DELETE FROM inventory_movements WHERE variant_id=?')->execute([$variant]);
$pdo->prepare('UPDATE inventory SET on_hand_milli=0,revision=1 WHERE variant_id=?')->execute([$variant]);
$resumed = $seeder->run($admin);
$check($resumed['created_products'] === 0 && $resumed['created_images'] === 1 && $resumed['stock_adjustments'] === 1, 'Interrupted fixture slices can resume.');
[$status, $out, $err] = CommerceTest::command(['vayu', 'demo:seed', '--actor-id=' . $admin], '', ['APP_ENV' => 'production']);
$check($status === 1 && $out === '' && str_contains($err, 'Requires development/test'), 'Production CLI refuses seeding.');
[$status, $out, $err] = CommerceTest::command(['vayu', 'demo:seed', '--actor-id=' . $admin]);
$check($status === 0 && $err === '' && json_decode($out, true)['skipped_products'] === 8, 'Development CLI returns repeatable seed summary.');
CommerceTest::finish('Demo catalog seeder');
