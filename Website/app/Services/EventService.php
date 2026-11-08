<?php

/**
 * EVT-01 append-only event tracking. Public browser events (product_view, search, add_to_cart) are
 * ingested with a server-derived principal/session/time — never a browser-supplied user id or purchase.
 * Trusted purchase/cancellation events are written only by the held `purchase_events` queue consumer,
 * from the authoritative order snapshot, and are deduplicated by a durable business-key ledger that
 * outlives raw-event retention. Immutable events live in `events`; mutable work state in `event_processing`.
 */
final class EventService
{
    public const PUBLIC_TYPES = ['product_view', 'search', 'add_to_cart'];
    private const RATE_WINDOW = 60;   // seconds
    private const RATE_CAP = 120;     // events per session per window

    public function __construct(private PDO $pdo) {}
    private function query(string $sql, array $args = []): PDOStatement { return CommerceDatabase::query($this->pdo, $sql, $args); }

    /** Standalone public ingestion: owns its own transaction and rate-limits by pseudonymous session. */
    public function ingest(string $type, array $input, ?int $userId, string $sessionKey): array
    {
        if (!in_array($type, self::PUBLIC_TYPES, true)) CommerceValues::invalid('event_type');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $sessionKey)) CommerceValues::invalid('session_key');
        if ($userId !== null) $userId = CommerceValues::id($userId, 'user_id');
        if ($this->pdo->inTransaction()) throw new LogicException('Standalone ingest owns its transaction.');
        return CommerceDatabase::transaction($this->pdo, function () use ($type, $input, $userId, $sessionKey): array {
            $now = CommerceDatabase::clock($this->pdo);
            $recent = (int) $this->query('SELECT COUNT(*) FROM events WHERE session_key=? AND created_at>?',
                [$sessionKey, $now - self::RATE_WINDOW])->fetchColumn();
            if ($recent >= self::RATE_CAP) throw new RequestRejected(429, 'rate_limited', 'Too many events. Please slow down.');
            $fields = $this->publicFields($type, $input);
            self::insertEvent($this->pdo, $type, null, $userId, $sessionKey, $fields, $now);
            // Build co-view recommendation support from this session's recent distinct views (REC-01).
            if ($type === 'product_view' && isset($fields['product_id'])) {
                (new RecommendationService($this->pdo))->recordCoView($sessionKey, (int) $fields['product_id'], $now);
            }
            return ['status' => 'accepted'];
        });
    }

    /** Validate and bound the public payload; the server resolves product/category from the catalog. */
    private function publicFields(string $type, array $input): array
    {
        if ($type === 'product_view') {
            $productId = CommerceValues::id($input['product_id'] ?? null, 'product_id');
            $product = $this->query("SELECT id,category_id FROM products WHERE id=? AND status='active'", [$productId])->fetch();
            if (!$product) throw new RequestRejected(404, 'not_found', 'Product not found.');
            return ['product_id' => $productId, 'category_id' => (int) $product['category_id'], 'payload' => []];
        }
        if ($type === 'add_to_cart') {
            $variantId = CommerceValues::id($input['variant_id'] ?? null, 'variant_id');
            $variant = $this->query("SELECT v.id,v.product_id,p.category_id FROM product_variants v JOIN products p ON p.id=v.product_id
                WHERE v.id=? AND v.enabled=1 AND p.status='active'", [$variantId])->fetch();
            if (!$variant) throw new RequestRejected(404, 'not_found', 'Variant not found.');
            return ['product_id' => (int) $variant['product_id'], 'variant_id' => $variantId,
                'category_id' => (int) $variant['category_id'], 'payload' => []];
        }
        // search: normalize the query; store nothing that could carry PII.
        $raw = CommerceValues::text($input['query'] ?? '', 160, 'query');
        $query = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $raw)));
        if ($query === '' || mb_strlen($query) > 80) CommerceValues::invalid('query');
        return ['payload' => ['query' => $query]];
    }

    /** Trusted accepted-placement event, written inside the consumer's transaction. Idempotent per order. */
    public function recordPurchase(PDO $pdo, int $orderId): array
    {
        return self::recordOrderEvent($pdo, CommerceValues::id($orderId, 'order_id'), 'purchase', 'order:' . $orderId . ':purchase:v1');
    }

    /** Trusted cancellation correction event; EVT-02 nets it against the original purchase bucket. */
    public function recordCancellation(PDO $pdo, int $orderId): array
    {
        return self::recordOrderEvent($pdo, CommerceValues::id($orderId, 'order_id'), 'order_cancelled', 'order:' . $orderId . ':cancelled:v1');
    }

    private static function recordOrderEvent(PDO $pdo, int $orderId, string $type, string $businessKey): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Trusted order events run inside the consumer transaction.');
        $lock = CommerceDatabase::lock($pdo);
        // The durable ledger is the authority: a replay after the raw event is archived still finds it here.
        if (CommerceDatabase::query($pdo, 'SELECT id FROM event_business_keys WHERE business_key=?' . $lock, [$businessKey])->fetchColumn()) {
            return ['status' => 'duplicate', 'business_key' => $businessKey];
        }
        $order = CommerceDatabase::query($pdo, 'SELECT id,user_id FROM orders WHERE id=?' . $lock, [$orderId])->fetch();
        if (!$order) throw new RequestRejected(404, 'not_found', 'Order not found for event.');
        $now = CommerceDatabase::clock($pdo);
        CommerceDatabase::query($pdo, 'INSERT INTO event_business_keys (business_key,order_id,kind,created_at) VALUES (?,?,?,?)',
            [$businessKey, $orderId, $type === 'purchase' ? 'purchase' : 'cancelled', $now]);
        $userId = $order['user_id'] !== null ? (int) $order['user_id'] : null;
        $id = self::insertEvent($pdo, $type, $businessKey, $userId, null, ['order_id' => $orderId, 'payload' => []], $now);
        return ['status' => 'recorded', 'business_key' => $businessKey, 'event_id' => $id];
    }

    /** One immutable event row plus its pending processing metadata. */
    private static function insertEvent(PDO $pdo, string $type, ?string $eventKey, ?int $userId, ?string $sessionKey, array $fields, int $now): int
    {
        CommerceDatabase::query($pdo, 'INSERT INTO events (event_type,event_key,user_id,session_key,product_id,variant_id,category_id,order_id,payload,occurred_at,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$type, $eventKey, $userId, $sessionKey, $fields['product_id'] ?? null, $fields['variant_id'] ?? null,
                $fields['category_id'] ?? null, $fields['order_id'] ?? null,
                json_encode($fields['payload'] ?? [], JSON_THROW_ON_ERROR), $now, $now]);
        $id = (int) $pdo->lastInsertId();
        CommerceDatabase::query($pdo, "INSERT INTO event_processing (event_id,state,created_at) VALUES (?,'pending',?)", [$id, $now]);
        return $id;
    }
}
