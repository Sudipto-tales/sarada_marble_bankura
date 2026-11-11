<?php

final class JobExecutionException extends RuntimeException
{
    public function __construct(public readonly string $classifier,public readonly bool $permanent=false) {parent::__construct('Job execution failed.');}
}
final class QueueWorker
{
    public function __construct(private PDO $pdo) {}
    public function run(array $queues,int $max=20,int $seconds=30):array
    {
        $max=CommerceValues::integer($max,1,100,'max_jobs');$seconds=CommerceValues::integer($seconds,1,300,'max_seconds');
        if(!$queues||array_diff($queues,JobRegistry::enabledQueues()))throw new InvalidArgumentException('Queue is held or unsupported.');
        $queue=new Queue($this->pdo);$deadline=hrtime(true)+$seconds*1000000000;$owner='cli:'.getmypid().':'.bin2hex(random_bytes(8));
        // Keep fallback lock contention below the invocation allowance.
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$this->pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
        $stats=['claimed'=>0,'completed'=>0,'failed'=>0,'lease_lost'=>0,'reaped'=>0];
        while($stats['claimed']<$max&&($deadline-hrtime(true))/1000000000>=27) {
            $stats['reaped']+=$queue->reap($queues,1);
            if(($deadline-hrtime(true))/1000000000<27)break;
            $job=$queue->claim($queues,$owner);if(!$job)break;$stats['claimed']++;
            try {JobRegistry::execute($this->pdo,$job);$stats['completed']++;}
            catch(Throwable $e) {
                $code=$e instanceof JobExecutionException?$e->classifier:($e instanceof RequestRejected?'invalid_payload':'handler_failure');
                $permanent=$e instanceof JobExecutionException?$e->permanent:$e instanceof RequestRejected;
                if($queue->fail((int)$job['id'],$job['lease_token'],$code,$permanent))$stats['failed']++;else $stats['lease_lost']++;
            }
        }
        return $stats;
    }
}
