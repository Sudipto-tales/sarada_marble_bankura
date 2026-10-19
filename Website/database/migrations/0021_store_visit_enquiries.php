<?php
class StoreVisitEnquiries extends Migration
{
    public function up()
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'INT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        // product_id kept nullable with SET NULL so enquiry history outlives the material it referenced.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS store_visit_enquiries (id $id, product_id INT NULL,
            customer_name VARCHAR(100) NOT NULL, contact VARCHAR(20) NOT NULL, note VARCHAR(500) NOT NULL,
            created_at BIGINT NOT NULL, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL)$suffix");
        MigrationSchema::ensureIndex($this->pdo,'store_visit_enquiries','idx_enquiry_time',['created_at','id'],false);
        $this->verify();
    }
    public function verify():void
    {
        MigrationSchema::verifyDefinition($this->pdo,'store_visit_enquiries',
            ['id'=>'id','product_id'=>'?int','customer_name'=>'varchar(100)','contact'=>'varchar(20)','note'=>'varchar(500)','created_at'=>'bigint'],
            [[['created_at','id'],false]],[['product_id','products','id','SET NULL']]);
    }
}
