<?php

class CatalogSimplified extends Migration
{
    public function up()
    {
        // Availability status already added by 0005_catalog_schema; idempotent no-op if missing.
        if (!isset(MigrationSchema::columns($this->pdo, 'products')['availability_status'])) {
            $this->pdo->exec("ALTER TABLE products ADD availability_status VARCHAR(20) NOT NULL DEFAULT 'in_stock'");
        }
    }
    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo,'products',['id'=>'id','slug'=>'varchar(120)','name'=>'varchar(190)','description'=>'text','tags'=>'text','category_id'=>'int','brand_id'=>'?int','status'=>'varchar(16)','featured'=>'int','revision'=>'bigint','created_at'=>'varchar(20)','updated_at'=>'varchar(20)','availability_status'=>'varchar(20)'],
            [[['slug'],true],[['status','category_id','id'],false],[['brand_id','status'],false]],[['category_id','categories','id','RESTRICT'],['brand_id','brands','id','RESTRICT']]);

        MigrationSchema::verifyDefinition($this->pdo,'product_images',['id'=>'id','product_id'=>'int','path'=>'varchar(190)','mime'=>'varchar(40)','width'=>'int','height'=>'int','position'=>'int','alt_text'=>'varchar(190)'],[[['product_id','position','id'],false]],[['product_id','products','id','RESTRICT']]);

        MigrationSchema::verifyDefinition($this->pdo,'catalog_versions',['id'=>'id','namespace'=>'varchar(40)','version'=>'bigint'],[[['namespace'],true]]);
        foreach (['commerce_write_lock','catalog_hierarchy_lock'] as $table) MigrationSchema::verifyDefinition($this->pdo,$table,['id'=>'id','revision'=>'bigint']);
    }
}