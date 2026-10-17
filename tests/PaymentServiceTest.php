<?php
// php tests/PaymentServiceTest.php [--mysql]; full migrations, disposable resources only.
require __DIR__ . '/support/CommerceTest.php';
$pdo = CommerceTest::open(); $check = [CommerceTest::class, 'check'];
foreach (['CHECKOUT_RAZORPAY_ENABLED'=>'true','RAZORPAY_KEY_ID'=>'rzp_test_disposable',
    'RAZORPAY_KEY_SECRET'=>'disposable-key-secret','RAZORPAY_WEBHOOK_SECRET'=>'disposable-webhook-secret',
    'CHECKOUT_SHIPPING_MINOR'=>'0','CHECKOUT_TAX_BPS'=>'0'] as $key=>$value) $_ENV[$key] = $value;
$check((new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations', static function ($line) {}))->run() === 0,
    'All migrations, including payment definitions, verify on rerun.');
$admin = CommerceAccess::provision('pay-admin@example.test', 'Disposable-Payment-Password');
$buyer = CommerceTest::user('pay-buyer@example.test'); $other = CommerceTest::user('pay-other@example.test');
$products = new ProductService($pdo);
$category = $products->saveCategory($admin, ['slug'=>'pay-stone','name'=>'Payment stone'], 'category:payment');
$product = $products->save($admin, ['slug'=>'pay-marble','name'=>'Payment marble','category_id'=>$category,'status'=>'active',
    'variants'=>[['sku'=>'PAY-BOX','sell_unit'=>'box','qty_increment_milli'=>1000,'coverage_sqft_milli'=>16000,'unit_price_minor'=>12551]]], 0, 'product:payment');
$variant = $product['variant_ids'][0];
(new InventoryService($pdo))->adjust($admin, $variant, 100000, 'Payment test stock', 'stock:payment');
$address = (new AddressService($pdo))->save($buyer, ['recipient'=>'Test Buyer','phone'=>'9999988888','line1'=>'Test Road',
    'city'=>'Bankura','state'=>'West Bengal','postal_code'=>'722101']);
$service = new PaymentService($pdo); $sequence = 0;
$newOrder = function () use ($pdo,$buyer,$variant,$address,&$sequence): array {
    $cart = (new CartService($pdo))->change('user:' . $buyer, $variant, 1000, 0);
    return (new OrderService($pdo))->place($buyer,$cart['id'],$cart['revision'],$address['id'],'razorpay','payment:test:' . ++$sequence);
};
$fake = new class($pdo) {
    public int $creates = 0;
    public int $fetches = 0;
    public ?Closure $onCreate = null;
    public array $remote = [];
    public function __construct(private PDO $pdo) {}
    public function createOrder(int $amountMinor, string $receipt, array $notes): array {
        CommerceTest::check(!$this->pdo->inTransaction(), 'Gateway creation runs after commit, outside locks.');
        CommerceTest::check($amountMinor === 12551 && str_starts_with($receipt,'SMB-') && isset($notes['order_id']), 'Gateway receives exact integer paise, receipt and order note.');
        $this->creates++;
        if ($this->onCreate) ($this->onCreate)();
        return ['id'=>'order_Test' . $this->creates,'amount'=>$amountMinor,'currency'=>'INR'];
    }
    public function fetchPayment(string $id): array {
        CommerceTest::check(!$this->pdo->inTransaction(), 'Server payment fetch runs outside locks.');
        $this->fetches++;
        return $this->remote;
    }
};
PaymentService::$clientFactory = static fn() => $fake;
$remote = static fn(array $data, string $id, string $status = 'captured'): array =>
    ['id'=>$id,'order_id'=>$data['provider_order_id'],'amount'=>$data['amount_minor'],'currency'=>'INR','status'=>$status];
$signature = static fn(array $data, string $id): string => hash_hmac('sha256', $data['provider_order_id'] . '|' . $id, 'disposable-key-secret');
$snapshot = static function () use ($pdo): array {
    $state = [];
    foreach (['orders','payments','payment_events','jobs','inventory'] as $table) $state[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . ($table === 'inventory' ? 'variant_id' : 'id'))->fetchAll();
    return $state;
};
$webhook = static function (array $entity, string $id, string $event = 'payment.captured') use ($service): void {
    $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] = $id;
    $body = json_encode(['event'=>$event,'payload'=>['payment'=>['entity'=>$entity]]], JSON_THROW_ON_ERROR);
    $service->handleWebhook($body, hash_hmac('sha256', $body, 'disposable-webhook-secret'));
};

$first = $newOrder();
$check($first['payment_status'] === 'awaiting_payment', 'Online placement awaits payment.');
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='order_confirmation'")->fetchColumn() === 0, 'Online placement defers confirmation.');
$check((int)$pdo->query('SELECT on_hand_milli FROM inventory WHERE variant_id=' . $variant)->fetchColumn() === 99000, 'Online placement consumes stock before payment.');
$fake->onCreate = fn() => CommerceTest::rejects(fn() => $service->beginOnline($buyer,$first['id']), 503, 'An overlapping creation cannot create a second gateway order.');
$data = $service->beginOnline($buyer, $first['id']); $fake->onCreate = null;
$check($service->beginOnline($buyer,$first['id']) === $data && $fake->creates === 1, 'Gateway creation replay returns the stored gateway order.');
CommerceTest::rejects(fn() => $service->beginOnline($other,$first['id']), 404, 'Gateway creation enforces ownership.');
CommerceTest::rejects(fn() => $service->checkoutData($other,$first['id']), 404, 'Pay screen enforces ownership.');
$fake->remote = $remote($data, 'pay_Test1');
$before = $snapshot(); $fetches = $fake->fetches;
CommerceTest::rejects(fn() => $service->confirmCallback($buyer,$data['provider_order_id'],'pay_Test1',str_repeat('0',64)), 400, 'Tampered callback signature is rejected.');
$check($before === $snapshot() && $fake->fetches === $fetches, 'Bad signature changes nothing and performs no external fetch.');
CommerceTest::rejects(fn() => $service->confirmCallback($other,$data['provider_order_id'],'pay_Test1',$signature($data,'pay_Test1')), 404, 'Valid signature cannot bypass ownership.');
$fake->remote['amount']++;
CommerceTest::rejects(fn() => $service->confirmCallback($buyer,$data['provider_order_id'],'pay_Test1',$signature($data,'pay_Test1')), 422, 'Mismatched gateway amount is rejected.');
$check($snapshot() === $before, 'Amount mismatch preserves the awaiting order and ledger.');
$fake->remote = $remote($data,'pay_Test1'); $fake->remote['currency'] = 'USD';
CommerceTest::rejects(fn() => $service->confirmCallback($buyer,$data['provider_order_id'],'pay_Test1',$signature($data,'pay_Test1')), 422, 'Currency mismatch is rejected.');
$fake->remote = $remote($data,'pay_Test1'); $fake->remote['order_id'] = 'order_Other';
CommerceTest::rejects(fn() => $service->confirmCallback($buyer,$data['provider_order_id'],'pay_Test1',$signature($data,'pay_Test1')), 422, 'Gateway order mismatch is rejected.');
$fake->remote = $remote($data,'pay_Test1','authorized');
CommerceTest::rejects(fn() => $service->confirmCallback($buyer,$data['provider_order_id'],'pay_Test1',$signature($data,'pay_Test1')), 409, 'Authorization alone cannot mark an order paid.');
$fake->remote = $remote($data,'pay_Test1');
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$pdo->exec($mysql ? "CREATE TRIGGER payment_queue_failure BEFORE INSERT ON jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected'"
    : "CREATE TRIGGER payment_queue_failure BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT,'Injected'); END");
CommerceTest::fails(fn() => $service->confirmCallback($buyer,$data['provider_order_id'],'pay_Test1',$signature($data,'pay_Test1')), 'Confirmation enqueue failure rejects settlement.');
$pdo->exec('DROP TRIGGER payment_queue_failure');
$check($snapshot() === $before, 'Queue failure rolls back payment, order and event ledger together.');
$result = $service->confirmCallback($buyer,$data['provider_order_id'],'pay_Test1',$signature($data,'pay_Test1'));
$check($result['id'] === $first['id'] && $pdo->query('SELECT payment_status FROM orders WHERE id=' . $first['id'])->fetchColumn() === 'paid', 'Valid callback marks the owned order paid.');
$check($pdo->query('SELECT status FROM payments WHERE order_id=' . $first['id'])->fetchColumn() === 'captured', 'Valid callback captures the payment.');
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='order_confirmation'")->fetchColumn() === 1, 'Capture enqueues one confirmation.');
$before = $snapshot();
$service->confirmCallback($buyer,$data['provider_order_id'],'pay_Test1',$signature($data,'pay_Test1'));
$check($snapshot() === $before, 'Callback replay makes no second paid write or job.');

$second = $newOrder(); $data2 = $service->beginOnline($buyer,$second['id']);
$entity = $remote($data2,'pay_Test2');
$body = "{ \"event\":\"payment.captured\",\"payload\":{\"payment\":{\"entity\":" . json_encode($entity) . '}}}';
$_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] = 'evt_raw';
$before = $snapshot();
CommerceTest::rejects(fn() => $service->handleWebhook($body,hash_hmac('sha256',json_encode(json_decode($body,true)),'disposable-webhook-secret')), 400, 'HMAC over re-encoded JSON is rejected.');
$check($snapshot() === $before, 'Tampered raw signature preserves all state.');
$bad = $entity; $bad['amount']++;
CommerceTest::rejects(fn() => $webhook($bad,'evt_bad_amount'), 422, 'Webhook amount mismatch is rejected.');
$service->handleWebhook($body,hash_hmac('sha256',$body,'disposable-webhook-secret'));
$check($pdo->query('SELECT payment_status FROM orders WHERE id=' . $second['id'])->fetchColumn() === 'paid', 'Exact raw body HMAC settles the webhook.');
// A trigger fails ANY further paid update, proving replay skips the write even within the same second.
$pdo->exec($mysql ? "CREATE TRIGGER payment_paid_guard BEFORE UPDATE ON orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Double paid write'"
    : "CREATE TRIGGER payment_paid_guard BEFORE UPDATE ON orders BEGIN SELECT RAISE(ABORT,'Double paid write'); END");
$before = $snapshot();
$service->handleWebhook($body,hash_hmac('sha256',$body,'disposable-webhook-secret'));
$check($snapshot() === $before, 'Replayed webhook is a no-op with no second paid write or confirmation.');
$webhook($entity,'evt_order_paid','order.paid');
$webhook($remote($data2,'pay_FailedAttempt','failed'),'evt_late_failure','payment.failed');
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='order_confirmation'")->fetchColumn() === 2, 'Distinct capture event and late failure cannot duplicate confirmation or downgrade paid.');
$pdo->exec('DROP TRIGGER payment_paid_guard');
$fake->remote = $entity;
$service->confirmCallback($buyer,$data2['provider_order_id'],'pay_Test2',$signature($data2,'pay_Test2'));
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='order_confirmation'")->fetchColumn() === 2, 'Webhook followed by callback still has one confirmation per order.');

$third = $newOrder(); $data3 = $service->beginOnline($buyer,$third['id']);
$fake->remote = $remote($data3,'pay_Test3','failed');
CommerceTest::rejects(fn()=> $service->confirmCallback($buyer,$data3['provider_order_id'],'pay_Test3',$signature($data3,'pay_Test3')),422,'Failed payment returns a safe declined response.');
$check($pdo->query('SELECT status FROM payments WHERE order_id=' . $third['id'])->fetchColumn() === 'failed'
    && $pdo->query('SELECT payment_status FROM orders WHERE id=' . $third['id'])->fetchColumn() === 'awaiting_payment', 'Failed fetch records failure while keeping order awaiting payment.');
$check($pdo->query('SELECT last_error_code FROM payments WHERE order_id=' . $third['id'])->fetchColumn()==='payment_declined','Failed payment retains a sanitized diagnostic classifier.');
$check($service->beginOnline($buyer,$third['id']) === $data3 && $fake->creates === 3, 'Failed attempt reuses the original gateway order.');
$webhook($remote($data3,'pay_Retry3'),'evt_retry_capture');
$check($pdo->query('SELECT payment_status FROM orders WHERE id=' . $third['id'])->fetchColumn() === 'paid', 'A later captured attempt settles the existing gateway order.');
$check($pdo->query('SELECT last_error_code FROM payments WHERE order_id=' . $third['id'])->fetchColumn()===null,'Captured retry clears the prior failure classifier.');
$check((int)$pdo->query('SELECT on_hand_milli FROM inventory WHERE variant_id=' . $variant)->fetchColumn() === 97000, 'Settlement never consumes stock twice.');

// Exercise the real controller/router: raw request bytes, no browser session or CSRF.
$fourth = $newOrder(); $data4 = $service->beginOnline($buyer,$fourth['id']);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Unable to reserve a test port.');
$port = (int)substr(strrchr(stream_socket_get_name($socket,false), ':'), 1); fclose($socket);
$log = fopen(CommerceTest::$temp . '/payment-http.log', 'w');
$environment = array_merge(getenv(), CommerceTest::$environment, [
    'CHECKOUT_RAZORPAY_ENABLED'=>'true','RAZORPAY_WEBHOOK_SECRET'=>'disposable-webhook-secret','APP_DEBUG'=>'false']);
$server = proc_open([PHP_BINARY,'-d','variables_order=EGPCS','-S','127.0.0.1:' . $port,'vayu'], [0=>['pipe','r'],1=>$log,2=>$log], $pipes, dirname(__DIR__), $environment);
if (!is_resource($server)) throw new RuntimeException('Unable to start disposable HTTP server.');
fclose($pipes[0]);
try {
    $deadline = microtime(true) + 5;
    do {
        $ready = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if ($ready) { fclose($ready); break; }
        if (microtime(true) >= $deadline) throw new RuntimeException('HTTP server did not start.');
        usleep(10000);
    } while (true);
    $request = static function (string $path, string $body, array $headers = [], string $method = 'POST') use ($port): array {
        $context = stream_context_create(['http'=>['method'=>$method,'content'=>$body,'header'=>implode("\r\n",$headers),
            'ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
        $result = file_get_contents('http://127.0.0.1:' . $port . '/' . $path, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
        return [(int)$match[1],$result];
    };
    $httpBody = "{\n\"event\":\"payment.captured\",\"payload\":{\"payment\":{\"entity\":" . json_encode($remote($data4,'pay_Http4')) . '}}}';
    $headers = ['Content-Type: application/json','X-Razorpay-Event-Id: evt_http4',
        'X-Razorpay-Signature: ' . hash_hmac('sha256',$httpBody,'disposable-webhook-secret')];
    $response = $request('api/webhooks/razorpay',$httpBody,$headers);
    $check($response[0] === 200 && json_decode($response[1],true) === ['status'=>'ok'], 'Raw webhook HTTP request succeeds without CSRF or login.');
    $before = $snapshot();
    $response = $request('api/webhooks/razorpay',$httpBody,$headers);
    $check($response[0] === 200 && $snapshot() === $before, 'Replayed webhook HTTP request returns 200 with no DB effects.');
    $response = $request('api/webhooks/razorpay',$httpBody,['Content-Type: application/json','X-Razorpay-Signature: tampered']);
    $check($response[0] === 400 && (json_decode($response[1],true)['error']['code'] ?? '') === 'payment_signature_invalid', 'Bad webhook signature returns sanitized HTTP 400.');
    $response = $request('payment/verify','razorpay_order_id=' . $data4['provider_order_id'],['Content-Type: application/x-www-form-urlencoded']);
    $check($response[0] === 403, 'Browser verification requires session CSRF.');
    $response = $request('api/webhooks/razorpay','',[], 'GET');
    $check($response[0] === 405, 'Webhook rejects GET at the route boundary.');
} finally {
    proc_terminate($server); proc_close($server); fclose($log);
}

// Placement's saved response stays awaiting_payment even after the payment has captured.
// Exercise the actual controller with a reviewed contract-2 checkout and an authenticated session.
$cart = (new CartService($pdo))->change('user:' . $buyer,$variant,1000,0);
$quote = OrderService::quote($cart); $reviewKey = 'payment:reviewed:retry';
$selection = ['cart_id'=>$cart['id'],'cart_revision'=>$cart['revision'],'address_id'=>$address['id'],'payment_mode'=>'razorpay'];
$reviewed = (new OrderService($pdo))->place($buyer,$cart['id'],$cart['revision'],$address['id'],'razorpay',$reviewKey,$quote);
$reviewData = $service->beginOnline($buyer,$reviewed['id']);
RequestSecurity::session();
Auth::establishSession($buyer);
$_SESSION['checkout_keys'] = [['key'=>$reviewKey,'actor'=>$buyer,'selection'=>$selection,'quote'=>$quote]];
$_POST = $selection + ['checkout_key'=>$reviewKey,'csrf'=>RequestSecurity::csrfToken()];
$_SERVER['REQUEST_METHOD'] = 'POST';
$controller = new class extends Cart {
    public string $redirectPath = '';
    protected function handle(callable $action, bool $api = false): void { $action(); }
    protected function redirect(string $path): void { $this->redirectPath = $path; }
};
$controller->placeOrder();
$check($controller->redirectPath === 'checkout?order=' . $reviewed['id'], 'Unpaid checkout replay reopens the stored gateway order.');
$webhook($remote($reviewData,'pay_Reviewed'),'evt_reviewed');
$before = $snapshot(); $creates = $fake->creates; $fetches = $fake->fetches;
$controller->placeOrder();
$check($controller->redirectPath === 'order/' . $reviewed['id'], 'Paid checkout replay redirects to its order summary.');
$check($snapshot() === $before && $fake->creates === $creates && $fake->fetches === $fetches, 'Paid checkout replay has no new payment, stock, queue or gateway effects.');
$_GET = ['order'=>(string)$reviewed['id']]; $controller->redirectPath = '';
$controller->checkout();
$check($controller->redirectPath === 'order/' . $reviewed['id'] && $snapshot() === $before,
    'A pay-page refresh after webhook capture redirects to the paid summary without mutations.');
$_GET = [];
session_write_close();

// Independent processes exercise real overlapping transactions. SQLite evidence is not MySQL evidence.
$fixture = CommerceTest::$temp . '/payment-race.php';
file_put_contents($fixture, '<?php require ' . var_export(dirname(__DIR__) . '/config/bootstarp.php',true) . ';' . <<<'CHILD'
$_ENV['CHECKOUT_RAZORPAY_ENABLED']='true';
$_ENV['RAZORPAY_KEY_ID']='rzp_test_disposable';
$_ENV['RAZORPAY_KEY_SECRET']='disposable-key-secret';
$_ENV['RAZORPAY_WEBHOOK_SECRET']='disposable-webhook-secret';
$in=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
PaymentService::$clientFactory=static fn()=>new class($pdo,$in) {
    public function __construct(private PDO $pdo,private array $input) {}
    public function createOrder(int $amount,string $receipt,array $notes):array {
        if($this->pdo->inTransaction())throw new RuntimeException('Gateway inside transaction.');
        file_put_contents($this->input['log'],"create\n",FILE_APPEND|LOCK_EX);
        usleep(150000);
        return ['id'=>'order_Race'.$notes['order_id'],'amount'=>$amount,'currency'=>'INR'];
    }
    public function fetchPayment(string $id):array {
        if($this->pdo->inTransaction())throw new RuntimeException('Gateway inside transaction.');
        return $this->input['remote'];
    }
};
file_put_contents(getenv('TEST_READY'),'ready');$deadline=microtime(true)+8;
while(!is_file(getenv('TEST_BARRIER'))){if(microtime(true)>$deadline)throw new RuntimeException('Barrier timeout.');usleep(10000);}
try {
    $service=new PaymentService($pdo);
    if($in['mode']==='begin')$service->beginOnline($in['buyer'],$in['order']);
    elseif($in['mode']==='callback')$service->confirmCallback($in['buyer'],$in['remote']['order_id'],$in['remote']['id'],hash_hmac('sha256',$in['remote']['order_id'].'|'.$in['remote']['id'],'disposable-key-secret'));
    else {
        $_SERVER['HTTP_X_RAZORPAY_EVENT_ID']=$in['event'];
        $body=json_encode(['event'=>'payment.captured','payload'=>['payment'=>['entity'=>$in['remote']]]],JSON_THROW_ON_ERROR);
        $service->handleWebhook($body,hash_hmac('sha256',$body,'disposable-webhook-secret'));
    }
    echo json_encode(['status'=>200]);
} catch(RequestRejected $error) {echo json_encode(['status'=>$error->status,'code'=>$error->errorCode]);}
CHILD);
$raceOrder = $newOrder(); $creationLog = CommerceTest::$temp . '/gateway-creation.log';
$input = ['mode'=>'begin','buyer'=>$buyer,'order'=>$raceOrder['id'],'log'=>$creationLog];
$results = CommerceTest::race([$input,$input],$fixture);
$statuses = array_column($results,'status'); sort($statuses);
$check($statuses === [200,503] || $statuses === [200,200], 'Concurrent gateway creation returns one result and either the same result or a pending response.');
$check(file_get_contents($creationLog) === "create\n" && (int)$pdo->query('SELECT COUNT(*) FROM payments WHERE order_id=' . $raceOrder['id'])->fetchColumn() === 1,
    'Independent creation requests perform exactly one gateway call and persist one payment.');
$raceData = $service->checkoutData($buyer,$raceOrder['id']);
$pdo->exec('CREATE TABLE payment_test_paid_writes (order_id INT NOT NULL)');
$pdo->exec($mysql ? "CREATE TRIGGER payment_count_paid AFTER UPDATE ON orders FOR EACH ROW INSERT INTO payment_test_paid_writes (order_id) SELECT NEW.id WHERE NEW.payment_status='paid'"
    : "CREATE TRIGGER payment_count_paid AFTER UPDATE ON orders WHEN NEW.payment_status='paid' BEGIN INSERT INTO payment_test_paid_writes (order_id) VALUES (NEW.id); END");
$raceRemote = $remote($raceData,'pay_RaceCapture');
$input = ['mode'=>'webhook','remote'=>$raceRemote,'event'=>'evt_same_race'];
$results = CommerceTest::race([$input,$input],$fixture);
$check(array_column($results,'status') === [200,200], 'Identical concurrent webhook deliveries both succeed.');
$check((int)$pdo->query('SELECT COUNT(*) FROM payment_test_paid_writes WHERE order_id=' . $raceOrder['id'])->fetchColumn() === 1,
    'Identical concurrent webhooks write paid only once.');
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='order_confirmation' AND dedupe_key='order:" . $raceOrder['id'] . ":confirmation:v1'")->fetchColumn() === 1,
    'Identical concurrent webhooks enqueue one confirmation.');
$mixedOrder = $newOrder(); $mixedData = $service->beginOnline($buyer,$mixedOrder['id']);
$mixedRemote = $remote($mixedData,'pay_MixedRace');
$results = CommerceTest::race([
    ['mode'=>'callback','buyer'=>$buyer,'remote'=>$mixedRemote],
    ['mode'=>'webhook','remote'=>$mixedRemote,'event'=>'evt_mixed_race']
],$fixture);
$check(array_column($results,'status') === [200,200], 'Concurrent callback and webhook both succeed.');
$check((int)$pdo->query('SELECT COUNT(*) FROM payment_test_paid_writes WHERE order_id=' . $mixedOrder['id'])->fetchColumn() === 1
    && $pdo->query('SELECT status FROM payments WHERE order_id=' . $mixedOrder['id'])->fetchColumn() === 'captured',
    'Concurrent callback and webhook capture and write paid once.');
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='order_confirmation' AND dedupe_key='order:" . $mixedOrder['id'] . ":confirmation:v1'")->fetchColumn() === 1,
    'Concurrent callback and webhook enqueue exactly one confirmation.');
$pdo->exec('DROP TRIGGER payment_count_paid'); $pdo->exec('DROP TABLE payment_test_paid_writes');

$_ENV['CHECKOUT_RAZORPAY_ENABLED'] = 'false'; $before = $snapshot();
CommerceTest::rejects(fn() => OrderStates::payment('razorpay'), 422, 'Disabled mode rejects with 422.');
$check(!isset(OrderStates::paymentModes()['razorpay']), 'Disabled mode disappears from checkout.');
CommerceTest::rejects(fn() => $service->beginOnline($buyer,$third['id']), 422, 'Disabled mode gates the payment service.');
CommerceTest::rejects(fn() => $service->handleWebhook($body,''), 422, 'Disabled mode gates webhook processing.');
$check($snapshot() === $before, 'Disabled mode cannot change payment state.');
PaymentService::$clientFactory = null;
$diagnostics=file_get_contents(PrivateStorage::directory('logs').'/payments.log');
$check(str_contains($diagnostics,'payment_signature_invalid')&&str_contains($diagnostics,'payment_amount_mismatch')&&str_contains($diagnostics,'payment_declined'),'Private diagnostics contain safe payment failure classifiers.');
$check(!str_contains($diagnostics,'disposable-key-secret')&&!str_contains($diagnostics,'disposable-webhook-secret')&&!str_contains($diagnostics,$signature($data,'pay_Test1'))&&!str_contains($diagnostics,$body),
    'Payment logs contain no secrets, signatures or raw webhook bodies.');
$check((fileperms(PrivateStorage::directory('logs').'/payments.log')&0777)===0600,'Payment diagnostic log is private.');
CommerceTest::finish('PaymentServiceTest');
