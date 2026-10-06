<?php

/** Typed durable intents; the caller owns commit/rollback on this exact PDO. */
final class QueueService
{
    private Queue $queue;
    public function __construct(PDO $pdo) {$this->queue=new Queue($pdo);}
    public function orderConfirmation(int $id):int {return $this->queue->enqueue('emails','order_confirmation',1,['order_id'=>$id],'order:'.$id.':confirmation:v1');}
    public function orderCancelled(int $id):int {return $this->queue->enqueue('emails','order_cancelled',1,['order_id'=>$id],'order:'.$id.':cancelled:v1');}
    public function purchase(int $id):int {return $this->queue->enqueue('purchase_events','purchase_event',1,['order_id'=>$id],'order:'.$id.':purchase:v1');}
    public function reservationExpiry(int $id):int {return $this->queue->enqueue('maintenance','reservation_expiry',1,['reservation_id'=>$id],'reservation:'.$id.':expiry:v1');}
    public function verification(int $id):int {return $this->queue->enqueue('emails','auth_verification',1,['verification_id'=>$id],'verification:'.$id.':v1');}
}
