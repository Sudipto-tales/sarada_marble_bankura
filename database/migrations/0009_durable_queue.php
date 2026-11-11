<?php

class DurableQueue extends Migration
{
    public function up()
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';$binary=$mysql?' COLLATE utf8mb4_bin':'';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS jobs (id $id, queue VARCHAR(40) NOT NULL, type VARCHAR(40) NOT NULL, version INT NOT NULL,
            payload TEXT NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL, max_attempts INT NOT NULL,
            available_at BIGINT NOT NULL, created_at BIGINT NOT NULL, reserved_at BIGINT NULL, lease_expires_at BIGINT NULL,
            lease_token VARCHAR(64) NULL, lease_owner VARCHAR(80) NULL, dedupe_key VARCHAR(191)$binary NULL, last_error_code VARCHAR(80) NULL,
            CHECK(attempts>=0 AND max_attempts>=1 AND max_attempts<=10),
            CHECK((status='ready' AND lease_token IS NULL AND lease_owner IS NULL AND reserved_at IS NULL AND lease_expires_at IS NULL)
             OR (status='running' AND lease_token IS NOT NULL AND lease_owner IS NOT NULL AND reserved_at IS NOT NULL AND lease_expires_at IS NOT NULL)))$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS job_failed (id $id, original_job_id INT NOT NULL, queue VARCHAR(40) NOT NULL, type VARCHAR(40) NOT NULL,
            version INT NOT NULL, payload TEXT NOT NULL, attempts INT NOT NULL, max_attempts INT NOT NULL, dedupe_key VARCHAR(191)$binary NULL,
            original_created_at BIGINT NOT NULL, failed_at BIGINT NOT NULL, error_code VARCHAR(80) NOT NULL, correlation_id VARCHAR(32) NOT NULL,
            retried_job_id INT NULL, retried_at BIGINT NULL, retry_operator VARCHAR(80) NULL)$suffix");
        foreach([['jobs','idx_job_dedupe',['dedupe_key'],true],['jobs','idx_job_schedule',['queue','reserved_at','available_at'],false],
            ['jobs','idx_job_ready',['queue','status','available_at','id'],false],['jobs','idx_job_expiry',['queue','status','lease_expires_at','id'],false],
            ['job_failed','idx_failed_original',['original_job_id'],true],['job_failed','idx_failed_time',['failed_at','id'],false]] as [$table,$name,$columns,$unique]) MigrationSchema::ensureIndex($this->pdo,$table,$name,$columns,$unique);
        $this->verify();
    }
    public function verify():void
    {
        MigrationSchema::verifyDefinition($this->pdo,'jobs',['id'=>'id','queue'=>'varchar(40)','type'=>'varchar(40)','version'=>'int','payload'=>'text','status'=>'varchar(16)','attempts'=>'int','max_attempts'=>'int',
            'available_at'=>'bigint','created_at'=>'bigint','reserved_at'=>'?bigint','lease_expires_at'=>'?bigint','lease_token'=>'?varchar(64)','lease_owner'=>'?varchar(80)','dedupe_key'=>'?varchar(191)','last_error_code'=>'?varchar(80)'],
            [[['dedupe_key'],true],[['queue','reserved_at','available_at'],false],[['queue','status','available_at','id'],false],[['queue','status','lease_expires_at','id'],false]]);
        MigrationSchema::verifyDefinition($this->pdo,'job_failed',['id'=>'id','original_job_id'=>'int','queue'=>'varchar(40)','type'=>'varchar(40)','version'=>'int','payload'=>'text','attempts'=>'int','max_attempts'=>'int','dedupe_key'=>'?varchar(191)',
            'original_created_at'=>'bigint','failed_at'=>'bigint','error_code'=>'varchar(80)','correlation_id'=>'varchar(32)','retried_job_id'=>'?int','retried_at'=>'?bigint','retry_operator'=>'?varchar(80)'],[[['original_job_id'],true],[['failed_at','id'],false]]);
    }
}
