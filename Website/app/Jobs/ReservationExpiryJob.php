<?php

/** Releases one expired reservation under lock, then acknowledges the lease. Idempotent on replay. */
final class ReservationExpiryJob
{
    public static function run(PDO $pdo, array $job): void
    {
        $reservationId = (int)JobRegistry::decode($job)['reservation_id'];
        $queue = new Queue($pdo);
        CommerceDatabase::transaction($pdo, function () use ($pdo, $job, $queue, $reservationId): void {
            // Claimed before its lease actually expired: leave it for a later run rather than dropping the release.
            if ((new InventoryService($pdo))->expireReservation($reservationId) === 'active') throw new JobExecutionException('reservation_not_expired');
            if (!$queue->acknowledge((int)$job['id'], $job['lease_token'])) throw new JobExecutionException('lease_lost');
        });
    }
}
