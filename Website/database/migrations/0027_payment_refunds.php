<?php

/** Advance/full refunds for rejected or cancelled paid orders (ADV-BOOK-01). A durable refund record
    carries its own lease so the external Razorpay call runs between short transactions, like notifications.
    `idempotency_key` (the operator/system operation key) makes a refund request replay-safe. */
class PaymentRefunds extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS payment_refunds (id $id,payment_id INT NOT NULL,order_id INT NOT NULL,
            amount_minor BIGINT NOT NULL,currency VARCHAR(3) NOT NULL,status VARCHAR(16) NOT NULL,
            provider_refund_id VARCHAR(64) NULL,reason VARCHAR(190) NULL,idempotency_key VARCHAR(190) NOT NULL,
            lease_token VARCHAR(64) NULL,lease_expires_at BIGINT NULL,attempts INT NOT NULL DEFAULT 0,
            last_error_code VARCHAR(80) NULL,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,
            FOREIGN KEY(payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'payment_refunds', 'idx_refund_key', ['idempotency_key'], true);
        MigrationSchema::ensureIndex($this->pdo, 'payment_refunds', 'idx_refund_order', ['order_id'], false);
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'payment_refunds', ['id'=>'id','payment_id'=>'int','order_id'=>'int',
            'amount_minor'=>'bigint','currency'=>'varchar(3)','status'=>'varchar(16)','provider_refund_id'=>'?varchar(64)',
            'reason'=>'?varchar(190)','idempotency_key'=>'varchar(190)','lease_token'=>'?varchar(64)','lease_expires_at'=>'?bigint',
            'attempts'=>'int','last_error_code'=>'?varchar(80)','created_at'=>'bigint','updated_at'=>'bigint'],
            [[['idempotency_key'],true],[['order_id'],false]],
            [['payment_id','payments','id','RESTRICT'],['order_id','orders','id','RESTRICT']]);
    }
}
