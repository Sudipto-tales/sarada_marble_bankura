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
    public static function initialize(PDO $pdo,int $variantId):void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Stock setup must be atomic with variant creation.');
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? 'INSERT INTO inventory (variant_id,on_hand_milli,reserved_milli,revision) VALUES (?,0,0,1) ON DUPLICATE KEY UPDATE variant_id=variant_id'
            : 'INSERT INTO inventory (variant_id,on_hand_milli,reserved_milli,revision) VALUES (?,0,0,1) ON CONFLICT(variant_id) DO NOTHING';
        CommerceDatabase::query($pdo,$sql,[$variantId]);
    }
    private function lines(array $input):array
    {
        if (!$input || count($input)>50) CommerceValues::invalid('lines');
        $lines=[];
        foreach($input as $row) {
            if (!is_array($row)) CommerceValues::invalid('lines');
            $id=CommerceValues::id($row['variant_id']??null,'variant_id');
            if(isset($lines[$id])) CommerceValues::invalid('variant_id');
            $lines[$id]=CommerceValues::integer($row['qty_milli']??null,1,CommerceValues::MAX_QUANTITY,'qty_milli');
        }
        ksort($lines,SORT_NUMERIC); return $lines;
    }
    /** Product -> variant -> stock, each group sorted by its identity. */
    private function lockedLines(array $lines,bool $sellable):array
    {
        $ids=array_keys($lines); $marks=implode(',',array_fill(0,count($ids),'?')); $lock=CommerceDatabase::lock($this->pdo);
        $refs=$this->query("SELECT id,product_id FROM product_variants WHERE id IN ($marks) ORDER BY id",$ids)->fetchAll();
        if(count($refs)!==count($ids)) throw new RequestRejected(404,'not_found','Variant not found.');
        $productIds=array_values(array_unique(array_column($refs,'product_id'))); sort($productIds,SORT_NUMERIC);
        $productMarks=implode(',',array_fill(0,count($productIds),'?'));
        $products=$this->query("SELECT * FROM products WHERE id IN ($productMarks) ORDER BY id".$lock,$productIds)->fetchAll();
        $products=array_column($products,null,'id');
        $categories=[];$brands=[];
        if($sellable) {
            $categoryIds=array_values(array_unique(array_column($products,'category_id')));sort($categoryIds,SORT_NUMERIC);
            foreach($categoryIds as $categoryId)$categories[$categoryId]=$this->query('SELECT enabled FROM categories WHERE id=?'.$lock,[$categoryId])->fetchColumn();
            $brandIds=array_values(array_unique(array_filter(array_column($products,'brand_id'))));sort($brandIds,SORT_NUMERIC);
            foreach($brandIds as $brandId)$brands[$brandId]=$this->query('SELECT enabled FROM brands WHERE id=?'.$lock,[$brandId])->fetchColumn();
        }
        $variants=$this->query("SELECT * FROM product_variants WHERE id IN ($marks) ORDER BY id".$lock,$ids)->fetchAll();
        $stocks=$this->query("SELECT * FROM inventory WHERE variant_id IN ($marks) ORDER BY variant_id".$lock,$ids)->fetchAll();
        $stocks=array_column($stocks,null,'variant_id'); $result=[];
        foreach($variants as $variant) {
            $id=(int)$variant['id']; $product=$products[$variant['product_id']]??null; $stock=$stocks[$id]??null;
            if(!$stock || !$product) throw new RequestRejected(409,'stock_unavailable','Stock setup is required.');
            if($sellable) {
                $category=$categories[$product['category_id']]??0;
                $brand=$product['brand_id']?($brands[$product['brand_id']]??0):1;
                if($product['status']!=='active'||!(int)$variant['enabled']||!(int)$category||!(int)$brand) throw new RequestRejected(409,'item_unavailable','An item is unavailable.');
                CommerceValues::quantity($lines[$id],$variant);
            }
            if((int)$stock['reserved_milli']<0 || (int)$stock['on_hand_milli']<(int)$stock['reserved_milli']) throw new RuntimeException('Inventory invariant failed.');
            $result[$id]=['product'=>$product,'variant'=>$variant,'stock'=>$stock,'quantity'=>$lines[$id]];
        }
        return $result;
    }
    private function stock(array $stock,int $onHand,int $reserved):void
    {
        if($reserved<0||$onHand<$reserved||$onHand>CommerceValues::MAX_QUANTITY) throw new RequestRejected(409,'stock_conflict','Stock cannot cover this operation.');
        $count=$this->query('UPDATE inventory SET on_hand_milli=?,reserved_milli=?,revision=revision+1 WHERE variant_id=? AND on_hand_milli=? AND reserved_milli=?',
            [$onHand,$reserved,$stock['variant_id'],$stock['on_hand_milli'],$stock['reserved_milli']])->rowCount();
        if($count!==1) throw new RequestRejected(409,'stock_conflict','Stock changed. Retry the operation.');
    }
    private function movement(int $variant,int $onHand,int $reserved,string $reason,string $key,?int $actor=null,?int $reservation=null,?int $order=null):void
    {
        $this->query('INSERT INTO inventory_movements (variant_id,on_hand_delta_milli,reserved_delta_milli,reason,actor_user_id,order_id,reservation_id,business_key,created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$variant,$onHand,$reserved,$reason,$actor,$order,$reservation,$key,CommerceDatabase::clock($this->pdo)]);
    }
    private function reservation(int $id):array
    {
        $row=$this->query('SELECT * FROM inventory_reservations WHERE id=?',[$id])->fetch();
        if(!$row) throw new RequestRejected(404,'not_found','Reservation not found.');
        $row['items']=$this->query('SELECT variant_id,qty_milli FROM inventory_reservation_items WHERE reservation_id=? ORDER BY variant_id',[$id])->fetchAll();
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
            ? "INSERT INTO inventory_reservations (principal_key,cart_id,cart_revision,business_key,fingerprint,state,expires_at,created_at) VALUES (?,?,?,?,?,'active',?,?) ON DUPLICATE KEY UPDATE id=id"
            : "INSERT INTO inventory_reservations (principal_key,cart_id,cart_revision,business_key,fingerprint,state,expires_at,created_at) VALUES (?,?,?,?,?,'active',?,?) ON CONFLICT(business_key) DO NOTHING";
        $created=$this->query($sql,[$principal,$cartId,$cartRevision,$key,$fingerprint,$now+$ttl,$now])->rowCount()===1;
        $header=$this->query('SELECT * FROM inventory_reservations WHERE business_key=?'.CommerceDatabase::lock($this->pdo),[$key])->fetch();
        if(!$header||$header['fingerprint']!==$fingerprint) throw new RequestRejected(409,'reservation_key_conflict','Reservation key has different inputs.');
        if(!$created) {
            if($header['state']==='active'&&(int)$header['expires_at']<=$now) throw new RequestRejected(409,'reservation_expired','Reservation expired.');
            return $this->reservation((int)$header['id']);
        }
        $locked=$this->lockedLines($lines,true);
        foreach($locked as $row) if((int)$row['stock']['on_hand_milli']-(int)$row['stock']['reserved_milli']<$row['quantity']) throw new RequestRejected(409,'insufficient_stock','Requested stock is unavailable.');
        $id=(int)$header['id'];
        foreach($locked as $variant=>$row) {
            $stock=$row['stock']; $quantity=$row['quantity'];
            $this->stock($stock,(int)$stock['on_hand_milli'],(int)$stock['reserved_milli']+$quantity);
            $this->query('INSERT INTO inventory_reservation_items (reservation_id,variant_id,qty_milli) VALUES (?,?,?)',[$id,$variant,$quantity]);
            $this->movement($variant,0,$quantity,'reserve','reserve:'.$id,null,$id);
        }
        ProductService::advanceVersion($this->pdo); return $this->reservation($id);
    }
    public function consume(int $id,string $principal,?int $orderId=null):array { return $this->close($id,'consumed',$principal,$orderId); }
    /** Validate exact owned held quantities and expose current locked server quotes. */
    public function checkoutLines(int $id,string $principal,array $expected):array
    {
        $this->transactionRequired();$this->principal($principal);$lines=$this->lines($expected);
        $header=$this->query('SELECT * FROM inventory_reservations WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetch();
        if(!$header||$header['principal_key']!==$principal)throw new RequestRejected(404,'not_found','Reservation not found.');
        if($header['state']!=='active'||(int)$header['expires_at']<=CommerceDatabase::clock($this->pdo))throw new RequestRejected(409,'reservation_expired','Checkout reservation is closed or expired.');
        $items=$this->query('SELECT variant_id,qty_milli FROM inventory_reservation_items WHERE reservation_id=? ORDER BY variant_id'.CommerceDatabase::lock($this->pdo),[$id])->fetchAll();
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
        $items=$this->query('SELECT variant_id,qty_milli FROM inventory_reservation_items WHERE reservation_id=? ORDER BY variant_id',[$id])->fetchAll();
        $locked=$this->lockedLines($this->lines($items),$state==='consumed');
        foreach($locked as $row) if((int)$row['stock']['reserved_milli']<$row['quantity']||(int)$row['stock']['on_hand_milli']<$row['quantity']) throw new RuntimeException('Reserved stock invariant failed.');
        foreach($locked as $variant=>$row) {
            $quantity=$row['quantity']; $stock=$row['stock']; $delta=$state==='consumed'?-$quantity:0;
            $this->stock($stock,(int)$stock['on_hand_milli']+$delta,(int)$stock['reserved_milli']-$quantity);
            $this->movement($variant,$delta,-$quantity,$state,($state==='consumed'?'consume:':'release:').$id,null,$id,$orderId);
        }
        $this->query('UPDATE inventory_reservations SET state=? WHERE id=?',[$state,$id]);
        ProductService::advanceVersion($this->pdo); return $this->reservation($id);
    }
    public function adjust(int $actorId,int $variantId,int $delta,string $reason,string $key):array
    {
        CommerceAccess::requirePermission('admin.inventory.adjust',$actorId); $key=CommerceValues::key($key);
        $delta=CommerceValues::integer($delta,-CommerceValues::MAX_QUANTITY,CommerceValues::MAX_QUANTITY,'delta_milli');
        if($delta===0) CommerceValues::invalid('delta_milli'); $reason=CommerceValues::text($reason,190,'reason');
        return CommerceDatabase::transaction($this->pdo,function() use($actorId,$variantId,$delta,$reason,$key):array {
            CommerceAccess::requirePermission('admin.inventory.adjust',$actorId);
            $row=$this->lockedLines([$variantId=>1],false)[$variantId]; $stock=$row['stock'];
            $prior=$this->query('SELECT * FROM inventory_movements WHERE business_key=? AND variant_id=?'.CommerceDatabase::lock($this->pdo),[$key,$variantId])->fetch();
            if($prior) {
                if((int)$prior['on_hand_delta_milli']!==$delta||(int)$prior['reserved_delta_milli']!==0||$prior['reason']!==$reason||(int)$prior['actor_user_id']!==$actorId) throw new RequestRejected(409,'operation_key_conflict','Adjustment key has different inputs.');
                return $stock;
            }
            $this->stock($stock,(int)$stock['on_hand_milli']+$delta,(int)$stock['reserved_milli']);
            $this->movement($variantId,$delta,0,$reason,$key,$actorId);
            ProductService::advanceVersion($this->pdo);
            StaffAudit::record($this->pdo,$actorId,'inventory.adjust','variant',$variantId,$key,['delta_milli'=>$delta,'reason'=>$reason,'on_hand_milli'=>(int)$stock['on_hand_milli']+$delta]);
            return $this->query('SELECT * FROM inventory WHERE variant_id=?',[$variantId])->fetch();
        });
    }
    public function staffListing(int $actorId,int $page=1):array
    {
        CommerceAccess::requirePermission('admin.inventory.view',$actorId); $page=CommerceValues::integer($page,1,1000,'page'); $offset=($page-1)*30;
        return $this->query("SELECT i.*,v.sku,v.sell_unit,p.name FROM inventory i JOIN product_variants v ON v.id=i.variant_id JOIN products p ON p.id=v.product_id ORDER BY i.variant_id LIMIT 30 OFFSET $offset")->fetchAll();
    }
}
