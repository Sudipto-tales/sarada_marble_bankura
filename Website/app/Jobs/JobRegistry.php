<?php

/** Explicit versioned envelopes. Producing an intent does not enable its consumer. */
final class JobRegistry
{
    private const TYPES=[
        'auth_verification'=>['emails',['verification_id']],
        'order_confirmation'=>['emails',['order_id']],
        'order_cancelled'=>['emails',['order_id']],
        'reservation_expiry'=>['maintenance',['reservation_id']],
        'low_stock_alert'=>['emails',['alert_id']],
        'purchase_event'=>['purchase_events',['order_id']],
    ];
    public static function envelope(string $queue,string $type,int $version,array $payload):array
    {
        $spec=self::TYPES[$type]??null;
        if(!$spec||$spec[0]!==$queue||$version!==1) throw new RequestRejected(422,'invalid_job','Unsupported queue envelope.');
        $allowed=[...$spec[1],'correlation_id'];
        if(array_diff(array_keys($payload),$allowed)) throw new RequestRejected(422,'invalid_job','Unexpected job payload field.');
        $result=[];foreach($spec[1] as $field) $result[$field]=CommerceValues::id($payload[$field]??null,$field);
        if(isset($payload['correlation_id'])) {
            if(!is_string($payload['correlation_id'])||!preg_match('/\A[a-f0-9]{32}\z/',$payload['correlation_id'])) CommerceValues::invalid('correlation_id');
            $result['correlation_id']=$payload['correlation_id'];
        }
        ksort($result);$json=json_encode($result,JSON_THROW_ON_ERROR);
        $limit=CommerceValues::integer((string)env('QUEUE_PAYLOAD_LIMIT','16384'),256,16384,'queue_payload_limit');
        if(strlen($json)>$limit) throw new RequestRejected(422,'invalid_job','Job payload is too large.');
        return ['queue'=>$queue,'type'=>$type,'version'=>$version,'payload'=>$json,'max_attempts'=>5,'budget_seconds'=>15,'lease_seconds'=>45];
    }
    public static function decode(array $job):array
    {
        if(!is_string($job['payload'])||strlen($job['payload'])>16384) throw new RequestRejected(422,'invalid_job','Invalid job payload.');
        try {$payload=json_decode($job['payload'],true,8,JSON_THROW_ON_ERROR);}
        catch(Throwable $e) {throw new RequestRejected(422,'invalid_job','Invalid job payload.');}
        if(!is_array($payload)||array_is_list($payload)) throw new RequestRejected(422,'invalid_job','Invalid job payload.');
        $envelope=self::envelope($job['queue'],$job['type'],(int)$job['version'],$payload);return json_decode($envelope['payload'],true,8,JSON_THROW_ON_ERROR);
    }
    /** Maintenance and analytics stay held until their domain consumers integrate. */
    public static function enabledQueues():array {return ['emails'];}
    public static function execute(PDO $pdo,array $job):void
    {
        self::decode($job);
        if(in_array($job['type'],['auth_verification','order_confirmation','order_cancelled'],true)) {NotificationJob::run($pdo,$job);return;}
        throw new JobExecutionException('unknown_handler',true);
    }
}
