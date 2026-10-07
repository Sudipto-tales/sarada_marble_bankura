<?php

/** Scans active expired reservations and releases them via InventoryService. */
final class ReservationExpiryJob
{
    public static function run(PDO $pdo, array $job): void
    {
        $payload = JobRegistry::decode($job);
        $reservationId = (int)($payload['reservation_id'] ?? 0);
        if ($reservationId <= 0) {
            if (!$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $pdo->rollBack();
            }
            return;
        }
        $inventory = new InventoryService($pdo);
        $reservation = $inventory->reservation($reservationId);
        $now = CommerceDatabase::clock($pdo);
        if ($reservation['state'] !== 'active' || (int)$reservation['expires_at'] > $now) {
            if (!$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $pdo->rollBack();
            }
            return;
        }
        $result = $inventory->release($reservationId, $reservation['principal_key'], true);
        if (!$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->rollBack();
        }
    }
}