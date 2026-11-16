<?php

/** Form-specific decimal conversion; services revalidate all authoritative fields. */
final class AdminCatalogForm
{
    public static function product(array $post): array
    {
        $data = [];
        $errors = [];
        $read = static function(string $field, callable $convert) use (&$errors){
            try{
                return $convert();
            }catch(RequestRejected $e){
                $errors[$field] = $e->getMessage();
                return null;
            }
        };
        foreach(['name'=>190,'slug'=>120,'description'=>10000,'tags'=>1000] as $field=>$max){
            $data[$field]=$read($field,fn()=> $field==='slug'?CommerceValues::slug($post[$field]??null):CommerceValues::text($post[$field]??'', $max,$field,in_array($field,['description','tags'],true)));
        }
        $data['category_id']=$read('category_id',fn()=>CommerceValues::id($post['category_id']??null,'category_id'));
        $data['brand_id']=($post['brand_id']??'')===''?null:$read('brand_id',fn()=>CommerceValues::id($post['brand_id'],'brand_id'));
        $data['status']=$post['status']??'active';if(!in_array($data['status'],['draft','active','archived'],true))$errors['status']='Select draft, active or archived.';
        $data['featured']=$read('featured',fn()=>CommerceValues::flag($post['featured']??0,'featured'));
        // Price in paise (INR), dimensions in millimetres
        $data['price_minor']=$read('price_minor',fn()=>CommerceValues::integer($post['price_minor']??null,0,10000000000,'price_minor'));
        $data['thickness_mm_milli']=$read('thickness_mm_milli',fn()=>CommerceValues::integer($post['thickness_mm_milli']??null,1,1000000000,'thickness_mm_milli'));
        $data['width_mm_milli']=$read('width_mm_milli',fn()=>CommerceValues::integer($post['width_mm_milli']??null,1,1000000000,'width_mm_milli'));
        $data['length_mm_milli']=$read('length_mm_milli',fn()=>CommerceValues::integer($post['length_mm_milli']??null,1,1000000000,'length_mm_milli'));
        // Sell unit validation (done after read to avoid arrow function block body issues)
        $sell_unit=$post['sell_unit']??'';
        if(!in_array($sell_unit,['sqft','slab','box'],true))$errors['sell_unit']='Select a selling unit.';
        $data['sell_unit']=$sell_unit;
        $data['qty_increment_milli']=$read('qty_increment_milli',fn()=>CommerceValues::integer($post['qty_increment_milli']??1000,1,1000000000,'qty_increment_milli'));
        $data['coverage_sqft_milli']=$read('coverage_sqft_milli',fn()=>CommerceValues::integer($post['coverage_sqft_milli']??null,1,1000000000,'coverage_sqft_milli'));
        return compact('data','errors');
    }
    public static function display(array $product): array
    {
        return [
            'id' => $product['id'] ?? '',
            'name' => $product['name'] ?? '',
            'slug' => $product['slug'] ?? '',
            'description' => $product['description'] ?? '',
            'tags' => $product['tags'] ?? '',
            'category_id' => $product['category_id'] ?? '',
            'brand_id' => $product['brand_id'] ?? '',
            'status' => $product['status'] ?? 'active',
            'featured' => $product['featured'] ?? 0,
            'price_minor' => ($product['price_minor']??null)===null?'':AdminReadService::decimal($product['price_minor'],2),
            'thickness_mm_milli' => ($product['thickness_mm_milli']??null)===null?'':AdminReadService::integer($product['thickness_mm_milli'],0,1000000000,'thickness'),
            'width_mm_milli' => ($product['width_mm_milli']??null)===null?'':AdminReadService::integer($product['width_mm_milli'],0,1000000000,'width'),
            'length_mm_milli' => ($product['length_mm_milli']??null)===null?'':AdminReadService::integer($product['length_mm_milli'],0,1000000000,'length'),
            'sell_unit' => $product['sell_unit'] ?? 'sqft',
            'qty_increment_milli' => ($product['qty_increment_milli']??1000)===1000?'':AdminReadService::integer($product['qty_increment_milli'],1,1000000000,'increment'),
            'coverage_sqft_milli' => ($product['coverage_sqft_milli']??null)===null?'':AdminReadService::integer($product['coverage_sqft_milli'],1,1000000000,'coverage'),
        ];
    }
}