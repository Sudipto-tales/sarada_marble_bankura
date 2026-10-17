<?php

class ProductDetailFeatures extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS product_details (id $id,product_id INT NOT NULL,content_json TEXT NOT NULL,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS variant_reference_prices (id $id,variant_id INT NOT NULL,original_price_minor BIGINT NOT NULL,
            FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,CHECK(original_price_minor>=0))$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS product_reviews (id $id,product_id INT NOT NULL,user_id INT NOT NULL,rating INT NOT NULL,
            title VARCHAR(120) NOT NULL,body TEXT NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending',revision BIGINT NOT NULL DEFAULT 1,
            created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT,CHECK(rating BETWEEN 1 AND 5))$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS purchase_scopes (id $id,user_id INT NOT NULL,scope_key VARCHAR(100) NOT NULL,fingerprint VARCHAR(64) NOT NULL,cart_id INT NULL,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT,FOREIGN KEY(cart_id) REFERENCES carts(id) ON DELETE RESTRICT)$suffix");
        foreach ([['product_details','idx_detail_product',['product_id'],true],['variant_reference_prices','idx_reference_variant',['variant_id'],true],
            ['product_reviews','idx_review_owner',['product_id','user_id'],true],['product_reviews','idx_review_public',['product_id','status','id'],false],
            ['product_reviews','idx_review_moderation',['status','id'],false],['purchase_scopes','idx_purchase_scope',['user_id','scope_key'],true],
            ['purchase_scopes','idx_purchase_cart',['cart_id'],true]] as [$table,$name,$columns,$unique]) MigrationSchema::ensureIndex($this->pdo,$table,$name,$columns,$unique);
        $this->verify();
    }
    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo,'product_details',['id'=>'id','product_id'=>'int','content_json'=>'text'],[[['product_id'],true]],[['product_id','products','id','CASCADE']]);
        MigrationSchema::verifyDefinition($this->pdo,'variant_reference_prices',['id'=>'id','variant_id'=>'int','original_price_minor'=>'bigint'],[[['variant_id'],true]],[['variant_id','product_variants','id','CASCADE']]);
        MigrationSchema::verifyDefinition($this->pdo,'product_reviews',['id'=>'id','product_id'=>'int','user_id'=>'int','rating'=>'int','title'=>'varchar(120)','body'=>'text','status'=>'varchar(16)','revision'=>'bigint','created_at'=>'bigint','updated_at'=>'bigint'],
            [[['product_id','user_id'],true],[['product_id','status','id'],false],[['status','id'],false]],[['product_id','products','id','CASCADE'],['user_id','users_tbl','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'purchase_scopes',['id'=>'id','user_id'=>'int','scope_key'=>'varchar(100)','fingerprint'=>'varchar(64)','cart_id'=>'?int'],[[['user_id','scope_key'],true],[['cart_id'],true]],[['user_id','users_tbl','id','RESTRICT'],['cart_id','carts','id','RESTRICT']]);
    }
}
