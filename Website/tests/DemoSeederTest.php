<?php
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open();
require dirname(__DIR__) . '/database/seeders/DemoCatalogSeeder.php';
$check = [CommerceTest::class, 'check'];
$admin = CommerceAccess::provision('demo-seed-admin@example.test', bin2hex(random_bytes(24)));
$customer = CommerceTest::user('demo-seed-customer@example.test');
$seeder = new DemoCatalogSeeder($pdo);

CommerceTest::rejects(fn() => $seeder->run($customer), 403, 'Customer cannot seed catalog.');
$check((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0, 'Denied seeding writes no products.');

$first = $seeder->run($admin);
$check(
    $first['created_products'] === 0
    && $first['skipped_products'] === 0
    && $first['created_images'] === 0
    && $first['stock_adjustments'] === 0
    && $first['products'] === [],
    'Empty catalog.php returns a zeroed, idempotent summary and writes nothing.'
);
$check((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0, 'No products are created when fixtures are empty.');
$check((int)$pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn() === 0, 'No variants are created when fixtures are empty.');
$check((int)$pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn() === 0, 'No images are created when fixtures are empty.');
$check((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0, 'No categories are created when fixtures are empty.');
$check((int)$pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn() === 0, 'No brands are created when fixtures are empty.');
$check((int)$pdo->query('SELECT COUNT(*) FROM users_tbl')->fetchColumn() === 2, 'Seeder creates no identities or passwords.');

// Rerun stays a no-op.
$second = $seeder->run($admin);
$check(
    $second['created_products'] === 0
    && $second['skipped_products'] === 0
    && $second['created_images'] === 0
    && $second['stock_adjustments'] === 0,
    'Rerun on empty fixtures remains a no-op.'
);

// Production CLI still refuses demo seeding.
[$status, $out, $err] = CommerceTest::command(['vayu', 'demo:seed', '--actor-id=' . $admin], '', ['APP_ENV' => 'production']);
$check($status === 1 && $out === '' && str_contains($err, 'Requires development/test'), 'Production CLI refuses seeding.');

// Development/test CLI returns the same zeroed summary.
[$status, $out, $err] = CommerceTest::command(['vayu', 'demo:seed', '--actor-id=' . $admin]);
$check($status === 0 && $err === '', 'Development CLI succeeds with empty fixtures.');
$summary = json_decode($out, true);
$check(
    is_array($summary)
    && ($summary['created_products'] ?? null) === 0
    && ($summary['skipped_products'] ?? null) === 0
    && ($summary['created_images'] ?? null) === 0
    && ($summary['stock_adjustments'] ?? null) === 0,
    'Development CLI returns a repeatable zeroed seed summary.'
);

CommerceTest::finish('Demo catalog seeder');