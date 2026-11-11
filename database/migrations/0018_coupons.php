<?php
class Coupons extends Migration
{
    public function up()
    {
        $mysql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'INT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS coupons (id $id,code VARCHAR(40) NOT NULL,kind VARCHAR(8) NOT NULL,value INT NOT NULL,
            min_order_minor BIGINT NOT NULL DEFAULT 0,max_discount_minor BIGINT NULL,usage_limit INT NULL,used_count INT NOT NULL DEFAULT 0,
            per_user_limit INT NULL,starts_at BIGINT NULL,ends_at BIGINT NULL,enabled INT NOT NULL DEFAULT 1,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS coupon_redemptions (id $id,coupon_id INT NOT NULL,user_id INT NOT NULL,order_id INT NOT NULL,
            amount_minor BIGINT NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT)$suffix");
        MigrationSchema::ensureIndex($this->pdo,'coupons','idx_coupon_code',['code'],true);
        MigrationSchema::ensureIndex($this->pdo,'coupon_redemptions','idx_redemption_order',['order_id'],true);
        MigrationSchema::ensureIndex($this->pdo,'coupon_redemptions','idx_redemption_user',['coupon_id','user_id'],false);
        MigrationSchema::ensureIndex($this->pdo,'coupon_redemptions','idx_redemption_time',['created_at','coupon_id'],false);
        MigrationSchema::ensureIndex($this->pdo,'orders','idx_order_paid_time',['payment_status','created_at'],false);
        $this->verify();
    }
    public function verify():void
    {
        MigrationSchema::verifyDefinition($this->pdo,'coupons',['id'=>'id','code'=>'varchar(40)','kind'=>'varchar(8)','value'=>'int',
            'min_order_minor'=>'bigint','max_discount_minor'=>'?bigint','usage_limit'=>'?int','used_count'=>'int','per_user_limit'=>'?int',
            'starts_at'=>'?bigint','ends_at'=>'?bigint','enabled'=>'int','created_at'=>'bigint','updated_at'=>'bigint'],[[['code'],true]]);
        MigrationSchema::verifyDefinition($this->pdo,'coupon_redemptions',['id'=>'id','coupon_id'=>'int','user_id'=>'int','order_id'=>'int','amount_minor'=>'bigint','created_at'=>'bigint'],
            [[['order_id'],true],[['coupon_id','user_id'],false],[['created_at','coupon_id'],false]],
            [['coupon_id','coupons','id','RESTRICT'],['user_id','users_tbl','id','RESTRICT'],['order_id','orders','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'orders',[],[[['payment_status','created_at'],false]]);
    }
}
