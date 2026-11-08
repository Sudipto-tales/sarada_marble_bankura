<?php

/** Executes one durable refund against Razorpay between short transactions (ADV-BOOK-01), like
    notifications: claim the refund under lock, call the gateway outside the lock, then settle the
    refund/payment/order atomically. The refund's `idempotency_key` is replayed to the gateway so a
    retried job never double-refunds. Never promises exactly-once; a crash after the gateway call
    leaves the refund `processing` for operator reconciliation rather than silently re-refunding. */
final class RefundJob
{
    public static function run(PDO $pdo, array $job): void
    {
        $refundId = (int)JobRegistry::decode($job)['refund_id'];
        $queue = new Queue($pdo);
        $token = bin2hex(random_bytes(32));
        $work = CommerceDatabase::transaction($pdo, function () use ($pdo, $job, $queue, $refundId, $token): ?array {
            $lock = CommerceDatabase::lock($pdo); $now = CommerceDatabase::clock($pdo);
            $ack = function () use ($queue, $job): void {
                if (!$queue->acknowledge((int)$job['id'], $job['lease_token'])) throw new JobExecutionException('lease_lost');
            };
            $refund = CommerceDatabase::query($pdo, 'SELECT * FROM payment_refunds WHERE id=?' . $lock, [$refundId])->fetch();
            if (!$refund || $refund['status'] === 'refunded') { $ack(); return null; }
            if ($refund['status'] === 'processing' && (int)$refund['lease_expires_at'] > $now) throw new JobExecutionException('refund_busy');
            $payment = CommerceDatabase::query($pdo, 'SELECT * FROM payments WHERE id=?' . $lock, [$refund['payment_id']])->fetch();
            if (!$payment || $payment['status'] !== 'captured' || $payment['provider_payment_id'] === null) throw new JobExecutionException('refund_not_captured', true);
            CommerceDatabase::query($pdo, "UPDATE payment_refunds SET status='processing',attempts=attempts+1,lease_token=?,lease_expires_at=?,last_error_code=NULL,updated_at=? WHERE id=?", [$token, $now + 45, $now, $refundId]);
            $live = CommerceDatabase::query($pdo, "SELECT lease_token,lease_expires_at FROM jobs WHERE id=? AND status='running'" . $lock, [$job['id']])->fetch();
            if (!$live || $live['lease_token'] !== $job['lease_token'] || (int)$live['lease_expires_at'] <= $now) throw new JobExecutionException('lease_lost');
            return ['refund_id' => $refundId, 'payment_id' => (int)$payment['id'], 'order_id' => (int)$refund['order_id'],
                'provider_payment_id' => $payment['provider_payment_id'], 'amount_minor' => (int)$refund['amount_minor'], 'idempotency' => $refund['idempotency_key']];
        });
        if (!$work) return;
        try {
            $remote = PaymentService::refundClient()->refundPayment($work['provider_payment_id'], $work['amount_minor'], $work['idempotency']);
            $providerRefundId = $remote['id'] ?? '';
            if (!is_string($providerRefundId) || !preg_match('/\Arfnd_[A-Za-z0-9]+\z/', $providerRefundId)) throw new JobExecutionException('refund_response_invalid');
            if (($remote['amount'] ?? null) !== $work['amount_minor']) throw new JobExecutionException('refund_amount_mismatch');
        } catch (Throwable $error) {
            CommerceDatabase::transaction($pdo, function () use ($pdo, $work, $token): void {
                CommerceDatabase::query($pdo, "UPDATE payment_refunds SET status='pending',lease_token=NULL,lease_expires_at=NULL,last_error_code='refund_unavailable',updated_at=? WHERE id=? AND lease_token=? AND status='processing'",
                    [CommerceDatabase::clock($pdo), $work['refund_id'], $token]);
            });
            if ($error instanceof JobExecutionException) throw $error;
            throw new JobExecutionException('refund_unavailable');
        }
        CommerceDatabase::transaction($pdo, function () use ($pdo, $queue, $job, $work, $token, $providerRefundId): void {
            $lock = CommerceDatabase::lock($pdo); $now = CommerceDatabase::clock($pdo);
            $refund = CommerceDatabase::query($pdo, 'SELECT * FROM payment_refunds WHERE id=?' . $lock, [$work['refund_id']])->fetch();
            if (!$refund || $refund['lease_token'] !== $token || $refund['status'] !== 'processing' || (int)$refund['lease_expires_at'] <= $now) throw new JobExecutionException('refund_lease_lost');
            CommerceDatabase::query($pdo, "UPDATE payment_refunds SET status='refunded',provider_refund_id=?,lease_token=NULL,lease_expires_at=NULL,updated_at=? WHERE id=?", [$providerRefundId, $now, $refund['id']]);
            CommerceDatabase::query($pdo, "UPDATE payments SET status='refunded',updated_at=? WHERE id=?", [$now, $work['payment_id']]);
            CommerceDatabase::query($pdo, "UPDATE orders SET payment_status='refunded',updated_at=? WHERE id=?", [$now, $work['order_id']]);
            if (!$queue->acknowledge((int)$job['id'], $job['lease_token'])) throw new JobExecutionException('lease_lost');
        });
    }
}
