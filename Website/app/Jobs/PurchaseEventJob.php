<?php

/** EVT-01 consumer for the held `purchase_events` queue. Writes the trusted purchase/cancellation
    event, its durable business key and processing metadata, then acknowledges the lease — all in one
    transaction. Replay is idempotent via the business-key ledger; an accepted intent is never dropped
    because of a delivery failure. */
final class PurchaseEventJob
{
    public static function run(PDO $pdo, array $job): void
    {
        $orderId = (int) JobRegistry::decode($job)['order_id'];
        $queue = new Queue($pdo);
        CommerceDatabase::transaction($pdo, function () use ($pdo, $job, $queue, $orderId): void {
            $events = new EventService($pdo);
            if ($job['type'] === 'purchase_event') {
                $events->recordPurchase($pdo, $orderId);
                (new RecommendationService($pdo))->recordPurchaseOrder($orderId); // co-purchase pairs (REC-01)
            } else {
                $events->recordCancellation($pdo, $orderId);
            }
            if (!$queue->acknowledge((int) $job['id'], $job['lease_token'])) throw new JobExecutionException('lease_lost');
        });
    }
}
