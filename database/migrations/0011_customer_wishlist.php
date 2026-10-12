<?php

class CustomerWishlist extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS wishlists (id $id,user_id INT NOT NULL,product_id INT NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE CASCADE,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'wishlists', 'idx_wishlist_owner_product', ['user_id','product_id'], true);
        $this->verify();
    }
    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'wishlists', ['id'=>'id','user_id'=>'int','product_id'=>'int','created_at'=>'bigint'],
            [[['user_id','product_id'],true]], [['user_id','users_tbl','id','CASCADE'],['product_id','products','id','CASCADE']]);
    }
}
