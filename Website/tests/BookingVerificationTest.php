<?php
// ADV-BOOK-01: minimum-order advance waiver, capture verification gate, confirm-by-call outcomes, and the two booking timers.
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];
foreach (['CHECKOUT_RAZORPAY_ENABLED' => 'true', 'RAZORPAY_KEY_ID' => 'rzp_test_disposable', 'RAZORPAY_KEY_SECRET' => 'disposable-key-secret',
    'RAZORPAY_WEBHOOK_SECRET' => 'disposable-webhook-secret', 'CHECKOUT_SHIPPING_MINOR' => '0', 'CHECKOUT_TAX_BPS' => '0',
    'CHECKOUT_ADVANCE_BPS' => '2000', 'CHECKOUT_ADVANCE_MIN_MINOR' => '500000',
    'CHECKOUT_VERIFY_AUTOCONFIRM_HOURS' => '48', 'CHECKOUT_PAYMENT_WINDOW_HOURS' => '48'] as $k => $v) $_ENV[$k] = $v;

$admin = CommerceAccess::provision('book-admin@example.test', 'Disposable-Book-Password');
$viewer = CommerceTest::user('book-viewer@example.test'); // a plain customer: no admin.orders.edit
$buyer = CommerceTest::user('book-buyer@example.test');
$products = new ProductService($pdo);
$category = $products->saveCategory($admin, ['slug' => 'book-stone', 'name' => 'Booking stone'], 'book:category');
$product = $products->save($admin, ['slug' => 'book-marble', 'name' => 'Booking marble', 'category_id' => $category, 'status' => 'active',
    'variants' => [['sku' => 'BOOK-SQFT', 'sell_unit' => 'sqft', 'qty_increment_milli' => 1000, 'unit_price_minor' => 100000]]], 0, 'book:product');
$variant = $product['variant_ids'][0];
(new InventoryService($pdo))->adjust($admin, $variant, 1000000, 'Booking stock', 'book:stock');
$address = (new AddressService($pdo))->save($buyer, ['recipient' => 'Book Buyer', 'phone' => '9998887777', 'line1' => 'Road', 'city' => 'Bankura', 'state' => 'West Bengal', 'postal_code' => '722101']);

$fake = new class {
    public array $remote = [];
    public function createOrder(int $amountMinor, string $receipt, array $notes): array { return ['id' => 'order_Book' . $amountMinor . bin2hex(random_bytes(2)), 'amount' => $amountMinor, 'currency' => 'INR']; }
    public function fetchPayment(string $id): array { return $this->remote; }
    public function refundPayment(string $paymentId, int $amountMinor, string $idempotency): array { return ['id' => 'rfnd_' . bin2hex(random_bytes(6)), 'amount' => $amountMinor, 'status' => 'processed']; }
};
PaymentService::$clientFactory = static fn() => $fake;
$service = new PaymentService($pdo);
$orders = new OrderService($pdo);
$seq = 0;
$place = static function (int $qtyMilli) use ($pdo, $buyer, $variant, $address, &$seq): array {
    $cart = (new CartService($pdo))->change('user:' . $buyer, $variant, $qtyMilli, 0);
    return (new OrderService($pdo))->place($buyer, $cart['id'], $cart['revision'], $address['id'], 'razorpay', 'book:place:' . ++$seq);
};
$capture = static function (array $order) use ($pdo, $service, $buyer, $fake): void {
    $data = $service->beginOnline($buyer, $order['id']);
    $entity = ['id' => 'pay_Book' . $order['id'], 'order_id' => $data['provider_order_id'], 'amount' => (int)$data['amount_minor'], 'currency' => 'INR', 'status' => 'captured'];
    $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] = 'evt_book_' . $order['id'];
    $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => $entity]]], JSON_THROW_ON_ERROR);
    $service->handleWebhook($body, hash_hmac('sha256', $body, 'disposable-webhook-secret'));
};
$orderRow = static fn(int $id) => $pdo->query('SELECT * FROM orders WHERE id=' . $id)->fetch();

// --- Minimum order waives the advance ---
$small = $place(1000); // total 100000 < 500000 minimum
$check((int)$small['amount_due_minor'] === (int)$small['total_minor'] && (int)$small['total_minor'] === 100000, 'Below the minimum order the full amount is due upfront.');
$big = $place(6000); // total 600000 >= 500000 -> 20% advance = 120000
$check((int)$big['amount_due_minor'] === 120000 && (int)$big['total_minor'] === 600000, 'At or above the minimum the 20% advance is due.');

// --- Capture enters the verification gate (not confirmed) ---
$capture($big);
$row = $orderRow($big['id']);
$check($row['status'] === 'awaiting_verify' && $row['payment_status'] === 'advance_paid' && $row['verify_deadline_at'] !== null, 'Advance capture enters the verification gate with a deadline.');
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='verify_window' AND dedupe_key='order:" . $big['id'] . ":verify_window:v1'")->fetchColumn() === 1, 'Capture schedules exactly one verify-window timer.');
$rev = (int)$row['revision'];

// --- Guards ---
CommerceTest::rejects(fn() => $orders->confirmByCall($viewer, (int)$big['id'], 'reached', null, $rev, 'book:verify:perm'), 403, 'A customer cannot verify an order.');
CommerceTest::rejects(fn() => $orders->confirmByCall($admin, (int)$big['id'], 'maybe', null, $rev, 'book:verify:badout'), 422, 'An unknown outcome is rejected.');
CommerceTest::rejects(fn() => $orders->confirmByCall($admin, (int)$big['id'], 'reached', null, $rev + 5, 'book:verify:rev'), 409, 'A stale revision is rejected.');

// --- Unreachable logs an attempt and keeps the order pending ---
$orders->confirmByCall($admin, (int)$big['id'], 'unreachable', 'No answer', $rev, 'book:verify:attempt1');
$row = $orderRow($big['id']);
$check($row['status'] === 'awaiting_verify' && (int)$row['verify_attempts'] === 1 && $row['verify_outcome'] === 'unreachable', 'An unreachable call logs an attempt and leaves the order pending.');

// --- Reached confirms the order and emails the customer ---
$out = $orders->confirmByCall($admin, (int)$big['id'], 'reached', 'Spoke to buyer', (int)$row['revision'], 'book:verify:reached');
$row = $orderRow($big['id']);
$check($out['status'] === 'confirmed' && $row['status'] === 'confirmed' && (int)$row['verified_by'] === $admin && $row['verify_outcome'] === 'reached' && $row['verify_deadline_at'] === null, 'A reached call confirms the order and clears the deadline.');
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='order_status' AND dedupe_key='order:" . $big['id'] . ":status:confirmed:v1'")->fetchColumn() === 1, 'Confirmation queues the customer status email.');
CommerceTest::rejects(fn() => $orders->confirmByCall($admin, (int)$big['id'], 'reached', null, (int)$row['revision'], 'book:verify:again'), 409, 'A confirmed order is no longer awaiting verification.');

// --- Reject cancels, restocks and refunds the captured advance ---
$stockBefore = (int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$variant")->fetchColumn();
$reject = $place(6000); $capture($reject);
$rr = $orderRow($reject['id']);
$orders->confirmByCall($admin, (int)$reject['id'], 'rejected', 'Customer declined', (int)$rr['revision'], 'book:verify:reject');
$rr = $orderRow($reject['id']);
$check($rr['status'] === 'cancelled' && $rr['verify_outcome'] === 'rejected', 'A rejected call cancels the order.');
$check((int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$variant")->fetchColumn() === $stockBefore, 'Rejection restores the consumed stock.');
$check((int)$pdo->query("SELECT COUNT(*) FROM payment_refunds WHERE order_id=" . $reject['id'] . " AND status='pending'")->fetchColumn() === 1, 'Rejecting a paid order enqueues one refund.');

// --- verify_window timer auto-confirms a paid, unverified order ---
$auto = $place(6000); $capture($auto);
$pdo->exec('UPDATE orders SET verify_deadline_at=1 WHERE id=' . $auto['id']);
$pdo->exec("UPDATE jobs SET available_at=0 WHERE dedupe_key='order:" . $auto['id'] . ":verify_window:v1'");
(new QueueWorker($pdo))->run(['maintenance'], 20, 30);
$row = $orderRow($auto['id']);
$check($row['status'] === 'confirmed' && $row['verify_outcome'] === 'auto', 'The verify-window timer auto-confirms a paid, unverified order.');

// --- payment_window timer auto-cancels an unpaid order and restocks ---
$stockBefore = (int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$variant")->fetchColumn();
$unpaid = $place(6000); // placed, awaiting_payment, stock consumed
$check((int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$variant")->fetchColumn() === $stockBefore - 6000, 'Placement consumes stock before payment.');
$pdo->exec('UPDATE orders SET payment_deadline_at=1 WHERE id=' . $unpaid['id']);
$pdo->exec("UPDATE jobs SET available_at=0 WHERE dedupe_key='order:" . $unpaid['id'] . ":payment_window:v1'");
(new QueueWorker($pdo))->run(['maintenance'], 20, 30);
$row = $orderRow($unpaid['id']);
$check($row['status'] === 'cancelled' && $row['payment_status'] === 'awaiting_payment', 'The payment-window timer auto-cancels an unpaid order.');
$check((int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$variant")->fetchColumn() === $stockBefore, 'Auto-cancelling an unpaid order restores its stock.');

echo 'BookingVerificationTest (' . CommerceTest::$version . '): ' . CommerceTest::$checks . " checks passed.\n";
