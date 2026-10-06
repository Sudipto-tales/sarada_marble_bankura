<?php

final class Queue
{
    public function __construct(private PDO $pdo) {}
    private function query(string $sql,array $args=[]):PDOStatement {return CommerceDatabase::query($this->pdo,$sql,$args);}
    public function enqueue(string $queue,string $type,int $version,array $payload,?string $dedupeKey=null,?int $availableAt=null):int
    {
        if(!$this->pdo->inTransaction()) throw new LogicException('Durable enqueue requires the business transaction.');
        $envelope=JobRegistry::envelope($queue,$type,$version,$payload);
        if($dedupeKey!==null&&(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_.-]{0,190}\z/',$dedupeKey))) CommerceValues::invalid('dedupe_key');
        $now=CommerceDatabase::clock($this->pdo);$at=$availableAt===null?$now:CommerceValues::integer($availableAt,0,PHP_INT_MAX,'available_at');
        $sql="INSERT INTO jobs (queue,type,version,payload,status,attempts,max_attempts,available_at,created_at,dedupe_key) VALUES (?,?,?,?,'ready',0,?,?,?,?)";
        $sql.=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ON DUPLICATE KEY UPDATE id=id':' ON CONFLICT(dedupe_key) DO NOTHING';
        $this->query($sql,[$queue,$type,$version,$envelope['payload'],$envelope['max_attempts'],$at,$now,$dedupeKey]);
        if($dedupeKey===null) return (int)$this->pdo->lastInsertId();
        $row=$this->query('SELECT * FROM jobs WHERE dedupe_key=?'.CommerceDatabase::lock($this->pdo),[$dedupeKey])->fetch();
        if(!$row||$row['queue']!==$queue||$row['type']!==$type||(int)$row['version']!==$version||$row['payload']!==$envelope['payload']
            ||($availableAt!==null&&(int)$row['available_at']!==$at)) throw new RequestRejected(409,'job_key_conflict','Job key has different inputs.');
        return (int)$row['id'];
    }

    private function queues(array $queues):array
    {
        $queues=array_values(array_unique($queues));
        if(!$queues||count($queues)>8||array_diff($queues,JobRegistry::enabledQueues())) throw new InvalidArgumentException('Worker queue is not enabled.');
        return $queues;
    }
    /** Portable conditional-update strategy, not inferred SKIP LOCKED support. */
    public function claim(array $queues,string $owner):?array
    {
        if($this->pdo->inTransaction()) throw new LogicException('Claim requires a short independent transaction.');
        $queues=$this->queues($queues);$owner=CommerceValues::text($owner,80,'lease_owner');
        $marks=implode(',',array_fill(0,count($queues),'?'));$now=CommerceDatabase::clock($this->pdo);
        $eligible="queue IN ($marks) AND available_at<=? AND attempts<max_attempts AND ((status='ready' AND lease_token IS NULL) OR (status='running' AND lease_expires_at<=?))";
        $candidates=$this->query("SELECT id FROM jobs WHERE $eligible ORDER BY available_at,id LIMIT 2",[...$queues,$now,$now])->fetchAll(PDO::FETCH_COLUMN);
        foreach($candidates as $id) {
            $job=CommerceDatabase::transaction($this->pdo,function() use($eligible,$queues,$owner,$id):?array {
                $now=CommerceDatabase::clock($this->pdo);$token=bin2hex(random_bytes(32));
                $count=$this->query("UPDATE jobs SET status='running',attempts=attempts+1,lease_token=?,lease_owner=?,reserved_at=?,lease_expires_at=? WHERE id=? AND $eligible",
                    [$token,$owner,$now,$now+45,$id,...$queues,$now,$now])->rowCount();
                if($count!==1) return null;
                // A contended update may wait; start the actual lease after acquisition.
                $now=CommerceDatabase::clock($this->pdo);
                $this->query('UPDATE jobs SET reserved_at=?,lease_expires_at=? WHERE id=? AND lease_token=?',[$now,$now+45,$id,$token]);
                return $this->query('SELECT * FROM jobs WHERE id=? AND lease_token=?',[$id,$token])->fetch();
            });
            if($job)return $job;
        }
        return null;
    }
    private function owned(int $id,string $token):?array
    {
        $row=$this->query("SELECT * FROM jobs WHERE id=? AND status='running' AND lease_token=?".CommerceDatabase::lock($this->pdo),[$id,$token])->fetch();
        return $row&&(int)$row['lease_expires_at']>CommerceDatabase::clock($this->pdo)?$row:null;
    }
    private function ownTransaction(callable $work)
    {
        return $this->pdo->inTransaction()?$work():CommerceDatabase::transaction($this->pdo,$work);
    }
    /** Last write in a database-effect transaction; false must roll back that effect. */
    public function acknowledge(int $id,string $token):bool
    {
        return $this->ownTransaction(function() use($id,$token):bool {
            if(!$this->owned($id,$token))return false;
            return $this->query('DELETE FROM jobs WHERE id=? AND lease_token=?',[$id,$token])->rowCount()===1;
        });
    }
    public function renew(int $id,string $token):bool
    {
        return $this->ownTransaction(function() use($id,$token):bool {
            if(!$this->owned($id,$token))return false;
            $this->query('UPDATE jobs SET lease_expires_at=? WHERE id=? AND lease_token=?',[CommerceDatabase::clock($this->pdo)+45,$id,$token]);return true;
        });
    }
    private function errorCode(string $code):string
    {
        if(!preg_match('/\A[a-z][a-z0-9_]{0,79}\z/',$code)) throw new InvalidArgumentException('Use a safe error classifier.');return $code;
    }
    private function archive(array $row,string $code):int
    {
        $this->query('INSERT INTO job_failed (original_job_id,queue,type,version,payload,attempts,max_attempts,dedupe_key,original_created_at,failed_at,error_code,correlation_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$row['id'],$row['queue'],$row['type'],$row['version'],$row['payload'],$row['attempts'],$row['max_attempts'],$row['dedupe_key'],$row['created_at'],CommerceDatabase::clock($this->pdo),$code,bin2hex(random_bytes(16))]);
        $id=(int)$this->pdo->lastInsertId();$this->query('DELETE FROM jobs WHERE id=?',[$row['id']]);return $id;
    }
    public function fail(int $id,string $token,string $code,bool $permanent=false):bool
    {
        $code=$this->errorCode($code);
        return $this->ownTransaction(function() use($id,$token,$code,$permanent):bool {
            $row=$this->owned($id,$token);if(!$row)return false;
            if($permanent||(int)$row['attempts']>=(int)$row['max_attempts']) {$this->archive($row,$code);return true;}
            $delay=min(3600,10*(2**min(10,(int)$row['attempts']-1)))+random_int(0,5);
            $this->query("UPDATE jobs SET status='ready',available_at=?,last_error_code=?,reserved_at=NULL,lease_expires_at=NULL,lease_token=NULL,lease_owner=NULL WHERE id=? AND lease_token=?",
                [CommerceDatabase::clock($this->pdo)+$delay,$code,$id,$token]);return true;
        });
    }
    public function reap(array $queues,int $limit=20):int
    {
        $queues=$this->queues($queues);$limit=CommerceValues::integer($limit,1,100,'reap_limit');$marks=implode(',',array_fill(0,count($queues),'?'));$now=CommerceDatabase::clock($this->pdo);
        $ids=$this->query("SELECT id FROM jobs WHERE queue IN ($marks) AND attempts>=max_attempts AND (status='ready' OR lease_expires_at<=?) ORDER BY id LIMIT $limit",[...$queues,$now])->fetchAll(PDO::FETCH_COLUMN);$count=0;
        foreach($ids as $id) $count+=CommerceDatabase::transaction($this->pdo,function() use($id,$queues):int {
            $row=$this->query('SELECT * FROM jobs WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetch();$now=CommerceDatabase::clock($this->pdo);
            if(!$row||!in_array($row['queue'],$queues,true)||(int)$row['attempts']<(int)$row['max_attempts']||($row['status']==='running'&&(int)$row['lease_expires_at']>$now))return 0;
            $this->archive($row,'attempt_limit_after_crash');return 1;
        });return $count;
    }
    public function retryFailed(int $id,string $operator='local-cli'):int
    {
        $id=CommerceValues::id($id);$operator=CommerceValues::text($operator,80,'retry_operator');
        return CommerceDatabase::transaction($this->pdo,function() use($id,$operator):int {
            $row=$this->query('SELECT * FROM job_failed WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetch();
            if(!$row)throw new RequestRejected(404,'not_found','Failed job not found.');
            if($row['retried_job_id']!==null)return (int)$row['retried_job_id'];
            $payload=JobRegistry::decode($row);$job=$this->enqueue($row['queue'],$row['type'],(int)$row['version'],$payload,$row['dedupe_key']);
            $this->query('UPDATE job_failed SET retried_job_id=?,retried_at=?,retry_operator=? WHERE id=?',[$job,CommerceDatabase::clock($this->pdo),$operator,$id]);return $job;
        });
    }
}
