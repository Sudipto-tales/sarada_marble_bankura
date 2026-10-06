<?php

/** SMTP occurs between short delivery transactions. Acceptance may still be duplicated after a crash. */
final class NotificationJob
{
    public static function run(PDO $pdo,array $job):void
    {
        $payload=JobRegistry::decode($job);$type=$job['type'];$queue=new Queue($pdo);
        if(!in_array($type,['auth_verification','order_confirmation','order_cancelled'],true)) throw new JobExecutionException('unknown_handler',true);
        $target=$payload[$type==='auth_verification'?'verification_id':'order_id'];
        $deliveryKey=$type.':'.$target.':v1';$deliveryToken=bin2hex(random_bytes(32));
        $message=CommerceDatabase::transaction($pdo,function() use($pdo,$job,$type,$target,$deliveryKey,$deliveryToken):?array {
            $q=fn($sql,$args=[])=>CommerceDatabase::query($pdo,$sql,$args);$lock=CommerceDatabase::lock($pdo);$now=CommerceDatabase::clock($pdo);
            if($type==='auth_verification') {
                $owner=$q('SELECT user_id FROM auth_verification_tokens WHERE id=?',[$target])->fetchColumn();
                if(!$owner)return null;
                $user=$q('SELECT * FROM users_tbl WHERE id=?'.$lock,[$owner])->fetch();
                $proof=$q('SELECT * FROM auth_verification_tokens WHERE id=?'.$lock,[$target])->fetch();
                if(!$user||!$proof||(int)$user['status']!==1||(int)$user['email_verify']===1||(int)$proof['expires_at']<=$now)return null;
                $url=rtrim((string)env('APP_URL',''),'/');
                if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)) throw new JobExecutionException('verification_url_missing');
                if(env('APP_ENV','production')==='production'&&parse_url($url,PHP_URL_SCHEME)!=='https')throw new JobExecutionException('verification_https_required');
                $token=bin2hex(random_bytes(32));
                $subject='Verify your Maa Sarada account';$body='Verify your email: '.$url.'/auth/verify_email?token='.$token."\nExpires at ".gmdate('Y-m-d H:i:s',(int)$proof['expires_at'])." UTC. If you did not request this account, ignore this message.";
            } else {
                $order=$q('SELECT * FROM orders WHERE id=?',[$target])->fetch();if(!$order)throw new JobExecutionException('order_missing',true);
                if(($type==='order_cancelled'&&$order['status']!=='cancelled')||($type==='order_confirmation'&&$order['status']==='cancelled'))return null;
                $user=$q('SELECT * FROM users_tbl WHERE id=?',[$order['user_id']])->fetch();if(!$user)throw new JobExecutionException('recipient_missing',true);
                $subject=$type==='order_cancelled'?'Your Maa Sarada order was cancelled':'Your Maa Sarada order is confirmed';
                $total=(int)$order['total_minor'];$formatted=intdiv($total,100).'.'.str_pad((string)($total%100),2,'0',STR_PAD_LEFT);
                $body=$subject."\nOrder: ".$order['order_number']."\nTotal: INR ".$formatted."\nPayment: ".$order['payment_mode'].' / '.$order['payment_status'];
            }
            $sql="INSERT INTO notification_deliveries (delivery_key,type,target_id,status) VALUES (?,?,?,'pending')";
            $sql.=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE id=id':' ON CONFLICT(delivery_key) DO NOTHING';
            $q($sql,[$deliveryKey,$type,$target]);$delivery=$q('SELECT * FROM notification_deliveries WHERE delivery_key=?'.$lock,[$deliveryKey])->fetch();
            if($delivery['status']==='sent')return null;
            if($delivery['status']==='sending'&&(int)$delivery['lease_expires_at']>$now)throw new JobExecutionException('delivery_busy');
            if($type==='auth_verification')$q('UPDATE auth_verification_tokens SET token_hash=? WHERE id=?',[hash('sha256',$token),$target]);
            $q("UPDATE notification_deliveries SET status='sending',attempts=attempts+1,lease_token=?,lease_expires_at=?,last_error_code=NULL WHERE id=?",[$deliveryToken,$now+45,$delivery['id']]);
            // Final live-job lock fences preparation; it is released before transport I/O.
            $live=$q("SELECT lease_token,lease_expires_at FROM jobs WHERE id=? AND status='running'".$lock,[$job['id']])->fetch();
            if(!$live||$live['lease_token']!==$job['lease_token']||(int)$live['lease_expires_at']<=CommerceDatabase::clock($pdo))throw new JobExecutionException('lease_lost');
            return ['to'=>$user['email'],'subject'=>$subject,'body'=>$body,'delivery_id'=>(int)$delivery['id']];
        });
        if(!$message) {if(!$queue->acknowledge((int)$job['id'],$job['lease_token']))throw new JobExecutionException('lease_lost');return;}
        try {
            if(!Mailer::send($message['to'],$message['subject'],$message['body'],false,hash('sha256',$deliveryKey))) throw new JobExecutionException('transport_disabled');
        } catch(Throwable $e) {
            CommerceDatabase::transaction($pdo,function() use($pdo,$message,$deliveryToken):void {
                CommerceDatabase::query($pdo,"UPDATE notification_deliveries SET status='pending',lease_token=NULL,lease_expires_at=NULL,last_error_code='transport_unavailable' WHERE id=? AND lease_token=? AND status='sending'",[$message['delivery_id'],$deliveryToken]);
            });
            if($e instanceof JobExecutionException)throw $e;throw new JobExecutionException('transport_unavailable');
        }
        CommerceDatabase::transaction($pdo,function() use($pdo,$queue,$job,$message,$deliveryToken):void {
            $delivery=CommerceDatabase::query($pdo,'SELECT * FROM notification_deliveries WHERE id=?'.CommerceDatabase::lock($pdo),[$message['delivery_id']])->fetch();
            if(!$delivery||$delivery['lease_token']!==$deliveryToken||$delivery['status']!=='sending'||(int)$delivery['lease_expires_at']<=CommerceDatabase::clock($pdo))throw new JobExecutionException('delivery_lease_lost');
            CommerceDatabase::query($pdo,"UPDATE notification_deliveries SET status='sent',sent_at=?,lease_token=NULL,lease_expires_at=NULL WHERE id=?",[CommerceDatabase::clock($pdo),$delivery['id']]);
            if(!$queue->acknowledge((int)$job['id'],$job['lease_token']))throw new JobExecutionException('lease_lost');
        });
    }
}
