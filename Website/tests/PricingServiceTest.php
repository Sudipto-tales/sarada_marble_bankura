<?php
// PRC-01: bounded default-off dynamic pricing — factor math, clamps, bands, determinism and order snapshots.
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];
foreach (['CHECKOUT_COD_ENABLED' => 'true', 'CHECKOUT_SHIPPING_MINOR' => '0', 'CHECKOUT_TAX_BPS' => '0', 'PRICING_ENABLED' => 'true'] as $k => $v) { $_ENV[$k] = $v; putenv($k . '=' . $v); }

$admin = CommerceAccess::provision('prc-admin@example.test', 'Disposable-Prc-Password');
$buyer = CommerceTest::user('prc-buyer@example.test');
$products = new ProductService($pdo); $inventory = new InventoryService($pdo); $pricing = new PricingService($pdo);
$carts = new CartService($pdo); $orders = new OrderService($pdo); $addresses = new AddressService($pdo);
$category = $products->saveCategory($admin, ['slug' => 'prc-stone', 'name' => 'Prc Stone'], 'prc:category');
$n = 0;
$make = function (int $priceMinor, int $stock = 100000) use (&$n, $products, $inventory, $admin, $category): array {
    $n++;
    $s = $products->save($admin, ['slug' => "prc-$n", 'name' => "Prc $n", 'category_id' => $category, 'status' => 'active',
        'variants' => [['sku' => "PRC-$n", 'sell_unit' => 'sqft', 'qty_increment_milli' => 1000, 'unit_price_minor' => $priceMinor]]], 0, "prc:product:$n");
    $inventory->adjust($admin, $s['variant_ids'][0], $stock, 'Opening stock', "prc:stock:$n");
    return [(int) $s['id'], (int) $s['variant_ids'][0]];
};
$rule = fn(array $input, string $key) => $pricing->saveRule($admin, $input, $key);
$eff = fn(int $p, int $v, int $base) => (int) $pricing->effective($p, $category, $v, $base)['unit_price_minor'];

// --- Campaign factor: base 10000 * 0.9 = 9000 ---
[$p1, $v1] = $make(10000);
$r1 = $rule(['factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $p1, 'factor_ppm' => 900000, 'enabled' => 1], 'prc:rule:campaign1');
$check($eff($p1, $v1, 10000) === 9000, 'A 0.9 campaign factor turns 10000 into 9000.');

// --- Disabled rule and globally disabled pricing both return the base ---
$rule(['id' => $r1['id'], 'factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $p1, 'factor_ppm' => 900000, 'enabled' => 0], 'prc:rule:campaign1:off');
$check($eff($p1, $v1, 10000) === 10000, 'A disabled rule leaves the base price.');
$rule(['id' => $r1['id'], 'factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $p1, 'factor_ppm' => 900000, 'enabled' => 1], 'prc:rule:campaign1:on');
putenv('PRICING_ENABLED=false'); $_ENV['PRICING_ENABLED'] = 'false';
$check($eff($p1, $v1, 10000) === 10000 && PricingService::enabled() === false, 'Globally disabled pricing returns the base.');
putenv('PRICING_ENABLED=true'); $_ENV['PRICING_ENABLED'] = 'true';

// --- Floor clamp: 0.9 of 10000 = 9000, floored to 9500 ---
$rule(['id' => $r1['id'], 'factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $p1, 'factor_ppm' => 900000, 'floor_minor' => 9500, 'enabled' => 1], 'prc:rule:campaign1:floor');
$check($eff($p1, $v1, 10000) === 9500, 'The approved floor clamps the discounted price up to 9500.');

// --- Ceiling clamp with an increase factor ---
[$p2, $v2] = $make(10000);
$rule(['factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $p2, 'factor_ppm' => 1200000, 'ceiling_minor' => 11000, 'enabled' => 1], 'prc:rule:campaign2');
$check($eff($p2, $v2, 10000) === 11000, 'The approved ceiling clamps an increase down to 11000.');

// --- Overflow is rejected, never silently wrapped ---
[$p3, $v3] = $make(1000);
$rule(['factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $p3, 'factor_ppm' => 1050000, 'enabled' => 1], 'prc:rule:campaign3');
CommerceTest::rejects(fn() => $pricing->effective($p3, $category, $v3, CommerceValues::MAX_MONEY), 422, 'A factor that overflows the money ceiling is rejected.');

// --- Overlapping rules resolve deterministically by priority ---
[$p4, $v4] = $make(10000);
$rule(['factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $p4, 'factor_ppm' => 900000, 'priority' => 5, 'enabled' => 1], 'prc:rule:overlap:a');
$rule(['factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $p4, 'factor_ppm' => 800000, 'priority' => 10, 'enabled' => 1], 'prc:rule:overlap:b');
$check($eff($p4, $v4, 10000) === 8000, 'The highest-priority overlapping rule wins deterministically.');

// --- Expired rules are ignored ---
[$p5, $v5] = $make(10000);
$rule(['factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $p5, 'factor_ppm' => 500000, 'effective_to' => CommerceDatabase::clock($pdo) - 10, 'enabled' => 1], 'prc:rule:expired');
$check($eff($p5, $v5, 10000) === 10000, 'An expired rule does not apply.');

// --- Demand band from published metrics ---
$now = CommerceDatabase::clock($pdo); $today = gmdate('Y-m-d', $now); $recent = gmdate('Y-m-d', $now - 10 * 86400);
$seedMetric = function (int $product, string $date, int $net) use ($pdo, $now): void {
    $pdo->prepare('INSERT INTO product_daily_metrics (product_id,metric_date,orders_net,orders_gross,updated_at) VALUES (?,?,?,?,?)')->execute([$product, $date, $net, $net, $now]);
};
[$pHigh, $vHigh] = $make(10000); $rule(['factor_type' => 'demand', 'scope' => 'product', 'scope_id' => $pHigh, 'enabled' => 1], 'prc:rule:demandH');
$seedMetric($pHigh, $today, 5); $seedMetric($pHigh, $recent, 10); // A=5, B=10 → 8A=40 >= 3B=30 → high
$check($eff($pHigh, $vHigh, 10000) === 10500, 'High demand (8A>=3B) raises the price 5%.');
[$pLow, $vLow] = $make(10000); $rule(['factor_type' => 'demand', 'scope' => 'product', 'scope_id' => $pLow, 'enabled' => 1], 'prc:rule:demandL');
$seedMetric($pLow, $today, 0); $seedMetric($pLow, $recent, 10); // A=0, B=10 → 8A=0 < B → low
$check($eff($pLow, $vLow, 10000) === 9500, 'Low demand (8A<B) trims the price 5%.');
[$pSupp, $vSupp] = $make(10000); $rule(['factor_type' => 'demand', 'scope' => 'product', 'scope_id' => $pSupp, 'enabled' => 1], 'prc:rule:demandN');
$seedMetric($pSupp, $recent, 2); // B=2 < 3 → insufficient support → neutral
$check($eff($pSupp, $vSupp, 10000) === 10000, 'Insufficient demand support stays neutral.');

// --- Inventory band: plentiful stock trims 2% ---
[$pInv, $vInv] = $make(10000, 100000); // available==on_hand → 4*avail>=3*on_hand
$rule(['factor_type' => 'inventory', 'scope' => 'product', 'scope_id' => $pInv, 'enabled' => 1], 'prc:rule:inv');
$check($eff($pInv, $vInv, 10000) === 9800, 'Plentiful inventory trims the price 2%.');

// --- Checkout snapshots the effective price and rule version; later rule changes never alter the order ---
[$pOrd, $vOrd] = $make(10000);
$snapRule = $rule(['factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $pOrd, 'factor_ppm' => 900000, 'enabled' => 1], 'prc:rule:order');
$address = $addresses->save($buyer, ['recipient' => 'Prc Buyer', 'phone' => '9876500000', 'line1' => 'Station Road', 'city' => 'Bankura', 'state' => 'West Bengal', 'postal_code' => '722101']);
$cart = $carts->change('user:' . $buyer, $vOrd, 2000, 0);
$check((int) $cart['items'][0]['unit_price_minor'] === 9000, 'The cart shows the effective price so checkout agrees.');
$order = $orders->place($buyer, $cart['id'], $cart['revision'], $address['id'], 'cod', 'prc:checkout:order');
$item = $pdo->query('SELECT base_price_minor,unit_price_minor,price_rule_version FROM order_items WHERE order_id=' . (int) $order['id'])->fetch();
$version = PricingService::version($pdo);
$check((int) $item['base_price_minor'] === 10000 && (int) $item['unit_price_minor'] === 9000 && (int) $item['price_rule_version'] === $version,
    'Checkout persists base, effective and the applied rule version.');
$check((int) $order['subtotal_minor'] === 18000, 'The order subtotal uses the effective price (9000 x 2).');
$rule(['id' => $snapRule['id'], 'factor_type' => 'campaign', 'scope' => 'product', 'scope_id' => $pOrd, 'factor_ppm' => 500000, 'enabled' => 1], 'prc:rule:order:changed');
$again = $pdo->query('SELECT unit_price_minor FROM order_items WHERE order_id=' . (int) $order['id'])->fetch();
$check((int) $again['unit_price_minor'] === 9000 && PricingService::version($pdo) > $version, 'Changing the rule advances the version but never mutates the existing order snapshot.');

// --- saveRule is audited and requires the grant ---
$check((int) $pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action='pricing.save'")->fetchColumn() > 0, 'Rule writes are audited.');
CommerceTest::rejects(fn() => $pricing->saveRule($buyer, ['factor_type' => 'campaign', 'scope' => 'global', 'factor_ppm' => 900000, 'enabled' => 1], 'prc:rule:forbidden'), 403, 'A non-pricing actor cannot write rules.');

CommerceTest::finish('Bounded default-off dynamic pricing (PRC-01)');
