<?php
class StoreSettings extends Migration
{
    public function up()
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'INT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS store_settings (id $id,skey VARCHAR(40) NOT NULL,svalue VARCHAR(190) NOT NULL,updated_at BIGINT NOT NULL)$suffix");
        MigrationSchema::ensureIndex($this->pdo,'store_settings','idx_store_setting_key',['skey'],true);
        $this->verify();
    }
    public function verify():void
    {
        // svalue width is intentionally not pinned here: 0024 widens it to VARCHAR(512) on MySQL for
        // provider credentials, and the runner re-verifies every migration against the final schema.
        MigrationSchema::verifyDefinition($this->pdo,'store_settings',['id'=>'id','skey'=>'varchar(40)','updated_at'=>'bigint'],[[['skey'],true]]);
        MigrationSchema::requireColumns($this->pdo,'store_settings',['svalue']);
    }
}
