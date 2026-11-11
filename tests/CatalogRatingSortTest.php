<?php
// PAR-HTML-01: data-backed rating sort (`rating_high`) and minimum-rating filter (`min_rating`)
// on the public storefront listing. Ratings come only from approved reviews by active, verified
// users (matching the hydrate() and ReviewService::publicPage roll-up). Runs on SQLite and, with
// --mysql, a disposable MariaDB. Usage: php tests/CatalogRatingSortTest.php [--mysql]
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];

$admin = CommerceAccess::provision('rate-admin@example.test', 'Disposable-Rate-Password');
$service = new ProductService($pdo);
$category = $service->saveCategory($admin, ['slug' => 'rate-stone', 'name' => 'Rated Stone'], 'rate:category');

$make = static function (string $slug, string $name) use ($service, $admin, $category): int {
    $saved = $service->save($admin, ['slug' => $slug, 'name' => $name, 'category_id' => $category, 'status' => 'active',
        'variants' => [['sku' => strtoupper($slug) . '-SQFT', 'sell_unit' => 'sqft', 'qty_increment_milli' => 1000, 'unit_price_minor' => 100000]]], 0, 'rate:product:' . $slug);
    return (int) $service->detail($slug)['id'];
};
$high = $make('rate-high', 'Highly Rated Marble');   // avg 5.0
$mid  = $make('rate-mid', 'Mid Rated Marble');       // avg 3.5
$none = $make('rate-none', 'Unrated Marble');        // no reviews

// Two active, email-verified reviewers; one approved review each per rated product (unique per owner).
$u1 = CommerceTest::user('rate-reviewer-1@example.test');
$u2 = CommerceTest::user('rate-reviewer-2@example.test');
$review = static function (int $product, int $user, int $rating) use ($pdo): void {
    $pdo->prepare("INSERT INTO product_reviews (product_id,user_id,rating,title,body,status,created_at,updated_at)
        VALUES (?,?,?,'Review','Body text.','approved',1,1)")->execute([$product, $user, $rating]);
};
$review($high, $u1, 5); $review($high, $u2, 5);   // avg (50+50)/2 = 50 tenths
$review($mid, $u1, 4);  $review($mid, $u2, 3);    // avg (40+30)/2 = 35 tenths

$catalog = new CatalogReadService($pdo);
$slugs = static fn(array $result): array => array_values(array_map(
    static fn(array $p): string => $p['slug'],
    array_filter($result['items'], static fn(array $p): bool => str_starts_with($p['slug'], 'rate-'))));
$pos = static function (array $list, string $slug): int { $i = array_search($slug, $list, true); return $i === false ? 9999 : $i; };

// 1) rating_high orders by average approved rating, unrated products last.
$ranked = $slugs($catalog->listing(CatalogReadService::filters(['sort' => 'rating_high', 'per_page' => 50])));
$check($pos($ranked, 'rate-high') < $pos($ranked, 'rate-mid'), 'Higher average rating sorts before a lower one.');
$check($pos($ranked, 'rate-mid') < $pos($ranked, 'rate-none'), 'A rated product sorts before an unrated one.');

// 2) min_rating excludes products below the threshold and all unrated products.
$four = $slugs($catalog->listing(CatalogReadService::filters(['min_rating' => '4', 'per_page' => 50])));
$check(in_array('rate-high', $four, true) && !in_array('rate-mid', $four, true) && !in_array('rate-none', $four, true),
    'min_rating=4 keeps only the 5.0 product; the 3.5 and unrated ones are excluded.');
$three = $slugs($catalog->listing(CatalogReadService::filters(['min_rating' => '3', 'per_page' => 50])));
$check(in_array('rate-high', $three, true) && in_array('rate-mid', $three, true) && !in_array('rate-none', $three, true),
    'min_rating=3 keeps both rated products but still excludes the unrated one.');

// 3) rating_high composes with min_rating.
$both = $slugs($catalog->listing(CatalogReadService::filters(['sort' => 'rating_high', 'min_rating' => '3', 'per_page' => 50])));
$check($both === ['rate-high', 'rate-mid'], 'Rating sort and min_rating filter compose, excluding the unrated product.');

// 4) Validation: the new sort is accepted; out-of-range/invalid ratings are rejected.
$check(CatalogReadService::filters(['sort' => 'rating_high'])['sort'] === 'rating_high', 'rating_high is an accepted sort value.');
CommerceTest::rejects(fn() => CatalogReadService::filters(['min_rating' => '0']), 422, 'min_rating below 1 is rejected.');
CommerceTest::rejects(fn() => CatalogReadService::filters(['min_rating' => '6']), 422, 'min_rating above 5 is rejected.');
CommerceTest::rejects(fn() => CatalogReadService::filters(['min_rating' => 'x']), 422, 'Non-numeric min_rating is rejected.');
CommerceTest::rejects(fn() => CatalogReadService::filters(['sort' => 'rating_low']), 422, 'An unknown sort value is rejected.');

CommerceTest::finish('Catalog rating sort and minimum-rating filter');
