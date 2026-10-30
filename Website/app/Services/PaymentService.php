<?php

final class PaymentService
{
    /** CLI test seam only; production always constructs the env-configured HTTP client. */
    public static ?Closure $clientFactory = null;
    public function __construct(private PDO $pdo) {}
    private function observe(string $operation,callable $work)
    {
        return DeveloperTrace::span(__CLASS__.'::'.$operation,function()use($operation,$work){
            try{return $work();}catch(Throwable $error){
                $code=$error instanceof RequestRejected?$error->errorCode:'payment_service_unavailable';
                // Classifiers only: no keys, signatures, request bodies, addresses or provider messages.
                if(!$this->pdo->inTransaction()&&preg_match('/\A[a-z][a-z0-9_]{0,79}\z/',$code))try{
                    $file=PrivateStorage::directory('logs').'/payments.log';$mask=umask(0077);
                    try{$handle=@fopen($file,'c+');}finally{umask($mask);}
                    if($handle){
                        if(flock($handle,LOCK_EX|LOCK_NB)){
                            if(fstat($handle)['size']>65536)ftruncate($handle,0);fseek($handle,0,SEEK_END);
                            fwrite($handle,gmdate('c').' '.$operation.' '.$code."\n");flock($handle,LOCK_UN);
                        }
                        fclose($handle);
                    }
                }catch(Throwable $ignored){}
                throw $error;
            }
        });
    }
    private function query(string $sql, array $args = []): PDOStatement { return CommerceDatabase::query($this->pdo, $sql, $args); }
    private function enabled(): void { OrderStates::payment('razorpay'); }
    /** Amount collected online now: the committed advance for an advance order, else the full total (legacy NULL). */
    private static function due(array $order): int { return (int)($order['amount_due_minor'] ?? $order['total_minor']); }
    private function client(): object
    {
        if ($this->pdo->inTransaction()) throw new LogicException('Payment HTTP requires a committed transaction.');
        if (PHP_SAPI === 'cli' && self::$clientFactory !== null) return (self::$clientFactory)();
        return new RazorpayClient($this->secret('RAZORPAY_KEY_ID'), $this->secret('RAZORPAY_KEY_SECRET'));
    }
    private function secret(string $key): string
    {
        $value = (string)env($key, '');
        if ($value === '') throw new RuntimeException('Payment configuration is unavailable.');
        return $value;
    }

    /** Transport for the refund worker, which runs between transactions (no lock held). Test seam reused. */
    public static function refundClient(): object
    {
        if (PHP_SAPI === 'cli' && self::$clientFactory !== null) return (self::$clientFactory)();
        $keyId = (string)env('RAZORPAY_KEY_ID', ''); $keySecret = (string)env('RAZORPAY_KEY_SECRET', '');
        if ($keyId === '' || $keySecret === '') throw new RuntimeException('Payment configuration is unavailable.');
        return new RazorpayClient($keyId, $keySecret);
    }

    /** Records one durable refund for an order's captured payment and enqueues its worker. The caller owns
        the open transaction so the refund is atomic with the cancellation that triggered it; one refund per
        order (deduped on a stable key). No-op when nothing was captured. */
    public static function enqueueRefund(PDO $pdo, array $order, string $reason): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Refund enqueue requires the business transaction.');
        $lock = CommerceDatabase::lock($pdo); $now = CommerceDatabase::clock($pdo);
        $payment = CommerceDatabase::query($pdo, "SELECT * FROM payments WHERE order_id=? AND provider='razorpay' AND status='captured' ORDER BY id LIMIT 1" . $lock, [$order['id']])->fetch();
        if (!$payment || $payment['provider_payment_id'] === null) return;
        $dedupe = 'order:' . $order['id'] . ':refund';
        if (CommerceDatabase::query($pdo, 'SELECT id FROM payment_refunds WHERE idempotency_key=?' . $lock, [$dedupe])->fetchColumn()) return;
        CommerceDatabase::query($pdo, "INSERT INTO payment_refunds (payment_id,order_id,amount_minor,currency,status,reason,idempotency_key,attempts,created_at,updated_at) VALUES (?,?,?,?,'pending',?,?,0,?,?)",
            [(int)$payment['id'], (int)$order['id'], (int)$payment['amount_minor'], $payment['currency'], CommerceValues::text($reason, 190, 'reason', true), $dedupe, $now, $now]);
        (new QueueService($pdo))->paymentRefund((int)$pdo->lastInsertId());
    }
    private function gatewayId(string $value, string $prefix): string
    {
        if (strlen($value) > 64 || !preg_match('/\A' . $prefix . '_[A-Za-z0-9]+\z/', $value)) CommerceValues::invalid('payment_id');
        return $value;
    }

    public function beginOnline(int $userId,int $orderId):array
    {
        return $this->observe('beginOnline',fn()=> $this->begin($userId,$orderId));
    }
    private function begin(int $userId, int $orderId): array
    {
        $this->enabled(); CommerceAccess::requireActor($userId); $orderId = CommerceValues::id($orderId);
        // Validate configuration before committing the durable creation claim.
        $this->secret('RAZORPAY_KEY_ID'); $this->secret('RAZORPAY_KEY_SECRET');
        $claim = CommerceDatabase::transaction($this->pdo, function () use ($userId, $orderId): array {
            $lock = CommerceDatabase::lock($this->pdo);
            $order = $this->query('SELECT * FROM orders WHERE id=? AND user_id=?' . $lock, [$orderId,$userId])->fetch();
            $this->payable($order);
            $prior = $this->query("SELECT * FROM payments WHERE order_id=? AND provider='razorpay' ORDER BY id LIMIT 1" . $lock, [$orderId])->fetch();
            if ($prior) return ['payment'=>$prior,'order'=>$order,'create'=>false];
            $now = CommerceDatabase::clock($this->pdo);
            // A durable claim prevents concurrent requests from creating two gateway orders.
            // Never retry an ambiguous HTTP creation: operator reconciliation is required after a crash/timeout.
            $this->query("INSERT INTO payments (order_id,provider,provider_order_id,amount_minor,currency,status,created_at,updated_at) VALUES (?,'razorpay',?,?,'INR','created',?,?)",
                [$orderId,'pending:' . $orderId,self::due($order),$now,$now]);
            return ['payment'=>['id'=>(int)$this->pdo->lastInsertId()],'order'=>$order,'create'=>true];
        });
        if (!$claim['create']) return $this->checkoutData($userId, $orderId);
        $order = $claim['order'];
        $remote = $this->client()->createOrder(self::due($order), $order['order_number'], ['order_id'=>(string)$orderId]);
        $providerId = $this->gatewayId($remote['id'] ?? '', 'order');
        if (!is_int($remote['amount'] ?? null) || $remote['amount'] !== self::due($order) || ($remote['currency'] ?? null) !== 'INR')
            throw new RequestRejected(422, 'payment_amount_mismatch', 'Payment amount or currency does not match.');
        CommerceDatabase::transaction($this->pdo, function () use ($claim, $providerId): void {
            $this->query('UPDATE payments SET provider_order_id=?,updated_at=? WHERE id=? AND provider_order_id=?',
                [$providerId,CommerceDatabase::clock($this->pdo),$claim['payment']['id'],'pending:' . $claim['order']['id']]);
        });
        return $this->checkoutData($userId, $orderId);
    }

    private function payable($order): void
    {
        if (!$order) throw new RequestRejected(404, 'not_found', 'Order not found.');
        if ($order['payment_mode'] !== 'razorpay' || $order['payment_status'] !== 'awaiting_payment' || $order['status'] === 'cancelled')
            throw new RequestRejected(409, 'payment_unavailable', 'This order cannot accept payment.');
    }

    /** Read-only data for the owned order/pay screen; GET never creates a gateway order. */
    public function checkoutData(int $userId, int $orderId): array
    {
        $this->enabled(); CommerceAccess::requireActor($userId);
        $order = $this->query('SELECT * FROM orders WHERE id=? AND user_id=?', [$orderId,$userId])->fetch();
        $this->payable($order);
        $payment = $this->query("SELECT * FROM payments WHERE order_id=? AND provider='razorpay' ORDER BY id LIMIT 1", [$orderId])->fetch();
        if (!$payment || str_starts_with($payment['provider_order_id'], 'pending:'))
            throw new RequestRejected(503, 'payment_initializing', 'Payment setup is pending. Please contact the store before retrying.');
        return ['key_id'=>$this->secret('RAZORPAY_KEY_ID'),'provider_order_id'=>$payment['provider_order_id'],
            'amount_minor'=>(int)$payment['amount_minor'],'currency'=>$payment['currency'],'order_number'=>$order['order_number']];
    }

    public function confirmCallback(int $userId,string $providerOrderId,string $providerPaymentId,string $signature):array
    {
        return $this->observe('confirmCallback',fn()=> $this->callback($userId,$providerOrderId,$providerPaymentId,$signature));
    }
    private function callback(int $userId, string $providerOrderId, string $providerPaymentId, string $signature): array
    {
        $this->enabled(); CommerceAccess::requireActor($userId);
        $this->gatewayId($providerOrderId, 'order'); $this->gatewayId($providerPaymentId, 'pay');
        // Checkout signature = HMAC-SHA256(provider_order_id|provider_payment_id, KEY_SECRET).
        $expected = hash_hmac('sha256', $providerOrderId . '|' . $providerPaymentId, $this->secret('RAZORPAY_KEY_SECRET'));
        if (!hash_equals($expected, $signature)) throw new RequestRejected(400, 'payment_signature_invalid', 'The payment signature could not be verified.');
        $payment = $this->query("SELECT p.* FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.provider='razorpay' AND p.provider_order_id=? AND o.user_id=?", [$providerOrderId,$userId])->fetch();
        if (!$payment) throw new RequestRejected(404, 'not_found', 'Payment not found.');
        $remote = $this->client()->fetchPayment($providerPaymentId);
        $this->validateRemote($payment, $remote, $providerPaymentId);
        $status = $remote['status'] ?? '';
        if (!in_array($status, ['captured','failed'], true)) throw new RequestRejected(409, 'payment_pending', 'Payment has not been captured.');
        $this->settle((int)$payment['id'], $providerPaymentId, 'callback:' . $providerPaymentId, $status);
        if($status==='failed')throw new RequestRejected(422,'payment_declined','Payment was declined. Open your order to retry payment or contact the store.');
        return ['id'=>(int)$payment['order_id']];
    }

    public function handleWebhook(string $rawBody, string $signatureHeader): void
    {
        $this->observe('handleWebhook',fn()=> $this->webhook($rawBody,$signatureHeader));
    }
    private function webhook(string $rawBody,string $signatureHeader):void
    {
        $this->enabled();
        // Webhook signature = HMAC-SHA256(raw request bytes, WEBHOOK_SECRET), never re-encoded JSON.
        $expected = hash_hmac('sha256', $rawBody, $this->secret('RAZORPAY_WEBHOOK_SECRET'));
        if (!hash_equals($expected, $signatureHeader)) throw new RequestRejected(400, 'payment_signature_invalid', 'The payment signature could not be verified.');
        try { $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable $error) { throw new RequestRejected(400, 'invalid_webhook', 'Invalid webhook.'); }
        if (!is_array($event)) throw new RequestRejected(400, 'invalid_webhook', 'Invalid webhook.');
        if (!in_array($event['event'] ?? '', ['payment.captured','payment.failed','order.paid'], true)) return;
        // Razorpay delivers the event identifier in this header, not in the payload.
        $eventId = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '';
        if (!is_string($eventId) || !preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $eventId))
            throw new RequestRejected(400, 'invalid_webhook', 'Invalid webhook.');
        $remote = $event['payload']['payment']['entity'] ?? null;
        if (!is_array($remote)) throw new RequestRejected(400, 'invalid_webhook', 'Invalid webhook.');
        $providerId = $this->gatewayId($remote['id'] ?? '', 'pay');
        $payment = $this->query("SELECT * FROM payments WHERE provider='razorpay' AND provider_order_id=?", [$remote['order_id'] ?? ''])->fetch();
        if (!$payment) throw new RequestRejected(409, 'payment_unknown', 'Payment is not available yet.');
        $this->validateRemote($payment, $remote, $providerId);
        $status = $event['event'] === 'payment.failed' ? 'failed' : 'captured';
        if (($remote['status'] ?? '') !== $status) throw new RequestRejected(400, 'invalid_webhook', 'Invalid webhook.');
        $this->settle((int)$payment['id'], $providerId, $eventId, $status);
    }

    private function validateRemote(array $payment, array $remote, string $providerId): void
    {
        if (($remote['id'] ?? null) !== $providerId || ($remote['order_id'] ?? null) !== $payment['provider_order_id']
            || !is_int($remote['amount'] ?? null) || $remote['amount'] !== (int)$payment['amount_minor']
            || ($remote['currency'] ?? null) !== $payment['currency'])
            throw new RequestRejected(422, 'payment_amount_mismatch', 'Payment amount, currency or order does not match.');
    }

    private function settle(int $paymentId, string $providerPaymentId, string $eventId, string $status): void
    {
        CommerceDatabase::transaction($this->pdo, function () use ($paymentId, $providerPaymentId, $eventId, $status): void {
            $lock = CommerceDatabase::lock($this->pdo); $now = CommerceDatabase::clock($this->pdo);
            $sql = "INSERT INTO payment_events (provider,event_id,payment_id,status,created_at) VALUES ('razorpay',?,?,'received',?)";
            $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ON DUPLICATE KEY UPDATE id=id' : ' ON CONFLICT(provider,event_id) DO NOTHING';
            $this->query($sql, [$eventId,$paymentId,$now]);
            $event = $this->query("SELECT * FROM payment_events WHERE provider='razorpay' AND event_id=?" . $lock, [$eventId])->fetch();
            if ((int)$event['payment_id'] !== $paymentId) throw new RequestRejected(409, 'payment_event_conflict', 'Payment event conflicts.');
            if ($event['status'] === 'processed') return;
            $payment = $this->query('SELECT * FROM payments WHERE id=?' . $lock, [$paymentId])->fetch();
            if ($payment['status'] !== 'captured') {
                $order = $this->query('SELECT * FROM orders WHERE id=?' . $lock, [$payment['order_id']])->fetch();
                $this->payable($order);
                if (self::due($order) !== (int)$payment['amount_minor'] || $order['currency'] !== $payment['currency'])
                    throw new RequestRejected(422, 'payment_amount_mismatch', 'Payment amount or currency does not match.');
                $this->query('UPDATE payments SET status=?,provider_payment_id=?,updated_at=?,last_error_code=? WHERE id=?', [$status,$providerPaymentId,$now,$status==='failed'?'payment_declined':null,$paymentId]);
                if ($status === 'captured') {
                    // An advance leaves a balance due on delivery; a full online payment settles the balance.
                    $paid = self::due($order) < (int)$order['total_minor'] ? 'advance_paid' : 'paid';
                    // Capture never confirms the order: it enters the phone-verification gate. The shop
                    // confirms by call, or the verify_window timer auto-confirms after the admin-set hours.
                    $settings = (new SettingsService($this->pdo))->effective();
                    $deadline = $settings['verify_autoconfirm_hours'] > 0 ? $now + $settings['verify_autoconfirm_hours'] * 3600 : null;
                    if ($order['status'] === 'placed') {
                        $this->query("UPDATE orders SET payment_status=?,status='awaiting_verification',verify_deadline_at=?,payment_deadline_at=NULL,revision=revision+1,updated_at=? WHERE id=?", [$paid,$deadline,$now,$order['id']]);
                        $this->query("INSERT INTO order_status_history (order_id,from_status,to_status,actor_user_id,reason,operation_key,created_at) VALUES (?,'placed','awaiting_verification',?,?,?,?)",
                            [$order['id'],(int)$order['user_id'],'Payment received; awaiting verification call','autogate:'.$order['id'],$now]);
                        if ($deadline !== null) (new QueueService($this->pdo))->verifyWindow((int)$order['id'], $deadline);
                    } else {
                        $this->query("UPDATE orders SET payment_status=?,updated_at=? WHERE id=?", [$paid,$now,$order['id']]);
                    }
                    (new QueueService($this->pdo))->orderPaymentReceived((int)$order['id']);
                }
            }
            $this->query("UPDATE payment_events SET status='processed' WHERE id=?", [$event['id']]);
        });
    }
}
