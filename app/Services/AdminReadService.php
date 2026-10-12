<?php

/** Bounded staff table projections; all mutations remain in domain services. */
final class AdminReadService
{
    public const TABLES = [
        'products'=>['Products','admin.products.view',['name'=>'Material','category_name'=>'Category','brand_name'=>'Brand','status'=>'Status','price'=>'From (INR)','updated_at'=>'Updated']],
        'categories'=>['Categories','admin.products.view',['name'=>'Category','slug'=>'Slug','parent_name'=>'Parent','enabled'=>'Enabled']],
        'brands'=>['Brands','admin.products.view',['name'=>'Brand','slug'=>'Slug','enabled'=>'Enabled']],
        'inventory'=>['Inventory','admin.inventory.view',['name'=>'Material','sku'=>'SKU','sell_unit'=>'Unit','on_hand'=>'On hand','reserved'=>'Reserved','available'=>'Available']],
        'orders'=>['Orders','admin.orders.view',['order_number'=>'Order','customer'=>'Customer','status'=>'Status','total'=>'Total (INR)','payment_status'=>'Payment','created'=>'Placed (UTC)']],
        'customers'=>['Customers','admin.customers.view',['name'=>'Name','email'=>'Email','status'=>'Active','email_verify'=>'Verified']],
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
            'products'=>['products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id',
                'p.id,p.name,c.name AS category_name,b.name AS brand_name,p.status,p.updated_at,(SELECT MIN(v.unit_price_minor) FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1) AS price',
                ['p.name','c.name','b.name','p.status','price','p.updated_at'],['p.name','p.slug','c.name','b.name'], "p.id IN (SELECT product_id FROM product_variants WHERE sku LIKE ? ESCAPE '!')"],
            'categories'=>['categories p LEFT JOIN categories c ON c.id=p.parent_id','p.id,p.name,p.slug,c.name AS parent_name,p.enabled',['p.name','p.slug','c.name','p.enabled'],['p.name','p.slug']],
            'brands'=>['brands p','p.id,p.name,p.slug,p.enabled',['p.name','p.slug','p.enabled'],['p.name','p.slug']],
            'inventory'=>['inventory i JOIN product_variants v ON v.id=i.variant_id JOIN products p ON p.id=v.product_id','v.id,p.name,v.sku,v.sell_unit,i.on_hand_milli AS on_hand,i.reserved_milli AS reserved,(i.on_hand_milli-i.reserved_milli) AS available',
                ['p.name','v.sku','v.sell_unit','i.on_hand_milli','i.reserved_milli','available'],['p.name','v.sku']],
            'orders'=>['orders o JOIN users_tbl u ON u.id=o.user_id','o.id,o.order_number,u.name AS customer,o.status,o.total_minor AS total,o.payment_status,o.created_at AS created',
                ['o.order_number','u.name','o.status','o.total_minor','o.payment_status','o.created_at'],['o.order_number','u.name','u.email']],
            'customers'=>['users_tbl p','p.id,p.name,p.email,p.status,p.email_verify',['p.name','p.email','p.status','p.email_verify'],['p.name','p.email']],
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
