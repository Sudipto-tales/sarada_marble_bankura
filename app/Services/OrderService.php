<?php

/** Sole owner of accepted checkout: all snapshots, stock, result and intents share one commit. */
final class OrderService
{
    public function __construct(private PDO $pdo) {}
    private function query(string $sql,array $args=[]):PDOStatement {return CommerceDatabase::query($this->pdo,$sql,$args);}
    public static function policy():array
    {
        return ['version'=>'inr-exclusive-v1','shipping_minor'=>CommerceValues::integer((string)env('CHECKOUT_SHIPPING_MINOR','0'),0,CommerceValues::MAX_MONEY,'shipping_minor'),
            'tax_bps'=>CommerceValues::integer((string)env('CHECKOUT_TAX_BPS','0'),0,10000,'tax_bps')];
    }
    public function place(int $userId,int $cartId,int $cartRevision,int $addressId,string $paymentMode,string $key):array
    {
        CommerceAccess::requireActor($userId);$cartId=CommerceValues::id($cartId);$addressId=CommerceValues::id($addressId);
        $cartRevision=CommerceValues::integer($cartRevision,1,PHP_INT_MAX,'revision');$key=CommerceValues::key($key);
        $paymentStatus=OrderStates::payment($paymentMode);$principal='user:'.$userId;
        $fingerprint=hash('sha256',json_encode(['contract'=>1,'cart'=>$cartId,'revision'=>$cartRevision,'address'=>$addressId,'payment'=>$paymentMode],JSON_THROW_ON_ERROR));
        return CommerceDatabase::transaction($this->pdo,function() use($userId,$cartId,$cartRevision,$addressId,$paymentMode,$paymentStatus,$key,$principal,$fingerprint):array {
            CommerceAccess::requireActor($userId);$lock=CommerceDatabase::lock($this->pdo);$now=CommerceDatabase::clock($this->pdo);
            $sql="INSERT INTO checkout_requests (principal_key,idempotency_key,fingerprint,state,created_at) VALUES (?,?,?,'pending',?)";
            $sql.=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE id=id':' ON CONFLICT(principal_key,idempotency_key) DO NOTHING';
            $this->query($sql,[$principal,$key,$fingerprint,$now]);
            $request=$this->query('SELECT * FROM checkout_requests WHERE principal_key=? AND idempotency_key=?'.$lock,[$principal,$key])->fetch();
            if(!$request||$request['fingerprint']!==$fingerprint)throw new RequestRejected(409,'checkout_key_conflict','Checkout key has different inputs.');
            if($request['state']==='completed') {
                $owned=$this->query('SELECT id FROM orders WHERE id=? AND user_id=?',[$request['order_id'],$userId])->fetchColumn();
                if(!$owned)throw new RuntimeException('Checkout result is unavailable.');
                return json_decode($request['response_json'],true,16,JSON_THROW_ON_ERROR);
            }
            if($request['state']!=='pending')throw new RequestRejected(409,'checkout_conflict','Checkout cannot be resumed.');
            $cart=(new CartService($this->pdo))->lockOwned($cartId,$principal);
            if((int)$cart['revision']!==$cartRevision)throw new RequestRejected(409,'revision_conflict','Cart changed. Refresh and retry.');
            $lines=$this->query('SELECT variant_id,qty_milli FROM cart_items WHERE cart_id=? ORDER BY variant_id'.$lock,[$cartId])->fetchAll();
            if(!$lines||count($lines)>50)throw new RequestRejected(422,'empty_cart','Choose between one and 50 cart items.');
            // Current owned address is locked before reservation/catalog rows; no address writer locks carts.
            $address=$this->query('SELECT * FROM addresses WHERE id=? AND user_id=? AND enabled=1'.$lock,[$addressId,$userId])->fetch();
            if(!$address)throw new RequestRejected(404,'not_found','Delivery address not found.');
            $snapshot=array_intersect_key($address,array_flip(['recipient','phone','line1','line2','city','state','postal_code','country_code']));
            $inventory=new InventoryService($this->pdo);
            $hold=$this->query("SELECT * FROM inventory_reservations WHERE cart_id=? AND state='active' ORDER BY id".$lock,[$cartId])->fetchAll();
            if(count($hold)>1)throw new RequestRejected(409,'reservation_conflict','Cart has conflicting reservations.');
            if($hold) {
                $reservation=$hold[0];
                if($reservation['principal_key']!==$principal||(int)$reservation['cart_revision']!==$cartRevision)throw new RequestRejected(409,'reservation_conflict','Reservation does not match this checkout.');
            }else $reservation=$inventory->reserve($principal,$lines,'checkout:'.$request['id'],$cartId,$cartRevision);
            $rows=$inventory->checkoutLines((int)$reservation['id'],$principal,$lines);
            $policy=self::policy();$subtotals=[];$taxParts=[];$fractions=[];
            foreach($rows as $vid=>$row) {
                $price=CommerceValues::integer($row['variant']['unit_price_minor'],0,CommerceValues::MAX_MONEY,'price');
                $subtotals[$vid]=CommerceValues::line($price,$row['quantity']);$numerator=CommerceValues::multiply($subtotals[$vid],$policy['tax_bps']);
                $taxParts[$vid]=intdiv($numerator,10000);$fractions[$vid]=$numerator%10000;
            }
            $subtotal=CommerceValues::sum(array_values($subtotals));if($subtotal>CommerceValues::MAX_MONEY)CommerceValues::invalid('subtotal');
            $tax=CommerceValues::round(CommerceValues::multiply($subtotal,$policy['tax_bps']),10000);
            $remainder=$tax-CommerceValues::sum(array_values($taxParts));
            $rank=array_keys($fractions);usort($rank,fn($a,$b)=>$fractions[$b]<=>$fractions[$a]?:$a<=>$b);
            for($n=0;$n<$remainder;$n++)$taxParts[$rank[$n]]++;
            $total=CommerceValues::sum([$subtotal,$policy['shipping_minor'],$tax]);if($total>CommerceValues::MAX_MONEY)CommerceValues::invalid('total');
            $number='SMB-'.gmdate('Ymd',$now).'-'.str_pad((string)$request['id'],10,'0',STR_PAD_LEFT);
            $this->query("INSERT INTO orders (order_number,user_id,cart_id,status,currency,subtotal_minor,discount_minor,shipping_minor,tax_minor,total_minor,address_snapshot,calculation_policy,tax_bps,payment_mode,payment_status,created_at,updated_at)
                VALUES (?,?,?,'placed','INR',?,0,?,?,?,?,?,?,?,?,?,?)",[$number,$userId,$cartId,$subtotal,$policy['shipping_minor'],$tax,$total,json_encode($snapshot,JSON_THROW_ON_ERROR),$policy['version'],$policy['tax_bps'],$paymentMode,$paymentStatus,$now,$now]);
            $orderId=(int)$this->pdo->lastInsertId();
            foreach($rows as $vid=>$row) {
                $variant=$row['variant'];$this->query('INSERT INTO order_items (order_id,product_id,variant_id,name,sku,sell_unit,coverage_sqft_milli,qty_increment_milli,qty_milli,base_price_minor,unit_price_minor,subtotal_minor,discount_minor,tax_minor,line_total_minor) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)',
                    [$orderId,$variant['product_id'],$vid,$row['product']['name'],$variant['sku'],$variant['sell_unit'],$variant['coverage_sqft_milli'],$variant['qty_increment_milli'],$row['quantity'],$variant['unit_price_minor'],$variant['unit_price_minor'],$subtotals[$vid],$taxParts[$vid],CommerceValues::sum([$subtotals[$vid],$taxParts[$vid]])]);
            }
            // The header/products/variants/stock are already locked; consume adds no new earlier lock.
            $inventory->consume((int)$reservation['id'],$principal,$orderId);
            $this->query("INSERT INTO order_status_history (order_id,from_status,to_status,actor_user_id,reason,operation_key,created_at) VALUES (?,NULL,'placed',?,'Order accepted',?,?)",[$orderId,$userId,'place:'.$request['id'],$now]);
            $producer=new QueueService($this->pdo);$producer->orderConfirmation($orderId);$producer->purchase($orderId);
            $this->query("UPDATE carts SET status='checked_out',revision=revision+1 WHERE id=?",[$cartId]);
            $result=['id'=>$orderId,'order_number'=>$number,'status'=>'placed','revision'=>1,'currency'=>'INR','subtotal_minor'=>$subtotal,'discount_minor'=>0,'shipping_minor'=>$policy['shipping_minor'],'tax_minor'=>$tax,'total_minor'=>$total,'payment_mode'=>$paymentMode,'payment_status'=>$paymentStatus];
            $this->query("UPDATE checkout_requests SET state='completed',order_id=?,response_json=? WHERE id=?",[$orderId,json_encode($result,JSON_THROW_ON_ERROR),$request['id']]);return $result;
        });
    }
    private function hydrate(array $order):array
    {
        $order['items']=$this->query('SELECT * FROM order_items WHERE order_id=? ORDER BY variant_id',[$order['id']])->fetchAll();
        $order['history']=$this->query('SELECT from_status,to_status,reason,created_at FROM order_status_history WHERE order_id=? ORDER BY id',[$order['id']])->fetchAll();
        $order['address']=json_decode($order['address_snapshot'],true,8,JSON_THROW_ON_ERROR);unset($order['address_snapshot']);return $order;
    }
    public function owned(int $userId,int $id):array
    {
        CommerceAccess::requireActor($userId);$order=$this->query('SELECT * FROM orders WHERE id=? AND user_id=?',[$id,$userId])->fetch();
        if(!$order)throw new RequestRejected(404,'not_found','Order not found.');return $this->hydrate($order);
    }
    public function listing(int $userId,int $page=1):array
    {
        CommerceAccess::requireActor($userId);$page=CommerceValues::integer($page,1,1000,'page');$offset=($page-1)*20;
        return $this->query("SELECT id,order_number,status,revision,currency,total_minor,payment_mode,payment_status,created_at FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 20 OFFSET $offset",[$userId])->fetchAll();
    }
    public function staffDetail(int $actorId,int $id):array
    {
        CommerceAccess::requirePermission('admin.orders.view',$actorId);$order=$this->query('SELECT * FROM orders WHERE id=?',[$id])->fetch();
        if(!$order)throw new RequestRejected(404,'not_found','Order not found.');return $this->hydrate($order);
    }
}
