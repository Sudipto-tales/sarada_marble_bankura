<?php

/** Reserve/consume/release use a caller-owned transaction; adjustment owns its transaction. */
final class InventoryService
{
    public function __construct(private PDO $pdo) {}
    private function query(string $sql,array $values=[]):PDOStatement { return CommerceDatabase::query($this->pdo,$sql,$values); }
    private function transactionRequired():void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Inventory operation requires the caller transaction.');
    }
    private function principal(string $principal):void
    {
        if (preg_match('/\Auser:([1-9][0-9]{0,9})\z/',$principal,$match)) CommerceAccess::requireActor(CommerceValues::id($match[1]));
        elseif (!preg_match('/\Aguest:[a-f0-9]{64}\z/',$principal)) CommerceValues::invalid('principal');
    }
    public static function initialize(PDO $pdo,int $productId):void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Stock setup must be atomic with product creation.');
        $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $sql=$mysql
            ? 'INSERT INTO inventory (product_id,on_hand_milli,reserved_milli,revision) VALUES (?,0,0,1) ON DUPLICATE KEY UPDATE product_id=product_id'
            : 'INSERT INTO inventory (product_id,on_hand_milli,reserved_milli,revision) VALUES (?,0,0,1) ON CONFLICT(product_id) DO NOTHING';
        CommerceDatabase::query($pdo,$sql,[$productId]);
    }
    private function lines(array $input):array
    {
        if (!$input || count($input)>50) CommerceValues::invalid('lines');
        $lines=[];
        foreach($input as $row) {
            if (!is_array($row)) CommerceValues::invalid('lines');
            $id=CommerceValues::id($row['product_id']??null,'product_id');
            if(isset($lines[$id])) CommerceValues::invalid('product_id');
            $lines[$id]=CommerceValues::integer($row['qty_milli']??null,1,CommerceValues::MAX_QUANTITY,'qty_milli');
        }
        ksort($lines,SORT_NUMERIC); return $lines;
    }
    private function lockedLines(array $lines,bool $sellable):array
    {
        $ids=array_keys($lines); $marks=implode(',',array_fill(0,count($ids),'?')); $lock=CommerceDatabase::lock($this->pdo);
        $products=$this->query("SELECT * FROM products WHERE id IN ($marks) ORDER BY id".$lock,$ids)->fetchAll();
        $products=array_column($products,null,'id');
        $categories=[];$brands=[];
        if($sellable) {
            $categoryIds=array_values(array_unique(array_column($products,'category_id')));sort($categoryIds,SORT_NUMERIC);
            foreach($categoryIds as $categoryId)$categories[$categoryId]=$this->query('SELECT enabled FROM categories WHERE id=?'.$lock,[$categoryId])->fetchColumn();
            $brandIds=array_values(array_unique(array_filter(array_column($products,'brand_id'))));sort($brandIds,SORT_NUMERIC);
            foreach($brandIds as $brandId)$brands[$brandId]=$this->query('SELECT enabled FROM brands WHERE id=?'.$lock,[$brandId])->fetchColumn();
        }
        $stocks=$this->query("SELECT * FROM inventory WHERE product_id IN ($marks) ORDER BY product_id".$lock,$ids)->fetchAll();
        $stocks=array_column($stocks,null,'product_id'); $result=[];
        foreach($lines as $productId=>$row) {
            $quantity=$lines[$productId];
            $product=$products[$productId]??null; $stock=$stocks[$productId]??null;
            if(!$stock || !$product) throw new RequestRejected(409,'stock_unavailable','Stock setup is required.');
            if($sellable) {
                $category=$categories[$product['category_id']]??0;
                $brand=$product['brand_id']?($brands[$product['brand_id']]??0):1;
                if($product['status']!=='active') throw new RequestRejected(409,'item_unavailable','An item is unavailable.');
                CommerceValues::quantity($quantity,$row);
            }
            if((int)$stock['reserved_milli']<0 || (int)$stock['on_hand_milli']<(int)$stock['reserved_milli']) throw new RuntimeException('Inventory invariant failed.');
            $result[$productId]=['product'=>$product,'stock'=>$stock,'quantity'=>$quantity];
        }
        return $result;
    }
    private function stock(array $stock,int $onHand,int $reserved):void
    {
        if($reserved<0||$onHand<$reserved||$onHand>CommerceValues::MAX_QUANTITY) throw new RequestRejected(409,'stock_conflict','Stock cannot cover this operation.');
        $count=$this->query('UPDATE inventory SET on_hand_milli=?,reserved_milli=?,revision=revision+1 WHERE product_id=? AND on_hand_milli=? AND reserved_milli=?',
            [$onHand,$reserved,$stock['product_id'],$stock['on_hand_milli'],$stock['reserved_milli']])->rowCount();
        if($count!==1) throw new RequestRejected(409,'stock_conflict','Stock changed. Retry the operation.');
    }
    private function movement(int $product,int $onHand,int $reserved,string $reason,string $key,?int $actor=null,?int $reservation=null,?int $order=null):void
    {
        $this->query('INSERT INTO inventory_movements (product_id,on_hand_delta_milli,reserved_delta_milli,reason,actor_user_id,order_id,reservation_id,business_key,created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$product,$onHand,$reserved,$reason,$actor,$order,$reservation,$key,CommerceDatabase::clock($this->pdo)]);
    }
    private function reservation(int $id):array
    {
        $row=$this->query('SELECT * FROM inventory_reservations WHERE id=?',[$id])->fetch();
        if(!$row) throw new RequestRejected(404,'not_found','Reservation not found.');
        $row['items']=$this->query('SELECT product_id,qty_milli FROM inventory_reservation_items WHERE reservation_id=? ORDER BY product_id',$id)->fetchAll();
        return $row;
    }
    public function reserve(string $principal,array $input,string $key,?int $cartId=null,?int $cartRevision=null,int $ttl=900):array
    {
        $this->transactionRequired(); $this->principal($principal); $key=CommerceValues::key($key); $lines=$this->lines($input);
        $ttl=CommerceValues::integer($ttl,60,3600,'reservation_ttl');
        if($cartId!==null) {
            if(!class_exists('CartService')) throw new RequestRejected(503,'cart_unavailable','Cart integration is unavailable.');
            $cart=(new CartService($this->pdo))->lockOwned($cartId,$principal);
            if((int)$cart['revision']!==$cartRevision) throw new RequestRejected(409,'revision_conflict','Cart changed.');
            $held=$this->query("SELECT business_key FROM inventory_reservations WHERE cart_id=? AND state='active'".CommerceDatabase::lock($this->pdo),[$cartId])->fetchColumn();
            if($held!==false&&$held!==$key) throw new RequestRejected(409,'cart_reserved','Cart already has a checkout reservation.');
        }
        $fingerprint=hash('sha256',json_encode(['v'=>1,'principal'=>$principal,'cart'=>$cartId,'revision'=>$cartRevision,'ttl'=>$ttl,'lines'=>$lines],JSON_THROW_ON_ERROR));
        $now=CommerceDatabase::clock($this->pdo);
        $sql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? "INSERT INTO inventory_reservations (principal_key,product_id,cart_id,cart_revision,business_key,fingerprint,state,expires_at,created_at) VALUES (?,?,?,?,?,'active',?,?) ON DUPLICATE KEY UPDATE id=id"
            : "INSERT INTO inventory_reservations (principal_key,product_id,cart_id,cart_revision,business_key,fingerprint,state,expires_at,created_at) VALUES (?,?,?,?,?,'active',?,?) ON CONFLICT(business_key) DO NOTHING";
        $created=$this->query($sql,[$principal,$cartId??null,$cartRevision,$key,$fingerprint,$now+$ttl,$now])->rowCount()===1;
        $header=$this->query('SELECT * FROM inventory_reservations WHERE business_key=?'.CommerceDatabase::lock($this->pdo),[$key])->fetch();
        if(!$header||$header['fingerprint']!==$fingerprint) throw new RequestRejected(409,'reservation_key_conflict','Reservation key has different inputs.');
        if(!$created) {
            if($header['state']==='active'&&(int)$header['expires_at']<=$now) throw new RequestRejected(409,'reservation_expired','Reservation expired.');
            return $this->reservation((int)$header['id']);
        }
        $locked=$this->lockedLines($lines,true);
        foreach($locked as $product=>$row) if((int)$row['stock']['on_hand_milli']-(int)$row['stock']['reserved_milli']<$row['quantity']) throw new RequestRejected(409,'insufficient_stock','Requested stock is unavailable.');
        $id=(int)$header['id'];
        foreach($locked as $product=>$row) {
            $stock=$row['stock']; $quantity=$row['quantity'];
            $this->stock($stock,(int)$stock['on_hand_milli'],(int)$stock['reserved_milli']+$quantity);
            $this->query('INSERT INTO inventory_reservation_items (reservation_id,product_id,qty_milli) VALUES (?,?,?)',[$id,$product,$quantity]);
            $this->movement($product,0,$quantity,'reserve','reserve:'.$id,null,$id);
        }
        (new QueueService($this->pdo))->reservationExpiry($id,$now+$ttl);
        ProductService::advanceVersion($this->pdo); return $this->reservation($id);
    }
    public function consume(int $id,string $principal,?int $orderId=null):array { return $this->close($id,'consumed',$principal,$orderId); }
    /** Release an active reservation only once its lease has actually expired. Idempotent for a replayed job. */
    public function expireReservation(int $id):string
    {
        $this->transactionRequired(); $id=CommerceValues::id($id);
        $header=$this->query('SELECT * FROM inventory_reservations WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetch();
        if(!$header) return 'missing';
        if($header['state']!=='active') return 'closed';
        if((int)$header['expires_at']>CommerceDatabase::clock($this->pdo)) return 'active';
        $this->close($id,'expired',$header['principal_key'],null); return 'expired';
    }
    /** Edge-triggered low-stock alert. Default-off (threshold 0); fires once per dip until stock recovers. */
    private function maybeLowStock(int $productId,int $newOnHand,int $flag):void
    {
        $threshold=CommerceValues::integer((string)env('INVENTORY_LOW_STOCK_THRESHOLD_MILLI','0'),0,CommerceValues::MAX_QUANTITY,'low_stock_threshold');
        if($threshold===0) return;
        if($newOnHand<=$threshold&&$flag===0) {
            $this->query('INSERT INTO low_stock_alerts (product_id,threshold_milli,on_hand_milli,created_at) VALUES (?,?,?,?)',[$productId,$threshold,$newOnHand,CommerceDatabase::clock($this->pdo)]);
            $alertId=(int)$this->pdo->lastInsertId();
            $this->query('UPDATE inventory SET low_stock_notified=1 WHERE product_id=?',[$productId]);
            (new QueueService($this->pdo))->lowStockAlert($alertId);
        } elseif($newOnHand>$threshold&&$flag===1) {
            $this->query('UPDATE inventory SET low_stock_notified=0 WHERE product_id=?',[$productId]);
        }
    }
    /** Validate exact owned held quantities and expose current locked server quotes. */
    public function checkoutLines(int $id,string $principal,array $expected):array
    {
        $this->transactionRequired();$this->principal($principal);$lines=$this->lines($expected);
        $header=$this->query('SELECT * FROM inventory_reservations WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetch();
        if(!$header||$header['principal_key']!==$principal)throw new RequestRejected(404,'not_found','Reservation not found.');
        if($header['state']!=='active'||(int)$header['expires_at']<=CommerceDatabase::clock($this->pdo))throw new RequestRejected(409,'reservation_expired','Checkout reservation is closed or expired.');
        $items=$this->query('SELECT product_id,qty_milli FROM inventory_reservation_items WHERE reservation_id=? ORDER BY product_id'.CommerceDatabase::lock($this->pdo),[$id])->fetchAll();
        if($this->lines($items)!==$lines)throw new RequestRejected(409,'reservation_conflict','Reservation does not match this cart.');
        $rows=$this->lockedLines($lines,true);
        foreach($rows as $row)if((int)$row['stock']['reserved_milli']<$row['quantity']||(int)$row['stock']['on_hand_milli']<$row['quantity'])throw new RuntimeException('Reserved stock invariant failed.');
        return $rows;
    }
    public function release(int $id,string $principal,bool $expire=false):array { return $this->close($id,$expire?'expired':'released',$principal,null); }
    private function close(int $id,string $state,string $principal,?int $orderId):array
    {
        $this->transactionRequired();
        if ($state==='consumed') $this->principal($principal);
        $header=$this->query('SELECT * FROM inventory_reservations WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetch();
        if(!$header||$header['principal_key']!==$principal) throw new RequestRejected(404,'not_found','Reservation not found.');
        if($header['state']===$state) {
            if ($state==='consumed') {
                $orders=$this->query('SELECT DISTINCT order_id FROM inventory_movements WHERE reservation_id=? AND business_key=?',[$id,'consume:'.$id])->fetchAll(PDO::FETCH_COLUMN);
                foreach($orders as $order) if(($order===null?null:(int)$order)!==$orderId) throw new RequestRejected(409,'reservation_state_conflict','Reservation belongs to another order effect.');
            }
            return $this->reservation($id);
        }
        if($header['state']!=='active') throw new RequestRejected(409,'reservation_state_conflict','Reservation is already closed.');
        $now=CommerceDatabase::clock($this->pdo);
        if($state==='consumed'&&(int)$header['expires_at']<=$now) throw new RequestRejected(409,'reservation_expired','Reservation expired.');
        if($state==='expired'&&(int)$header['expires_at']>$now) throw new RequestRejected(409,'reservation_not_expired','Reservation is still active.');
        $items=$this->query('SELECT product_id,qty_milli FROM inventory_reservation_items WHERE reservation_id=? ORDER BY product_id',$id)->fetchAll();
        $locked=$this->lockedLines($this->lines($items),$state==='consumed');
        foreach($locked as $product=>$row) if((int)$row['stock']['reserved_milli']<$row['quantity']||(int)$row['stock']['on_hand_milli']<$row['quantity']) throw new RuntimeException('Reserved stock invariant failed.');
        foreach($locked as $product=>$row) {
            $quantity=$row['quantity']; $stock=$row['stock']; $delta=$state==='consumed'?-$quantity:0;
            $this->stock($stock,(int)$stock['on_hand_milli']+$delta,(int)$stock['reserved_milli']-$quantity);
            $this->movement($product,$delta,-$quantity,$state,($state==='consumed'?'consume:':'release:').$id,null,$id,$orderId);
            if($state==='consumed') $this->maybeLowStock($product,(int)$stock['on_hand_milli']+$delta,(int)($stock['low_stock_notified']??0));
        }
        $this->query('UPDATE inventory_reservations SET state=? WHERE id=?',[$state,$id]);
        ProductService::advanceVersion($this->pdo); return $this->reservation($id);
    }
    /** Caller has locked the order. Restore consumed stock once, leaving other reservations intact. */
    public function restockOrder(int $orderId, int $actorId, bool $system = false): void
    {
        $this->transactionRequired();
        if (!$system) CommerceAccess::requirePermission('admin.orders.edit', $actorId);
        $lock = CommerceDatabase::lock($this->pdo);
        $headers = $this->query('SELECT * FROM inventory_reservations WHERE id IN (SELECT reservation_id FROM inventory_movements WHERE order_id=?) ORDER BY id' . $lock, [$orderId])->fetchAll();
        if (count($headers) !== 1 || $headers[0]['state'] !== 'consumed') throw new RequestRejected(409, 'stock_inconsistent', 'Order stock history is unavailable.');
        $reservationId = (int)$headers[0]['id'];
        $items = $this->query('SELECT product_id,qty_milli FROM order_items WHERE order_id=? ORDER BY product_id', [$orderId])->fetchAll();
        $rows = $this->lockedLines($this->lines($items), false);
        $movements = $this->query('SELECT * FROM inventory_movements WHERE order_id=? AND business_key=? ORDER BY product_id', [$orderId, 'consume:' . $reservationId])->fetchAll();
        $consumed = array_column($movements, null, 'product_id');
        if (count($consumed) !== count($rows)) throw new RequestRejected(409, 'stock_inconsistent', 'Order stock history is incomplete.');
        // Validate every line before restoring any stock; the surrounding transaction owns rollback.
        foreach ($rows as $product => $row) {
            $movement = $consumed[$product] ?? null;
            if (!$movement || (int)$movement['on_hand_delta_milli'] !== -$row['quantity'] || (int)$movement['reserved_delta_milli'] !== -$row['quantity']
                || (int)$row['stock']['on_hand_milli'] > CommerceValues::MAX_QUANTITY - $row['quantity']) throw new RequestRejected(409, 'stock_inconsistent', 'Order stock cannot be restored.');
            if ($this->query('SELECT id FROM inventory_movements WHERE business_key=? AND product_id=?', ['cancel:' . $orderId, $product])->fetchColumn()) throw new RequestRejected(409, 'stock_inconsistent', 'Order stock was already restored.');
        }
        foreach ($rows as $product => $row) {
            $this->stock($row['stock'], (int)$row['stock']['on_hand_milli'] + $row['quantity'], (int)$row['stock']['reserved_milli']);
            $this->movement($product, $row['quantity'], 0, 'cancelled', 'cancel:' . $orderId, $actorId, $reservationId, $orderId);
            $this->maybeLowStock($product, (int)$row['stock']['on_hand_milli'] + $row['quantity'], (int)($row['stock']['low_stock_notified'] ?? 0));
        }
        ProductService::advanceVersion($this->pdo);
    }
    public function adjust(int $actorId,int $productId,int $delta,string $reason,string $key,?int $expectedRevision=null):array
    {
        CommerceAccess::requirePermission('admin.inventory.adjust',$actorId); $key=CommerceValues::key($key);
        $delta=CommerceValues::integer($delta,-CommerceValues::MAX_QUANTITY,CommerceValues::MAX_QUANTITY,'delta_milli');
        if($delta===0) CommerceValues::invalid('delta_milli'); $reason=CommerceValues::text($reason,190,'reason');
        return CommerceDatabase::transaction($this->pdo,function() use($actorId,$productId,$delta,$reason,$key,$expectedRevision):array {
            CommerceAccess::requirePermission('admin.inventory.adjust',$actorId);
            $row=$this->lockedLines([$productId=>1],false)[$productId]; $stock=$row['stock'];
            $prior=$this->query('SELECT * FROM inventory_movements WHERE business_key=? AND product_id=?'.CommerceDatabase::lock($this->pdo),[$key,$productId])->fetch();
            if($prior) {
                if((int)$prior['on_hand_delta_milli']!==$delta||(int)$prior['reserved_delta_milli']!==0||$prior['reason']!==$reason||(int)$prior['actor_user_id']!==$actorId) throw new RequestRejected(409,'operation_key_conflict','Adjustment key has different inputs.');
                return $stock;
            }
            if($expectedRevision!==null && (int)$stock['revision']!==$expectedRevision) throw new RequestRejected(409,'revision_conflict','Stock changed. Reload before adjusting.');
            CommerceValues::quantity(abs($delta),$row['product']);
            $this->stock($stock,(int)$stock['on_hand_milli']+$delta,(int)$stock['reserved_milli']);
            $this->movement($productId,$delta,0,$reason,$key,$actorId);
            $this->maybeLowStock($productId,(int)$stock['on_hand_milli']+$delta,(int)($stock['low_stock_notified']??0));
            ProductService::advanceVersion($this->pdo);
            StaffAudit::record($this->pdo,$actorId,'inventory.adjust','product',$productId,$key,['delta_milli'=>$delta,'reason'=>$reason,'on_hand_milli'=>(int)$stock['on_hand_milli']+$delta]);
            return $this->query('SELECT * FROM inventory WHERE product_id=?',[$productId])->fetch();
        });
    }
    public function staffListing(int $actorId,int $page=1):array
    {
        CommerceAccess::requirePermission('admin.inventory.view',$actorId); $page=CommerceValues::integer($page,1,1000,'page'); $offset=($page-1)*30;
        return $this->query("SELECT i.*,p.name FROM inventory i JOIN products p ON p.id=i.product_id ORDER BY i.product_id LIMIT 30 OFFSET $offset")->fetchAll();
    }
}