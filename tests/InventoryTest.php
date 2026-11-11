<?php
require __DIR__.'/support/CommerceTest.php';
$pdo=CommerceTest::open(); $check=[CommerceTest::class,'check']; $service=new ProductService($pdo); $inventory=new InventoryService($pdo);
$admin=CommerceAccess::provision('inventory-admin@example.test','Disposable-Inventory-Password');
$a=CommerceTest::user('stock-a@example.test'); $b=CommerceTest::user('stock-b@example.test');
$category=$service->saveCategory($admin,['slug'=>'stock-test','name'=>'Stock Test'],'category:stock:test');
$product=['slug'=>'stock-marble','name'=>'Stock Marble','category_id'=>$category,'status'=>'active','variants'=>[
    ['sku'=>'STOCK-A','sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>1000],
    ['sku'=>'STOCK-B','sell_unit'=>'box','qty_increment_milli'=>1000,'unit_price_minor'=>5000,'coverage_sqft_milli'=>16000]]];
$saved=$service->save($admin,$product,0,'product:stock:create'); [$v1,$v2]=$saved['variant_ids'];
$check((int)$pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn()===2,'Every new variant gets exactly one zero-stock row atomically.');
$check(!$service->detail('stock-marble')['in_stock'],'Zero stock is never interpreted as unlimited.');
CommerceTest::rejects(fn()=>$inventory->adjust($a,$v1,5000,'Initial stock','stock:customer:deny'),403,'Customer cannot adjust inventory.');
$inventory->adjust($admin,$v1,5000,'Initial stock','stock:initial:first');
$inventory->adjust($admin,$v2,5000,'Initial stock','stock:initial:second');
$inventory->adjust($admin,$v1,5000,'Initial stock','stock:initial:first');
$check((int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===5000,'Adjustment replay changes stock once.');
CommerceTest::rejects(fn()=>$inventory->adjust($admin,$v1,6000,'Initial stock','stock:initial:first'),409,'Conflicting adjustment key rejected.');
CommerceTest::fails(fn()=>$inventory->reserve('user:'.$a,[['variant_id'=>$v1,'qty_milli'=>3000]],'reserve:no:transaction'),'Reserve requires caller transaction.');
$lines=[['variant_id'=>$v1,'qty_milli'=>3000]];
$r=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$a,$lines,'reserve:first:a'));
$check((int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===3000,'Reserve increments held stock.');
$same=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$a,$lines,'reserve:first:a'));
$check((int)$same['id']===(int)$r['id']&&(int)$pdo->query('SELECT COUNT(*) FROM inventory_reservation_items')->fetchColumn()===1,'Matching reservation replay does not duplicate quantities.');
CommerceTest::rejects(fn()=>CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$b,$lines,'reserve:first:b')),409,'Competing 3000 reservation cannot fit remaining 2000.');
CommerceTest::rejects(fn()=>$inventory->adjust($admin,$v1,-3000,'Invalid decrease','stock:below:reserved'),409,'Adjustment cannot reduce on-hand below reservations.');
$beforeHeaders=(int)$pdo->query('SELECT COUNT(*) FROM inventory_reservations')->fetchColumn();
CommerceTest::rejects(fn()=>CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$a,[['variant_id'=>$v1,'qty_milli'=>1000],['variant_id'=>$v2,'qty_milli'=>6000]],'reserve:all:validate')),409,'Every reservation line validates before any stock change.');
$check((int)$pdo->query('SELECT COUNT(*) FROM inventory_reservations')->fetchColumn()===$beforeHeaders
    &&(int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===3000,'Failed multiline reserve rolls back header/stock/movements.');
CommerceTest::rejects(fn()=>CommerceDatabase::transaction($pdo,fn()=>$inventory->consume((int)$r['id'],'user:'.$b)),404,'Another owner cannot consume reservation.');
CommerceDatabase::transaction($pdo,fn()=>$inventory->consume((int)$r['id'],'user:'.$a));
CommerceDatabase::transaction($pdo,fn()=>$inventory->consume((int)$r['id'],'user:'.$a));
$stock=$pdo->query("SELECT * FROM inventory WHERE variant_id=$v1")->fetch();
$check((int)$stock['on_hand_milli']===2000&&(int)$stock['reserved_milli']===0,'Consume subtracts both counters exactly once.');
CommerceTest::rejects(fn()=>CommerceDatabase::transaction($pdo,fn()=>$inventory->release((int)$r['id'],'user:'.$a)),409,'Consumed reservation cannot also release.');
$expired=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$a,[['variant_id'=>$v1,'qty_milli'=>1000]],'reserve:expiry:a'));
$pdo->prepare('UPDATE inventory_reservations SET expires_at=1 WHERE id=?')->execute([$expired['id']]);
CommerceTest::rejects(fn()=>CommerceDatabase::transaction($pdo,fn()=>$inventory->consume((int)$expired['id'],'user:'.$a)),409,'Expired reservation cannot be consumed.');
CommerceDatabase::transaction($pdo,fn()=>$inventory->release((int)$expired['id'],'user:'.$a,true));
CommerceDatabase::transaction($pdo,fn()=>$inventory->release((int)$expired['id'],'user:'.$a,true));
$check((int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===0,'Expired reserve releases exactly once.');
$hold=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$a,[['variant_id'=>$v2,'qty_milli'=>1000]],'reserve:hold:box'));
$product['id']=$saved['id']; foreach($product['variants']as$n=>&$v)$v['id']=$saved['variant_ids'][$n]; unset($v);
$product['variants'][1]['sell_unit']='sqft';
CommerceTest::rejects(fn()=>$service->save($admin,$product,1,'product:stocked:unit'),409,'Stocked unit cannot be reinterpreted by catalog update.');
CommerceDatabase::transaction($pdo,fn()=>$inventory->release((int)$hold['id'],'user:'.$a));
CommerceTest::fails(fn()=>$pdo->exec("UPDATE inventory SET reserved_milli=999999 WHERE variant_id=$v1"),'Database stock invariant also rejects reserved above on-hand.');
$fixture=CommerceTest::$temp.'/inventory-race.php';
file_put_contents($fixture,'<?php require '.var_export(dirname(__DIR__).'/config/bootstarp.php',true).';'. <<<'CHILD'
$input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR); $inventory=new InventoryService($pdo);
file_put_contents(getenv('TEST_READY'),'ready'); $deadline=microtime(true)+8;
while(!is_file(getenv('TEST_BARRIER'))) { if(microtime(true)>$deadline)throw new RuntimeException('Barrier timeout.'); usleep(10000); }
try {
 $result=CommerceDatabase::transaction($pdo,function() use($input,$inventory) {
  if($input['mode']==='adjust')throw new LogicException('Adjust uses its own transaction.');
  if($input['mode']==='reserve')return $inventory->reserve($input['principal'],$input['lines'],$input['key']);
  if($input['mode']==='consume')return $inventory->consume($input['id'],$input['principal']);
  return $inventory->release($input['id'],$input['principal'],true);
 });
 echo json_encode(['status'=>200,'state'=>$result['state']]);
} catch(RequestRejected $e) { echo json_encode(['status'=>$e->status,'code'=>$e->errorCode]); }
CHILD);
// Restore first stock to 5000, then race two real transactions for 3000 each.
$inventory->adjust($admin,$v1,3000,'Race stock','stock:race:restore');
$race=CommerceTest::race([
 ['mode'=>'reserve','principal'=>'user:'.$a,'lines'=>$lines,'key'=>'reserve:race:a'],
 ['mode'=>'reserve','principal'=>'user:'.$b,'lines'=>$lines,'key'=>'reserve:race:b']],$fixture);
$statuses=array_column($race,'status'); sort($statuses);
$check($statuses===[200,409],'Independent transactions permit only one competing reservation.');
$check((int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===3000,'Reservation race leaves one held quantity.');
$winner=$pdo->query("SELECT * FROM inventory_reservations WHERE business_key IN ('reserve:race:a','reserve:race:b')")->fetch();
$pdo->prepare('UPDATE inventory_reservations SET expires_at=1 WHERE id=?')->execute([$winner['id']]);
$race=CommerceTest::race([
 ['mode'=>'consume','id'=>(int)$winner['id'],'principal'=>$winner['principal_key']],
 ['mode'=>'expire','id'=>(int)$winner['id'],'principal'=>$winner['principal_key']]],$fixture);
$statuses=array_column($race,'status'); sort($statuses);
$check($statuses===[200,409],'Expiry/consume race closes only the legal transition.');
$stock=$pdo->query("SELECT * FROM inventory WHERE variant_id=$v1")->fetch();
$check((int)$stock['on_hand_milli']===5000&&(int)$stock['reserved_milli']===0,'Expiry race preserves on-hand and releases held stock once.');
$live=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$a,[['variant_id'=>$v1,'qty_milli'=>1000]],'reserve:consume:race'));
$race=CommerceTest::race([
 ['mode'=>'consume','id'=>(int)$live['id'],'principal'=>'user:'.$a],
 ['mode'=>'consume','id'=>(int)$live['id'],'principal'=>'user:'.$a]],$fixture);
$check(array_column($race,'status')===[200,200],'Concurrent matching consume retries both resolve successfully.');
$check((int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===4000
 &&(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE business_key='consume:".(int)$live['id']."'")->fetchColumn()===1,'Concurrent consume changes stock and movement once.');
$adjustFixture=CommerceTest::$temp.'/adjust-race.php';
file_put_contents($adjustFixture,'<?php require '.var_export(dirname(__DIR__).'/config/bootstarp.php',true).';'. <<<'CHILD'
$input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);file_put_contents(getenv('TEST_READY'),'ready');$deadline=microtime(true)+8;
while(!is_file(getenv('TEST_BARRIER'))){if(microtime(true)>$deadline)throw new RuntimeException('Barrier timeout.');usleep(10000);}
$result=(new InventoryService($pdo))->adjust($input['actor'],$input['variant'],1000,'Concurrent adjustment','stock:adjust:concurrent');
echo json_encode(['on_hand'=>(int)$result['on_hand_milli']]);
CHILD);
$race=CommerceTest::race([['actor'=>$admin,'variant'=>$v1],['actor'=>$admin,'variant'=>$v1]],$adjustFixture);
$check((int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===5000
 &&(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE business_key='stock:adjust:concurrent'")->fetchColumn()===1,'Concurrent adjustment key changes stock once and both callers recover.');
CommerceTest::finish('Inventory and reservation races');
