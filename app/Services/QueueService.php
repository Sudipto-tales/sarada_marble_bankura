<?php

/** Typed durable intents; the caller owns commit/rollback on this exact PDO. */
final class QueueService
{
    private Queue $queue;
    public function __construct(PDO $pdo) {$this->queue=new Queue($pdo);}
    public function orderConfirmation(int $id):int {return $this->queue->enqueue('emails','order_confirmation',1,['order_id'=>$id],'order:'.$id.':confirmation:v1');}
    public function orderCancelled(int $id):int {return $this->queue->enqueue('emails','order_cancelled',1,['order_id'=>$id],'order:'.$id.':cancelled:v1');}
    public function orderStatus(int $id,string $status):int
    {
        if(!in_array($status,array_values(OrderStates::NEXT),true))CommerceValues::invalid('order_status');
        return $this->queue->enqueue('emails','order_status',1,['order_id'=>$id],'order:'.$id.':status:'.$status.':v1');
    }
    public function purchase(int $id):int {return $this->queue->enqueue('purchase_events','purchase_event',1,['order_id'=>$id],'order:'.$id.':purchase:v1');}
    public function reservationExpiry(int $id,?int $availableAt=null):int {return $this->queue->enqueue('maintenance','reservation_expiry',1,['reservation_id'=>$id],'reservation:'.$id.':expiry:v1',$availableAt);}
    public function lowStockAlert(int $id):int {return $this->queue->enqueue('emails','low_stock_alert',1,['alert_id'=>$id],'low_stock:'.$id.':v1');}
    public function verification(int $id):int {return $this->queue->enqueue('emails','auth_verification',1,['verification_id'=>$id],'verification:'.$id.':v1');}
}
