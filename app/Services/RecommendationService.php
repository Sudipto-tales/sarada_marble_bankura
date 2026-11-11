<?php

/**
 * REC-01 bounded recommendations. Co-view pairs are built incrementally from a session's recent distinct
 * product views; co-purchase pairs from an accepted order's distinct products (capped). Each support row is
 * unique per session-day / order+pair, so refreshes never inflate a pair. `rebuild()` writes a shadow
 * generation of top-N targets per source — pair-supported (≥ min support, cancellations excluded) first,
 * then category/brand/price-distance similarity — and publishes it atomically by advancing the generation
 * pointer; a half-built generation is never visible. `forProduct()` reads the live generation, or falls back
 * to live similarity when none is published. No demographic or individual price inference.
 */
final class RecommendationService
{
    private const WINDOW_DAYS = 30;
    private const SESSION_WINDOW = 1800; // 30 minutes
    private const RECENT = 10;
    private const ORDER_CAP = 20;
    private const MIN_SUPPORT = 3;
    private const MAX_RESULTS = 8;

    public function __construct(private PDO $pdo) {}
    private function query(string $sql, array $args = []): PDOStatement { return CommerceDatabase::query($this->pdo, $sql, $args); }

    public static function generation(PDO $pdo): int
    {
        return (int) CommerceDatabase::query($pdo, "SELECT version FROM catalog_versions WHERE namespace='recommendations'")->fetchColumn();
    }

    private function insertSupport(string $kind, int $source, int $target, ?int $orderId, string $key, int $now): void
    {
        $sql = 'INSERT INTO rec_pair_support (kind,source_product_id,target_product_id,order_id,support_key,created_at) VALUES (?,?,?,?,?,?)';
        $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ON DUPLICATE KEY UPDATE id=id' : ' ON CONFLICT(support_key) DO NOTHING';
        $this->query($sql, [$kind, $source, $target, $orderId, $key, $now]);
    }

    /** Incremental co-view support from the session's recent distinct views (bounded, indexed; no cross join). */
    public function recordCoView(string $sessionKey, int $productId, ?int $now = null): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $sessionKey)) return;
        $productId = CommerceValues::id($productId);
        $now ??= CommerceDatabase::clock($this->pdo);
        $day = gmdate('Y-m-d', $now);
        $recent = $this->query("SELECT DISTINCT product_id FROM events WHERE session_key=? AND event_type='product_view'
            AND product_id IS NOT NULL AND product_id<>? AND created_at>=? ORDER BY product_id LIMIT " . self::RECENT,
            [$sessionKey, $productId, $now - self::SESSION_WINDOW])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($recent as $other) {
            $o = (int) $other;
            $this->insertSupport('coview', $productId, $o, null, "coview:$sessionKey:$day:$productId:$o", $now);
            $this->insertSupport('coview', $o, $productId, null, "coview:$sessionKey:$day:$o:$productId", $now);
        }
    }

    /** Co-purchase support from one order's distinct products (capped). Runs inside the consumer transaction. */
    public function recordPurchaseOrder(int $orderId): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Co-purchase support runs inside the consumer transaction.');
        $orderId = CommerceValues::id($orderId);
        $ids = array_map('intval', $this->query('SELECT DISTINCT product_id FROM order_items WHERE order_id=? ORDER BY product_id LIMIT ' . self::ORDER_CAP, [$orderId])->fetchAll(PDO::FETCH_COLUMN));
        $now = CommerceDatabase::clock($this->pdo);
        foreach ($ids as $source) foreach ($ids as $target) if ($source !== $target) {
            $this->insertSupport('copurchase', $source, $target, $orderId, "copurchase:$orderId:$source:$target", $now);
        }
    }

    /** Rebuild and atomically publish a new generation. A crash before publish leaves the shadow invisible. */
    public function rebuild(): array
    {
        $generation = self::generation($this->pdo) + 1;
        $now = CommerceDatabase::clock($this->pdo); $cutoff = $now - self::WINDOW_DAYS * 86400;
        $this->query('DELETE FROM rec_suggestions WHERE generation=?', [$generation]); // clear any prior shadow attempt
        $sources = $this->query("SELECT p.id,p.category_id,p.brand_id,
            (SELECT MIN(unit_price_minor) FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1) AS price
            FROM products p JOIN categories c ON c.id=p.category_id
            WHERE p.status='active' AND c.enabled=1 AND EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1)
            ORDER BY p.id")->fetchAll();
        $built = 0;
        foreach ($sources as $src) {
            $position = 0;
            foreach ($this->suggestFor((int) $src['id'], $src, $cutoff) as $target) {
                $this->query('INSERT INTO rec_suggestions (generation,source_product_id,target_product_id,position,created_at) VALUES (?,?,?,?,?)',
                    [$generation, (int) $src['id'], $target, $position++, $now]);
            }
            $built++;
        }
        CommerceDatabase::transaction($this->pdo, function () use ($generation): void {
            if (CommerceDatabase::query($this->pdo, "UPDATE catalog_versions SET version=? WHERE namespace='recommendations'", [$generation])->rowCount() !== 1) {
                throw new RuntimeException('Recommendation generation is unavailable.');
            }
        });
        $this->query('DELETE FROM rec_suggestions WHERE generation<?', [$generation]); // prune superseded generations
        return ['generation' => $generation, 'sources' => $built];
    }

    /** Ordered target ids for one source: pair-supported (cancellations excluded) first, then similarity. */
    private function suggestFor(int $source, array $src, int $cutoff): array
    {
        // MIN_SUPPORT/MAX_RESULTS are interpolated constants: a bound string breaks the SQLite COUNT comparison.
        $rows = $this->query("SELECT s.target_product_id,COUNT(*) AS c FROM rec_pair_support s
            JOIN products p ON p.id=s.target_product_id JOIN categories c2 ON c2.id=p.category_id
            LEFT JOIN orders o ON o.id=s.order_id
            WHERE s.source_product_id=? AND s.created_at>=? AND s.target_product_id<>?
            AND (s.order_id IS NULL OR o.status<>'cancelled')
            AND p.status='active' AND c2.enabled=1
            AND EXISTS(SELECT 1 FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.product_id=p.id AND v.enabled=1 AND i.on_hand_milli-i.reserved_milli>=v.qty_increment_milli)
            GROUP BY s.target_product_id HAVING COUNT(*)>=" . self::MIN_SUPPORT . "
            ORDER BY c DESC, s.target_product_id ASC LIMIT " . self::MAX_RESULTS, [$source, $cutoff, $source])->fetchAll();
        $result = array_map(static fn(array $r): int => (int) $r['target_product_id'], $rows);
        if (count($result) < self::MAX_RESULTS) {
            foreach ($this->fallback($source, $src, $result) as $target) {
                if (!in_array($target, $result, true)) $result[] = $target;
                if (count($result) >= self::MAX_RESULTS) break;
            }
        }
        return array_slice($result, 0, self::MAX_RESULTS);
    }

    /** Category/brand/price-distance similarity fallback, excluding self, the given ids, inactive and out-of-stock. */
    private function fallback(int $source, array $src, array $exclude): array
    {
        $excluded = array_merge([$source], array_map('intval', $exclude));
        $marks = implode(',', array_fill(0, count($excluded), '?'));
        $rows = $this->query("SELECT p.id FROM products p JOIN categories c ON c.id=p.category_id
            WHERE p.status='active' AND c.enabled=1 AND p.category_id=? AND p.id NOT IN ($marks)
            AND EXISTS(SELECT 1 FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.product_id=p.id AND v.enabled=1 AND i.on_hand_milli-i.reserved_milli>=v.qty_increment_milli)
            ORDER BY (CASE WHEN p.brand_id=? THEN 0 ELSE 1 END),
            ABS(COALESCE((SELECT MIN(unit_price_minor) FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1),0)-?), p.id
            LIMIT " . self::MAX_RESULTS, array_merge([(int) $src['category_id']], $excluded, [$src['brand_id'] === null ? 0 : (int) $src['brand_id'], (int) ($src['price'] ?? 0)]))->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    }

    /** Card-ready recommendations for a product: live generation, else live similarity fallback. */
    public function forProduct(int $productId, int $limit = self::MAX_RESULTS): array
    {
        $productId = CommerceValues::id($productId);
        $limit = CommerceValues::integer($limit, 1, self::MAX_RESULTS, 'limit');
        $generation = self::generation($this->pdo); $ids = [];
        if ($generation >= 1) {
            $ids = array_map('intval', $this->query('SELECT target_product_id FROM rec_suggestions WHERE generation=? AND source_product_id=? ORDER BY position LIMIT ' . self::MAX_RESULTS, [$generation, $productId])->fetchAll(PDO::FETCH_COLUMN));
        }
        if (!$ids) {
            $src = $this->query('SELECT id,category_id,brand_id,(SELECT MIN(unit_price_minor) FROM product_variants v WHERE v.product_id=id AND v.enabled=1) AS price FROM products WHERE id=?', [$productId])->fetch();
            if ($src) $ids = $this->fallback($productId, $src, []);
        }
        if (!$ids) return [];
        // selection() returns only currently active, card-ready products, so stock/visibility are re-checked here.
        $products = array_column((new ProductService($this->pdo))->selection($ids), null, 'id');
        $ordered = [];
        foreach ($ids as $id) { if (isset($products[$id])) $ordered[] = $products[$id]; if (count($ordered) >= $limit) break; }
        return $ordered;
    }
}
