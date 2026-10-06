<?php

/** Cart quantities belong to the server principal. Prices are always read afresh. */
final class CartService
{
    public function __construct(private PDO $pdo) {}
    private function query(string $sql,array $args=[]):PDOStatement { return CommerceDatabase::query($this->pdo,$sql,$args); }
    public static function validatePrincipal(string $principal):void
    {
        if(preg_match('/\Auser:([1-9][0-9]*)\z/',$principal,$m)) CommerceAccess::requireActor(CommerceValues::id($m[1]));
        elseif(!preg_match('/\Aguest:[a-f0-9]{64}\z/',$principal)) CommerceValues::invalid('principal');
    }
    public static function guestPrincipal(bool $create=false):?string
    {
        global $pdo;
        $token=$_COOKIE['commerce_guest']??null;
        if(is_string($token)&&preg_match('/\A[a-f0-9]{64}\z/',$token)) {
            $principal='guest:'.hash('sha256',$token);
            $row=CommerceDatabase::query($pdo,'SELECT status,expires_at FROM carts WHERE owner_key=? ORDER BY id DESC LIMIT 1',[$principal])->fetch();
            if(!$row||($row['status']==='active'&&(int)$row['expires_at']>CommerceDatabase::clock($pdo))) return $principal;
        }
        if(!$create) return null;
        $token=bin2hex(random_bytes(32));
        setcookie('commerce_guest',$token,RequestSecurity::cookieOptions(time()+2592000));
        $_COOKIE['commerce_guest']=$token;
        return 'guest:'.hash('sha256',$token);
    }
    public static function principal(bool $create=false):?string
    {
        $actor=CommerceAccess::actor(); return $actor?'user:'.$actor['id']:self::guestPrincipal($create);
    }
    public static function clearGuestCookie():void
    {
        setcookie('commerce_guest','',RequestSecurity::cookieOptions(time()-3600)); unset($_COOKIE['commerce_guest']);
    }
    public function lockOwned(int $id,string $principal):array
    {
        if(!$this->pdo->inTransaction()) throw new LogicException('Cart lock requires the caller transaction.');
        self::validatePrincipal($principal);
        $row=$this->query('SELECT * FROM carts WHERE id=? AND owner_key=?'.CommerceDatabase::lock($this->pdo),[$id,$principal])->fetch();
        if(!$row) throw new RequestRejected(404,'not_found','Cart not found.');
        if($row['status']!=='active'||(int)$row['expires_at']<=CommerceDatabase::clock($this->pdo)) throw new RequestRejected(409,'cart_closed','Cart has expired or is closed.');
        return $row;
    }
    private function ensure(string $principal):array
    {
        self::validatePrincipal($principal); $now=CommerceDatabase::clock($this->pdo);
        $row=$this->query("SELECT * FROM carts WHERE owner_key=? AND status='active'".CommerceDatabase::lock($this->pdo),[$principal])->fetch();
        if($row&&(int)$row['expires_at']>$now) return $row;
        if($row) $this->query("UPDATE carts SET status='expired',revision=revision+1 WHERE id=?",[$row['id']]);
        if(str_starts_with($principal,'guest:')&&$this->query('SELECT id FROM carts WHERE owner_key=? LIMIT 1',[$principal])->fetchColumn()) throw new RequestRejected(409,'guest_expired','Start a new guest cart.');
        $sql="INSERT INTO carts (user_id,guest_token_hash,owner_key,expires_at,created_at) VALUES (?,?,?,?,?)";
        $sql.=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE id=id':' ON CONFLICT(active_owner_key) DO NOTHING';
        $this->query($sql,[str_starts_with($principal,'user:')?(int)substr($principal,5):null,str_starts_with($principal,'guest:')?substr($principal,6):null,$principal,$now+2592000,$now]);
        return $this->query("SELECT * FROM carts WHERE owner_key=? AND status='active'".CommerceDatabase::lock($this->pdo),[$principal])->fetch();
    }
    public function get(string $principal,?int $id=null):array
    {
        self::validatePrincipal($principal);
        $sql="SELECT * FROM carts WHERE owner_key=? AND status='active' AND expires_at>?"; $args=[$principal,CommerceDatabase::clock($this->pdo)];
        if($id!==null) {$sql.=' AND id=?';$args[]=CommerceValues::id($id);}
        $row=$this->query($sql,$args)->fetch();
        if(!$row) {
            if($id!==null) throw new RequestRejected(404,'not_found','Cart not found.');
            return ['id'=>null,'revision'=>0,'items'=>[],'subtotal_minor'=>0,'currency'=>'INR'];
        }
        $items=$this->query('SELECT ci.variant_id,ci.qty_milli,v.*,p.name,p.slug,p.status,c.enabled AS category_enabled,b.enabled AS brand_enabled,i.on_hand_milli,i.reserved_milli
            FROM cart_items ci JOIN product_variants v ON v.id=ci.variant_id JOIN products p ON p.id=v.product_id JOIN categories c ON c.id=p.category_id
            LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN inventory i ON i.variant_id=v.id WHERE ci.cart_id=? ORDER BY ci.variant_id',[$row['id']])->fetchAll();
        $amounts=[];
        foreach($items as &$item) {
            $item['qty_milli']=(int)$item['qty_milli'];$item['unit_price_minor']=(int)$item['unit_price_minor'];
            $item['line_total_minor']=CommerceValues::line($item['unit_price_minor'],$item['qty_milli']);
            $item['available_milli']=max(0,(int)$item['on_hand_milli']-(int)$item['reserved_milli']);
            $item['purchasable']=$this->sellable($item)&&$item['qty_milli']<=$item['available_milli'];$amounts[]=$item['line_total_minor'];
        } unset($item);
        return ['id'=>(int)$row['id'],'revision'=>(int)$row['revision'],'items'=>$items,'subtotal_minor'=>CommerceValues::sum($amounts),'currency'=>'INR'];
    }
    private function noHold(int $id):void
    {
        if($this->query("SELECT id FROM inventory_reservations WHERE cart_id=? AND state='active' LIMIT 1".CommerceDatabase::lock($this->pdo),[$id])->fetchColumn()) throw new RequestRejected(409,'cart_reserved','Release the checkout reservation before changing the cart.');
    }
    private function sellable(array $v):bool { return $v['status']==='active'&&(int)$v['enabled']===1&&(int)$v['category_enabled']===1&&($v['brand_enabled']===null||(int)$v['brand_enabled']===1); }
    private function variants(array $ids):array
    {
        if(!$ids) return [];sort($ids,SORT_NUMERIC); $marks=implode(',',array_fill(0,count($ids),'?'));
        $products=$this->query("SELECT DISTINCT product_id FROM product_variants WHERE id IN ($marks) ORDER BY product_id",$ids)->fetchAll(PDO::FETCH_COLUMN);
        foreach($products as $product) $this->query('SELECT id FROM products WHERE id=?'.CommerceDatabase::lock($this->pdo),[$product])->fetch();
        $rows=$this->query("SELECT v.*,p.status,c.enabled AS category_enabled,b.enabled AS brand_enabled FROM product_variants v JOIN products p ON p.id=v.product_id
            JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id WHERE v.id IN ($marks) ORDER BY v.id".CommerceDatabase::lock($this->pdo),$ids)->fetchAll();
        $result=[];foreach($rows as $v) $result[(int)$v['id']]=$v; return $result;
    }
    public function change(string $principal,int $variantId,$quantity,int $expectedRevision,?int $cartId=null,bool $remove=false):array
    {
        self::validatePrincipal($principal);$variantId=CommerceValues::id($variantId);CommerceValues::integer($expectedRevision,0,PHP_INT_MAX,'revision');
        $id=CommerceDatabase::transaction($this->pdo,function() use($principal,$variantId,$quantity,$expectedRevision,$cartId,$remove):int {
            $cart=$cartId===null?$this->ensure($principal):$this->lockOwned($cartId,$principal);
            $actual=(int)$cart['revision'];
            if($actual!==$expectedRevision&&!($cartId===null&&$expectedRevision===0&&$actual===1&&!$this->query('SELECT id FROM cart_items WHERE cart_id=? LIMIT 1',[$cart['id']])->fetchColumn())) throw new RequestRejected(409,'revision_conflict','Cart changed. Refresh and retry.');
            $this->noHold((int)$cart['id']);
            if($remove) $this->query('DELETE FROM cart_items WHERE cart_id=? AND variant_id=?',[$cart['id'],$variantId]);
            else {
                $variant=$this->variants([$variantId])[$variantId]??null;
                if(!$variant||!$this->sellable($variant)) throw new RequestRejected(409,'variant_unavailable','This item is unavailable.');
                $qty=CommerceValues::quantity($quantity,$variant);
                $exists=$this->query('SELECT id FROM cart_items WHERE cart_id=? AND variant_id=?',[$cart['id'],$variantId])->fetchColumn();
                if(!$exists&&(int)$this->query('SELECT COUNT(*) FROM cart_items WHERE cart_id=?',[$cart['id']])->fetchColumn()>=50) throw new RequestRejected(422,'cart_limit','A cart supports up to 50 items.');
                if($exists) $this->query('UPDATE cart_items SET qty_milli=? WHERE id=?',[$qty,$exists]);
                else $this->query('INSERT INTO cart_items (cart_id,variant_id,qty_milli) VALUES (?,?,?)',[$cart['id'],$variantId,$qty]);
            }
            $this->query('UPDATE carts SET revision=revision+1 WHERE id=?',[$cart['id']]);return (int)$cart['id'];
        });return $this->get($principal,$id);
    }
    public function merge(string $guest,int $userId):array
    {
        self::validatePrincipal($guest);if(!str_starts_with($guest,'guest:')) CommerceValues::invalid('guest');
        CommerceAccess::requireActor($userId);$principal='user:'.$userId;
        // Establish the destination separately so the actual merge can lock both IDs in order.
        $destination=CommerceDatabase::transaction($this->pdo,fn()=>$this->ensure($principal));
        return CommerceDatabase::transaction($this->pdo,function() use($guest,$principal,$destination):array {
            $source=$this->query('SELECT id FROM carts WHERE owner_key=? ORDER BY id DESC LIMIT 1',[$guest])->fetchColumn();
            if(!$source) return ['cart_id'=>(int)$destination['id'],'excluded'=>[],'replayed'=>false];
            $ids=array_unique([(int)$source,(int)$destination['id']]);sort($ids,SORT_NUMERIC);$locked=[];
            foreach($ids as $id) $locked[$id]=$this->query('SELECT * FROM carts WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetch();
            $src=$locked[(int)$source];$dst=$locked[(int)$destination['id']];
            if(!$src||$src['owner_key']!==$guest||!$dst||$dst['owner_key']!==$principal) throw new RequestRejected(404,'not_found','Cart not found.');
            if($src['status']==='merged') {
                $prior=$this->query('SELECT id FROM carts WHERE id=? AND owner_key=?',[$src['merged_cart_id'],$principal])->fetchColumn();
                if(!$prior) throw new RequestRejected(404,'not_found','Cart not found.');
                return ['cart_id'=>(int)$prior,'excluded'=>[],'replayed'=>true];
            }
            $this->lockOwned((int)$source,$guest);$this->lockOwned((int)$dst['id'],$principal);$this->noHold((int)$source);$this->noHold((int)$dst['id']);
            $srcItems=$this->query('SELECT variant_id,qty_milli FROM cart_items WHERE cart_id=? ORDER BY variant_id',[$source])->fetchAll();
            $dstItems=$this->query('SELECT variant_id,qty_milli FROM cart_items WHERE cart_id=? ORDER BY variant_id',[$dst['id']])->fetchAll();
            $quantities=[];foreach($dstItems as $line) $quantities[(int)$line['variant_id']]=(int)$line['qty_milli'];
            $variants=$this->variants(array_column($srcItems,'variant_id'));$excluded=[];
            foreach($srcItems as $line) {
                $vid=(int)$line['variant_id'];$variant=$variants[$vid]??null;
                if(!$variant||!$this->sellable($variant)) {$excluded[]=['variant_id'=>$vid,'reason'=>'unavailable'];continue;}
                $qty=CommerceValues::sum([$quantities[$vid]??0,(int)$line['qty_milli']]);
                CommerceValues::quantity($qty,$variant);
                if(!isset($quantities[$vid])&&count($quantities)>=50) throw new RequestRejected(422,'cart_limit','A merged cart supports up to 50 items.');
                $sql='INSERT INTO cart_items (cart_id,variant_id,qty_milli) VALUES (?,?,?)';
                $sql.=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE qty_milli=VALUES(qty_milli)':' ON CONFLICT(cart_id,variant_id) DO UPDATE SET qty_milli=excluded.qty_milli';
                $this->query($sql,[$dst['id'],$vid,$qty]);$quantities[$vid]=$qty;
            }
            $this->query("UPDATE carts SET status='merged',merged_cart_id=?,revision=revision+1 WHERE id=?",[$dst['id'],$source]);
            $this->query('UPDATE carts SET revision=revision+1 WHERE id=?',[$dst['id']]);
            return ['cart_id'=>(int)$dst['id'],'excluded'=>$excluded,'replayed'=>false];
        });
    }
}
