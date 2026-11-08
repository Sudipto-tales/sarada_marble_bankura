<?php

/** Form-specific decimal conversion; services revalidate all authoritative fields. */
final class AdminCatalogForm
{
    public static function product(array $post): array
    {
        $data=[];$errors=[];
        $read=static function(string $field,callable $convert)use(&$errors){try{return $convert();}catch(RequestRejected $e){$errors[$field]=$e->getMessage();return null;}};
        foreach(['name'=>190,'slug'=>120,'description'=>10000,'tags'=>1000] as $field=>$max){
            $data[$field]=$read($field,fn()=> $field==='slug'?CommerceValues::slug($post[$field]??null):CommerceValues::text($post[$field]??'', $max,$field,in_array($field,['description','tags'],true)));
        }
        if(isset($post['details']))$data['details']=$read('details',fn()=>is_array($post['details'])?ProductDetailService::validate($post['details']):CommerceValues::invalid('details'));
        if(!empty($post['id']))$data['id']=$read('id',fn()=>CommerceValues::id($post['id']));
        $data['category_id']=$read('category_id',fn()=>CommerceValues::id($post['category_id']??null,'category_id'));
        $data['brand_id']=($post['brand_id']??'')===''?null:$read('brand_id',fn()=>CommerceValues::id($post['brand_id'],'brand_id'));
        $data['status']=$post['status']??'draft';if(!in_array($data['status'],['draft','active','archived'],true))$errors['status']='Select draft, active or archived.';
        $data['featured']=$read('featured',fn()=>CommerceValues::flag($post['featured']??0,'featured'));
        // Advance payment: blank inherits (null), 0 forces full payment, a percent becomes basis points.
        $data['advance_bps']=($post['advance_rate']??'')===''?null:$read('advance_rate',fn()=>CommerceValues::decimal($post['advance_rate'],2,10000,'advance_rate'));
        $rows=$post['variants']??null;$data['variants']=[];
        if(!is_array($rows)||count($rows)<1||count($rows)>20){$errors['variants']='Add between 1 and 20 variants.';return compact('data','errors');}
        if(($post['form_complete']??'')!=='1'||(string)count($rows)!==($post['variant_count']??''))$errors['variants']='The form was incomplete. Reload and try again; no variants were saved.';
        foreach(array_values($rows) as $i=>$row){
            if(!is_array($row)){$errors['variants']='Invalid variant row.';continue;}
            $variant=[];
            foreach(['sku'=>80,'finish'=>80] as $field=>$max)$variant[$field]=$read("variants.$i.$field",fn()=>CommerceValues::text($row[$field]??'', $max,$field,$field==='finish'));
            if(!empty($row['id']))$variant['id']=$read("variants.$i.id",fn()=>CommerceValues::id($row['id'],'variant_id'));
            $variant['sell_unit']=$row['sell_unit']??'';if(!in_array($variant['sell_unit'],['sqft','slab','box'],true))$errors["variants.$i.sell_unit"]='Select a selling unit.';
            $variant['enabled']=$read("variants.$i.enabled",fn()=>CommerceValues::flag($row['enabled']??0,'enabled'));
            foreach(['original_price'=>['original_price_minor',2,CommerceValues::MAX_MONEY], 'price'=>['unit_price_minor',2,CommerceValues::MAX_MONEY], 'increment'=>['qty_increment_milli',3,1000000], 'coverage'=>['coverage_sqft_milli',3,1000000000], 'thickness'=>['thickness_mm_milli',3,1000000000], 'length'=>['length_mm_milli',3,1000000000], 'width'=>['width_mm_milli',3,1000000000]] as $field=>[$target,$places,$max]){
                if(!in_array($field,['price','increment'],true)&&($row[$field]??'')===''){$variant[$target]=null;continue;}
                $variant[$target]=$read("variants.$i.$field",fn()=>CommerceValues::decimal($row[$field]??null,$places,$max,$field));
                if(!in_array($field,['price','original_price'],true)&&$variant[$target]===0)$errors["variants.$i.$field"]='Enter a value greater than zero.';
            }
            if(in_array($variant['sell_unit'],['box','slab'],true)){
                if($variant['qty_increment_milli']!==null&&$variant['qty_increment_milli']%1000!==0)$errors["variants.$i.increment"]='Boxes and slabs require a whole-unit increment.';
                if(!$variant['coverage_sqft_milli'])$errors["variants.$i.coverage"]='Enter the square-foot coverage of one box or slab.';
            }
            $data['variants'][]=$variant;
        }
        return compact('data','errors');
    }
    public static function display(array $product): array
    {
        foreach($product['variants'] as &$variant){
            foreach(['original_price'=>['original_price_minor',2],'price'=>['unit_price_minor',2],'increment'=>['qty_increment_milli',3],'coverage'=>['coverage_sqft_milli',3],'thickness'=>['thickness_mm_milli',3],'length'=>['length_mm_milli',3],'width'=>['width_mm_milli',3]] as $field=>[$source,$places])$variant[$field]=($variant[$source]??null)===null?'':AdminReadService::decimal($variant[$source],$places);
        }unset($variant);
        $product['advance_rate']=($product['advance_bps']??null)===null?'':AdminReadService::decimal($product['advance_bps'],2);
        return $product;
    }
}
