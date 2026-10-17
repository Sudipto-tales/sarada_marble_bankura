<?php

/** Operator-authored product metadata; no inferred origin, policy, delivery or merchandising claims. */
final class ProductDetailService
{
    public function __construct(private PDO $pdo) {}
    public static function validate(array $input): array
    {
        $data=[];
        foreach(['colour'=>80,'origin'=>190,'replacement_policy'=>2000,'quality_policy'=>2000,'installation_policy'=>2000] as $field=>$max)
            $data[$field]=CommerceValues::text($input[$field]??'',$max,$field,true);
        foreach(['applications'=>[12,80],'delivery_pincodes'=>[200,6]] as $field=>[$limit,$length]) {
            $values=$input[$field]??[];
            if(is_string($values))$values=preg_split('/[,\r\n]+/',$values,-1,PREG_SPLIT_NO_EMPTY);
            if(!is_array($values)||count($values)>$limit)CommerceValues::invalid($field);
            $data[$field]=[];
            foreach($values as $value) {
                $value=CommerceValues::text($value,$length,$field);
                if($field==='delivery_pincodes'&&!preg_match('/\A[1-9][0-9]{5}\z/',$value))CommerceValues::invalid($field);
                $data[$field][]=$value;
            }
            $data[$field]=array_values(array_unique($data[$field]));
        }
        foreach(['delivery_min_days','delivery_max_days'] as $field)$data[$field]=($input[$field]??'')===''?null:CommerceValues::integer($input[$field],1,365,$field);
        if($data['delivery_pincodes']&&($data['delivery_min_days']===null||$data['delivery_max_days']===null||$data['delivery_min_days']>$data['delivery_max_days']))CommerceValues::invalid('delivery_days');
        $data['catalogue_slug']=$input['catalogue_slug']??'';
        if(!is_string($data['catalogue_slug'])||!in_array($data['catalogue_slug'],['','kasamood'],true))CommerceValues::invalid('catalogue_slug');
        $data['companion_product_id']=($input['companion_product_id']??'')===''?null:CommerceValues::id($input['companion_product_id']);
        return $data;
    }
    public function get(int $productId): array
    {
        $json=CommerceDatabase::query($this->pdo,'SELECT content_json FROM product_details WHERE product_id=?',[$productId])->fetchColumn();
        return $json?json_decode($json,true,16,JSON_THROW_ON_ERROR):self::validate([]);
    }
    public function saveInside(int $productId,array $data): void
    {
        if(!$this->pdo->inTransaction())throw new LogicException('Product transaction required.');
        if($data['companion_product_id']!==null) {
            if($data['companion_product_id']===$productId||!CommerceDatabase::query($this->pdo,'SELECT id FROM products WHERE id=?',[$data['companion_product_id']])->fetchColumn())CommerceValues::invalid('companion_product_id');
        }
        $sql='INSERT INTO product_details (product_id,content_json) VALUES (?,?)';
        $sql.=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE content_json=VALUES(content_json)':' ON CONFLICT(product_id) DO UPDATE SET content_json=excluded.content_json';
        CommerceDatabase::query($this->pdo,$sql,[$productId,json_encode($data,JSON_THROW_ON_ERROR)]);
    }
    public function delivery(array $details,string $pin): array
    {
        if(!preg_match('/\A[1-9][0-9]{5}\z/',$pin))CommerceValues::invalid('postal_code');
        if(!$details['delivery_pincodes'])return ['status'=>'unconfigured','message'=>'Contact the store to confirm delivery to this PIN code.'];
        if(!in_array($pin,$details['delivery_pincodes'],true))return ['status'=>'unavailable','message'=>'Online delivery is not configured for this PIN code. Contact the store.'];
        return ['status'=>'available','message'=>'Estimated delivery: '.$details['delivery_min_days'].'–'.$details['delivery_max_days'].' days. Confirm site access and timing with the store.'];
    }
}
