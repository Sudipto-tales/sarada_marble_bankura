<?php
// SRCH-01: two-stage ranked keyword search (SQLite LIKE fallback) — relevance/sales/stock/featured factors,
// deterministic ties, filters, escaped wildcards, zero matches, candidate honesty and stable pagination.
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];

$admin = CommerceAccess::provision('srch-admin@example.test', 'Disposable-Srch-Password');
$products = new ProductService($pdo); $inventory = new InventoryService($pdo);
$category = $products->saveCategory($admin, ['slug' => 'srch-stone', 'name' => 'Srch Stone'], 'srch:category');
$brand = $products->saveBrand($admin, ['slug' => 'srch-brand', 'name' => 'Srch Brand'], 'srch:brand');
$n = 0;
$make = function (string $slug, string $name, string $tags, string $desc, int $featured, int $stock, ?int $brandId = null) use (&$n, $products, $inventory, $admin, $category): int {
    $n++;
    $saved = $products->save($admin, ['slug' => $slug, 'name' => $name, 'tags' => $tags, 'description' => $desc, 'category_id' => $category,
        'brand_id' => $brandId, 'status' => 'active', 'featured' => $featured,
        'variants' => [['sku' => 'SRCH-' . $n, 'sell_unit' => 'sqft', 'qty_increment_milli' => 1000, 'unit_price_minor' => 1000 * $n]]], 0, 'srch:p:' . $n);
    if ($stock > 0) $inventory->adjust($admin, $saved['variant_ids'][0], $stock, 'Stock', 'srch:stock:' . $n);
    return (int) $saved['id'];
};
// One factor differs per pair; everything is created "now" so freshness is equal.
$full = $make('m-full', 'Marble Full', 'marble premium', 'a marble slab', 0, 5000, $brand); // lexical 3+2+1
$name = $make('m-name', 'Marble Name', 'granite', 'plain stone', 0, 5000);                  // lexical name-only
$feat = $make('m-feat', 'Marble Feat', 'granite', 'plain stone', 1, 5000);                  // + featured
$sale = $make('m-sale', 'Marble Sale', 'granite', 'plain stone', 0, 5000);                  // + sales metric
$nostock = $make('m-nostock', 'Marble Nostock', 'granite', 'plain stone', 0, 0);            // no stock
$twin1 = $make('m-twin1', 'Marble Twin', 'granite', 'plain stone', 0, 5000);                // identical to twin2
$twin2 = $make('m-twin2', 'Marble Twin', 'granite', 'plain stone', 0, 5000);
$pdo->prepare('INSERT INTO product_daily_metrics (product_id,metric_date,orders_net,updated_at) VALUES (?,?,?,?)')
    ->execute([$sale, gmdate('Y-m-d', CommerceDatabase::clock($pdo)), 9, CommerceDatabase::clock($pdo)]);

$search = fn(array $f) => $products->listing($f);
$order = function (array $result): array { return array_map(static fn(array $p): string => $p['slug'], $result['items']); };
$pos = function (array $slugs, string $slug): int { $i = array_search($slug, $slugs, true); return $i === false ? 9999 : $i; };

$result = $search(['q' => 'marble', 'per_page' => 50]);
$slugs = $order($result);
$check($result['total'] === 7 && count($slugs) === 7 && $result['capped'] === false, 'All matching products are ranked and returned.');
$check($pos($slugs, 'm-full') < $pos($slugs, 'm-name'), 'Higher lexical relevance ranks above a name-only match.');
$check($pos($slugs, 'm-feat') < $pos($slugs, 'm-name'), 'A featured product outranks an otherwise identical one.');
$check($pos($slugs, 'm-sale') < $pos($slugs, 'm-name'), 'Recent net sales lift a product above an otherwise identical one.');
$check($pos($slugs, 'm-name') < $pos($slugs, 'm-nostock'), 'An in-stock product outranks an out-of-stock one.');
$check($pos($slugs, 'm-twin1') < $pos($slugs, 'm-twin2'), 'Equal scores break deterministically by ascending product id.');
$check(is_string($result['generation']) && $result['generation'] === $search(['q' => 'marble', 'per_page' => 50])['generation'], 'The generation token is stable for the same query.');

// Zero matches and zero max sales are safe.
$none = $search(['q' => 'zzqqnomatch']);
$check($none['total'] === 0 && $none['items'] === [] && $none['pages'] === 0 && $none['has_more'] === false, 'A query with no matches returns an empty ranked page.');

// Wildcards in the query are never treated as operators. SQLite escapes '%' in LIKE so it matches literally
// (nothing); MySQL FULLTEXT natural-language mode tokenises the '%' away so it matches the same as the bare
// term (and '%' is not a FULLTEXT operator). Either way a percent cannot act as a wildcard.
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
    $check($search(['q' => 'marble%'])['total'] === $search(['q' => 'marble'])['total'], 'A percent is tokenised away, not treated as a wildcard operator.');
} else {
    $check($search(['q' => 'marble%'])['total'] === 0, 'A percent in the query is matched literally, not as a wildcard.');
}
$check($search(['q' => 'granite'])['total'] === 6, 'A term in tags matches via the lexical fallback (the six granite-tagged products).');
$check($search(['q' => 'slab'])['total'] === 1 && $order($search(['q' => 'slab']))[0] === 'm-full', 'A description-only term still matches via the lexical fallback.');

// Filters compose with ranking.
$branded = $search(['q' => 'marble', 'brand' => 'srch-brand']);
$check($branded['total'] === 1 && $branded['items'][0]['slug'] === 'm-full', 'A brand filter narrows the ranked candidates.');
$priced = $search(['q' => 'marble', 'max_price_minor' => 2000]);

// Stable, deterministic pagination over the ranked generation.
$p1 = $order($search(['q' => 'marble', 'per_page' => 3, 'page' => 1]));
$p2 = $order($search(['q' => 'marble', 'per_page' => 3, 'page' => 2]));
$p3 = $search(['q' => 'marble', 'per_page' => 3, 'page' => 3]);
$check(count($p1) === 3 && count($p2) === 3 && !array_intersect($p1, $p2), 'Pages are disjoint slices of one ranked order.');
$check($p1 === $order($search(['q' => 'marble', 'per_page' => 3, 'page' => 1])), 'Repeating a page returns the same order.');
$check($search(['q' => 'marble', 'per_page' => 3, 'page' => 1])['has_more'] === true && $p3['has_more'] === false, 'has_more reflects the ranked total honestly.');

CommerceTest::finish('Ranked keyword search (SRCH-01)');
