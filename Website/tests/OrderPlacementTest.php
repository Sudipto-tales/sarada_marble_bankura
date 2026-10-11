<?php
require __DIR__ . '/support/CommerceTest.php';
$pdo=CommerceTest::open();$check=[CommerceTest::class,'check'];
foreach(['CHECKOUT_COD_ENABLED'=>'true','CHECKOUT_SHIPPING_MINOR'=>'100','CHECKOUT_TAX_BPS'=>'500'] as $name=>$value) {$_ENV[$name]=$value;putenv($name.'='.$value);}
$admin=CommerceAccess::provision('checkout-admin@example.test','Disposable-Checkout-Password');
$a=CommerceTest::user('checkout-a@example.test');$b=CommerceTest::user('checkout-b@example.test');
$products=new ProductService($pdo);$inventory=new InventoryService($pdo);$carts=new CartService($pdo);$orders=new OrderService($pdo);$addresses=new AddressService($pdo);
$category=$products->saveCategory($admin,['slug'=>'checkout-stones','name'=>'Checkout Stones'],'category:checkout:stones');
$input=['slug'=>'checkout-marble','name'=>'Original Checkout Marble','category_id'=>$category,'status'=>'active','variants'=>[
 ['sku'=>'CHECKOUT-SQFT','sell_unit'=>'sqft','qty_increment_milli'=>500,'unit_price_minor'=>101],
 ['sku'=>'CHECKOUT-BOX','sell_unit'=>'box','qty_increment_milli'=>1000,'coverage_sqft_milli'=>16000,'unit_price_minor'=>12500]]];
$product=$products->save($admin,$input,0,'product:checkout:create');[$v1,$v2]=$product['variant_ids'];
$inventory->adjust($admin,$v1,50000,'Initial stock','stock:checkout:first');$inventory->adjust($admin,$v2,50000,'Initial stock','stock:checkout:second');
$addressInput=['recipient'=>'Original Recipient','phone'=>'9999988888','line1'=>'Original Road','city'=>'Bankura','state'=>'West Bengal','postal_code'=>'722101'];
$addrA=$addresses->save($a,$addressInput);$addrB=$addresses->save($b,array_replace($addressInput,['recipient'=>'Other Customer']));
$cart=$carts->change('user:'.$a,$v1,500,0);$cart=$carts->change('user:'.$a,$v2,1000,$cart['revision'],$cart['id']);
CommerceTest::rejects(fn()=>$orders->place($a,$cart['id'],$cart['revision'],$addrB['id'],'cod','checkout:wrong:owner'),404,'Address ownership enforced.');
CommerceTest::rejects(fn()=>$orders->place($b,$cart['id'],$cart['revision'],$addrB['id'],'cod','checkout:wrong:cart'),404,'Cart ownership enforced.');
CommerceTest::rejects(fn()=>$orders->place($a,$cart['id'],$cart['revision']-1,$addrA['id'],'cod','checkout:wrong:revision'),409,'Stale cart revision rejected.');
$check((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM checkout_requests')->fetchColumn()===0,'Rejected checkout leaves no order or pending key.');
$placed=$orders->place($a,$cart['id'],$cart['revision'],$addrA['id'],'cod','checkout:original:key');
$check($placed['subtotal_minor']===12551&&$placed['tax_minor']===628&&$placed['total_minor']===13279,'Half-up rounding, tax and shipping produce exact integer totals.');
$stored=$orders->owned($a,$placed['id']);
$check(array_sum(array_map(fn($line)=>(int)$line['tax_minor'],$stored['items']))===628,'Allocated line taxes sum to rounded order tax.');
$check($stored['payment_status']==='unpaid'&&count($stored['history'])===1,'COD remains unpaid with one placement history.');
$stock=$pdo->query("SELECT * FROM inventory WHERE variant_id=$v1")->fetch();
$check((int)$stock['on_hand_milli']===49500&&(int)$stock['reserved_milli']===0,'Placement consumes stock once.');
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type IN ('order_confirmation','purchase_event')")->fetchColumn()===2,'Confirmation and held purchase intents commit with checkout.');
$check($carts->get('user:'.$a)['items']===[],'Service closes accepted cart.');
$check($orders->place($a,$cart['id'],$cart['revision'],$addrA['id'],'cod','checkout:original:key')===$placed,'Lost-response retry returns saved result despite closed cart.');
$_ENV['CHECKOUT_COD_ENABLED']='false';
$check($orders->place($a,$cart['id'],$cart['revision'],$addrA['id'],'cod','checkout:original:key')===$placed,'Completed retry remains valid after store disables new COD orders.');
$_ENV['CHECKOUT_COD_ENABLED']='true';
CommerceTest::rejects(fn()=>$orders->place($a,$cart['id'],$cart['revision']+1,$addrA['id'],'cod','checkout:original:key'),409,'Changed inputs with same key conflict.');
CommerceTest::rejects(fn()=>$orders->place($a,$cart['id'],$cart['revision'],$addrA['id'],'cod','checkout:new:key'),409,'New key cannot reuse checked-out cart.');
CommerceTest::rejects(fn()=>$orders->owned($b,$placed['id']),404,'Other customer cannot read order.');
$input['id']=$product['id'];$input['name']='Changed Checkout Marble';
foreach($input['variants'] as $n=>&$variant){$variant['id']=$product['variant_ids'][$n];$variant['unit_price_minor']+=100;}unset($variant);
$products->save($admin,$input,1,'product:checkout:changed');
$addresses->save($a,array_replace($addressInput,['id'=>$addrA['id'],'line1'=>'Changed Road']),1);
$stored=$orders->owned($a,$placed['id']);
$check($stored['items'][0]['name']==='Original Checkout Marble'&&(int)$stored['items'][0]['unit_price_minor']===101&&$stored['address']['line1']==='Original Road','Order snapshots survive catalog/address changes.');
$state=static function()use($pdo):array{
 $out=[];foreach(['orders','order_items','order_status_history','checkout_requests','inventory','inventory_reservations','inventory_reservation_items','inventory_movements','jobs','admin_activity_log','catalog_versions','carts','cart_items']as$table){
 $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.($table==='catalog_versions'?'namespace':($table==='inventory'?'variant_id':'id')))->fetchAll();}return$out;
};
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$inject=static function(string$table,callable$operation)use($pdo,$mysql):void{
 $pdo->exec($mysql?"CREATE TRIGGER checkout_test_failure BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected failure'":"CREATE TRIGGER checkout_test_failure BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT,'Injected failure'); END");
 try{$operation();}finally{$pdo->exec('DROP TRIGGER checkout_test_failure');}
};
$cart2=$carts->change('user:'.$a,$v1,1000,0);
$quote=OrderService::quote($cart2);
$pdo->prepare('UPDATE product_variants SET unit_price_minor=unit_price_minor+1 WHERE id=?')->execute([$v1]);
$before=$state();
CommerceTest::rejects(fn()=>$orders->place($a,$cart2['id'],$cart2['revision'],$addrA['id'],'cod','checkout:changed:price',$quote),409,'Changed authoritative price requires a new review.');
$check($state()===$before,'Price conflict rolls back all checkout effects.');
$pdo->prepare('UPDATE product_variants SET unit_price_minor=unit_price_minor-1 WHERE id=?')->execute([$v1]);
$_ENV['CHECKOUT_SHIPPING_MINOR']='200'; $before=$state();
CommerceTest::rejects(fn()=>$orders->place($a,$cart2['id'],$cart2['revision'],$addrA['id'],'cod','checkout:changed:shipping',$quote),409,'Changed delivery policy requires a new review.');
$check($state()===$before,'Delivery conflict rolls back all checkout effects.');
$_ENV['CHECKOUT_SHIPPING_MINOR']='100';
foreach(['order_items','jobs']as$table){$before=$state();
 $inject($table,fn()=>CommerceTest::fails(fn()=>$orders->place($a,$cart2['id'],$cart2['revision'],$addrA['id'],'cod','checkout:rollback:'.$table),'Injected '.$table.' failure rejects placement.'));
 $check($state()===$before,'Injected '.$table.' failure rolls back stock/snapshots/keys/jobs/cart/cache.');
}
$hold=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$a,[['variant_id'=>$v1,'qty_milli'=>1000]],'reserve:checkout:existing',$cart2['id'],$cart2['revision']));
$pdo->prepare('UPDATE inventory_reservations SET expires_at=1 WHERE id=?')->execute([$hold['id']]);$before=$state();
CommerceTest::rejects(fn()=>$orders->place($a,$cart2['id'],$cart2['revision'],$addrA['id'],'cod','checkout:expired:hold'),409,'Expired held stock cannot be consumed.');
$check($state()===$before,'Expired checkout preserves all business state.');
CommerceDatabase::transaction($pdo,fn()=>$inventory->release($hold['id'],'user:'.$a,true));
$hold=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$a,[['variant_id'=>$v1,'qty_milli'=>1000]],'reserve:checkout:live',$cart2['id'],$cart2['revision']));
$heldOrder=$orders->place($a,$cart2['id'],$cart2['revision'],$addrA['id'],'cod','checkout:live:hold');
$check((int)$pdo->query('SELECT COUNT(*) FROM inventory_reservations WHERE cart_id='.$cart2['id'])->fetchColumn()===2,'Matching hold consumed without duplicate active reservation.');
$check($pdo->query('SELECT state FROM inventory_reservations WHERE id='.$hold['id'])->fetchColumn()==='consumed','Existing hold closes as consumed.');
CommerceTest::rejects(fn()=>$orders->cancel($a,$placed['id'],1,'cancel:customer:denied'),403,'Staff cancellation requires permission.');
CommerceTest::rejects(fn()=>$orders->cancel($admin,$placed['id'],2,'cancel:stale:revision'),409,'Cancellation rejects stale revision.');
$otherHold=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$b,[['variant_id'=>$v1,'qty_milli'=>1000]],'reserve:other:customer'));
$before=$state();$inject('jobs',fn()=>CommerceTest::fails(fn()=>$orders->cancel($admin,$placed['id'],1,'cancel:rollback:queue'),'Cancellation queue failure rejects operation.'));
$check($state()===$before,'Cancellation failure rolls back stock/history/audit/version/status.');
$oldStock=$pdo->query("SELECT * FROM inventory WHERE variant_id=$v1")->fetch();$cancelled=$orders->cancel($admin,$placed['id'],1,'cancel:original:key');
$newStock=$pdo->query("SELECT * FROM inventory WHERE variant_id=$v1")->fetch();
$check($cancelled===['id'=>$placed['id'],'status'=>'cancelled','revision'=>2]&&(int)$newStock['on_hand_milli']===(int)$oldStock['on_hand_milli']+500&&(int)$newStock['reserved_milli']===1000,'Staff cancels customer order and restores stock while preserving unrelated holds.');
$before=$state();$check($orders->cancel($admin,$placed['id'],1,'cancel:original:key')===$cancelled&&$state()===$before,'Cancellation replay has no further effects.');
CommerceTest::rejects(fn()=>$orders->cancel($admin,$placed['id'],2,'cancel:original:key'),409,'Changed replay revision conflicts.');
CommerceTest::rejects(fn()=>$orders->cancel($admin,$placed['id'],2,'cancel:another:key'),409,'Second key cannot restock cancelled order.');
$check((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE business_key='cancel:".$placed['id']."'")->fetchColumn()===2,'Exactly one cancel movement per variant.');
CommerceDatabase::transaction($pdo,fn()=>$inventory->release($otherHold['id'],'user:'.$b));
$confirmed=$orders->transition($admin,$heldOrder['id'],'confirmed',1,'transition:confirmed:key');$before=$state();
$check($orders->transition($admin,$heldOrder['id'],'confirmed',1,'transition:confirmed:key')===$confirmed&&$state()===$before,'Non-stock status transition is idempotent.');
$orders->transition($admin,$heldOrder['id'],'processing',2,'transition:processing:key');$orders->transition($admin,$heldOrder['id'],'shipped',3,'transition:shipped:key');
CommerceTest::rejects(fn()=>$orders->cancel($admin,$heldOrder['id'],4,'cancel:shipped:denied'),409,'Shipped orders cannot be cancelled.');
$fixture=CommerceTest::$temp.'/checkout-race.php';
file_put_contents($fixture,'<?php require '.var_export(dirname(__DIR__).'/config/bootstarp.php',true).';'.<<<'CHILD'
$in=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);file_put_contents(getenv('TEST_READY'),'ready');$deadline=microtime(true)+8;
while(!is_file(getenv('TEST_BARRIER'))){if(microtime(true)>$deadline)throw new RuntimeException('Barrier timeout.');usleep(10000);}
try{$orders=new OrderService($pdo);$result=$in['mode']==='cancel'?$orders->cancel($in['actor'],$in['id'],$in['revision'],$in['key']):$orders->place($in['user'],$in['cart'],$in['revision'],$in['address'],'cod',$in['key']);echo json_encode(['status'=>200,'result'=>$result]);}
catch(RequestRejected$e){echo json_encode(['status'=>$e->status,'code'=>$e->errorCode]);}
CHILD);
$remaining=(int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$v1")->fetchColumn();$inventory->adjust($admin,$v1,1000-$remaining,'Last unit fixture','stock:checkout:last');
$raceA=$carts->change('user:'.$a,$v1,1000,0);$raceB=$carts->change('user:'.$b,$v1,1000,0);
$inputs=[['mode'=>'place','user'=>$a,'cart'=>$raceA['id'],'revision'=>$raceA['revision'],'address'=>$addrA['id'],'key'=>'checkout:race:a'],
 ['mode'=>'place','user'=>$b,'cart'=>$raceB['id'],'revision'=>$raceB['revision'],'address'=>$addrB['id'],'key'=>'checkout:race:b']];
$results=CommerceTest::race($inputs,$fixture);$statuses=array_column($results,'status');sort($statuses);
$check($statuses===[200,409]&&(int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===0,'Two buyers for last unit produce one order and no oversell.');
$winner=array_values(array_filter($results,fn($r)=>$r['status']===200))[0]['result'];
$cancelInput=['mode'=>'cancel','actor'=>$admin,'id'=>$winner['id'],'revision'=>1,'key'=>'cancel:race:identical'];$results=CommerceTest::race([$cancelInput,$cancelInput],$fixture);
$check(array_column($results,'status')===[200,200]&&$results[0]['result']===$results[1]['result']&&(int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===1000,'Simultaneous cancel replay restores stock once.');
$winnerUser=(int)$pdo->query('SELECT user_id FROM orders WHERE id='.$winner['id'])->fetchColumn();$raceCart=$carts->change('user:'.$winnerUser,$v1,1000,0);
$input=['mode'=>'place','user'=>$winnerUser,'cart'=>$raceCart['id'],'revision'=>$raceCart['revision'],'address'=>$winnerUser===$a?$addrA['id']:$addrB['id'],'key'=>'checkout:race:identical'];
$beforeCount=(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();$results=CommerceTest::race([$input,$input],$fixture);
$check(array_column($results,'status')===[200,200]&&$results[0]['result']===$results[1]['result']&&(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$beforeCount+1,'Identical concurrent checkout keys create one order and return same result.');
$check((int)$pdo->query('SELECT COUNT(*) FROM inventory WHERE on_hand_milli<0 OR reserved_milli<0 OR reserved_milli>on_hand_milli')->fetchColumn()===0,'Contention preserves inventory invariants.');
$attempts=0;$version=(int)$pdo->query("SELECT version FROM catalog_versions WHERE namespace='catalog'")->fetchColumn();
CommerceDatabase::transaction($pdo,function()use($pdo,&$attempts):void{
 ProductService::advanceVersion($pdo);$attempts++;
 if($attempts<3){$error=new PDOException('Injected retryable deadlock',40001);$error->errorInfo=['40001',1213,'Injected'];throw$error;}
});
$check($attempts===3&&(int)$pdo->query("SELECT version FROM catalog_versions WHERE namespace='catalog'")->fetchColumn()===$version+1,'Injected deadlock classifier retries the whole transaction and commits one effect.');
$attempts=0;$before=$state();
CommerceTest::fails(function()use($pdo,&$attempts):void{CommerceDatabase::transaction($pdo,function()use($pdo,&$attempts):void{
 ProductService::advanceVersion($pdo);$attempts++;$error=new PDOException('Injected retryable deadlock',40001);$error->errorInfo=['40001',1213,'Injected'];throw$error;
});},'Persistent retryable failure terminates.');
$check($attempts===3&&$state()===$before,'Deadlock retry budget stops at three attempts with all effects rolled back.');
CommerceTest::finish('Atomic checkout and order transitions');
