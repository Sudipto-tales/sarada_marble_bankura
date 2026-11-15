<?php

class Enquiries extends Migration
{
    public function up()
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS enquiries (id INTEGER PRIMARY KEY, product_id INT NOT NULL, customer_name VARCHAR(190) NOT NULL, phone VARCHAR(40) NOT NULL, email VARCHAR(190), quantity INTEGER, message TEXT, status VARCHAR(20) NOT NULL DEFAULT 'new', created_at VARCHAR(20) NOT NULL, updated_at VARCHAR(20) NOT NULL, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT)");

        $this->pdo->exec("CREATE INDEX idx_enquiries_product ON enquiries(product_id)");

        $this->pdo->exec("CREATE INDEX idx_enquiries_status ON enquiries(status)");
    }
    public function verify(): void
    {
MigrationSchema::verifyDefinition($this->pdo,'enquiries',['id'=>'int','product_id'=>'int','customer_name'=>'varchar(190)','phone'=>'varchar(40)','email'=>'?varchar(190)','quantity'=>'integer','message'=>'text','status'=>'varchar(20)','created_at'=>'varchar(20)','updated_at'=>'varchar(20)'],
    [[['product_id'],false],[['status'],false]],[['product_id','products','id','RESTRICT']]);

        MigrationSchema::verifyDefinition($this->pdo,'catalog_versions',['id'=>'id','namespace'=>'varchar(40)','version'=>'bigint'],[[['namespace'],true]]);
        foreach (['commerce_write_lock','catalog_hierarchy_lock'] as $table) MigrationSchema::verifyDefinition($this->pdo,$table,['id'=>'id','revision'=>'bigint']);
    }
}