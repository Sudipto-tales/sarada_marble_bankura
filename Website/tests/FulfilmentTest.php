<?php
require __DIR__.'/support/CommerceTest.php';
$pdo=CommerceTest::open();$check=[CommerceTest::class,'check'];
foreach(['CHECKOUT_COD_ENABLED'=>'true','CHECKOUT_TAX_BPS'=>'0','CHECKOUT_SHIPPING_MINOR'=>'0','MAIL_TRANSPORT'=>'test'] as $key=>$value)$_ENV[$key]=$value;
$admin=CommerceAccess::provision('fulfil-admin@example.test','Disposable-Fulfil-Password');$buyer=CommerceTest::user('fulfil-buyer@example.test');
$products=new ProductService($pdo);$category=$products->saveCategory($admin,['slug'=>'fulfil-stone','name'=>'Fulfil stone'],'fulfil:category');
$product=$products->save($admin,['slug'=>'fulfil-marble','name'=>'Fulfil <stone>','category_id'=>$category,'status'=>'active',
    'variants'=>[['sku'=>'FULFIL-BOX','sell_unit'=>'box','qty_increment_milli'=>1000,'coverage_sqft_milli'=>16000,'unit_price_minor'=>12500]]],0,'fulfil:product');
$variant=$product['variant_ids'][0];(new InventoryService($pdo))->adjust($admin,$variant,10000,'Test opening stock','fulfil:stock');
$address=(new AddressService($pdo))->save($buyer,['recipient'=>'Test Buyer','phone'=>'9999988888','line1'=>'Test Road','city'=>'Bankura','state'=>'West Bengal','postal_code'=>'722101']);
$orders=new OrderService($pdo);$carts=new CartService($pdo);$producer=new QueueService($pdo);$worker=new QueueWorker($pdo);
$cart=$carts->change('user:'.$buyer,$variant,1000,0);
$order=$orders->place($buyer,$cart['id'],$cart['revision'],$address['id'],'cod','fulfil:place:first');$id=$order['id'];
$files=static fn()=>glob(CommerceTest::$temp.'/private/test-mail/*.json')?:[];
$worker->run(['emails'],10,30);
$check(count($files())===1&&$pdo->query("SELECT status FROM notification_deliveries WHERE delivery_key='order_confirmation:$id:v1'")->fetchColumn()==='sent','Existing confirmation still delivers through the queued worker.');
$orders->transition($admin,$id,'awaiting_verification',1,'fulfil:transition:verify');
$revision=2;
foreach(['confirmed','processing','shipped'] as $status){
    $orders->transition($admin,$id,$status,$revision++,'fulfil:transition:'.$status);
    $job=CommerceDatabase::query($pdo,'SELECT * FROM jobs WHERE dedupe_key=?',['order:'.$id.':status:'.$status.':v1'])->fetch();
    $check($job&&$job['type']==='order_status'&&json_decode($job['payload'],true)===['order_id'=>$id],'Transition '.$status.' enqueues a distinct status job with an ID-only payload.');
}
$stats=$worker->run(['emails'],10,30);
$check($stats['completed']===3&&count($files())===2,'Delayed status jobs coalesce to one email describing the current shipped status.');
$subjects=array_map(static fn($file)=>json_decode(file_get_contents($file),true)['subject'],$files());
$check(in_array('Your Maa Sarada order is on the way',$subjects,true),'Shipped status renders an appropriate subject.');
$check((int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE type='order_status'")->fetchColumn()===1,'Delayed jobs share one delivery marker for the same current status.');
foreach(['outForDelivery','delivered'] as $status){
    $orders->transition($admin,$id,$status,$revision++,'fulfil:transition:'.$status);$worker->run(['emails'],10,30);
}
$subjects=array_map(static fn($file)=>json_decode(file_get_contents($file),true)['subject'],$files());
$check(in_array('Your Maa Sarada order is out for delivery',$subjects,true)&&in_array('Your Maa Sarada order was delivered',$subjects,true),'Later statuses each deliver their own appropriate email.');
$check((int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE type='order_status' AND status='sent'")->fetchColumn()===3,'Shipped, out-for-delivery and delivered have separate sent delivery markers.');
$before=count($files());
$orders->transition($admin,$id,'delivered',6,'fulfil:transition:delivered');
CommerceDatabase::transaction($pdo,fn()=>$producer->orderStatus($id,'delivered'));
$worker->run(['emails'],10,30);
$check(count($files())===$before,'Replaying transition or deleted successful notification does not resend.');
CommerceTest::rejects(fn()=>CommerceDatabase::transaction($pdo,fn()=>$producer->orderStatus($id,'unknown')),422,'Unknown status jobs are rejected.');

$cart=$carts->change('user:'.$buyer,$variant,1000,0);$second=$orders->place($buyer,$cart['id'],$cart['revision'],$address['id'],'cod','fulfil:place:second');
$orders->transition($admin,$second['id'],'awaiting_verification',1,'fulfil:second:verify');
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$snapshot=static function()use($pdo):array{$out=[];foreach(['orders','order_status_history','admin_activity_log','jobs'] as $table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();return $out;};
$before=$snapshot();
$pdo->exec($mysql?"CREATE TRIGGER fulfil_queue_failure BEFORE INSERT ON jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected'":"CREATE TRIGGER fulfil_queue_failure BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT,'Injected'); END");
CommerceTest::fails(fn()=>$orders->transition($admin,$second['id'],'confirmed',2,'fulfil:rollback:transition'),'Status enqueue failure rejects transition.');
$pdo->exec('DROP TRIGGER fulfil_queue_failure');$check($snapshot()===$before,'Failed transition rolls back status, history, audit and notification intent.');
$orders->transition($admin,$second['id'],'confirmed',2,'fulfil:second:confirmed');
$_ENV['MAIL_TEST_FAILURE']='true';$stats=$worker->run(['emails'],10,30);
$check($stats['failed']===2&&(int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE target_id=".$second['id']." AND status='pending'")->fetchColumn()===2,'Failed status/confirmation transport remains pending and classified.');
$_ENV['MAIL_TEST_FAILURE']='false';$pdo->exec("UPDATE jobs SET available_at=0 WHERE queue='emails'");$stats=$worker->run(['emails'],10,30);
$check($stats['completed']===2,'Bounded worker retries succeed after transport recovery.');
$orders->cancel($admin,$second['id'],3,'fulfil:second:cancel');$worker->run(['emails'],10,30);
$check($pdo->query("SELECT status FROM notification_deliveries WHERE delivery_key='order_cancelled:".$second['id'].":v1'")->fetchColumn()==='sent','Cancellation still uses its original delivery path.');
CommerceDatabase::transaction($pdo,fn()=>$producer->orderStatus($second['id'],'shipped'));$before=count($files());$worker->run(['emails'],10,30);
$check(count($files())===$before,'Stale shipping job cannot send after cancellation.');
CommerceTest::finish('FulfilmentTest');
