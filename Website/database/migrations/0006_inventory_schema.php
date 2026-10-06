<?php

class InventorySchema extends Migration
{
    public function up()
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'INT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS inventory (id $id, variant_id INT NOT NULL,
            on_hand_milli BIGINT NOT NULL DEFAULT 0, reserved_milli BIGINT NOT NULL DEFAULT 0, revision BIGINT NOT NULL DEFAULT 1,
            FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
            CHECK(on_hand_milli >= 0), CHECK(reserved_milli >= 0 AND reserved_milli <= on_hand_milli))$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS inventory_reservations (id $id, principal_key VARCHAR(80) NOT NULL,
            cart_id INT NULL, cart_revision BIGINT NULL, business_key VARCHAR(100) NOT NULL, fingerprint VARCHAR(64) NOT NULL,
            state VARCHAR(16) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS inventory_reservation_items (id $id, reservation_id INT NOT NULL,
            variant_id INT NOT NULL, qty_milli BIGINT NOT NULL, FOREIGN KEY(reservation_id) REFERENCES inventory_reservations(id) ON DELETE RESTRICT,
            FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT, CHECK(qty_milli > 0))$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS inventory_movements (id $id, variant_id INT NOT NULL,
            on_hand_delta_milli BIGINT NOT NULL, reserved_delta_milli BIGINT NOT NULL, reason VARCHAR(190) NOT NULL,
            actor_user_id INT NULL, order_id INT NULL, reservation_id INT NULL, business_key VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL,
            FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
            FOREIGN KEY(actor_user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT,
            FOREIGN KEY(reservation_id) REFERENCES inventory_reservations(id) ON DELETE RESTRICT)$suffix");
        foreach ([['inventory','idx_inventory_variant',['variant_id'],true],
            ['inventory_reservations','idx_reservation_key',['business_key'],true],
            ['inventory_reservations','idx_reservation_expiry',['state','expires_at','id'],false],
            ['inventory_reservations','idx_reservation_cart',['cart_id','state'],false],
            ['inventory_reservation_items','idx_reservation_item_pair',['reservation_id','variant_id'],true],
            ['inventory_movements','idx_movement_business',['business_key','variant_id'],true],
            ['inventory_movements','idx_movement_variant_time',['variant_id','created_at','id'],false]] as [$table,$name,$columns,$unique]) MigrationSchema::ensureIndex($this->pdo,$table,$name,$columns,$unique);
        $this->verify();
        $insert=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
        $this->pdo->exec("$insert INTO inventory (variant_id,on_hand_milli,reserved_milli,revision) SELECT id,0,0,1 FROM product_variants");
    }
    public function verify():void
    {
        MigrationSchema::verifyDefinition($this->pdo,'inventory',['id'=>'id','variant_id'=>'int','on_hand_milli'=>'bigint','reserved_milli'=>'bigint','revision'=>'bigint'],[[['variant_id'],true]],[['variant_id','product_variants','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'inventory_reservations',['id'=>'id','principal_key'=>'varchar(80)','cart_id'=>'?int','cart_revision'=>'?bigint','business_key'=>'varchar(100)','fingerprint'=>'varchar(64)','state'=>'varchar(16)','expires_at'=>'bigint','created_at'=>'bigint'],
            [[['business_key'],true],[['state','expires_at','id'],false],[['cart_id','state'],false]]);
        MigrationSchema::verifyDefinition($this->pdo,'inventory_reservation_items',['id'=>'id','reservation_id'=>'int','variant_id'=>'int','qty_milli'=>'bigint'],[[['reservation_id','variant_id'],true]],
            [['reservation_id','inventory_reservations','id','RESTRICT'],['variant_id','product_variants','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'inventory_movements',['id'=>'id','variant_id'=>'int','on_hand_delta_milli'=>'bigint','reserved_delta_milli'=>'bigint','reason'=>'varchar(190)','actor_user_id'=>'?int','order_id'=>'?int','reservation_id'=>'?int','business_key'=>'varchar(100)','created_at'=>'bigint'],
            [[['business_key','variant_id'],true],[['variant_id','created_at','id'],false]],
            [['variant_id','product_variants','id','RESTRICT'],['actor_user_id','users_tbl','id','RESTRICT'],['reservation_id','inventory_reservations','id','RESTRICT']]);
    }
}
