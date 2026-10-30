<?php
// ADV-BOOK-01: refund of a captured advance when a paid order is cancelled — durable, idempotent, retry-safe.
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];
foreach (['CHECKOUT_RAZORPAY_ENABLED' => 'true', 'RAZORPAY_KEY_ID' => 'rzp_test_disposable', 'RAZORPAY_KEY_SECRET' => 'disposable-key-secret',
    'RAZORPAY_WEBHOOK_SECRET' => 'disposable-webhook-secret', 'CHECKOUT_SHIPPING_MINOR' => '0', 'CHECKOUT_TAX_BPS' => '0',
    'CHECKOUT_ADVANCE_BPS' => '2000', 'CHECKOUT_ADVANCE_MIN_MINOR' => '0', 'CHECKOUT_PAYMENT_WINDOW_HOURS' => '0', 'CHECKOUT_VERIFY_AUTOCONFIRM_HOURS' => '0'] as $k => $v) $_ENV[$k] = $v;

$admin = CommerceAccess::provision('refund-admin@example.test', 'Disposable-Refund-Password');
$buyer = CommerceTest::user('refund-buyer@example.test');
$products = new ProductService($pdo);
$category = $products->saveCategory($admin, ['slug' => 'refund-stone', 'name' => 'Refund stone'], 'refund:category');
$product = $products->save($admin, ['slug' => 'refund-marble', 'name' => 'Refund marble', 'category_id' => $category, 'status' => 'active',
    'variants' => [['sku' => 'REFUND-SQFT', 'sell_unit' => 'sqft', 'qty_increment_milli' => 1000, 'unit_price_minor' => 100000]]], 0, 'refund:product');
$variant = $product['variant_ids'][0];
(new InventoryService($pdo))->adjust($admin, $variant, 1000000, 'Refund stock', 'refund:stock');
$address = (new AddressService($pdo))->save($buyer, ['recipient' => 'Refund Buyer', 'phone' => '9990001111', 'line1' => 'Road', 'city' => 'Bankura', 'state' => 'West Bengal', 'postal_code' => '722101']);

$fake = new class {
    public bool $failRefund = false;
    public int $refunds = 0;
    public array $seen = [];
    public function createOrder(int $amountMinor, string $receipt, array $notes): array { return ['id' => 'order_Ref' . bin2hex(random_bytes(3)), 'amount' => $amountMinor, 'currency' => 'INR']; }
    public function fetchPayment(string $id): array { return []; }
    public function refundPayment(string $paymentId, int $amountMinor, string $idempotency): array {
        if ($this->failRefund) throw new RuntimeException('Gateway down');
        $this->refunds++; $this->seen[] = $idempotency;
        return ['id' => 'rfnd_' . bin2hex(random_bytes(6)), 'amount' => $amountMinor, 'status' => 'processed'];
    }
};
PaymentService::$clientFactory = static fn() => $fake;
$service = new PaymentService($pdo); $orders = new OrderService($pdo); $worker = new QueueWorker($pdo);

$cart = (new CartService($pdo))->change('user:' . $buyer, $variant, 6000, 0);
$order = $orders->place($buyer, $cart['id'], $cart['revision'], $address['id'], 'razorpay', 'refund:place');
$data = $service->beginOnline($buyer, $order['id']);
$entity = ['id' => 'pay_Refund', 'order_id' => $data['provider_order_id'], 'amount' => (int)$data['amount_minor'], 'currency' => 'INR', 'status' => 'captured'];
$_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] = 'evt_refund';
$body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => $entity]]], JSON_THROW_ON_ERROR);
$service->handleWebhook($body, hash_hmac('sha256', $body, 'disposable-webhook-secret'));
$row = $pdo->query('SELECT * FROM orders WHERE id=' . $order['id'])->fetch();
$check($row['payment_status'] === 'advance_paid' && (int)$row['amount_due_minor'] === 120000, 'Advance captured: 20% of 600000 paid.');

// Cancel the paid order via the staff transition -> one refund enqueued, deduped on a stable key.
$orders->transition($admin, (int)$order['id'], 'cancelled', (int)$row['revision'], 'refund:cancel', 'Order cancelled');
$refund = $pdo->query('SELECT * FROM payment_refunds WHERE order_id=' . $order['id'])->fetch();
$check($refund && $refund['status'] === 'pending' && (int)$refund['amount_minor'] === 120000 && $refund['idempotency_key'] === 'order:' . $order['id'] . ':refund', 'Cancelling a paid order enqueues one pending refund for the captured amount.');

// First the gateway is down: the job fails safely, leaving the refund recoverable (never silently refunded).
$fake->failRefund = true;
$worker->run(['maintenance'], 20, 30);
$refund = $pdo->query('SELECT * FROM payment_refunds WHERE order_id=' . $order['id'])->fetch();
$check($refund['status'] === 'pending' && $fake->refunds === 0 && $refund['last_error_code'] === 'refund_unavailable', 'A failed gateway call leaves the refund pending for retry, with no money moved.');

// Gateway recovers: the retried job refunds exactly once and settles payment and order.
$fake->failRefund = false;
$pdo->exec('UPDATE jobs SET available_at=0 WHERE type=\'payment_refund\'');
$worker->run(['maintenance'], 20, 30);
$refund = $pdo->query('SELECT * FROM payment_refunds WHERE order_id=' . $order['id'])->fetch();
$check($refund['status'] === 'refunded' && $fake->refunds === 1 && str_starts_with((string)$refund['provider_refund_id'], 'rfnd_'), 'The retried job refunds exactly once.');
$check($pdo->query('SELECT payment_status FROM orders WHERE id=' . $order['id'])->fetchColumn() === 'refunded'
    && $pdo->query('SELECT status FROM payments WHERE order_id=' . $order['id'])->fetchColumn() === 'refunded', 'The refund settles both the order and payment.');
$check($fake->seen === ['order:' . $order['id'] . ':refund'], 'The stable idempotency key is replayed to the gateway.');

// Re-running the (already acknowledged) work queue performs no further refund.
$pdo->exec('UPDATE jobs SET available_at=0');
$worker->run(['maintenance'], 20, 30);
$check($fake->refunds === 1, 'A settled refund is never issued twice.');

echo 'RefundTest (' . CommerceTest::$version . '): ' . CommerceTest::$checks . " checks passed.\n";
