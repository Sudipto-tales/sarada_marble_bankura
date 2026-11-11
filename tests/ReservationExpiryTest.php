<?php
// ORD-02: reservation-expiry consumer and low-stock alerts.
require __DIR__.'/support/CommerceTest.php';
$pdo=CommerceTest::open(); $check=[CommerceTest::class,'check'];
$admin=CommerceAccess::provision('ord2-admin@example.test','Disposable-Ord2-Password');
$buyer=CommerceTest::user('ord2-buyer@example.test');
$service=new ProductService($pdo); $inventory=new InventoryService($pdo); $worker=new QueueWorker($pdo);
$category=$service->saveCategory($admin,['slug'=>'ord2-stone','name'=>'Ord2 Stone'],'ord2:category');
$saved=$service->save($admin,['slug'=>'ord2-marble','name'=>'Ord2 Marble','category_id'=>$category,'status'=>'active',
    'variants'=>[['sku'=>'ORD2-A','sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>1000],
                 ['sku'=>'ORD2-LOW','sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>1000]]],0,'ord2:product');
[$v1,$low]=$saved['variant_ids'];
$inventory->adjust($admin,$v1,5000,'Opening stock','ord2:stock:v1');
$lines=[['variant_id'=>$v1,'qty_milli'=>3000]];

// Reserve enqueues a durable expiry job that only becomes available at the lease expiry.
$r=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$buyer,$lines,'ord2:reserve:a'));
$rid=(int)$r['id']; $now=CommerceDatabase::clock($pdo);
$job=$pdo->query("SELECT * FROM jobs WHERE dedupe_key='reservation:$rid:expiry:v1'")->fetch();
$check($job&&$job['queue']==='maintenance'&&(int)$job['available_at']>$now+800,'Reserve enqueues a maintenance expiry job delayed until the reservation expires.');
$check((int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===3000,'Active reservation holds its stock.');

// A still-active (not yet expired) reservation is left untouched even if its job is claimed early.
$pdo->exec("UPDATE jobs SET available_at=0 WHERE dedupe_key='reservation:$rid:expiry:v1'");
$worker->run(['maintenance'],10,30);
$check((int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===3000
    &&$pdo->query("SELECT state FROM inventory_reservations WHERE id=$rid")->fetchColumn()==='active','An unexpired reservation is not released early.');

// Once expired, the job releases the stock exactly once and is acknowledged.
$pdo->prepare('UPDATE inventory_reservations SET expires_at=1 WHERE id=?')->execute([$rid]);
$pdo->exec("UPDATE jobs SET available_at=0 WHERE dedupe_key='reservation:$rid:expiry:v1'");
$stats=$worker->run(['maintenance'],10,30);
$check($stats['completed']===1,'Expiry worker completes the release job.');
$check((int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===0
    &&$pdo->query("SELECT state FROM inventory_reservations WHERE id=$rid")->fetchColumn()==='expired','Expired reservation releases its held stock.');
$check((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reservation_id=$rid AND reason='expired'")->fetchColumn()===1,'Release writes exactly one expiry movement.');
$check(!$pdo->query("SELECT id FROM jobs WHERE dedupe_key='reservation:$rid:expiry:v1'")->fetchColumn(),'Completed expiry job is acknowledged.');

// Replaying the release is idempotent: already-closed reservation does nothing.
$replay=CommerceDatabase::transaction($pdo,fn()=>$inventory->expireReservation($rid));
$check($replay==='closed'&&(int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$v1")->fetchColumn()===0
    &&(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reservation_id=$rid AND reason='expired'")->fetchColumn()===1,'Replayed release makes no second stock change.');

// A consumed reservation's expiry job is a safe no-op that still acknowledges.
$r2=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$buyer,[['variant_id'=>$v1,'qty_milli'=>1000]],'ord2:reserve:consume'));
$rid2=(int)$r2['id'];
CommerceDatabase::transaction($pdo,fn()=>$inventory->consume($rid2,'user:'.$buyer));
$pdo->exec("UPDATE jobs SET available_at=0 WHERE dedupe_key='reservation:$rid2:expiry:v1'");
$stats=$worker->run(['maintenance'],10,30);
$onHand=(int)$pdo->query("SELECT on_hand_milli FROM inventory WHERE variant_id=$v1")->fetchColumn();
$check($stats['completed']===1&&$onHand===4000&&$pdo->query("SELECT state FROM inventory_reservations WHERE id=$rid2")->fetchColumn()==='consumed'
    &&!$pdo->query("SELECT id FROM jobs WHERE dedupe_key='reservation:$rid2:expiry:v1'")->fetchColumn(),'Consumed reservation expiry job acknowledges without altering stock.');

// Low-stock alerts: default-off until a threshold is configured.
$inventory->adjust($admin,$low,10000,'Opening stock','ord2:low:open');
$check((int)$pdo->query('SELECT COUNT(*) FROM low_stock_alerts')->fetchColumn()===0,'No low-stock alert fires while the threshold is zero (default-off).');

$_ENV['INVENTORY_LOW_STOCK_THRESHOLD_MILLI']='4000'; $_ENV['OPS_ALERT_EMAIL']='ops@example.test'; $_ENV['MAIL_TRANSPORT']='test';
$inventory->adjust($admin,$low,-7000,'Sale','ord2:low:dip1'); // 10000 -> 3000, crosses below 4000
$check((int)$pdo->query("SELECT COUNT(*) FROM low_stock_alerts WHERE variant_id=$low")->fetchColumn()===1
    &&(int)$pdo->query("SELECT low_stock_notified FROM inventory WHERE variant_id=$low")->fetchColumn()===1
    &&(int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='low_stock_alert'")->fetchColumn()===1,'Crossing the threshold fires one alert and flags the variant.');
$inventory->adjust($admin,$low,-1000,'Sale','ord2:low:dip2'); // 3000 -> 2000, still low
$check((int)$pdo->query("SELECT COUNT(*) FROM low_stock_alerts WHERE variant_id=$low")->fetchColumn()===1,'A further dip while already low does not re-alert.');
$inventory->adjust($admin,$low,5000,'Restock','ord2:low:recover'); // 2000 -> 7000, recovers
$check((int)$pdo->query("SELECT low_stock_notified FROM inventory WHERE variant_id=$low")->fetchColumn()===0,'Recovering above the threshold clears the alert flag.');
$inventory->adjust($admin,$low,-4000,'Sale','ord2:low:dip3'); // 7000 -> 3000, crosses again
$check((int)$pdo->query("SELECT COUNT(*) FROM low_stock_alerts WHERE variant_id=$low")->fetchColumn()===2,'A fresh dip after recovery alerts again.');

$files=static fn()=>glob(CommerceTest::$temp.'/private/test-mail/*.json')?:[];
$before=count($files());
$stats=$worker->run(['emails'],10,30);
$check($stats['completed']===2&&count($files())===$before+2,'Both low-stock alerts deliver through the worker.');
$subjects=array_map(static fn($f)=>json_decode(file_get_contents($f),true)['subject'],$files());
$check(in_array('Low stock alert: ORD2-LOW',$subjects,true),'Alert email names the affected SKU.');
$check((int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE type='low_stock_alert' AND status='sent'")->fetchColumn()===2,'Each alert records a distinct sent delivery.');

// A missing operator recipient fails safely and retries once configured.
$_ENV['OPS_ALERT_EMAIL']='';
$inventory->adjust($admin,$low,5000,'Restock','ord2:low:recover2');
$inventory->adjust($admin,$low,-5000,'Sale','ord2:low:dip4'); // crosses again -> enqueues job #3
$stats=$worker->run(['emails'],10,30);
$check($stats['failed']===1&&(int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE type='low_stock_alert'")->fetchColumn()===2,'Alert with no configured recipient fails without creating a delivery.');
$_ENV['OPS_ALERT_EMAIL']='ops@example.test'; $pdo->exec("UPDATE jobs SET available_at=0 WHERE type='low_stock_alert'");
$before=count($files()); $stats=$worker->run(['emails'],10,30);
$check($stats['completed']===1&&count($files())===$before+1,'Configuring the recipient lets the held alert deliver on retry.');

CommerceTest::finish('Reservation expiry and low-stock (ORD-02)');
