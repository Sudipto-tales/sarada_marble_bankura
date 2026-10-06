<?php
class NotificationDelivery extends Migration
{
    public function up()
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $binary=$mysql?' COLLATE utf8mb4_bin':'';$suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS notification_deliveries (id $id, delivery_key VARCHAR(191)$binary NOT NULL,
            type VARCHAR(40) NOT NULL, target_id INT NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL DEFAULT 0,
            lease_token VARCHAR(64) NULL, lease_expires_at BIGINT NULL, sent_at BIGINT NULL, last_error_code VARCHAR(80) NULL,
            CHECK(status IN ('pending','sending','sent')))$suffix");
        MigrationSchema::ensureIndex($this->pdo,'notification_deliveries','idx_delivery_key',['delivery_key'],true);$this->verify();
    }
    public function verify():void
    {
        MigrationSchema::verifyDefinition($this->pdo,'notification_deliveries',['id'=>'id','delivery_key'=>'varchar(191)','type'=>'varchar(40)','target_id'=>'int','status'=>'varchar(16)','attempts'=>'int',
            'lease_token'=>'?varchar(64)','lease_expires_at'=>'?bigint','sent_at'=>'?bigint','last_error_code'=>'?varchar(80)'],[[['delivery_key'],true]]);
    }
}
