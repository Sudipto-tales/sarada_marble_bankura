<?php

/** Sole owner of accepted checkout: all snapshots, stock, result and intents share one commit. */
final class OrderService
{
    public function __construct(private PDO $pdo) {}
    private function query(string $sql,array $args=[]):PDOStatement {return CommerceDatabase::query($this->pdo,$sql,$args);}
    public static function policy():array
    {
        $settings=(new SettingsService($GLOBALS['pdo']))->effective();
        return ['version'=>'inr-exclusive-v1','shipping_minor'=>$settings['shipping_minor'],'tax_bps'=>$settings['tax_bps']];
    }
    /** A review is an explicit accepted quote, never authority for prices or stock. */
    public static function quote(array $cart, ?array $coupon = null): array
    {
        $policy = self::policy(); $lines = []; $amounts = [];
        foreach ($cart['items'] as $item) {
            $quantity = CommerceValues::integer($item['qty_milli'], 1, CommerceValues::MAX_QUANTITY, 'quantity');
            $price = CommerceValues::integer($item['unit_price_minor'], 0, CommerceValues::MAX_MONEY, 'price');
            $lines[(int)$item['variant_id']] = ['qty_milli' => $quantity, 'unit_price_minor' => $price];
            $amounts[] = CommerceValues::line($price, $quantity);
        }
        ksort($lines, SORT_NUMERIC);
        $subtotal = CommerceValues::sum($amounts);
        $discount=$coupon['discount_minor']??0;
        $discount=CommerceValues::integer($discount,0,$subtotal,'discount');
        $tax = CommerceValues::round(CommerceValues::multiply($subtotal-$discount, $policy['tax_bps']), 10000);
        $total = CommerceValues::sum([$subtotal-$discount, $policy['shipping_minor'], $tax]);
        if ($total > CommerceValues::MAX_MONEY) CommerceValues::invalid('total');
        $quote=['policy' => $policy, 'lines' => $lines, 'subtotal_minor' => $subtotal,
            'shipping_minor' => $policy['shipping_minor'], 'tax_minor' => $tax, 'total_minor' => $total];
        if($coupon!==null){$quote['coupon']=$coupon;$quote['discount_minor']=$discount;}
        return $quote;
    }

    /** Floor and remainder of weight*total/divisor without overflowing an intermediate product. */
    private static function share(int $weight,int $total,int $divisor):array
    {
        $quotient=0;$remainder=0;
        foreach(str_split(decbin($total)) as $bit){
            $quotient*=2;$remainder=$remainder*2+($bit==='1'?$weight:0);
            $quotient+=intdiv($remainder,$divisor);$remainder%=$divisor;
        }
        return [$quotient,$remainder];
    }
    private static function allocate(array $weights,int $total):array
    {
        $divisor=CommerceValues::sum(array_values($weights));$parts=array_fill_keys(array_keys($weights),0);$fractions=[];
        if($divisor===0||$total===0)return $parts;
        foreach($weights as $id=>$weight)[$parts[$id],$fractions[$id]]=self::share($weight,$total,$divisor);
        $rank=array_keys($weights);usort($rank,static fn($a,$b)=>$fractions[$b]<=>$fractions[$a]?:$a<=>$b);
        $remaining=$total-CommerceValues::sum(array_values($parts));
        for($i=0;$i<$remaining;$i++)$parts[$rank[$i]]++;
        return $parts;
    }

    public function place(int $userId,int $cartId,int $cartRevision,int $addressId,string $paymentMode,string $key,?array $expectedQuote=null,?int $couponId=null):array
    {
        CommerceAccess::requireActor($userId);$cartId=CommerceValues::id($cartId);$addressId=CommerceValues::id($addressId);
        $cartRevision=CommerceValues::integer($cartRevision,1,PHP_INT_MAX,'revision');$key=CommerceValues::key($key);
        $principal='user:'.$userId;
        $inputs=['contract'=>1,'cart'=>$cartId,'revision'=>$cartRevision,'address'=>$addressId,'payment'=>$paymentMode];
        if ($expectedQuote !== null) { $inputs['contract']=2; $inputs['accepted_quote']=$expectedQuote; }
        if($couponId!==null){$couponId=CommerceValues::id($couponId,'coupon_id');$inputs['coupon_id']=$couponId;}
        $fingerprint=hash('sha256',json_encode($inputs,JSON_THROW_ON_ERROR));
        return CommerceDatabase::transaction($this->pdo,function() use($userId,$cartId,$cartRevision,$addressId,$paymentMode,$expectedQuote,$couponId,$key,$principal,$fingerprint):array {
            CommerceAccess::requireActor($userId);$lock=CommerceDatabase::lock($this->pdo);$now=CommerceDatabase::clock($this->pdo);
            $sql="INSERT INTO checkout_requests (principal_key,idempotency_key,fingerprint,state,created_at) VALUES (?,?,?,'pending',?)";
            $sql.=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE id=id':' ON CONFLICT(principal_key,idempotency_key) DO NOTHING';
            $this->query($sql,[$principal,$key,$fingerprint,$now]);
            $request=$this->query('SELECT * FROM checkout_requests WHERE principal_key=? AND idempotency_key=?'.$lock,[$principal,$key])->fetch();
            if(!$request||$request['fingerprint']!==$fingerprint)throw new RequestRejected(409,'checkout_key_conflict','Checkout key has different inputs.');
            if($request['state']==='completed') {
                // InnoDB repeatable-read snapshots may predate a request that waited on the key.
                $owned=$this->query('SELECT id FROM orders WHERE id=? AND user_id=?'.$lock,[$request['order_id'],$userId])->fetchColumn();
                if(!$owned)throw new RuntimeException('Checkout result is unavailable.');
                return json_decode($request['response_json'],true,16,JSON_THROW_ON_ERROR);
            }
            if($request['state']!=='pending')throw new RequestRejected(409,'checkout_conflict','Checkout cannot be resumed.');
            $paymentStatus=OrderStates::payment($paymentMode);
            $cart=(new CartService($this->pdo))->lockOwned($cartId,$principal);
            if((int)$cart['revision']!==$cartRevision)throw new RequestRejected(409,'revision_conflict','Cart changed. Refresh and retry.');
            $lines=$this->query('SELECT variant_id,qty_milli FROM cart_items WHERE cart_id=? ORDER BY variant_id'.$lock,[$cartId])->fetchAll();
            if(!$lines||count($lines)>50)throw new RequestRejected(422,'empty_cart','Choose between one and 50 cart items.');
            // Current owned address is locked before reservation/catalog rows; no address writer locks carts.
            $address=$this->query('SELECT * FROM addresses WHERE id=? AND user_id=? AND enabled=1'.$lock,[$addressId,$userId])->fetch();
            if(!$address)throw new RequestRejected(404,'not_found','Delivery address not found.');
            $coupons=new CouponService($this->pdo);$coupon=$couponId===null?null:$coupons->locked($couponId);
            $snapshot=array_intersect_key($address,array_flip(['recipient','phone','line1','line2','city','state','postal_code','country_code']));
            $inventory=new InventoryService($this->pdo);
            $hold=$this->query("SELECT * FROM inventory_reservations WHERE cart_id=? AND state='active' ORDER BY id".$lock,[$cartId])->fetchAll();
            if(count($hold)>1)throw new RequestRejected(409,'reservation_conflict','Cart has conflicting reservations.');
            if($hold) {
                $reservation=$hold[0];
                if($reservation['principal_key']!==$principal||(int)$reservation['cart_revision']!==$cartRevision)throw new RequestRejected(409,'reservation_conflict','Reservation does not match this checkout.');
            }else $reservation=$inventory->reserve($principal,$lines,'checkout:'.$request['id'],$cartId,$cartRevision);
            $rows=$inventory->checkoutLines((int)$reservation['id'],$principal,$lines);
            $policy=self::policy();$subtotals=[];
            foreach($rows as $vid=>$row) {
                $price=CommerceValues::integer($row['variant']['unit_price_minor'],0,CommerceValues::MAX_MONEY,'price');
                $subtotals[$vid]=CommerceValues::line($price,$row['quantity']);
            }
            $subtotal=CommerceValues::sum(array_values($subtotals));if($subtotal>CommerceValues::MAX_MONEY)CommerceValues::invalid('subtotal');
            try{$couponPreview=$coupon===null?null:$coupons->previewRow($coupon,$subtotal);}
            catch(RequestRejected $error){if($expectedQuote!==null)throw new RequestRejected(409,'price_conflict','Coupon terms changed. Review your order again.');throw $error;}
            $discount=$couponPreview['discount_minor']??0;$lineDiscount=self::allocate($subtotals,$discount);
            $rates=[];$groups=[];$taxParts=[];
            foreach($rows as $vid=>$row){
                // InventoryService already holds this category lock; capture the resolved rate on the item.
                $categoryRate=$this->query('SELECT tax_bps FROM categories WHERE id=?'.$lock,[$row['product']['category_id']])->fetchColumn();
                $rate=$categoryRate===null?$policy['tax_bps']:CommerceValues::integer($categoryRate,0,10000,'tax_bps');
                $rates[$vid]=$rate;$groups[$rate][$vid]=$subtotals[$vid]-$lineDiscount[$vid];
            }
            foreach($groups as $rate=>$weights){
                $groupTax=CommerceValues::round(CommerceValues::multiply(CommerceValues::sum(array_values($weights)),(int)$rate),10000);
                // Largest remainders are the fractional parts of taxableLine*rate/10000.
                $parts=[];$fractions=[];
                foreach($weights as $vid=>$base){$n=CommerceValues::multiply($base,(int)$rate);$parts[$vid]=intdiv($n,10000);$fractions[$vid]=$n%10000;}
                $rank=array_keys($weights);usort($rank,static fn($a,$b)=>$fractions[$b]<=>$fractions[$a]?:$a<=>$b);
                $extra=$groupTax-CommerceValues::sum(array_values($parts));for($i=0;$i<$extra;$i++)$parts[$rank[$i]]++;
                $taxParts+=$parts;
            }
            $tax=CommerceValues::sum(array_values($taxParts));
            $total=CommerceValues::sum([$subtotal-$discount,$policy['shipping_minor'],$tax]);if($total>CommerceValues::MAX_MONEY)CommerceValues::invalid('total');
            if ($expectedQuote !== null) {
                $current = self::quote(['items' => array_map(static fn($vid, $row) => ['variant_id' => $vid,
                    'qty_milli' => $row['quantity'], 'unit_price_minor' => $row['variant']['unit_price_minor']], array_keys($rows), array_values($rows))],$couponPreview);
                if ($current !== $expectedQuote) throw new RequestRejected(409, 'price_conflict', 'Prices or delivery charges changed. Review your order again.');
            }
            $number='SMB-'.gmdate('Ymd',$now).'-'.str_pad((string)$request['id'],10,'0',STR_PAD_LEFT);
            $this->query("INSERT INTO orders (order_number,user_id,cart_id,status,currency,subtotal_minor,discount_minor,shipping_minor,tax_minor,total_minor,address_snapshot,calculation_policy,tax_bps,payment_mode,payment_status,created_at,updated_at)
                VALUES (?,?,?,'placed','INR',?,?,?,?,?,?,?,?,?,?,?,?)",[$number,$userId,$cartId,$subtotal,$discount,$policy['shipping_minor'],$tax,$total,json_encode($snapshot,JSON_THROW_ON_ERROR),$policy['version'],$policy['tax_bps'],$paymentMode,$paymentStatus,$now,$now]);
            $orderId=(int)$this->pdo->lastInsertId();
            // The FK needs the order identity. Amounts above came from the held coupon row; redeem rechecks
            // limits and recomputes on the same PDO before any transaction can commit the order.
            if($couponId!==null&&$coupons->redeem($this->pdo,$couponId,$userId,$orderId,$subtotal)!==$discount)throw new RuntimeException('Coupon amount changed inside checkout.');
            foreach($rows as $vid=>$row) {
                $variant=$row['variant'];$this->query('INSERT INTO order_items (order_id,product_id,variant_id,name,sku,sell_unit,coverage_sqft_milli,qty_increment_milli,qty_milli,base_price_minor,unit_price_minor,subtotal_minor,discount_minor,tax_minor,line_total_minor,tax_bps) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$orderId,$variant['product_id'],$vid,$row['product']['name'],$variant['sku'],$variant['sell_unit'],$variant['coverage_sqft_milli'],$variant['qty_increment_milli'],$row['quantity'],$variant['unit_price_minor'],$variant['unit_price_minor'],$subtotals[$vid],$lineDiscount[$vid],$taxParts[$vid],CommerceValues::sum([$subtotals[$vid]-$lineDiscount[$vid],$taxParts[$vid]]),$rates[$vid]]);
            }
            // The header/products/variants/stock are already locked; consume adds no new earlier lock.
            $inventory->consume((int)$reservation['id'],$principal,$orderId);
            $this->query("INSERT INTO order_status_history (order_id,from_status,to_status,actor_user_id,reason,operation_key,created_at) VALUES (?,NULL,'placed',?,'Order accepted',?,?)",[$orderId,$userId,'place:'.$request['id'],$now]);
            $producer=new QueueService($this->pdo);
            if($paymentMode!=='razorpay')$producer->orderConfirmation($orderId);
            $producer->purchase($orderId);
            $this->query("UPDATE carts SET status='checked_out',revision=revision+1 WHERE id=?",[$cartId]);
            $result=['id'=>$orderId,'order_number'=>$number,'status'=>'placed','revision'=>1,'currency'=>'INR','subtotal_minor'=>$subtotal,'discount_minor'=>$discount,'shipping_minor'=>$policy['shipping_minor'],'tax_minor'=>$tax,'total_minor'=>$total,'payment_mode'=>$paymentMode,'payment_status'=>$paymentStatus];
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
    /** Staff transitions use a revision and stable operation key; no stock effect at shipment. */
    public function transition(int $actorId, int $id, string $target, int $expectedRevision, string $key, string $reason = 'Order status updated'): array
    {
        CommerceAccess::requirePermission('admin.orders.edit', $actorId);
        $id = CommerceValues::id($id); $key = CommerceValues::key($key);
        $expectedRevision = CommerceValues::integer($expectedRevision, 1, PHP_INT_MAX, 'revision');
        $reason = CommerceValues::text($reason, 190, 'reason');
        return CommerceDatabase::transaction($this->pdo, function () use ($actorId, $id, $target, $expectedRevision, $key, $reason): array {
            CommerceAccess::requirePermission('admin.orders.edit', $actorId);
            $order = $this->query('SELECT * FROM orders WHERE id=?' . CommerceDatabase::lock($this->pdo), [$id])->fetch();
            if (!$order) throw new RequestRejected(404, 'not_found', 'Order not found.');
            $prior = $this->query('SELECT * FROM order_status_history WHERE operation_key=?' . CommerceDatabase::lock($this->pdo), [$key])->fetch();
            if ($prior) {
                if ((int)$prior['order_id'] !== $id || (int)$prior['actor_user_id'] !== $actorId || $prior['to_status'] !== $target || $prior['reason'] !== $reason
                    || !$this->transitionReplay($actorId, $id, $target, $expectedRevision, $key, $reason)) {
                    throw new RequestRejected(409, 'operation_key_conflict', 'Operation key has different inputs.');
                }
                return ['id' => $id, 'status' => $prior['to_status'], 'revision' => $expectedRevision + 1];
            }
            if ((int)$order['revision'] !== $expectedRevision) throw new RequestRejected(409, 'revision_conflict', 'Order changed. Refresh and retry.');
            OrderStates::validate($order['status'], $target);
            if ($target === 'cancelled') (new InventoryService($this->pdo))->restockOrder($id, $actorId);
            $now = CommerceDatabase::clock($this->pdo);
            $this->query('UPDATE orders SET status=?, revision=revision+1, updated_at=? WHERE id=? AND revision=?', [$target, $now, $id, $expectedRevision]);
            $this->query('INSERT INTO order_status_history (order_id,from_status,to_status,actor_user_id,reason,operation_key,created_at) VALUES (?,?,?,?,?,?,?)',
                [$id, $order['status'], $target, $actorId, $reason, $key, $now]);
            StaffAudit::record($this->pdo, $actorId, 'order.transition', 'order', $id, $key, ['status' => $target, 'revision' => $expectedRevision + 1, 'reason' => $reason]);
            if ($target === 'cancelled') (new QueueService($this->pdo))->orderCancelled($id);
            else (new QueueService($this->pdo))->orderStatus($id,$target);
            return ['id' => $id, 'status' => $target, 'revision' => $expectedRevision + 1];
        });
    }

    private function transitionReplay(int $actorId, int $id, string $target, int $revision, string $key, string $reason): bool
    {
        $audit = $this->query('SELECT * FROM admin_activity_log WHERE operation_key=?' . CommerceDatabase::lock($this->pdo), [$key])->fetch();
        if (!$audit || (int)$audit['actor_user_id'] !== $actorId || $audit['action'] !== 'order.transition' || $audit['target_type'] !== 'order' || (int)$audit['target_id'] !== $id) return false;
        $changes = json_decode($audit['changes_json'], true, 8, JSON_THROW_ON_ERROR);
        return ($changes['revision'] ?? null) === $revision + 1 && ($changes['status'] ?? null) === $target && ($changes['reason'] ?? null) === $reason;
    }

    public function cancel(int $actorId, int $id, int $expectedRevision, string $key): array
    {
        return $this->transition($actorId, $id, 'cancelled', $expectedRevision, $key, 'Order cancelled');
    }
}
