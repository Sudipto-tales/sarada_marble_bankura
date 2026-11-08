<?php

/**
 * EVT-02 incremental aggregation and retention. Work is driven by pending `event_processing` rows (never
 * a max(id) watermark): each batch locks pending rows in id order, applies deterministic daily deltas to
 * `product_daily_metrics`, marks those rows processed and advances the checkpoint in ONE transaction, so a
 * crash rolls back both effects and the checkpoint — retry never double counts. Purchase/cancellation both
 * bucket by the order's creation day from authoritative order data, so a cancellation arriving before its
 * purchase still nets into the same bucket. Counts are net (cancellation-aware) and gross, explicitly labeled.
 */
final class MetricsService
{
    private const BATCH_MAX = 1000;

    public function __construct(private PDO $pdo) {}
    private function query(string $sql, array $args = []): PDOStatement { return CommerceDatabase::query($this->pdo, $sql, $args); }

    /** Drain pending events in bounded batches until caught up or the runtime budget is spent. */
    public function aggregate(int $batch = 200, int $maxSeconds = 20): array
    {
        $batch = CommerceValues::integer($batch, 1, self::BATCH_MAX, 'batch');
        $maxSeconds = CommerceValues::integer($maxSeconds, 1, 300, 'max_seconds');
        if ($this->pdo->inTransaction()) throw new LogicException('Aggregation owns its own transactions.');
        $processed = 0; $deadline = time() + $maxSeconds;
        do {
            $done = CommerceDatabase::transaction($this->pdo, fn(): int => $this->aggregateBatch($batch));
            $processed += $done;
        } while ($done === $batch && time() < $deadline);
        return ['processed' => $processed, 'pending' => $this->pending()];
    }

    public function pending(): int
    {
        return (int) $this->query("SELECT COUNT(*) FROM event_processing WHERE state='pending'")->fetchColumn();
    }

    private function aggregateBatch(int $batch): int
    {
        $lock = CommerceDatabase::lock($this->pdo);
        // Lock pending rows in id order; a concurrent aggregator blocks, then sees them processed and skips.
        $rows = $this->query("SELECT ep.id AS processing_id, e.id AS event_id, e.event_type, e.product_id, e.order_id, e.occurred_at
            FROM event_processing ep JOIN events e ON e.id=ep.event_id WHERE ep.state='pending' ORDER BY ep.id LIMIT $batch" . $lock)->fetchAll();
        if (!$rows) return 0;
        $now = CommerceDatabase::clock($this->pdo); $maxEventId = 0;
        foreach ($rows as $row) {
            $this->applyEvent($row);
            $this->query("UPDATE event_processing SET state='processed',processed_at=? WHERE id=?", [$now, $row['processing_id']]);
            $maxEventId = max($maxEventId, (int) $row['event_id']);
        }
        $this->query('UPDATE analytics_checkpoint SET last_event_id=CASE WHEN last_event_id<? THEN ? ELSE last_event_id END,
            processed_total=processed_total+?,updated_at=?', [$maxEventId, $maxEventId, count($rows), $now]);
        return count($rows);
    }

    private function applyEvent(array $event): void
    {
        $type = $event['event_type'];
        if ($type === 'product_view' && $event['product_id'] !== null) {
            $this->bump((int) $event['product_id'], gmdate('Y-m-d', (int) $event['occurred_at']), ['views' => 1]);
            return;
        }
        if ($type === 'add_to_cart' && $event['product_id'] !== null) {
            $this->bump((int) $event['product_id'], gmdate('Y-m-d', (int) $event['occurred_at']), ['add_to_carts' => 1]);
            return;
        }
        if ($type === 'purchase' || $type === 'order_cancelled') {
            $this->applyOrder($type, (int) $event['order_id']);
        }
        // search and any other type leave no product-daily metric; the row is still marked processed.
    }

    /** Net sales use the order's creation-day bucket for both purchase and its cancellation, so the two
        contributions always meet in the same bucket regardless of which the aggregator processes first. */
    private function applyOrder(string $type, int $orderId): void
    {
        $order = $this->query('SELECT id,created_at FROM orders WHERE id=?', [$orderId])->fetch();
        if (!$order) return; // orders are never deleted; a missing one leaves no metric rather than failing the batch
        $date = gmdate('Y-m-d', (int) $order['created_at']);
        // One contribution per distinct product in the order; heterogeneous sell units are summed per product.
        $lines = $this->query('SELECT product_id, SUM(qty_milli) AS units FROM order_items WHERE order_id=? GROUP BY product_id', [$orderId])->fetchAll();
        foreach ($lines as $line) {
            $units = (int) $line['units'];
            if ($type === 'purchase') {
                $this->bump((int) $line['product_id'], $date, ['orders_gross' => 1, 'orders_net' => 1, 'units_gross_milli' => $units, 'units_net_milli' => $units]);
            } else {
                $this->bump((int) $line['product_id'], $date, ['orders_net' => -1, 'units_net_milli' => -$units]);
            }
        }
    }

    private function bump(int $productId, string $date, array $deltas): void
    {
        $cols = ['views', 'add_to_carts', 'orders_gross', 'orders_net', 'units_gross_milli', 'units_net_milli'];
        $values = [$productId, $date];
        foreach ($cols as $c) $values[] = (int) ($deltas[$c] ?? 0);
        $values[] = CommerceDatabase::clock($this->pdo);
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $set = $mysql
            ? implode(',', array_map(static fn($c): string => "$c=$c+VALUES($c)", $cols)) . ',updated_at=VALUES(updated_at)'
            : implode(',', array_map(static fn($c): string => "$c=$c+excluded.$c", $cols)) . ',updated_at=excluded.updated_at';
        $conflict = $mysql ? ' ON DUPLICATE KEY UPDATE ' . $set : ' ON CONFLICT(product_id,metric_date) DO UPDATE SET ' . $set;
        $this->query('INSERT INTO product_daily_metrics (product_id,metric_date,' . implode(',', $cols) . ',updated_at)
            VALUES (?,?,?,?,?,?,?,?,?)' . $conflict, $values);
    }

    /**
     * Retention: delete processed events (and their processing rows via cascade) older than the cutoff. This
     * is plain expired-observation deletion, not an archive. The `event_business_keys` ledger is kept so a
     * replayed purchase/cancellation intent can never be counted again. Pending intents are never deleted.
     */
    public function purge(int $retentionDays, int $batch = 500, int $maxSeconds = 20): array
    {
        $retentionDays = CommerceValues::integer($retentionDays, 1, 3650, 'retention_days');
        $batch = CommerceValues::integer($batch, 1, self::BATCH_MAX, 'batch');
        $maxSeconds = CommerceValues::integer($maxSeconds, 1, 300, 'max_seconds');
        if ($this->pdo->inTransaction()) throw new LogicException('Retention owns its own transactions.');
        $deleted = 0; $deadline = time() + $maxSeconds;
        do {
            $done = CommerceDatabase::transaction($this->pdo, fn(): int => $this->purgeBatch($retentionDays, $batch));
            $deleted += $done;
        } while ($done === $batch && time() < $deadline);
        return ['deleted' => $deleted];
    }

    private function purgeBatch(int $retentionDays, int $batch): int
    {
        $cutoff = CommerceDatabase::clock($this->pdo) - $retentionDays * 86400;
        $ids = $this->query("SELECT e.id FROM events e JOIN event_processing ep ON ep.event_id=e.id
            WHERE ep.state='processed' AND e.created_at<? ORDER BY e.id LIMIT $batch" . CommerceDatabase::lock($this->pdo), [$cutoff])->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return 0;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        // event_processing cascades on the events delete; delete it explicitly too for drivers without cascade.
        $this->query("DELETE FROM event_processing WHERE event_id IN ($marks)", $ids);
        $this->query("DELETE FROM events WHERE id IN ($marks)", $ids);
        return count($ids);
    }
}
