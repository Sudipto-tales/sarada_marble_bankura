<?php

class OrderSnapshots extends Migration
{
    public function up()
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS orders (id $id, order_number VARCHAR(40) NOT NULL, user_id INT NOT NULL, cart_id INT NOT NULL,
            status VARCHAR(20) NOT NULL, revision BIGINT NOT NULL DEFAULT 1, currency VARCHAR(3) NOT NULL,
            subtotal_minor BIGINT NOT NULL, discount_minor BIGINT NOT NULL, shipping_minor BIGINT NOT NULL, tax_minor BIGINT NOT NULL, total_minor BIGINT NOT NULL,
            address_snapshot TEXT NOT NULL, calculation_policy VARCHAR(40) NOT NULL, tax_bps INT NOT NULL,
            payment_mode VARCHAR(16) NOT NULL, payment_status VARCHAR(16) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT, FOREIGN KEY(cart_id) REFERENCES carts(id) ON DELETE RESTRICT,
            CHECK(subtotal_minor>=0 AND discount_minor>=0 AND discount_minor<=subtotal_minor AND shipping_minor>=0 AND tax_minor>=0),
            CHECK(total_minor=subtotal_minor-discount_minor+shipping_minor+tax_minor), CHECK(tax_bps>=0 AND tax_bps<=10000))$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS order_items (id $id, order_id INT NOT NULL, product_id INT NOT NULL, variant_id INT NOT NULL,
            name VARCHAR(190) NOT NULL, sku VARCHAR(80) NOT NULL, sell_unit VARCHAR(8) NOT NULL, coverage_sqft_milli BIGINT NULL,
            qty_increment_milli BIGINT NOT NULL, qty_milli BIGINT NOT NULL, base_price_minor BIGINT NOT NULL, unit_price_minor BIGINT NOT NULL,
            subtotal_minor BIGINT NOT NULL, discount_minor BIGINT NOT NULL, tax_minor BIGINT NOT NULL, line_total_minor BIGINT NOT NULL,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
            FOREIGN KEY(variant_id,product_id) REFERENCES product_variants(id,product_id) ON DELETE RESTRICT,
            CHECK(qty_milli>0 AND qty_increment_milli>0 AND base_price_minor>=0 AND unit_price_minor>=0 AND subtotal_minor>=0 AND tax_minor>=0),
            CHECK(discount_minor>=0 AND discount_minor<=subtotal_minor), CHECK(line_total_minor=subtotal_minor-discount_minor+tax_minor))$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS order_status_history (id $id, order_id INT NOT NULL, from_status VARCHAR(20) NULL,
            to_status VARCHAR(20) NOT NULL, actor_user_id INT NOT NULL, reason VARCHAR(190) NOT NULL, operation_key VARCHAR(100) NOT NULL,
            created_at BIGINT NOT NULL, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
            FOREIGN KEY(actor_user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS checkout_requests (id $id, principal_key VARCHAR(80) NOT NULL, idempotency_key VARCHAR(100) NOT NULL,
            fingerprint VARCHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, order_id INT NULL, response_json TEXT NULL, created_at BIGINT NOT NULL,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT)$suffix");
        foreach([['orders','idx_order_number',['order_number'],true],['orders','idx_order_cart',['cart_id'],true],['orders','idx_order_owner',['user_id','id'],false],
            ['orders','idx_order_status_time',['status','created_at','id'],false],['order_items','idx_order_variant',['order_id','variant_id'],true],
            ['order_status_history','idx_order_operation',['operation_key'],true],['order_status_history','idx_order_history',['order_id','id'],false],
            ['checkout_requests','idx_checkout_key',['principal_key','idempotency_key'],true]] as [$table,$name,$columns,$unique]) MigrationSchema::ensureIndex($this->pdo,$table,$name,$columns,$unique);
        $this->orderLink($mysql);$this->verify();
    }
    private function orderLink(bool $mysql):void
    {
        if($mysql) {
            $query=$this->pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_movements' AND COLUMN_NAME='order_id' AND REFERENCED_TABLE_NAME='orders'");
            if(!(int)$query->fetchColumn()) $this->pdo->exec('ALTER TABLE inventory_movements ADD CONSTRAINT fk_movement_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT');
        } else {
            foreach(['INSERT','UPDATE'] as $event) {
                $name='movement_order_'.strtolower($event);
                $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS $name BEFORE $event ON inventory_movements
                    WHEN NEW.order_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM orders WHERE id=NEW.order_id)
                    BEGIN SELECT RAISE(ABORT,'movement order link'); END");
            }
            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS movement_order_delete BEFORE DELETE ON orders
                WHEN EXISTS(SELECT 1 FROM inventory_movements WHERE order_id=OLD.id)
                BEGIN SELECT RAISE(ABORT,'movement order history'); END");
        }
    }
    public function verify():void
    {
        MigrationSchema::verifyDefinition($this->pdo,'orders',['id'=>'id','order_number'=>'varchar(40)','user_id'=>'int','cart_id'=>'int','status'=>'varchar(20)','revision'=>'bigint','currency'=>'varchar(3)',
            'subtotal_minor'=>'bigint','discount_minor'=>'bigint','shipping_minor'=>'bigint','tax_minor'=>'bigint','total_minor'=>'bigint','address_snapshot'=>'text',
            'calculation_policy'=>'varchar(40)','tax_bps'=>'int','payment_mode'=>'varchar(16)','payment_status'=>'varchar(16)','created_at'=>'bigint','updated_at'=>'bigint'],
            [[['order_number'],true],[['cart_id'],true],[['user_id','id'],false],[['status','created_at','id'],false]],
            [['user_id','users_tbl','id','RESTRICT'],['cart_id','carts','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'order_items',['id'=>'id','order_id'=>'int','product_id'=>'int','variant_id'=>'int','name'=>'varchar(190)','sku'=>'varchar(80)','sell_unit'=>'varchar(8)',
            'coverage_sqft_milli'=>'?bigint','qty_increment_milli'=>'bigint','qty_milli'=>'bigint','base_price_minor'=>'bigint','unit_price_minor'=>'bigint','subtotal_minor'=>'bigint',
            'discount_minor'=>'bigint','tax_minor'=>'bigint','line_total_minor'=>'bigint'],[[['order_id','variant_id'],true]],
            [['order_id','orders','id','RESTRICT'],['product_id','products','id','RESTRICT'],['variant_id','product_variants','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'order_status_history',['id'=>'id','order_id'=>'int','from_status'=>'?varchar(20)','to_status'=>'varchar(20)','actor_user_id'=>'int','reason'=>'varchar(190)','operation_key'=>'varchar(100)','created_at'=>'bigint'],
            [[['operation_key'],true],[['order_id','id'],false]],[['order_id','orders','id','RESTRICT'],['actor_user_id','users_tbl','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'checkout_requests',['id'=>'id','principal_key'=>'varchar(80)','idempotency_key'=>'varchar(100)','fingerprint'=>'varchar(64)','state'=>'varchar(16)','order_id'=>'?int','response_json'=>'?text','created_at'=>'bigint'],
            [[['principal_key','idempotency_key'],true]],[['order_id','orders','id','RESTRICT']]);
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') MigrationSchema::verifyDefinition($this->pdo,'inventory_movements',['order_id'=>'?int'],[],[['order_id','orders','id','RESTRICT']]);
        else foreach(['movement_order_insert','movement_order_update','movement_order_delete'] as $name) {
            $query=$this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='trigger' AND name=?");$query->execute([$name]);
            if(!$query->fetchColumn()) throw new RuntimeException('Missing movement order link trigger.');
        }
    }
}
