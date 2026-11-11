<?php
// EVT-02: incremental daily aggregation (net/gross), late-event bucketing, reversal correction and retention.
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];
foreach (['CHECKOUT_COD_ENABLED' => 'true', 'CHECKOUT_SHIPPING_MINOR' => '100', 'CHECKOUT_TAX_BPS' => '500'] as $name => $value) { $_ENV[$name] = $value; putenv($name . '=' . $value); }

$admin = CommerceAccess::provision('evt2-admin@example.test', 'Disposable-Evt2-Password');
$buyer = CommerceTest::user('evt2-buyer@example.test');
$products = new ProductService($pdo); $inventory = new InventoryService($pdo); $carts = new CartService($pdo);
$orders = new OrderService($pdo); $addresses = new AddressService($pdo); $events = new EventService($pdo);
$metrics = new MetricsService($pdo); $worker = new QueueWorker($pdo);

$category = $products->saveCategory($admin, ['slug' => 'evt2-stone', 'name' => 'Evt2 Stone'], 'evt2:category');
$seq = 0;
// Each case uses its own product so daily buckets never collide within one UTC day.
$make = function (string $slug) use (&$seq, $products, $inventory, $admin, $category): array {
    $seq++;
    $saved = $products->save($admin, ['slug' => $slug, 'name' => ucfirst($slug), 'category_id' => $category, 'status' => 'active',
        'variants' => [['sku' => 'EVT2-' . strtoupper($slug), 'sell_unit' => 'sqft', 'qty_increment_milli' => 1000, 'unit_price_minor' => 1000]]], 0, 'evt2:' . $slug);
    $inventory->adjust($admin, $saved['variant_ids'][0], 100000, 'Opening stock', 'evt2:stock:' . $seq);
    return [(int) $saved['id'], (int) $saved['variant_ids'][0]];
};
$address = $addresses->save($buyer, ['recipient' => 'Evt2 Buyer', 'phone' => '9876500000', 'line1' => 'Station Road', 'city' => 'Bankura', 'state' => 'West Bengal', 'postal_code' => '722101']);
$session = str_repeat('c', 64);
$today = gmdate('Y-m-d', CommerceDatabase::clock($pdo));
$bucket = fn(int $product, string $date) => $pdo->query("SELECT * FROM product_daily_metrics WHERE product_id=$product AND metric_date='$date'")->fetch();
$placeDay = fn(array $order) => gmdate('Y-m-d', (int) $pdo->query('SELECT created_at FROM orders WHERE id=' . (int) $order['id'])->fetchColumn());

// --- Public events aggregate into their own UTC day bucket ---
[$pv, $pvVar] = $make('views');
$events->ingest('product_view', ['product_id' => $pv], $buyer, $session);
$events->ingest('product_view', ['product_id' => $pv], null, $session);
$events->ingest('add_to_cart', ['variant_id' => $pvVar], null, $session);
$events->ingest('search', ['query' => 'marble'], null, $session); // no product metric
$result = $metrics->aggregate(100, 10);
$check($result['processed'] === 4 && $result['pending'] === 0, 'Aggregation drains all pending events.');
$row = $bucket($pv, $today);
$check($row && (int) $row['views'] === 2 && (int) $row['add_to_carts'] === 1, "Views and add-to-carts land in today's product bucket; search adds no product metric.");
$again = $metrics->aggregate(100, 10);
$check($again['processed'] === 0 && (int) $bucket($pv, $today)['views'] === 2, 'Processed events are not re-aggregated.');

// --- Late-arriving event applies to its own UTC bucket, not today ---
$events->ingest('product_view', ['product_id' => $pv], null, $session);
$lateId = (int) $pdo->query("SELECT MAX(id) FROM events WHERE product_id=$pv AND event_type='product_view'")->fetchColumn();
$lateTs = CommerceDatabase::clock($pdo) - 10 * 86400; $lateDay = gmdate('Y-m-d', $lateTs);
$pdo->prepare('UPDATE events SET occurred_at=? WHERE id=?')->execute([$lateTs, $lateId]);
$metrics->aggregate(100, 10);
$check((int) $bucket($pv, $lateDay)['views'] === 1 && (int) $bucket($pv, $today)['views'] === 2, "A late event lands in its own day bucket, not today's.");

// --- Purchase contributes gross and net once ---
[$pa, $paVar] = $make('bought');
$cart = $carts->change('user:' . $buyer, $paVar, 2000, 0);
$order = $orders->place($buyer, $cart['id'], $cart['revision'], $address['id'], 'cod', 'evt2:checkout:a');
$worker->run(['purchase_events'], 10, 30);
$metrics->aggregate(100, 10);
$row = $bucket($pa, $placeDay($order));
$check($row && (int) $row['orders_gross'] === 1 && (int) $row['orders_net'] === 1
    && (int) $row['units_gross_milli'] === 2000 && (int) $row['units_net_milli'] === 2000, 'A purchase adds one gross and one net order with its units.');

// --- Cancellation correction: gross counts it, net nets to zero ---
[$pb, $pbVar] = $make('cancelled');
$cart2 = $carts->change('user:' . $buyer, $pbVar, 1000, 0);
$orderB = $orders->place($buyer, $cart2['id'], $cart2['revision'], $address['id'], 'cod', 'evt2:checkout:b');
$orders->cancel($admin, (int) $orderB['id'], (int) $orderB['revision'], 'evt2:cancel:b');
$worker->run(['purchase_events'], 10, 30); // records both the purchase and the cancellation events
$metrics->aggregate(100, 10);
$row = $bucket($pb, $placeDay($orderB));
$check($row && (int) $row['orders_gross'] === 1 && (int) $row['orders_net'] === 0
    && (int) $row['units_gross_milli'] === 1000 && (int) $row['units_net_milli'] === 0, 'Cancellation nets out the order: gross one, net zero.');

// --- Reversal arriving before the purchase still nets into the order's creation bucket ---
[$pc, $pcVar] = $make('reversed');
$cart3 = $carts->change('user:' . $buyer, $pcVar, 3000, 0);
$orderC = $orders->place($buyer, $cart3['id'], $cart3['revision'], $address['id'], 'cod', 'evt2:checkout:c');
$pdo->exec("DELETE FROM jobs WHERE queue='purchase_events'"); // record manually, reversal first
$cDay = $placeDay($orderC);
CommerceDatabase::transaction($pdo, fn() => $events->recordCancellation($pdo, (int) $orderC['id']));
$metrics->aggregate(100, 10);
$rev = $bucket($pc, $cDay);
$check($rev && (int) $rev['orders_net'] === -1 && (int) $rev['units_net_milli'] === -3000 && (int) $rev['orders_gross'] === 0, 'A cancellation before its purchase posts a negative net correction.');
CommerceDatabase::transaction($pdo, fn() => $events->recordPurchase($pdo, (int) $orderC['id']));
$metrics->aggregate(100, 10);
$rev = $bucket($pc, $cDay);
$check((int) $rev['orders_net'] === 0 && (int) $rev['units_net_milli'] === 0 && (int) $rev['orders_gross'] === 1, 'The later purchase meets the reversal in the same bucket: net zero, gross one.');

// --- Checkpoint advances with processed work ---
$cp = $pdo->query('SELECT last_event_id,processed_total FROM analytics_checkpoint')->fetch();
$maxProcessed = (int) $pdo->query("SELECT MAX(event_id) FROM event_processing WHERE state='processed'")->fetchColumn();
$check((int) $cp['processed_total'] > 0 && (int) $cp['last_event_id'] === $maxProcessed, 'The checkpoint tracks the processed total and highest processed event id.');

// --- Retention deletes old processed events but keeps business keys and pending intents ---
$oldTs = CommerceDatabase::clock($pdo) - 200 * 86400;
$firstView = (int) $pdo->query("SELECT MIN(id) FROM events WHERE product_id=$pv")->fetchColumn();
$pdo->prepare('UPDATE events SET created_at=? WHERE id=?')->execute([$oldTs, $firstView]);
$events->ingest('product_view', ['product_id' => $pv], null, $session); // a fresh pending event
$oldPending = (int) $pdo->query('SELECT MAX(id) FROM events')->fetchColumn();
$pdo->prepare('UPDATE events SET created_at=? WHERE id=?')->execute([$oldTs, $oldPending]); // old but still pending
$keysBefore = (int) $pdo->query('SELECT COUNT(*) FROM event_business_keys')->fetchColumn();
$purge = $metrics->purge(90, 500, 10);
$check($purge['deleted'] >= 1 && !$pdo->query("SELECT id FROM events WHERE id=$firstView")->fetchColumn(), 'Retention deletes an old processed event.');
$check((int) $pdo->query("SELECT COUNT(*) FROM events WHERE id=$oldPending")->fetchColumn() === 1, 'Retention never deletes a pending intent, however old.');
$check((int) $pdo->query('SELECT COUNT(*) FROM event_business_keys')->fetchColumn() === $keysBefore, 'The durable business-key ledger is kept through retention.');

// --- CLI entry points run ---
[$exit, $out] = CommerceTest::command(['vayu', 'analytics:aggregate', '--batch=50', '--max-seconds=2']);
$check($exit === 0 && isset(json_decode($out, true)['processed']), 'analytics:aggregate runs from the CLI.');
[$exit, $out] = CommerceTest::command(['vayu', 'analytics:purge', '--retention-days=90', '--max-seconds=2']);
$check($exit === 0 && isset(json_decode($out, true)['deleted']), 'analytics:purge runs from the CLI.');

CommerceTest::finish('Incremental analytics aggregation and retention (EVT-02)');
