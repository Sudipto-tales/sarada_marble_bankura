<?php
final class SettingsService
{
    private const KEYS=['tax_bps'=>'CHECKOUT_TAX_BPS','shipping_minor'=>'CHECKOUT_SHIPPING_MINOR','cod_enabled'=>'CHECKOUT_COD_ENABLED','razorpay_enabled'=>'CHECKOUT_RAZORPAY_ENABLED','advance_bps'=>'CHECKOUT_ADVANCE_BPS'];
    public function __construct(private PDO $pdo) {}
    public function get(string $key,string $default):string
    {
        if(!isset(self::KEYS[$key])) CommerceValues::invalid('setting');
        $value=CommerceDatabase::query($this->pdo,'SELECT svalue FROM store_settings WHERE skey=?',[$key])->fetchColumn();
        return $value===false?(string)env(self::KEYS[$key],$default):$value;
    }
    private function rows(bool $exclusive=false):array
    {
        // Shared current reads preserve authoritative settings without serializing all MySQL checkouts.
        $lock=$this->pdo->inTransaction()&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ?($exclusive?' FOR UPDATE':' LOCK IN SHARE MODE'):'';
        return CommerceDatabase::query($this->pdo,"SELECT skey,svalue FROM store_settings WHERE skey IN ('cod_enabled','razorpay_enabled','shipping_minor','tax_bps','advance_bps','cashback_cod_bps','cashback_cod_cap','cashback_razorpay_bps','cashback_razorpay_cap','cashback_simulation_bps','cashback_simulation_cap') ORDER BY skey".$lock)->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    public const CASHBACK_METHODS=['cod','razorpay','simulation'];
    public function effective():array
    {
        $rows=$this->rows();
        $value=static fn($key,$default)=>$rows[$key]??env(self::KEYS[$key],$default);
        // Display-only cashback: a percentage (basis points) of the cart amount, optionally capped. Not credited to any balance.
        $cashback=[];
        foreach(self::CASHBACK_METHODS as $m)
            $cashback[$m]=['bps'=>(int)($rows["cashback_{$m}_bps"]??0),'cap_minor'=>(int)($rows["cashback_{$m}_cap"]??0)];
        return ['tax_bps'=>CommerceValues::integer($value('tax_bps','0'),0,10000,'tax_bps'),
            'shipping_minor'=>CommerceValues::integer($value('shipping_minor','0'),0,CommerceValues::MAX_MONEY,'shipping_minor'),
            'advance_bps'=>CommerceValues::integer($value('advance_bps','0'),0,10000,'advance_bps'),
            'cod_enabled'=>filter_var($value('cod_enabled',false),FILTER_VALIDATE_BOOLEAN),
            'razorpay_enabled'=>filter_var($value('razorpay_enabled',false),FILTER_VALIDATE_BOOLEAN),
            'cashback'=>$cashback];
    }
    /** Cashback amount (minor) each method would show for a given cart amount; omits methods offering none. */
    public function cashback(int $amountMinor):array
    {
        $out=[];
        if($amountMinor<=0)return $out;
        foreach($this->effective()['cashback'] as $m=>$c){
            if($c['bps']<=0)continue;
            $amount=intdiv($amountMinor*$c['bps'],10000);
            if($c['cap_minor']>0)$amount=min($amount,$c['cap_minor']);
            if($amount>0)$out[$m]=$amount;
        }
        return $out;
    }
    public function save(int $actorId,array $input,string $operationKey):void
    {
        CommerceAccess::requirePermission('admin.settings.edit',$actorId);$key=CommerceValues::key($operationKey);
        $values=['tax_bps'=>CommerceValues::integer($input['tax_bps']??null,0,10000,'tax_bps'),
            'shipping_minor'=>CommerceValues::decimal($input['shipping']??null,2,CommerceValues::MAX_MONEY,'shipping'),
            'advance_bps'=>CommerceValues::integer($input['advance_bps']??0,0,10000,'advance_bps'),
            'cod_enabled'=>CommerceValues::flag($input['cod_enabled']??0,'cod_enabled'),
            'razorpay_enabled'=>CommerceValues::flag($input['razorpay_enabled']??0,'razorpay_enabled')];
        foreach(self::CASHBACK_METHODS as $m){
            $cap=($input["cashback_{$m}_cap"]??'')===''?'0':$input["cashback_{$m}_cap"];
            $values["cashback_{$m}_bps"]=CommerceValues::integer($input["cashback_{$m}_bps"]??0,0,10000,"cashback_{$m}_bps");
            $values["cashback_{$m}_cap"]=CommerceValues::decimal($cap,2,CommerceValues::MAX_MONEY,"cashback_{$m}_cap");
        }
        ksort($values);
        CommerceDatabase::transaction($this->pdo,function()use($actorId,$key,$values):void {
            $this->rows(true);
            $prior=CommerceDatabase::query($this->pdo,'SELECT id FROM admin_activity_log WHERE operation_key=?'.CommerceDatabase::lock($this->pdo),[$key])->fetchColumn();
            StaffAudit::record($this->pdo,$actorId,'settings.save','settings',null,$key,$values);
            if($prior)return; // Replaying an old operation must not overwrite more recent settings.
            $sql='INSERT INTO store_settings (skey,svalue,updated_at) VALUES (?,?,?)';
            $sql.=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE svalue=VALUES(svalue),updated_at=VALUES(updated_at)':' ON CONFLICT(skey) DO UPDATE SET svalue=excluded.svalue,updated_at=excluded.updated_at';
            foreach($values as $name=>$value) CommerceDatabase::query($this->pdo,$sql,[$name,(string)$value,CommerceDatabase::clock($this->pdo)]);
            ProductService::advanceVersion($this->pdo);
        });
    }
}
