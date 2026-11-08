<?php
// EVT-01: append-only event ingestion, trusted purchase/cancellation consumer, dedup and retention-safe keys.
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];
foreach (['CHECKOUT_COD_ENABLED' => 'true', 'CHECKOUT_SHIPPING_MINOR' => '100', 'CHECKOUT_TAX_BPS' => '500'] as $name => $value) { $_ENV[$name] = $value; putenv($name . '=' . $value); }

$admin = CommerceAccess::provision('evt-admin@example.test', 'Disposable-Evt-Password');
$buyer = CommerceTest::user('evt-buyer@example.test');
$products = new ProductService($pdo); $inventory = new InventoryService($pdo); $carts = new CartService($pdo);
$orders = new OrderService($pdo); $addresses = new AddressService($pdo); $events = new EventService($pdo); $worker = new QueueWorker($pdo);

$category = $products->saveCategory($admin, ['slug' => 'evt-stone', 'name' => 'Evt Stone'], 'evt:category');
$saved = $products->save($admin, ['slug' => 'evt-marble', 'name' => 'Evt Marble', 'category_id' => $category, 'status' => 'active',
    'variants' => [['sku' => 'EVT-A', 'sell_unit' => 'sqft', 'qty_increment_milli' => 1000, 'unit_price_minor' => 1000]]], 0, 'evt:product');
$variant = $saved['variant_ids'][0];
$inventory->adjust($admin, $variant, 20000, 'Opening stock', 'evt:stock');
$address = $addresses->save($buyer, ['recipient' => 'Evt Buyer', 'phone' => '9876500000', 'line1' => 'Station Road', 'city' => 'Bankura', 'state' => 'West Bengal', 'postal_code' => '722101']);
$cart = $carts->change('user:' . $buyer, $variant, 2000, 0);

// --- Trusted purchase consumer (held queue, now enabled) ---
$order = $orders->place($buyer, $cart['id'], $cart['revision'], $address['id'], 'cod', 'evt:checkout:key');
$orderId = $order['id'];
$check((int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE queue='purchase_events' AND dedupe_key='order:$orderId:purchase:v1'")->fetchColumn() === 1, 'Placement enqueues one purchase_event intent on the held queue.');
$check((int) $pdo->query("SELECT COUNT(*) FROM events WHERE event_type='purchase'")->fetchColumn() === 0, 'No purchase event exists until the consumer runs.');

$stats = $worker->run(['purchase_events'], 10, 30);
$check($stats['completed'] === 1, 'Purchase-event worker completes the intent.');
$event = $pdo->query("SELECT * FROM events WHERE event_type='purchase'")->fetch();
$check($event && (int) $event['order_id'] === $orderId && $event['event_key'] === "order:$orderId:purchase:v1"
    && (int) $event['user_id'] === $buyer && $event['session_key'] === null, 'Purchase event records the authoritative order id and derived user, no session.');
$check((int) $pdo->query("SELECT COUNT(*) FROM event_business_keys WHERE business_key='order:$orderId:purchase:v1' AND kind='purchase'")->fetchColumn() === 1, 'A durable purchase business key is recorded.');
$check((int) $pdo->query("SELECT COUNT(*) FROM event_processing WHERE event_id=" . (int) $event['id'] . " AND state='pending'")->fetchColumn() === 1, 'Processing metadata is pending for aggregation.');
$check(!$pdo->query("SELECT id FROM jobs WHERE dedupe_key='order:$orderId:purchase:v1'")->fetchColumn(), 'The completed intent is acknowledged.');

// Replay is idempotent via the business-key ledger: no second event, even if the raw event were later pruned.
$replay = CommerceDatabase::transaction($pdo, fn() => $events->recordPurchase($pdo, $orderId));
$check($replay['status'] === 'duplicate' && (int) $pdo->query("SELECT COUNT(*) FROM events WHERE event_type='purchase'")->fetchColumn() === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM event_business_keys WHERE business_key='order:$orderId:purchase:v1'")->fetchColumn() === 1, 'Replayed purchase intent is a no-op.');
CommerceTest::rejects(fn() => CommerceDatabase::transaction($pdo, fn() => $events->recordPurchase($pdo, 999999)), 404, 'A purchase intent for a missing order is rejected.');
CommerceTest::fails(fn() => $events->recordPurchase($pdo, $orderId), 'Trusted recording outside a transaction is refused.');
CommerceTest::fails(fn() => CommerceDatabase::transaction($pdo, fn() => CommerceDatabase::query($pdo, "INSERT INTO event_business_keys (business_key,order_id,kind,created_at) VALUES ('order:$orderId:purchase:v1',$orderId,'purchase',1)")), 'A duplicate business key is rejected by the unique index.');

// --- Public ingestion: server-derived principal/session, bounded fields, no forged purchase ---
$session = str_repeat('a', 64);
CommerceTest::rejects(fn() => $events->ingest('purchase', [], $buyer, $session), 422, 'A browser cannot ingest a trusted purchase event.');
CommerceTest::rejects(fn() => $events->ingest('product_view', ['product_id' => $saved['id']], $buyer, 'short-session'), 422, 'An invalid session key is rejected.');
CommerceTest::rejects(fn() => $events->ingest('product_view', ['product_id' => 999999], null, $session), 404, 'A product_view for an unknown product is rejected.');

$events->ingest('product_view', ['product_id' => $saved['id']], $buyer, $session);
$view = $pdo->query("SELECT * FROM events WHERE event_type='product_view'")->fetch();
$check($view && (int) $view['product_id'] === (int) $saved['id'] && (int) $view['category_id'] === (int) $category
    && (int) $view['user_id'] === $buyer && $view['session_key'] === $session && $view['event_key'] === null, 'A product_view resolves product/category from the catalog and derives the session.');

CommerceTest::rejects(fn() => $events->ingest('add_to_cart', ['variant_id' => 999999], null, $session), 404, 'An add_to_cart for an unknown variant is rejected.');
$events->ingest('add_to_cart', ['variant_id' => $variant], null, $session);
$atc = $pdo->query("SELECT * FROM events WHERE event_type='add_to_cart'")->fetch();
$check($atc && (int) $atc['variant_id'] === (int) $variant && (int) $atc['product_id'] === (int) $saved['id'] && $atc['user_id'] === null, 'An add_to_cart resolves its product and stays anonymous when logged out.');

$events->ingest('search', ['query' => '  Marble   TILE  '], null, $session);
$search = json_decode($pdo->query("SELECT payload FROM events WHERE event_type='search'")->fetchColumn(), true);
$check($search['query'] === 'marble tile', 'A search query is normalized to a bounded lowercase phrase.');

// Rate limit: a session at the window cap is throttled.
$now = CommerceDatabase::clock($pdo); $filler = str_repeat('b', 64);
$stmt = $pdo->prepare("INSERT INTO events (event_type,session_key,occurred_at,created_at) VALUES ('search',?,?,?)");
for ($i = 0; $i < 120; $i++) $stmt->execute([$filler, $now, $now]);
CommerceTest::rejects(fn() => $events->ingest('search', ['query' => 'too many'], null, $filler), 429, 'A session over the per-window cap is rate limited.');

// --- Cancellation correction event ---
$cancelled = $orders->cancel($admin, $orderId, (int) $order['revision'], 'evt:cancel:key');
$check($cancelled['status'] === 'cancelled'
    && (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE queue='purchase_events' AND dedupe_key='order:$orderId:cancelled_event:v1'")->fetchColumn() === 1, 'Cancelling the order enqueues one cancellation-event intent.');
$stats = $worker->run(['purchase_events'], 10, 30);
$check($stats['completed'] === 1, 'The cancellation-event worker completes.');
$check((int) $pdo->query("SELECT COUNT(*) FROM events WHERE event_type='order_cancelled' AND order_id=$orderId")->fetchColumn() === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM event_business_keys WHERE business_key='order:$orderId:cancelled:v1' AND kind='cancelled'")->fetchColumn() === 1, 'A trusted cancellation event and business key are recorded.');
$check((int) $pdo->query("SELECT COUNT(*) FROM events WHERE event_type='purchase' AND order_id=$orderId")->fetchColumn() === 1, 'The original purchase event is preserved for net-sales correction.');

CommerceTest::finish('Event ingestion and trusted purchase/cancellation (EVT-01)');
