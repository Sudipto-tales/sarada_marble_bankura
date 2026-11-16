<?php

class CatalogSchema extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        // categories
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS categories (id $id, slug VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL,
            enabled INT NOT NULL DEFAULT 1, parent_id INT NULL, FOREIGN KEY(parent_id) REFERENCES categories(id) ON DELETE RESTRICT)$suffix");
        // brands
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS brands (id $id, slug VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL, enabled INT NOT NULL DEFAULT 1)$suffix");
        // products - simplified: all fields directly on product, no variants
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS products (id $id, slug VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL,
            description TEXT NOT NULL, tags TEXT NOT NULL, category_id INT NOT NULL, brand_id INT NULL,
            price_minor BIGINT NOT NULL, thickness_mm_milli BIGINT NULL, width_mm_milli BIGINT NULL, length_mm_milli BIGINT NULL,
            sell_unit VARCHAR(8) NOT NULL, qty_increment_milli BIGINT NOT NULL DEFAULT 1000, coverage_sqft_milli BIGINT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active', featured INT NOT NULL DEFAULT 0,
            availability_status VARCHAR(20) NOT NULL DEFAULT 'in_stock', revision BIGINT NOT NULL DEFAULT 1,
            created_at VARCHAR(20) NOT NULL, updated_at VARCHAR(20) NOT NULL,
            FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE RESTRICT,
            FOREIGN KEY(brand_id) REFERENCES brands(id) ON DELETE RESTRICT)$suffix");
        // product_images - reference product directly, no variant_id
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS product_images (id $id, product_id INT NOT NULL,
            path VARCHAR(190) NOT NULL, mime VARCHAR(40) NOT NULL, width INT NOT NULL, height INT NOT NULL,
            position INT NOT NULL, alt_text VARCHAR(190) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT)$suffix");
        // catalog_versions
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS catalog_versions (id $id, namespace VARCHAR(40) NOT NULL, version BIGINT NOT NULL)$suffix");
        // catalog_hierarchy_lock
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS catalog_hierarchy_lock (id $id, revision BIGINT NOT NULL)$suffix");
        // commerce_write_lock
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS commerce_write_lock (id $id, revision BIGINT NOT NULL)$suffix");

        // indexes
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_category_slug ON categories(slug)");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_brand_slug ON brands(slug)");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_product_slug ON products(slug)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_catalog ON products(status, category_id, id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_brand ON products(brand_id, status)");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_catalog_namespace ON catalog_versions(namespace)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_image_product ON product_images(product_id, position, id)");

        // catalog_versions seed
        $insert = $mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $this->pdo->exec("$insert INTO catalog_versions (namespace,version) VALUES ('catalog',1)");
        $this->pdo->exec("$insert INTO catalog_hierarchy_lock (id,revision) VALUES (1,1)");
        $this->pdo->exec("$insert INTO commerce_write_lock (id,revision) VALUES (1,1)");
    }
    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo,'categories',['id'=>'id','slug'=>'varchar(120)','name'=>'varchar(190)','enabled'=>'int','parent_id'=>'?int'],[[['slug'],true]],[['parent_id','categories','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'brands',['id'=>'id','slug'=>'varchar(120)','name'=>'varchar(190)','enabled'=>'int'],[[['slug'],true]]);
        MigrationSchema::verifyDefinition($this->pdo,'products',['id'=>'id','slug'=>'varchar(120)','name'=>'varchar(190)','description'=>'text','tags'=>'text','category_id'=>'int','brand_id'=>'?int','price_minor'=>'bigint','thickness_mm_milli'=>'?bigint','width_mm_milli'=>'?bigint','length_mm_milli'=>'?bigint','sell_unit'=>'varchar(8)','qty_increment_milli'=>'bigint','coverage_sqft_milli'=>'?bigint','status'=>'varchar(16)','featured'=>'int','revision'=>'bigint','created_at'=>'varchar(20)','updated_at'=>'varchar(20)'],
            [[['slug'],true],[['status','category_id','id'],false],[['brand_id','status'],false]],[['category_id','categories','id','RESTRICT'],['brand_id','brands','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'product_images',['id'=>'id','product_id'=>'int','path'=>'varchar(190)','mime'=>'varchar(40)','width'=>'int','height'=>'int','position'=>'int','alt_text'=>'varchar(190)'],[[['product_id','position','id'],false]],[['product_id','products','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'catalog_versions',['id'=>'id','namespace'=>'varchar(40)','version'=>'bigint'],[[['namespace'],true]]);
        foreach (['commerce_write_lock','catalog_hierarchy_lock'] as $table) MigrationSchema::verifyDefinition($this->pdo,$table,['id'=>'id','revision'=>'bigint']);
    }
}