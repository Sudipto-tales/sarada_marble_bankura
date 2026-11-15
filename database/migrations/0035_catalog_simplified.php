<?php

class CatalogSimplified extends Migration
{
    public function up()
    {
        // Availability status: manual status (in_stock, few_left, out_of_stock, etc.)
        // Idempotent: only add if the column does not already exist.
        if (!isset(MigrationSchema::columns($this->pdo, 'products')['availability_status'])) {
            $this->pdo->exec("ALTER TABLE products ADD availability_status VARCHAR(20) NOT NULL DEFAULT 'in_stock'");
        }
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo,'products',['id'=>'id','slug'=>'varchar(120)','name'=>'varchar(190)','description'=>'text','tags'=>'text','category_id'=>'int','brand_id'=>'?int','status'=>'varchar(16)','featured'=>'int','revision'=>'bigint','created_at'=>'varchar(20)','updated_at'=>'varchar(20)','availability_status'=>'varchar(20)'],
            [[['slug'],true],[['status','category_id','id'],false],[['brand_id','status'],false]],[['category_id','categories','id','RESTRICT'],['brand_id','brands','id','RESTRICT']]);

        MigrationSchema::verifyDefinition($this->pdo,'product_variants',['id'=>'id','product_id'=>'int','sku'=>'varchar(80)','finish'=>'varchar(80)','thickness_mm_milli'=>'?bigint','length_mm_milli'=>'?bigint','width_mm_milli'=>'?bigint','sell_unit'=>'varchar(8)','qty_increment_milli'=>'bigint','coverage_sqft_milli'=>'?bigint','unit_price_minor'=>'bigint','enabled'=>'int','revision'=>'bigint'],
            [[['sku'],true],[['product_id','enabled'],false],[['id','product_id'],true]],[['product_id','products','id','RESTRICT']]);

        MigrationSchema::verifyDefinition($this->pdo,'product_images',['id'=>'id','product_id'=>'int','variant_id'=>'?int','path'=>'varchar(190)','mime'=>'varchar(40)','width'=>'int','height'=>'int','position'=>'int','alt_text'=>'varchar(190)'],[[['product_id','position','id'],false]],
            [['product_id','products','id','RESTRICT'],['variant_id','product_variants','id','RESTRICT']]);

        MigrationSchema::verifyDefinition($this->pdo,'catalog_versions',['id'=>'id','namespace'=>'varchar(40)','version'=>'bigint'],[[['namespace'],true]]);
        foreach (['commerce_write_lock','catalog_hierarchy_lock'] as $table) MigrationSchema::verifyDefinition($this->pdo,$table,['id'=>'id','revision'=>'bigint']);
    }
}