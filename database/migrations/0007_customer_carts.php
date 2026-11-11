<?php

class CustomerCarts extends Migration
{
    public function up()
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'INT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $owner=$mysql?"CASE WHEN user_id IS NOT NULL THEN CONCAT('user:',user_id) ELSE CONCAT('guest:',guest_token_hash) END":"CASE WHEN user_id IS NOT NULL THEN 'user:'||user_id ELSE 'guest:'||guest_token_hash END";
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS addresses (id $id, user_id INT NOT NULL, label VARCHAR(40) NOT NULL,
            recipient VARCHAR(190) NOT NULL, phone VARCHAR(20) NOT NULL, line1 VARCHAR(190) NOT NULL, line2 VARCHAR(190) NOT NULL,
            city VARCHAR(100) NOT NULL, state VARCHAR(100) NOT NULL, postal_code VARCHAR(16) NOT NULL, country_code VARCHAR(2) NOT NULL,
            is_default INT NOT NULL DEFAULT 0, enabled INT NOT NULL DEFAULT 1, revision BIGINT NOT NULL DEFAULT 1,
            default_user_id INT GENERATED ALWAYS AS (CASE WHEN is_default=1 AND enabled=1 THEN user_id ELSE NULL END) VIRTUAL,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS carts (id $id, user_id INT NULL, guest_token_hash VARCHAR(64) NULL,
            owner_key VARCHAR(80) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', revision BIGINT NOT NULL DEFAULT 1,
            expires_at BIGINT NOT NULL, merged_cart_id INT NULL, created_at BIGINT NOT NULL,
            active_owner_key VARCHAR(80) GENERATED ALWAYS AS (CASE WHEN status='active' THEN owner_key ELSE NULL END) VIRTUAL,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT, FOREIGN KEY(merged_cart_id) REFERENCES carts(id) ON DELETE RESTRICT,
            CHECK((user_id IS NOT NULL AND guest_token_hash IS NULL) OR (user_id IS NULL AND guest_token_hash IS NOT NULL)), CHECK(owner_key=($owner)))$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS cart_items (id $id, cart_id INT NOT NULL, variant_id INT NOT NULL, qty_milli BIGINT NOT NULL,
            FOREIGN KEY(cart_id) REFERENCES carts(id) ON DELETE CASCADE, FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
            CHECK(qty_milli>0))$suffix");
        foreach([['addresses','idx_address_default',['default_user_id'],true],['addresses','idx_address_owner',['user_id','enabled','id'],false],
            ['carts','idx_cart_active_owner',['active_owner_key'],true],['carts','idx_cart_guest',['guest_token_hash','id'],false],
            ['carts','idx_cart_user',['user_id','id'],false],['carts','idx_cart_expiry',['status','expires_at','id'],false],
            ['cart_items','idx_cart_line',['cart_id','variant_id'],true]] as [$table,$name,$columns,$unique]) MigrationSchema::ensureIndex($this->pdo,$table,$name,$columns,$unique);
        $this->cartLink($mysql);
        $this->verify();
    }
    private function cartLink(bool $mysql):void
    {
        if($mysql) {
            $query=$this->pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_reservations' AND COLUMN_NAME='cart_id' AND REFERENCED_TABLE_NAME='carts'");
            if(!(int)$query->fetchColumn()) $this->pdo->exec('ALTER TABLE inventory_reservations ADD CONSTRAINT fk_reservation_cart FOREIGN KEY(cart_id) REFERENCES carts(id) ON DELETE RESTRICT');
        } else {
            // SQLite cannot add an FK with ALTER TABLE. Forward-compatible triggers
            // enforce the same optional link without rebuilding live reservation history.
            foreach(['INSERT','UPDATE'] as $event) {
                $name='reservation_cart_'.strtolower($event);
                $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS $name BEFORE $event ON inventory_reservations
                    WHEN NEW.cart_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM carts WHERE id=NEW.cart_id)
                    BEGIN SELECT RAISE(ABORT,'reservation cart link'); END");
            }
            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS reservation_cart_delete BEFORE DELETE ON carts
                WHEN EXISTS(SELECT 1 FROM inventory_reservations WHERE cart_id=OLD.id)
                BEGIN SELECT RAISE(ABORT,'reservation cart history'); END");
        }
    }
    public function verify():void
    {
        MigrationSchema::verifyDefinition($this->pdo,'addresses',['id'=>'id','user_id'=>'int','label'=>'varchar(40)','recipient'=>'varchar(190)','phone'=>'varchar(20)','line1'=>'varchar(190)','line2'=>'varchar(190)',
            'city'=>'varchar(100)','state'=>'varchar(100)','postal_code'=>'varchar(16)','country_code'=>'varchar(2)','is_default'=>'int','enabled'=>'int','revision'=>'bigint','default_user_id'=>'?int'],
            [[['default_user_id'],true],[['user_id','enabled','id'],false]],[['user_id','users_tbl','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'carts',['id'=>'id','user_id'=>'?int','guest_token_hash'=>'?varchar(64)','owner_key'=>'varchar(80)','status'=>'varchar(16)','revision'=>'bigint','expires_at'=>'bigint','merged_cart_id'=>'?int','created_at'=>'bigint','active_owner_key'=>'?varchar(80)'],
            [[['active_owner_key'],true],[['guest_token_hash','id'],false],[['user_id','id'],false],[['status','expires_at','id'],false]],
            [['user_id','users_tbl','id','RESTRICT'],['merged_cart_id','carts','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'cart_items',['id'=>'id','cart_id'=>'int','variant_id'=>'int','qty_milli'=>'bigint'],[[['cart_id','variant_id'],true]],
            [['cart_id','carts','id','CASCADE'],['variant_id','product_variants','id','RESTRICT']]);
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
            MigrationSchema::verifyDefinition($this->pdo,'inventory_reservations',['cart_id'=>'?int'],[],[['cart_id','carts','id','RESTRICT']]);
        } else {
            foreach(['reservation_cart_insert','reservation_cart_update','reservation_cart_delete'] as $name) {
                $query=$this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='trigger' AND name=?");$query->execute([$name]);
                if(!$query->fetchColumn()) throw new RuntimeException('Missing reservation cart link trigger.');
            }
        }
    }
}
