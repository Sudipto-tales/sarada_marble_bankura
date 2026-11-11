<?php
class LowStockAlerts extends Migration
{
    public function up()
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'INT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS low_stock_alerts (id $id, variant_id INT NOT NULL,
            threshold_milli BIGINT NOT NULL, on_hand_milli BIGINT NOT NULL, created_at BIGINT NOT NULL,
            FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT)$suffix");
        MigrationSchema::ensureIndex($this->pdo,'low_stock_alerts','idx_low_stock_variant_time',['variant_id','created_at','id'],false);
        // Edge-trigger dedupe: one alert per variant until stock recovers above the threshold.
        if(!isset(MigrationSchema::columns($this->pdo,'inventory')['low_stock_notified']))
            $this->pdo->exec('ALTER TABLE inventory ADD COLUMN low_stock_notified INT NOT NULL DEFAULT 0');
        $this->verify();
    }
    public function verify():void
    {
        MigrationSchema::verifyDefinition($this->pdo,'low_stock_alerts',
            ['id'=>'id','variant_id'=>'int','threshold_milli'=>'bigint','on_hand_milli'=>'bigint','created_at'=>'bigint'],
            [[['variant_id','created_at','id'],false]],[['variant_id','product_variants','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'inventory',['low_stock_notified'=>'int']);
    }
}
