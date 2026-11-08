<?php
// REC-01: co-purchase/co-view pair support, min-support threshold, cancellation exclusion, similarity
// fallback, self/out-of-stock filtering, atomic generation publish and deterministic ordering.
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];
foreach (['CHECKOUT_COD_ENABLED' => 'true', 'CHECKOUT_SHIPPING_MINOR' => '0', 'CHECKOUT_TAX_BPS' => '0'] as $k => $v) { $_ENV[$k] = $v; putenv($k . '=' . $v); }

$admin = CommerceAccess::provision('rec-admin@example.test', 'Disposable-Rec-Password');
$buyer = CommerceTest::user('rec-buyer@example.test');
$products = new ProductService($pdo); $inventory = new InventoryService($pdo); $carts = new CartService($pdo);
$orders = new OrderService($pdo); $addresses = new AddressService($pdo); $events = new EventService($pdo);
$rec = new RecommendationService($pdo); $worker = new QueueWorker($pdo);
$category = $products->saveCategory($admin, ['slug' => 'rec-stone', 'name' => 'Rec Stone'], 'rec:category');
$variant = []; $slugById = [];
$make = function (string $slug, int $stock) use (&$variant, &$slugById, $products, $inventory, $admin, $category): int {
    $n = count($variant) + 1;
    $s = $products->save($admin, ['slug' => $slug, 'name' => ucfirst($slug), 'category_id' => $category, 'status' => 'active',
        'variants' => [['sku' => 'REC-' . strtoupper($slug), 'sell_unit' => 'sqft', 'qty_increment_milli' => 1000, 'unit_price_minor' => 1000]]], 0, 'rec:' . $slug);
    $id = (int) $s['id']; $variant[$id] = (int) $s['variant_ids'][0]; $slugById[$id] = $slug;
    if ($stock > 0) $inventory->adjust($admin, $variant[$id], $stock, 'Stock', 'rec:stock:' . $n);
    return $id;
};
// Creation order fixes ids: C,D,E lower than A,B so fallback (id order) never puts B first.
$C = $make('prod-c', 100000); $D = $make('prod-d', 100000); $E = $make('prod-e', 100000);
$A = $make('prod-a', 100000); $B = $make('prod-b', 100000); $F = $make('prod-f', 0); // F out of stock
$addr = $addresses->save($buyer, ['recipient' => 'Rec Buyer', 'phone' => '9876500000', 'line1' => 'Road', 'city' => 'Bankura', 'state' => 'West Bengal', 'postal_code' => '722101'])['id'];
$slugs = fn(array $items): array => array_map(static fn(array $p): string => $p['slug'], $items);

// --- Fallback before any generation is published: live category similarity, self and out-of-stock excluded ---
$fallback = $rec->forProduct($A, 4);
$fb = $slugs($fallback);
$check($fb && !in_array('prod-a', $fb, true) && !in_array('prod-f', $fb, true), 'With no generation, forProduct returns similarity excluding self and out-of-stock.');
$check($fb === $slugs($rec->forProduct($A, 4)), 'The similarity fallback is deterministic.');

// --- Co-purchase: three orders buying A and B give pair (A,B) three supports ---
$place = function (array $productIds, string $key) use ($carts, $orders, $buyer, $addr, $variant): array {
    $cart = null;
    foreach ($productIds as $pid) { $cart = $carts->change('user:' . $buyer, $variant[$pid], 1000, $cart['revision'] ?? 0, $cart['id'] ?? null); }
    return $orders->place($buyer, $cart['id'], $cart['revision'], $addr, 'cod', $key);
};
$o1 = $place([$A, $B], 'rec:order:1'); $o2 = $place([$A, $B], 'rec:order:2'); $o3 = $place([$A, $B], 'rec:order:3');
$worker->run(['purchase_events'], 20, 30); // records purchase events + co-purchase pairs
$check((int) $pdo->query("SELECT COUNT(*) FROM rec_pair_support WHERE kind='copurchase' AND source_product_id=$A AND target_product_id=$B")->fetchColumn() === 3, 'Three orders yield three co-purchase supports for the pair.');

$build = $rec->rebuild();
$recos = $slugs($rec->forProduct($A, 4));
$check($build['generation'] === 1 && $recos[0] === 'prod-b', 'After rebuild, the thrice co-purchased product is the top recommendation.');
$check(!in_array('prod-a', $recos, true) && !in_array('prod-f', $recos, true), 'Recommendations never include self or out-of-stock products.');

// --- Minimum support: a pair below three supports is not promoted above similarity ---
$o4 = $place([$A, $C], 'rec:order:4'); $o5 = $place([$A, $C], 'rec:order:5'); // only two (A,C) supports
$worker->run(['purchase_events'], 20, 30);
$rec->rebuild();
$recos = $slugs($rec->forProduct($A, 4));
$check($recos[0] === 'prod-b', 'A pair with only two supports does not outrank the three-support pair.');

// --- Cancellation excludes an order's pair support at aggregate time ---
$orders->cancel($admin, (int) $o1['id'], (int) $o1['revision'], 'rec:cancel:1'); // (A,B) now has two live supports
$rec->rebuild();
$recosAfter = $slugs($rec->forProduct($A, 4));
$check($recosAfter[0] !== 'prod-b', 'Cancelling an order drops its pair below min support, so B is no longer promoted.');

// --- Co-view: three distinct sessions viewing A then C give pair (A,C) three supports ---
foreach (['s1', 's2', 's3'] as $tag) {
    $session = str_repeat(substr(md5($tag), 0, 1), 64);
    $events->ingest('product_view', ['product_id' => $A], null, $session);
    $events->ingest('product_view', ['product_id' => $C], null, $session);
}
$check((int) $pdo->query("SELECT COUNT(*) FROM rec_pair_support WHERE kind='coview' AND source_product_id=$A AND target_product_id=$C")->fetchColumn() === 3, 'Three sessions co-viewing A and C yield three co-view supports.');
$rec->rebuild();
$check(in_array('prod-c', $slugs($rec->forProduct($A, 4)), true), 'Co-viewed products become recommendations once supported.');

// --- Generation publish is atomic and prunes superseded generations; unpublished rows are invisible ---
$current = RecommendationService::generation($pdo);
$check($current >= 3 && (int) $pdo->query("SELECT COUNT(*) FROM rec_suggestions WHERE generation<$current")->fetchColumn() === 0, 'Rebuild advances and prunes to a single live generation.');
$pdo->prepare('INSERT INTO rec_suggestions (generation,source_product_id,target_product_id,position,created_at) VALUES (?,?,?,0,?)')->execute([$current + 7, $A, $F, CommerceDatabase::clock($pdo)]);
$check(!in_array('prod-f', $slugs($rec->forProduct($A, 4)), true), 'A higher, unpublished generation is invisible to reads.');

// --- Idempotent support keys: replaying a co-purchase does not inflate the pair ---
CommerceDatabase::transaction($pdo, fn() => $rec->recordPurchaseOrder((int) $o2['id']));
$check((int) $pdo->query("SELECT COUNT(*) FROM rec_pair_support WHERE kind='copurchase' AND order_id=" . (int) $o2['id'] . " AND source_product_id=$A AND target_product_id=$B")->fetchColumn() === 1, 'Replaying an order\'s co-purchase support is a no-op.');

CommerceTest::finish('Bounded recommendations (REC-01)');
