<?php
final class CouponService
{
    public function __construct(private PDO $pdo) {}
    private function query(string $sql,array $args=[]):PDOStatement {return CommerceDatabase::query($this->pdo,$sql,$args);}
    public static function code($value):string
    {
        $code=strtoupper(CommerceValues::text($value,40,'coupon'));
        if(!preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,39}\z/',$code))CommerceValues::invalid('coupon');return $code;
    }
    public static function discount(array $coupon,int $subtotal,int $now):int
    {
        if(!(int)$coupon['enabled']||($coupon['starts_at']!==null&&(int)$coupon['starts_at']>$now)||($coupon['ends_at']!==null&&(int)$coupon['ends_at']<=$now))
            throw new RequestRejected(409,'coupon_unavailable','This coupon is not currently available.');
        if($subtotal<(int)$coupon['min_order_minor'])throw new RequestRejected(409,'coupon_minimum','The coupon minimum order amount has not been reached.');
        $discount=$coupon['kind']==='percent'?CommerceValues::round(CommerceValues::multiply($subtotal,(int)$coupon['value']),10000):(int)$coupon['value'];
        return min($subtotal,$discount,$coupon['max_discount_minor']===null?$subtotal:(int)$coupon['max_discount_minor']);
    }
    public function preview(array $cart,string $code):array
    {
        $row=$this->query('SELECT * FROM coupons WHERE code=?',[self::code($code)])->fetch();
        if(!$row)throw new RequestRejected(409,'coupon_unavailable','This coupon is not currently available.');
        $amounts=[];foreach($cart['items'] as $item)$amounts[]=CommerceValues::line((int)$item['unit_price_minor'],(int)$item['qty_milli']);
        return $this->previewRow($row,CommerceValues::sum($amounts));
    }
    public function previewRow(array $row,int $subtotal):array
    {
        $terms=array_intersect_key($row,array_flip(['id','code','kind','value','min_order_minor','max_discount_minor','starts_at','ends_at','enabled','usage_limit','per_user_limit']));
        // Normalize PDO driver types so the accepted quote is stable across SQLite and MySQL.
        foreach($terms as $key=>&$value)if($value!==null&&!in_array($key,['code','kind'],true))$value=(int)$value;unset($value);
        return ['id'=>(int)$row['id'],'code'=>$row['code'],'discount_minor'=>self::discount($row,$subtotal,CommerceDatabase::clock($this->pdo)),
            'terms'=>hash('sha256',json_encode($terms,JSON_THROW_ON_ERROR))];
    }
    public function locked(int $couponId):array
    {
        if(!$this->pdo->inTransaction())throw new LogicException('Coupon lock requires the checkout transaction.');
        $row=$this->query('SELECT * FROM coupons WHERE id=?'.CommerceDatabase::lock($this->pdo),[$couponId])->fetch();
        if(!$row)throw new RequestRejected(409,'coupon_unavailable','This coupon is not currently available.');return $row;
    }
    public function redeem(PDO $pdo,int $couponId,int $userId,int $orderId,int $subtotal):int
    {
        if($pdo!==$this->pdo||!$pdo->inTransaction())throw new LogicException('Coupon redemption requires the caller PDO and transaction.');
        $coupon=$this->locked($couponId);
        $existing=$this->query('SELECT * FROM coupon_redemptions WHERE order_id=?'.CommerceDatabase::lock($pdo),[$orderId])->fetch();
        if($existing){
            if((int)$existing['coupon_id']!==$couponId||(int)$existing['user_id']!==$userId)throw new RequestRejected(409,'coupon_conflict','Order coupon conflicts.');
            return (int)$existing['amount_minor'];
        }
        $order=$this->query('SELECT user_id,subtotal_minor FROM orders WHERE id=?',[$orderId])->fetch();
        if(!$order||(int)$order['user_id']!==$userId||(int)$order['subtotal_minor']!==$subtotal)throw new RequestRejected(409,'coupon_conflict','Order coupon conflicts.');
        $discount=self::discount($coupon,$subtotal,CommerceDatabase::clock($pdo));
        if($coupon['usage_limit']!==null&&(int)$coupon['used_count']>=(int)$coupon['usage_limit'])throw new RequestRejected(409,'coupon_limit','This coupon has reached its usage limit.');
        // Locking read avoids a stale InnoDB repeatable-read snapshot after waiting for the coupon lock.
        if($coupon['per_user_limit']!==null){
            $limit=CommerceValues::integer($coupon['per_user_limit'],1,10000,'per_user_limit');
            $uses=$this->query('SELECT id FROM coupon_redemptions WHERE coupon_id=? AND user_id=? LIMIT '.$limit.CommerceDatabase::lock($pdo),[$couponId,$userId])->fetchAll(PDO::FETCH_COLUMN);
            if(count($uses)>=$limit)throw new RequestRejected(409,'coupon_user_limit','You have reached the limit for this coupon.');
        }
        $now=CommerceDatabase::clock($pdo);
        $this->query('INSERT INTO coupon_redemptions (coupon_id,user_id,order_id,amount_minor,created_at) VALUES (?,?,?,?,?)',[$couponId,$userId,$orderId,$discount,$now]);
        $this->query('UPDATE coupons SET used_count=used_count+1,updated_at=? WHERE id=?',[$now,$couponId]);return $discount;
    }
    public function detail(int $actorId,int $id):array
    {
        CommerceAccess::requirePermission('admin.pricing.edit',$actorId);$row=$this->query('SELECT * FROM coupons WHERE id=?',[$id])->fetch();
        if(!$row)throw new RequestRejected(404,'not_found','Coupon not found.');return $row;
    }
    public function save(int $actorId,array $input,string $operationKey):int
    {
        CommerceAccess::requirePermission('admin.pricing.edit',$actorId);$key=CommerceValues::key($operationKey);
        $id=empty($input['id'])?null:CommerceValues::id($input['id']);
        $kind=$input['kind']??'';if(!in_array($kind,['percent','fixed'],true))CommerceValues::invalid('kind');
        $values=['code'=>self::code($input['code']??null),'kind'=>$kind,'value'=>CommerceValues::integer($input['value']??null,0,$kind==='percent'?10000:2147483647,'value'),
            'min_order_minor'=>CommerceValues::integer($input['min_order_minor']??0,0,CommerceValues::MAX_MONEY,'min_order_minor'),
            'enabled'=>CommerceValues::flag($input['enabled']??1,'enabled')];
        foreach(['max_discount_minor','usage_limit','per_user_limit','starts_at','ends_at'] as $field){
            $value=$input[$field]??null;
            $max=match($field){'max_discount_minor'=>CommerceValues::MAX_MONEY,'starts_at','ends_at'=>253402300799,'per_user_limit'=>10000,default=>2147483647};
            $values[$field]=$value===null||$value===''?null:CommerceValues::integer($value,in_array($field,['usage_limit','per_user_limit'],true)?1:0,$max,$field);
        }
        if($values['starts_at']!==null&&$values['ends_at']!==null&&$values['ends_at']<=$values['starts_at'])CommerceValues::invalid('ends_at');
        try{return CommerceDatabase::transaction($this->pdo,function()use($actorId,$id,$key,$values):int {
            $prior=$this->query('SELECT * FROM admin_activity_log WHERE operation_key=?'.CommerceDatabase::lock($this->pdo),[$key])->fetch();
            if($prior){
                if($id!==null&&$id!==(int)$prior['target_id'])throw new RequestRejected(409,'coupon_conflict','Coupon operation conflicts.');
                StaffAudit::record($this->pdo,$actorId,'coupon.save','coupon',(int)$prior['target_id'],$key,$values);return (int)$prior['target_id'];
            }
            $now=CommerceDatabase::clock($this->pdo);$target=$id;
            if($id){$this->locked($id);$assign=implode(',',array_map(static fn($name)=>$name.'=?',array_keys($values)));$this->query('UPDATE coupons SET '.$assign.',updated_at=? WHERE id=?',[...array_values($values),$now,$id]);}
            else{$columns=implode(',',array_keys($values));$marks=implode(',',array_fill(0,count($values),'?'));$this->query("INSERT INTO coupons ($columns,created_at,updated_at) VALUES ($marks,?,?)",[...array_values($values),$now,$now]);$target=(int)$this->pdo->lastInsertId();}
            StaffAudit::record($this->pdo,$actorId,'coupon.save','coupon',$target,$key,$values);return $target;
        });}catch(Throwable $error){if(CommerceDatabase::uniqueConflict($error))throw new RequestRejected(409,'coupon_conflict','Coupon code already exists.');throw $error;}
    }
    public function archive(int $actorId,int $id,string $operationKey):void
    {
        CommerceAccess::requirePermission('admin.pricing.edit',$actorId);$key=CommerceValues::key($operationKey);
        CommerceDatabase::transaction($this->pdo,function()use($actorId,$id,$key):void {
            $this->locked($id);$prior=$this->query('SELECT id FROM admin_activity_log WHERE operation_key=?'.CommerceDatabase::lock($this->pdo),[$key])->fetchColumn();
            StaffAudit::record($this->pdo,$actorId,'coupon.archive','coupon',$id,$key,['enabled'=>0]);
            if(!$prior)$this->query('UPDATE coupons SET enabled=0,updated_at=? WHERE id=?',[CommerceDatabase::clock($this->pdo),$id]);
        });
    }
}
