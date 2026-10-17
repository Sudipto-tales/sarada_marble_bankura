<?php
require __DIR__.'/support/CommerceTest.php';
$pdo=CommerceTest::open();$check=[CommerceTest::class,'check'];
foreach(['CHECKOUT_COD_ENABLED'=>'true','CHECKOUT_RAZORPAY_ENABLED'=>'false','CHECKOUT_SHIPPING_MINOR'=>'0','CHECKOUT_TAX_BPS'=>'1800'] as $key=>$value){$_ENV[$key]=$value;putenv($key.'='.$value);CommerceTest::$environment[$key]=$value;}
$admin=CommerceAccess::provision('coupon-admin@example.test','Disposable-Coupon-Password');
$buyer=CommerceTest::user('coupon-buyer@example.test');$other=CommerceTest::user('coupon-other@example.test');
$settings=new SettingsService($pdo);$coupons=new CouponService($pdo);$products=new ProductService($pdo);$carts=new CartService($pdo);$orders=new OrderService($pdo);
require dirname(__DIR__).'/database/seeders/DemoCatalogSeeder.php';
$created=[];foreach(require dirname(__DIR__).'/database/seeders/catalog.php' as [$slug]){
    $file=dirname(__DIR__).'/assets/products/'.substr(hash('sha256','sarada-demo-v1:'.$slug),0,32).'.webp';if(!is_file($file))$created[]=$file;
}
register_shutdown_function(static function()use($created):void{foreach($created as $file)if(is_file($file))unlink($file);});
$seed=(new DemoCatalogSeeder($pdo))->run($admin);$check($seed['created_products']===8,'Development catalog and stock seed through existing services.');
$check((new MigrationRunner($pdo,dirname(__DIR__).'/database/migrations',static function($line){}))->run()===0,'New settings/tax/coupon schemas verify on rerun.');
$input=['tax_bps'=>'500','shipping'=>'1.25','cod_enabled'=>1,'razorpay_enabled'=>1];
CommerceTest::rejects(fn()=>$settings->save($buyer,$input,'settings:denied'),403,'Settings require staff edit permission.');
CommerceTest::rejects(fn()=>$settings->save($admin,array_replace($input,['tax_bps'=>10001]),'settings:bad:rate'),422,'Invalid tax rate rejects.');
$settings->save($admin,$input,'settings:first:save');
$check(OrderService::policy()===['version'=>'inr-exclusive-v1','shipping_minor'=>125,'tax_bps'=>500],'Admin settings override env policy with integer values.');
$check(isset(OrderStates::paymentModes()['cod'],OrderStates::paymentModes()['razorpay']),'Admin toggles enable independent payment modes.');
$check((int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action='settings.save'")->fetchColumn()===1,'Settings write an audit row.');
$settings->save($admin,array_replace($input,['shipping'=>'0','tax_bps'=>1800,'razorpay_enabled'=>0]),'settings:second:save');
$settings->save($admin,$input,'settings:first:save');
$check(OrderService::policy()['shipping_minor']===0&&OrderService::policy()['tax_bps']===1800,'Old settings replay cannot overwrite a later edit.');
CommerceTest::rejects(fn()=>$settings->save($admin,array_replace($input,['shipping'=>'2.00']),'settings:first:save'),409,'Conflicting settings replay rejects.');
$marble=$pdo->query("SELECT id FROM categories WHERE slug='demo-marble'")->fetchColumn();
$granite=$pdo->query("SELECT id FROM categories WHERE slug='demo-granite'")->fetchColumn();
$products->saveCategory($admin,['id'=>$granite,'slug'=>'demo-granite','name'=>'Demo Granite','tax_bps'=>500],'category:tax:override');
$p1=$products->save($admin,['slug'=>'tax-small-a','name'=>'Tax small A','category_id'=>$marble,'status'=>'active','variants'=>[
    ['sku'=>'TAX-A','sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>101],
    ['sku'=>'TAX-A2','sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>101]]],0,'tax:product:first');
$p2=$products->save($admin,['slug'=>'tax-small-b','name'=>'Tax small B','category_id'=>$granite,'status'=>'active','variants'=>[
    ['sku'=>'TAX-B','sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>101]]],0,'tax:product:second');
$vids=[...$p1['variant_ids'],...$p2['variant_ids']];foreach($vids as $vid)(new InventoryService($pdo))->adjust($admin,$vid,100000,'Tax fixtures','stock:tax:'.$vid);
$addressInput=['recipient'=>'Tax Buyer','phone'=>'9999988888','line1'=>'Test Road','city'=>'Bankura','state'=>'West Bengal','postal_code'=>'722101'];
$address=(new AddressService($pdo))->save($buyer,$addressInput);$otherAddress=(new AddressService($pdo))->save($other,$addressInput);
$cart=$carts->change('user:'.$buyer,$vids[0],1000,0);foreach(array_slice($vids,1) as $vid)$cart=$carts->change('user:'.$buyer,$vid,1000,$cart['revision'],$cart['id']);
$fixed=$coupons->save($admin,['code'=>'fixed10','kind'=>'fixed','value'=>10],'coupon:fixed:create');
$preview=$coupons->preview($cart,'fixed10');$check($preview['discount_minor']===10&&$preview['code']==='FIXED10','Fixed coupon preview normalizes code and amount.');
$quote=OrderService::quote($cart,$preview);
$placed=$orders->place($buyer,$cart['id'],$cart['revision'],$address['id'],'cod','coupon:checkout:first',$quote,$fixed);
$stored=$orders->owned($buyer,$placed['id']);
$check($placed['subtotal_minor']===303&&$placed['discount_minor']===10&&$placed['tax_minor']===40&&$placed['total_minor']===333,'Tax uses discounted bases and distinct 18%/5% groups.');
$check(array_map('intval',array_column($stored['items'],'discount_minor'))===[4,3,3],'Largest-remainder discount allocation resolves ties by variant identity.');
$check(array_map('intval',array_column($stored['items'],'tax_minor'))===[17,18,5],'Tax rounds once per rate group and allocates exact line tax.');
$check(array_sum(array_column($stored['items'],'discount_minor'))===$placed['discount_minor']&&array_sum(array_column($stored['items'],'tax_minor'))===$placed['tax_minor'],'Item discounts and taxes sum to order snapshots.');
$check(array_map('intval',array_column($stored['items'],'tax_bps'))===[1800,1800,500],'Each order item snapshots the resolved category/default rate.');
$check($orders->place($buyer,$cart['id'],$cart['revision'],$address['id'],'cod','coupon:checkout:first',$quote,$fixed)===$placed,'Checkout replay returns the stored discounted order.');
$check((int)$pdo->query('SELECT used_count FROM coupons WHERE id='.$fixed)->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM coupon_redemptions')->fetchColumn()===1,'Replay never redeems a coupon twice.');
CommerceDatabase::transaction($pdo,function()use($coupons,$pdo,$fixed,$buyer,$placed,$check):void{$check($coupons->redeem($pdo,$fixed,$buyer,$placed['id'],303)===10,'Direct redemption replay is idempotent on the caller transaction.');});
$percent=$coupons->save($admin,['code'=>'PERCENT','kind'=>'percent','value'=>2500,'max_discount_minor'=>60],'coupon:percent:create');
$check($coupons->preview($cart,'PERCENT')['discount_minor']===60,'Percentage coupon honors its maximum cap.');
$uncapped=$coupons->save($admin,['code'=>'UNCAPPED','kind'=>'percent','value'=>2500],'coupon:uncapped:create');
$check($coupons->preview($cart,'UNCAPPED')['discount_minor']===76,'Uncapped percentage uses half-up integer rounding.');
$all=$coupons->save($admin,['code'=>'ALL','kind'=>'fixed','value'=>10000],'coupon:all:create');
$check($coupons->preview($cart,'ALL')['discount_minor']===303,'Discount cannot exceed the subtotal.');
$min=$coupons->save($admin,['code'=>'MIN','kind'=>'fixed','value'=>10,'min_order_minor'=>304],'coupon:min:create');
CommerceTest::rejects(fn()=>$coupons->preview($cart,'MIN'),409,'Minimum subtotal is enforced.');
$future=$coupons->save($admin,['code'=>'FUTURE','kind'=>'percent','value'=>1000,'starts_at'=>time()+3600],'coupon:future:create');
CommerceTest::rejects(fn()=>$coupons->preview($cart,'FUTURE'),409,'Future coupons are rejected.');
$expired=$coupons->save($admin,['code'=>'EXPIRED','kind'=>'fixed','value'=>10,'ends_at'=>time()-1],'coupon:expired:create');
CommerceTest::rejects(fn()=>$coupons->preview($cart,'EXPIRED'),409,'Expired coupons are rejected.');
CommerceTest::rejects(fn()=>$coupons->save($buyer,['code'=>'DENIED','kind'=>'fixed','value'=>10],'coupon:denied:create'),403,'Coupon writes require pricing permission.');
$coupons->archive($admin,$all,'coupon:all:archive');CommerceTest::rejects(fn()=>$coupons->preview($cart,'ALL'),409,'Archived coupons are unavailable.');
$current=$carts->change('user:'.$buyer,$vids[0],1000,0);$review=OrderService::quote($current,$coupons->preview($current,'PERCENT'));
$coupons->save($admin,['id'=>$percent,'code'=>'PERCENT','kind'=>'percent','value'=>2000,'max_discount_minor'=>60],'coupon:percent:change');
CommerceTest::rejects(fn()=>$orders->place($buyer,$current['id'],$current['revision'],$address['id'],'cod','coupon:changed:review',$review,$percent),409,'Changed coupon terms reject the accepted quote.');
$check((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1,'Coupon conflict rolls back order, stock and checkout effects.');

// A product at MAX_MONEY exercises allocation where price*discount would overflow a signed integer.
$large=$products->save($admin,['slug'=>'tax-large','name'=>'Large money boundary','category_id'=>$granite,'status'=>'active','variants'=>[
    ['sku'=>'TAX-LARGE','sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>CommerceValues::MAX_MONEY]]],0,'tax:product:large');
$largeVid=$large['variant_ids'][0];(new InventoryService($pdo))->adjust($admin,$largeVid,2000,'Large fixture','stock:tax:large');
$half=$coupons->save($admin,['code'=>'HALF','kind'=>'percent','value'=>5000],'coupon:half:create');
$largeCart=$carts->buyNow($buyer,$largeVid,1000,'tax:large:cart');
$largeOrder=$orders->place($buyer,$largeCart['id'],$largeCart['revision'],$address['id'],'cod','tax:large:checkout',null,$half);
$check($largeOrder['discount_minor']===500000000000&&$largeOrder['tax_minor']===25000000000,'Large allocation stays integer and cannot overflow intermediate money products.');

// Failure after redemption must roll back usage, money, stock, jobs and the idempotency record.
$snapshot=static function()use($pdo):array{$out=[];foreach(['coupons','coupon_redemptions','orders','order_items','jobs','checkout_requests','inventory'] as $table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.($table==='inventory'?'variant_id':'id'))->fetchAll();return $out;};
$before=$snapshot();$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$pdo->exec($mysql?"CREATE TRIGGER coupon_item_failure BEFORE INSERT ON order_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected'":"CREATE TRIGGER coupon_item_failure BEFORE INSERT ON order_items BEGIN SELECT RAISE(ABORT,'Injected'); END");
CommerceTest::fails(fn()=>$orders->place($buyer,$current['id'],$current['revision'],$address['id'],'cod','coupon:rollback:test',null,$fixed),'Injected item failure rejects the discounted checkout.');
$pdo->exec('DROP TRIGGER coupon_item_failure');$check($snapshot()===$before,'Coupon redemption and all checkout effects roll back together.');

// Two independent checkout processes, including two purchase carts owned by the same customer.
$fixture=CommerceTest::$temp.'/coupon-race.php';file_put_contents($fixture,'<?php require '.var_export(dirname(__DIR__).'/config/bootstarp.php',true).';'.<<<'CHILD'
$in=json_decode(stream_get_contents(STDIN),true);file_put_contents(getenv('TEST_READY'),'ready');$deadline=microtime(true)+8;
while(!is_file(getenv('TEST_BARRIER'))){if(microtime(true)>$deadline)throw new RuntimeException('Barrier timeout.');usleep(10000);}
if(($in['mode']??'')==='settings_read'){
    CommerceDatabase::transaction($pdo,function()use($pdo,$in):void {
        $settings=(new SettingsService($pdo))->effective();file_put_contents($in['ready'],'ready');$deadline=microtime(true)+3;
        while(!is_file($in['peer'])){if(microtime(true)>$deadline)throw new RuntimeException('Settings reads serialized checkouts.');usleep(10000);}
        if($settings['tax_bps']!==1800)throw new RuntimeException('Incoherent settings.');
    });echo json_encode(['status'=>200]);exit;
}
try{$result=(new OrderService($pdo))->place($in['user'],$in['cart'],$in['revision'],$in['address'],'cod',$in['key'],null,$in['coupon']);echo json_encode(['status'=>200,'id'=>$result['id']]);}
catch(RequestRejected $e){echo json_encode(['status'=>$e->status,'code'=>$e->errorCode]);}
CHILD);
if($mysql){
    $firstReady=CommerceTest::$temp.'/settings-reader-a';$secondReady=CommerceTest::$temp.'/settings-reader-b';
    $readers=CommerceTest::race([
        ['mode'=>'settings_read','ready'=>$firstReady,'peer'=>$secondReady],
        ['mode'=>'settings_read','ready'=>$secondReady,'peer'=>$firstReady]
    ],$fixture);
    $check(array_column($readers,'status')===[200,200],'Authoritative MySQL settings reads coexist without serializing unrelated checkouts.');
}
foreach(['usage_limit','per_user_limit'] as $limit){
    $coupon=$coupons->save($admin,['code'=>strtoupper($limit),'kind'=>'fixed','value'=>10,$limit=>1],'coupon:race:'.$limit);
    $secondUser=$limit==='per_user_limit'?$buyer:$other;$secondAddress=$limit==='per_user_limit'?$address:$otherAddress;
    $a=$carts->buyNow($buyer,$vids[0],1000,'coupon:race:a:'.$limit);$b=$carts->buyNow($secondUser,$vids[0],1000,'coupon:race:b:'.$limit);
    $results=CommerceTest::race([
        ['user'=>$buyer,'cart'=>$a['id'],'revision'=>$a['revision'],'address'=>$address['id'],'key'=>'coupon:race:place:a:'.$limit,'coupon'=>$coupon],
        ['user'=>$secondUser,'cart'=>$b['id'],'revision'=>$b['revision'],'address'=>$secondAddress['id'],'key'=>'coupon:race:place:b:'.$limit,'coupon'=>$coupon]
    ],$fixture);$statuses=array_column($results,'status');sort($statuses);
    $check($statuses===[200,409],'Concurrent '.$limit.' allows only one redemption.');
    $check((int)$pdo->query('SELECT used_count FROM coupons WHERE id='.$coupon)->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id='.$coupon)->fetchColumn()===1,'Concurrent '.$limit.' persists one use and one order redemption.');
}
$today=gmdate('Y-m-d');$report=(new AdminReadService($pdo))->reports($admin,$today,$today);
$check((int)$report['sales']['order_count']===0,'Unpaid COD orders are excluded from paid revenue.');
CommerceDatabase::transaction($pdo,fn()=>CommerceDatabase::query($pdo,"UPDATE orders SET payment_status='paid' WHERE id=?",[$placed['id']]));
$report=(new AdminReadService($pdo))->reports($admin,$today,$today);
$check((int)$report['sales']['order_count']===1&&$report['sales']['revenue_minor']===$placed['total_minor'],'Paid revenue aggregates exact persisted integer totals.');
// Category edits cannot rewrite the rate captured on an existing order item.
$products->saveCategory($admin,['id'=>$granite,'slug'=>'demo-granite','name'=>'Demo Granite','tax_bps'=>0],'category:tax:zero');
$products->saveCategory($admin,['id'=>$granite,'slug'=>'demo-granite','name'=>'Demo Granite','tax_bps'=>500],'category:tax:override');
$check((int)$pdo->query('SELECT tax_bps FROM categories WHERE id='.(int)$granite)->fetchColumn()===0,'Replaying an older category GST edit cannot overwrite the newer rate.');
$check((int)$orders->owned($buyer,$placed['id'])['items'][2]['tax_bps']===500,'Historical item tax rates survive a category override edit.');
$zeroCart=$carts->buyNow($other,$vids[2],1000,'tax:zero:cart');
$zeroOrder=$orders->place($other,$zeroCart['id'],$zeroCart['revision'],$otherAddress['id'],'cod','tax:zero:checkout');
$check($zeroOrder['tax_minor']===0,'An explicit zero category rate overrides the nonzero store default.');
CommerceTest::rejects(fn()=>(new AdminReadService($pdo))->reports($buyer,$today,$today),403,'Sales reports require analytics permission.');
CommerceTest::rejects(fn()=>(new AdminReadService($pdo))->reports($admin,'2020-01-01',$today),422,'Reports reject an unbounded date range.');
$check((new AdminReadService($pdo))->table($admin,'coupons',['length'=>10])['recordsTotal']>=8,'Coupons integrate with the bounded admin table engine.');
CommerceTest::finish('CouponTaxTest');
