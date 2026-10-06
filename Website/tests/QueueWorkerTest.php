<?php
require __DIR__.'/support/CommerceTest.php';$pdo=CommerceTest::open();$check=[CommerceTest::class,'check'];$queue=new Queue($pdo);$typed=new QueueService($pdo);$worker=new QueueWorker($pdo);$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$id=CommerceDatabase::transaction($pdo,fn()=>$typed->orderConfirmation(1));$job=$queue->claim(['emails'],'worker:first');
$check((int)$job['id']===$id&&(int)$job['attempts']===1&&strlen($job['lease_token'])===64,'Successful claim consumes one attempt and assigns fresh random proof.');
$check($queue->claim(['emails'],'worker:competitor')===null,'Live lease cannot be claimed again.');
$check(!$queue->acknowledge($id,str_repeat('0',64)),'Wrong token cannot acknowledge.');
$check($queue->renew($id,$job['lease_token']),'Current worker can renew its lease without resetting attempts.');
$pdo->prepare('UPDATE jobs SET lease_expires_at=1 WHERE id=?')->execute([$id]);
$replacement=$queue->claim(['emails'],'worker:replacement');
$check($replacement['lease_token']!==$job['lease_token']&&(int)$replacement['attempts']===2,'Crash recovery consumes another attempt and replaces proof.');
$check(!$queue->acknowledge($id,$job['lease_token'])&&!$queue->renew($id,$job['lease_token'])&&!$queue->fail($id,$job['lease_token'],'stale_worker'),'Old worker cannot acknowledge, renew or retry a reclaimed job.');
$check($queue->fail($id,$replacement['lease_token'],'transport_unavailable'),'Retryable error returns current job to delayed ready state.');
$row=$pdo->query("SELECT * FROM jobs WHERE id=$id")->fetch();$check($row['status']==='ready'&&$row['lease_token']===null&&(int)$row['attempts']===2&&(int)$row['available_at']>CommerceDatabase::clock($pdo),'Retry has bounded backoff, cleared lease and preserved attempts.');
$check($queue->claim(['emails'],'worker:early')===null,'Delayed job cannot be claimed early.');
$pdo->exec("UPDATE jobs SET available_at=0 WHERE id=$id");$third=$queue->claim(['emails'],'worker:third');
$check($queue->fail($id,$third['lease_token'],'invalid_payload',true),'Permanent failure archives under live proof.');
$failed=$pdo->query('SELECT * FROM job_failed')->fetch();$check(!$pdo->query("SELECT id FROM jobs WHERE id=$id")->fetchColumn()&&$failed['error_code']==='invalid_payload'&&(int)$failed['attempts']===3,'Dead letter retains safe metadata and deletes active copy atomically.');
$replay=$queue->retryFailed((int)$failed['id']);$check($queue->retryFailed((int)$failed['id'])===$replay&&(int)$pdo->query('SELECT COUNT(*) FROM job_failed')->fetchColumn()===1,'Operator replay is idempotent and retains original failed history.');
$pdo->exec('DELETE FROM jobs');
$last=CommerceDatabase::transaction($pdo,fn()=>$typed->orderConfirmation(2));$pdo->exec("UPDATE jobs SET max_attempts=1 WHERE id=$last");$lastJob=$queue->claim(['emails'],'worker:last');
$check($queue->reap(['emails'])===0,'Reaper does not archive a live final attempt.');
$pdo->exec("UPDATE jobs SET lease_expires_at=1 WHERE id=$last");
$pdo->exec($mysql?"CREATE TRIGGER failed_archive_error BEFORE INSERT ON job_failed FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'":"CREATE TRIGGER failed_archive_error BEFORE INSERT ON job_failed BEGIN SELECT RAISE(ABORT,'injected'); END");
CommerceTest::fails(fn()=>$queue->reap(['emails']),'Archive insertion failure propagates safely.');
$check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE id=$last")->fetchColumn()===1,'Failed archive cannot destroy only durable active copy.');$pdo->exec('DROP TRIGGER failed_archive_error');
$check($queue->reap(['emails'])===1&&$queue->reap(['emails'])===0,'Crash on final attempt is reaped exactly once.');
$held=CommerceDatabase::transaction($pdo,fn()=>$typed->purchase(9));
CommerceTest::fails(fn()=>$queue->claim(['purchase_events'],'bad-worker'),'Held queue cannot be claimed.');CommerceTest::fails(fn()=>$queue->reap(['purchase_events']),'Held queue cannot be reaped.');
$check((int)$pdo->query("SELECT attempts FROM jobs WHERE id=$held")->fetchColumn()===0,'Held queue consumes no attempts or archival effects.');
$id=CommerceDatabase::transaction($pdo,fn()=>$typed->orderConfirmation(4));$pdo->exec("UPDATE jobs SET payload='malformed' WHERE id=$id");
$stats=$worker->run(['emails'],1,30);$check($stats['claimed']===1&&$stats['failed']===1&&(int)$pdo->query("SELECT COUNT(*) FROM job_failed WHERE original_job_id=$id")->fetchColumn()===1,'Malformed stored envelope fails permanently without dynamic execution.');
$id=CommerceDatabase::transaction($pdo,fn()=>$typed->orderConfirmation(5));$start=hrtime(true);$stats=$worker->run(['emails'],1,1);
$check($stats['claimed']===0&&(hrtime(true)-$start)<1000000000&&(int)$pdo->query("SELECT attempts FROM jobs WHERE id=$id")->fetchColumn()===0,'Insufficient invocation budget stops before consuming a lease.');
$pdo->exec("DELETE FROM jobs WHERE queue='emails'");
// Real domain effect is rolled back if the final acknowledgement is stale.
$admin=CommerceAccess::provision('queue-stock-admin@example.test','Disposable-Queue-Password');$user=CommerceTest::user('queue-stock-user@example.test');$products=new ProductService($pdo);$inventory=new InventoryService($pdo);
$cat=$products->saveCategory($admin,['slug'=>'queue-stones','name'=>'Queue Stones'],'category:queue:stones');
$product=$products->save($admin,['slug'=>'queue-marble','name'=>'Queue Marble','category_id'=>$cat,'status'=>'active','variants'=>[['sku'=>'QUEUE-STOCK','sell_unit'=>'sqft','qty_increment_milli'=>1000,'unit_price_minor'=>1000]]],0,'product:queue:stock');$variant=$product['variant_ids'][0];
$inventory->adjust($admin,$variant,5000,'Initial stock','stock:queue:initial');$reservation=CommerceDatabase::transaction($pdo,fn()=>$inventory->reserve('user:'.$user,[['variant_id'=>$variant,'qty_milli'=>1000]],'reserve:queue:effect'));
$id=CommerceDatabase::transaction($pdo,fn()=>$typed->orderConfirmation(10));$old=$queue->claim(['emails'],'effect:old');$pdo->exec("UPDATE jobs SET lease_expires_at=1 WHERE id=$id");$new=$queue->claim(['emails'],'effect:new');
CommerceTest::fails(fn()=>CommerceDatabase::transaction($pdo,function() use($inventory,$reservation,$user,$queue,$id,$old):void {
 $inventory->release((int)$reservation['id'],'user:'.$user);if(!$queue->acknowledge($id,$old['lease_token']))throw new RuntimeException('Fence lost.');
}),'Stale final acknowledgement aborts database effect transaction.');
$check((int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$variant")->fetchColumn()===1000&&$pdo->query('SELECT state FROM inventory_reservations WHERE id='.$reservation['id'])->fetchColumn()==='active','Stock/reservation/version effects roll back under stale fencing.');
CommerceDatabase::transaction($pdo,function() use($inventory,$reservation,$user,$queue,$id,$new):void {$inventory->release((int)$reservation['id'],'user:'.$user);if(!$queue->acknowledge($id,$new['lease_token']))throw new RuntimeException('Fence lost.');});
$check((int)$pdo->query("SELECT reserved_milli FROM inventory WHERE variant_id=$variant")->fetchColumn()===0,'Current fenced domain effect commits once.');
// Independent workers contend for one job, then concurrent operators replay one failure.
$pdo->exec('DELETE FROM jobs');$id=CommerceDatabase::transaction($pdo,fn()=>$typed->orderConfirmation(11));
$fixture=CommerceTest::$temp.'/queue-worker-race.php';file_put_contents($fixture,'<?php require '.var_export(dirname(__DIR__).'/config/bootstarp.php',true).';'.<<<'CHILD'
$in=json_decode(stream_get_contents(STDIN),true);file_put_contents(getenv('TEST_READY'),'ready');$deadline=microtime(true)+8;
while(!is_file(getenv('TEST_BARRIER'))) {if(microtime(true)>$deadline)throw new RuntimeException('Barrier timeout.');usleep(10000);}
$q=new Queue($pdo);if($in['mode']==='claim') {$job=$q->claim(['emails'],'race:'.getmypid());echo json_encode(['id'=>$job?(int)$job['id']:null]);}
else echo json_encode(['id'=>$q->retryFailed($in['id'])]);
CHILD);
$race=CommerceTest::race([['mode'=>'claim'],['mode'=>'claim']],$fixture);$winners=array_filter(array_column($race,'id'),fn($value)=>$value!==null);
$check(count($winners)===1&&(int)$pdo->query("SELECT attempts FROM jobs WHERE id=$id")->fetchColumn()===1,'Independent fallback workers claim one job and consume one attempt.');
$live=$pdo->query("SELECT * FROM jobs WHERE id=$id")->fetch();$queue->fail($id,$live['lease_token'],'test_permanent',true);$failedId=(int)$pdo->query("SELECT id FROM job_failed WHERE original_job_id=$id")->fetchColumn();
$race=CommerceTest::race([['mode'=>'retry','id'=>$failedId],['mode'=>'retry','id'=>$failedId]],$fixture);$check($race[0]['id']===$race[1]['id'],'Concurrent operator retries create one stable replay ID.');
$pdo->exec('DELETE FROM jobs');
$plan=$pdo->query("EXPLAIN ".($mysql?'':'QUERY PLAN ')."SELECT id FROM jobs WHERE queue='emails' AND status='ready' AND available_at<=1 ORDER BY available_at,id LIMIT 2")->fetchAll();
$check($plan!==[],'Eligibility query plan is captured on the actual driver.');
// Real queued signup sends no filesystem mail until worker; raw proof never enters SQL payload.
$_ENV['AUTH_REGISTRATION_ENABLED']='true';$_ENV['AUTH_VERIFICATION_TRANSPORT']='queue';$_ENV['MAIL_TRANSPORT']='test';
$_SERVER['REQUEST_METHOD']='POST';RequestSecurity::session();$_POST['csrf']=RequestSecurity::csrfToken();
$result=Auth::register('Queued','Customer','Queued Customer','queued-signup@example.test','Disposable-Queued-Password');$check($result['status'],'Configured queued signup succeeds without synchronous mail.');
$check(!is_dir(CommerceTest::$temp.'/private/test-mail'),'Signup stages no raw proof or mail file in queue mode.');
$jobRow=$pdo->query("SELECT * FROM jobs WHERE type='auth_verification'")->fetch();$check(array_keys(json_decode($jobRow['payload'],true))===['verification_id'],'Verification intent contains only reference ID.');
$stats=$worker->run(['emails'],1,30);$files=glob(CommerceTest::$temp.'/private/test-mail/*.json');$check($stats['completed']===1&&count($files)===1,'Worker sends committed verification through private local test transport.');
$mail=json_decode(file_get_contents($files[0]),true);preg_match('/token=([a-f0-9]{64})/',$mail['body'],$m);$token=$m[1]??'';
$check(strlen($token)===64&&(fileperms($files[0])&0777)===0600,'Raw proof appears only in private protected test mail.');
$check($pdo->query("SELECT status FROM notification_deliveries WHERE type='auth_verification'")->fetchColumn()==='sent','Successful transport has a persistent delivery marker.');
$verificationId=json_decode($jobRow['payload'],true)['verification_id'];CommerceDatabase::transaction($pdo,fn()=>$typed->verification($verificationId));$worker->run(['emails'],1,30);
$check(count(glob(CommerceTest::$temp.'/private/test-mail/*.json'))===1,'Requeued successful notification does not resend after active job deletion.');
$check(Auth::verifyEmail(null,$token)['status']&&!Auth::verifyEmail(null,$token)['status'],'Worker proof verifies exactly once with no identity/grant inference.');
// Missing/failed provider cannot falsely record success; retry succeeds once configured.
Auth::register('Retry','Customer','Retry Customer','queued-retry@example.test','Disposable-Queued-Password');$_ENV['MAIL_TEST_FAILURE']='true';$stats=$worker->run(['emails'],1,30);
$check($stats['failed']===1&&(int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status='pending'")->fetchColumn()===1,'Provider failure remains visible and has no sent marker.');
$_ENV['MAIL_TEST_FAILURE']='false';$pdo->exec("UPDATE jobs SET available_at=0 WHERE queue='emails'");$stats=$worker->run(['emails'],1,30);
$check($stats['completed']===1&&(int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status='sent'")->fetchColumn()===2,'A later bounded retry completes after transport recovery.');
// Transport accepted, then final SQL failed: retry can duplicate external delivery.
Auth::register('Uncertain','Customer','Uncertain Customer','queued-uncertain@example.test','Disposable-Queued-Password');
$before=count(glob(CommerceTest::$temp.'/private/test-mail/*.json'));
$pdo->exec($mysql?"CREATE TRIGGER delivery_commit_error BEFORE UPDATE ON notification_deliveries FOR EACH ROW BEGIN IF NEW.status='sent' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'; END IF; END":"CREATE TRIGGER delivery_commit_error BEFORE UPDATE ON notification_deliveries WHEN NEW.status='sent' BEGIN SELECT RAISE(ABORT,'injected'); END");
$stats=$worker->run(['emails'],1,30);
$check($stats['failed']===1&&count(glob(CommerceTest::$temp.'/private/test-mail/*.json'))===$before+1,'Accepted transport followed by SQL failure exposes an uncertain external outcome.');
$check((int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status='sending'")->fetchColumn()===1,'SQL failure does not falsely persist a sent marker.');
$pdo->exec('DROP TRIGGER delivery_commit_error');$pdo->exec("UPDATE notification_deliveries SET lease_expires_at=1 WHERE status='sending'");$pdo->exec("UPDATE jobs SET available_at=0 WHERE queue='emails'");
$stats=$worker->run(['emails'],1,30);
$check($stats['completed']===1&&count(glob(CommerceTest::$temp.'/private/test-mail/*.json'))===$before+2,'Recovery can send a duplicate after uncertain acceptance; exactly-once mail is not promised.');
$pdo->exec($mysql?"CREATE TRIGGER queue_insert_error BEFORE INSERT ON jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'":"CREATE TRIGGER queue_insert_error BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT,'injected'); END");
$result=Auth::register('Rollback','Customer','Rollback Customer','queued-rollback@example.test','Disposable-Queued-Password');
$check(!$result['status']&&(int)$pdo->query("SELECT COUNT(*) FROM users_tbl WHERE email='queued-rollback@example.test'")->fetchColumn()===0,'Enqueue failure rolls back signup and verification token atomically.');$pdo->exec('DROP TRIGGER queue_insert_error');
[$exit,$out,$err]=CommerceTest::command(['vayu','queue:work','--queues=emails','--max=1','--max-seconds=1']);$check($exit===0&&$err===''&&json_decode($out,true)['claimed']===0,'Bounded worker is available through CLI without HTTP/session dependency.');
[$exit,$out,$err]=CommerceTest::command(['vayu','queue:work','--queues=purchase_events']);$check($exit===1&&$out===''&&!str_contains($err,'queued-signup'),'CLI rejects held queues with sanitized error.');
CommerceTest::finish('Queue leases, workers and queued verification');
