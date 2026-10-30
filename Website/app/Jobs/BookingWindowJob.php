<?php

/** The two admin-set booking timers (ADV-BOOK-01), one job per order enqueued at the deadline:
    `payment_window` auto-cancels an order whose advance was never captured and restocks it;
    `verify_window` auto-confirms a paid order the shop did not verify by phone in time.
    Both re-read current state under lock so staff action before the deadline makes the job a no-op.
    Automated history rows are attributed to the order's own user (the only non-null FK available);
    the reason text and `verify_outcome='auto'` record that the change was automatic. */
final class BookingWindowJob
{
    public static function run(PDO $pdo, array $job): void
    {
        $orderId = (int)JobRegistry::decode($job)['order_id'];
        $type = $job['type'];
        $queue = new Queue($pdo);
        CommerceDatabase::transaction($pdo, function () use ($pdo, $job, $queue, $orderId, $type): void {
            $lock = CommerceDatabase::lock($pdo); $now = CommerceDatabase::clock($pdo);
            $ack = function () use ($queue, $job): void {
                if (!$queue->acknowledge((int)$job['id'], $job['lease_token'])) throw new JobExecutionException('lease_lost');
            };
            $order = CommerceDatabase::query($pdo, 'SELECT * FROM orders WHERE id=?' . $lock, [$orderId])->fetch();
            if (!$order) { $ack(); return; }
            if ($type === 'payment_window') {
                if ($order['status'] !== 'placed' || $order['payment_status'] !== 'awaiting_payment') { $ack(); return; }
                $deadline = $order['payment_deadline_at'] === null ? null : (int)$order['payment_deadline_at'];
                if ($deadline === null) { $ack(); return; }
                if ($now < $deadline) throw new JobExecutionException('payment_window_open');
                (new InventoryService($pdo))->restockOrder($orderId, (int)$order['user_id'], true);
                CommerceDatabase::query($pdo, "UPDATE orders SET status='cancelled',revision=revision+1,updated_at=?,payment_deadline_at=NULL WHERE id=?", [$now, $orderId]);
                CommerceDatabase::query($pdo, "INSERT INTO order_status_history (order_id,from_status,to_status,actor_user_id,reason,operation_key,created_at) VALUES (?,?,'cancelled',?,?,?,?)",
                    [$orderId, $order['status'], (int)$order['user_id'], 'Auto-cancelled: advance payment not completed in time', 'autocancel:' . $orderId, $now]);
                (new QueueService($pdo))->orderCancelled($orderId);
                $ack(); return;
            }
            // verify_window
            if ($order['status'] !== 'awaiting_verify') { $ack(); return; }
            $deadline = $order['verify_deadline_at'] === null ? null : (int)$order['verify_deadline_at'];
            if ($deadline === null) { $ack(); return; }
            if ($now < $deadline) throw new JobExecutionException('verify_window_open');
            CommerceDatabase::query($pdo, "UPDATE orders SET status='confirmed',verify_outcome='auto',verified_at=?,verify_deadline_at=NULL,revision=revision+1,updated_at=? WHERE id=?", [$now, $now, $orderId]);
            CommerceDatabase::query($pdo, "INSERT INTO order_status_history (order_id,from_status,to_status,actor_user_id,reason,operation_key,created_at) VALUES (?,'awaiting_verify','confirmed',?,?,?,?)",
                [$orderId, (int)$order['user_id'], 'Auto-confirmed: no verification call within the window', 'autoconfirm:' . $orderId, $now]);
            (new QueueService($pdo))->orderStatus($orderId, 'confirmed');
            $ack();
        });
    }
}
