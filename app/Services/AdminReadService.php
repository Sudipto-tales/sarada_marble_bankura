<?php

/** Bounded staff table projections; all mutations remain in domain services. */
final class AdminReadService
{
    public const TABLES = [
        'coupons'=>['Coupons','admin.pricing.edit',['code'=>'Code','kind'=>'Type','value'=>'Discount','used_count'=>'Uses','usage_limit'=>'Usage limit','enabled'=>'Enabled']],
        'products'=>['Products','admin.products.view',['name'=>'Material','category_name'=>'Category','brand_name'=>'Brand','status'=>'Status','price'=>'From (INR)','updated_at'=>'Updated']],
        'categories'=>['Categories','admin.products.view',['name'=>'Category','slug'=>'Slug','parent_name'=>'Parent','enabled'=>'Enabled']],
        'brands'=>['Brands','admin.products.view',['name'=>'Brand','slug'=>'Slug','enabled'=>'Enabled']],
        'inventory'=>['Inventory','admin.inventory.view',['name'=>'Material','sku'=>'SKU','sell_unit'=>'Unit','on_hand'=>'On hand','reserved'=>'Reserved','available'=>'Available']],
        'orders'=>['Orders','admin.orders.view',['order_number'=>'Order','customer'=>'Customer','status'=>'Status','total'=>'Total (INR)','payment_status'=>'Payment','created'=>'Placed (UTC)']],
        'customers'=>['Customers','admin.customers.view',['name'=>'Name','email'=>'Email','status'=>'Active','email_verify'=>'Verified']],
        'enquiries'=>['Store visit enquiries','admin.orders.view',['customer_name'=>'Name','contact'=>'Contact','product_name'=>'Material','note'=>'Note','created'=>'Received (UTC)']],
    ];
    public function __construct(private PDO $pdo) {}
    public static function definition(string $table): array
    {
        if (!isset(self::TABLES[$table])) throw new RequestRejected(404,'not_found','Table not found.');
        return self::TABLES[$table];
    }
    public static function decimal(int|string|null $value, int $places): string
    {
        if ($value===null) return '—';
        $number=(int)$value;$scale=10**$places;
        return intdiv($number,$scale).'.'.str_pad((string)($number%$scale),$places,'0',STR_PAD_LEFT);
    }
    public function table(int $actor, string $table, array $input): array
    {
        $definition=self::definition($table);CommerceAccess::requirePermission($definition[1],$actor);
        $draw=CommerceValues::integer($input['draw']??0,0,2147483647,'draw');
        $start=CommerceValues::integer($input['start']??0,0,1000000,'start');
        $length=CommerceValues::integer($input['length']??20,1,100,'length');
        $search=$input['search']['value']??$input['q']??'';
        $search=CommerceValues::text($search,80,'search',true);
        $config=match($table) {
            'coupons'=>['coupons p','p.id,p.code,p.kind,p.value,p.used_count,p.usage_limit,p.enabled',['p.code','p.kind','p.value','p.used_count','p.usage_limit','p.enabled'],['p.code']],
            'products'=>['products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id',
                'p.id,p.name,c.name AS category_name,b.name AS brand_name,p.status,p.updated_at,(SELECT MIN(v.unit_price_minor) FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1) AS price',
                ['p.name','c.name','b.name','p.status','price','p.updated_at'],['p.name','p.slug','c.name','b.name'], "p.id IN (SELECT product_id FROM product_variants WHERE sku LIKE ? ESCAPE '!')"],
            'categories'=>['categories p LEFT JOIN categories c ON c.id=p.parent_id','p.id,p.name,p.slug,c.name AS parent_name,p.enabled',['p.name','p.slug','c.name','p.enabled'],['p.name','p.slug']],
            'brands'=>['brands p','p.id,p.name,p.slug,p.enabled',['p.name','p.slug','p.enabled'],['p.name','p.slug']],
            'inventory'=>['inventory i JOIN product_variants v ON v.id=i.variant_id JOIN products p ON p.id=v.product_id','v.id,p.name,v.sku,v.sell_unit,i.on_hand_milli AS on_hand,i.reserved_milli AS reserved,(i.on_hand_milli-i.reserved_milli) AS available',
                ['p.name','v.sku','v.sell_unit','i.on_hand_milli','i.reserved_milli','available'],['p.name','v.sku']],
            'orders'=>['orders o JOIN users_tbl u ON u.id=o.user_id','o.id,o.order_number,u.name AS customer,o.status,o.total_minor AS total,o.payment_status,o.created_at AS created',
                ['o.order_number','u.name','o.status','o.total_minor','o.payment_status','o.created_at'],['o.order_number','u.name','u.email','o.status','o.payment_status']],
            'customers'=>['users_tbl p','p.id,p.name,p.email,p.status,p.email_verify',['p.name','p.email','p.status','p.email_verify'],['p.name','p.email']],
            'enquiries'=>['store_visit_enquiries p LEFT JOIN products pr ON pr.id=p.product_id','p.id,p.customer_name,p.contact,pr.name AS product_name,p.note,p.created_at AS created',
                ['p.customer_name','p.contact','pr.name','p.note','p.created_at'],['p.customer_name','p.contact','pr.name','p.note']],
        };
        [$from,$columns,$sorts,$searches]=$config;$where=[];$args=[];
        // Staff are not listed as customers. No password/token/address columns are projected.
        if ($table==='customers') $where[]="NOT EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions perm ON perm.id=rp.permission_id WHERE ur.user_id=p.id AND perm.permission_key LIKE 'admin.%')";
        $base=$where?' WHERE '.implode(' AND ',$where):'';
        $total=(int)CommerceDatabase::query($this->pdo,'SELECT COUNT(*) FROM '.$from.$base)->fetchColumn();
        if ($search!=='') {
            $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$terms=[];
            foreach($searches as $column){$terms[]="$column LIKE ? ESCAPE '!'";$args[]=$like;}
            if(isset($config[4])){$terms[]=$config[4];$args[]=$like;}
            $where[]='('.implode(' OR ',$terms).')';
        }
        $where=$where?' WHERE '.implode(' AND ',$where):'';
        $filtered=(int)CommerceDatabase::query($this->pdo,'SELECT COUNT(*) FROM '.$from.$where,$args)->fetchColumn();
        $order=$input['order'][0]??['column'=>0,'dir'=>'asc'];
        $column=CommerceValues::integer($order['column']??0,0,count($sorts)-1,'sort_column');
        $direction=$order['dir']??'asc';if(!in_array($direction,['asc','desc'],true))CommerceValues::invalid('sort_direction');
        $id=match($table){'inventory'=>'v.id','orders'=>'o.id',default=>'p.id'};
        $rows=CommerceDatabase::query($this->pdo,"SELECT $columns FROM $from$where ORDER BY {$sorts[$column]} $direction,$id ASC LIMIT $length OFFSET $start",$args)->fetchAll();
        foreach($rows as &$row){
            $row['id']=(string)$row['id'];
            if($table==='coupons')$row['value']=($row['kind']==='fixed'?'INR ':'').self::decimal($row['value'],2).($row['kind']==='percent'?'%':'');
            foreach(['price','total'] as $field)if(array_key_exists($field,$row))$row[$field]=self::decimal($row[$field],2);
            foreach(['on_hand','reserved','available'] as $field)if(isset($row[$field]))$row[$field]=self::decimal($row[$field],3);
            if(isset($row['created']))$row['created']=gmdate('Y-m-d H:i',(int)$row['created']);
        }unset($row);
        return ['draw'=>$draw,'recordsTotal'=>$total,'recordsFiltered'=>$filtered,'data'=>$rows];
    }
    public function stock(int $actor,int $id): array
    {
        CommerceAccess::requirePermission('admin.inventory.view',$actor);
        $row=CommerceDatabase::query($this->pdo,'SELECT i.*,v.sku,v.sell_unit,v.qty_increment_milli,p.name FROM inventory i JOIN product_variants v ON v.id=i.variant_id JOIN products p ON p.id=v.product_id WHERE i.variant_id=?',[$id])->fetch();
        if(!$row)throw new RequestRejected(404,'not_found','Stock not found.');return $row;
    }
    public function reports(int $actorId,string $from,string $to):array
    {
        CommerceAccess::requirePermission('admin.analytics.view',$actorId);
        $zone=new DateTimeZone('UTC');$dates=[];
        foreach([$from,$to] as $value){
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);
            if(!$date||$date->format('Y-m-d')!==$value)CommerceValues::invalid('date');$dates[]=$date->getTimestamp();
        }
        [$start,$last]=$dates;$end=$last+86400;
        if($last<$start||$end-$start>93*86400)CommerceValues::invalid('date_range');
        $sales=CommerceDatabase::query($this->pdo,"SELECT COUNT(*) AS order_count,COALESCE(SUM(total_minor),0) AS revenue_minor FROM orders WHERE payment_status='paid' AND created_at>=? AND created_at<?",[$start,$end])->fetch();
        $coupons=CommerceDatabase::query($this->pdo,'SELECT c.code,c.used_count,COUNT(r.id) AS range_uses,SUM(r.amount_minor) AS discount_minor FROM coupon_redemptions r JOIN coupons c ON c.id=r.coupon_id WHERE r.created_at>=? AND r.created_at<? GROUP BY c.id,c.code,c.used_count ORDER BY discount_minor DESC,c.id LIMIT 100',[$start,$end])->fetchAll();
        // Order pipeline across every status in range (not only paid), using idx_order_status_time.
        $statuses=CommerceDatabase::query($this->pdo,"SELECT status,COUNT(*) AS order_count,COALESCE(SUM(total_minor),0) AS value_minor FROM orders WHERE created_at>=? AND created_at<? GROUP BY status ORDER BY order_count DESC,status",[$start,$end])->fetchAll();
        // Best sellers by paid revenue in range, aggregated over the immutable order-item snapshots.
        $topProducts=CommerceDatabase::query($this->pdo,"SELECT oi.name,SUM(oi.qty_milli) AS qty_milli,SUM(oi.line_total_minor) AS revenue_minor,COUNT(DISTINCT oi.order_id) AS order_count
            FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.payment_status='paid' AND o.created_at>=? AND o.created_at<?
            GROUP BY oi.product_id,oi.name ORDER BY revenue_minor DESC,oi.name LIMIT 20",[$start,$end])->fetchAll();
        // Current operational stock state (point-in-time, not range): enabled variants within five increments of empty.
        $lowStock=CommerceDatabase::query($this->pdo,"SELECT p.name,v.sku,v.sell_unit,v.qty_increment_milli,(i.on_hand_milli-i.reserved_milli) AS available_milli
            FROM inventory i JOIN product_variants v ON v.id=i.variant_id JOIN products p ON p.id=v.product_id
            WHERE v.enabled=1 AND p.status='active' AND i.on_hand_milli-i.reserved_milli<v.qty_increment_milli*5
            ORDER BY available_milli ASC,p.name LIMIT 50")->fetchAll();
        $sales['revenue_minor']=CommerceValues::integer($sales['revenue_minor'],0,PHP_INT_MAX,'revenue_minor');
        foreach($coupons as &$row)$row['discount_minor']=CommerceValues::integer($row['discount_minor'],0,PHP_INT_MAX,'discount_minor');unset($row);
        foreach($statuses as &$row)$row['value_minor']=CommerceValues::integer($row['value_minor'],0,PHP_INT_MAX,'value_minor');unset($row);
        foreach($topProducts as &$row){$row['revenue_minor']=CommerceValues::integer($row['revenue_minor'],0,PHP_INT_MAX,'revenue_minor');$row['qty_milli']=CommerceValues::integer($row['qty_milli'],0,PHP_INT_MAX,'qty_milli');}unset($row);
        return compact('from','to','sales','coupons','statuses','topProducts','lowStock');
    }
    /** Open work across the modules a staff account can reach; counts link to the screen that clears them. */
    public function attention(array $actor): array
    {
        $permissions=$actor['permissions'];$items=[];
        $add=function(string $permission,string $label,string $module,string $hint,string $sql)use(&$items,$permissions):void{
            if(!in_array($permission,$permissions,true))return;
            $items[]=['label'=>$label,'module'=>$module,'hint'=>$hint,'count'=>(int)CommerceDatabase::query($this->pdo,$sql)->fetchColumn()];
        };
        // NEXT keys are exactly the non-terminal order statuses (placed…outForDelivery); constant, so safe to inline.
        $open="'".implode("','",array_keys(OrderStates::NEXT))."'";
        $add('admin.orders.view','Orders to fulfil','orders','Active orders not yet delivered',"SELECT COUNT(*) FROM orders WHERE status IN ($open)");
        $add('admin.products.view','Reviews to moderate','product-reviews','Customer reviews awaiting approval',"SELECT COUNT(*) FROM product_reviews WHERE status='pending'");
        $add('admin.orders.view','Store visit enquiries','enquiries','Showroom visit leads from the website',"SELECT COUNT(*) FROM store_visit_enquiries");
        $add('admin.inventory.view','Out of stock variants','inventory','Enabled variants with nothing sellable',"SELECT COUNT(*) FROM inventory i JOIN product_variants v ON v.id=i.variant_id WHERE v.enabled=1 AND i.on_hand_milli-i.reserved_milli<v.qty_increment_milli");
        return $items;
    }
    public function overview(array $actor): array
    {
        $cards=[];
        foreach(['products'=>['Materials','products'],'inventory'=>['Stock variants','inventory'],'orders'=>['Orders','orders'],'customers'=>['Customers','users_tbl']] as $module=>[$label,$table]){
            if(!in_array(self::TABLES[$module][1],$actor['permissions'],true))continue;
            $where=$module==='customers'?" WHERE NOT EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions perm ON perm.id=rp.permission_id WHERE ur.user_id=users_tbl.id AND perm.permission_key LIKE 'admin.%')":'';
            $cards[]=['label'=>$label,'count'=>(int)CommerceDatabase::query($this->pdo,"SELECT COUNT(*) FROM $table$where")->fetchColumn(),'module'=>$module];
        }return $cards;
    }
}
