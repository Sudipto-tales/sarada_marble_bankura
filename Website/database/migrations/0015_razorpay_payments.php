<?php

class RazorpayPayments extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS payments (id $id,order_id INT NOT NULL,
            provider VARCHAR(16) NOT NULL,provider_order_id VARCHAR(64) NOT NULL,provider_payment_id VARCHAR(64) NULL,
            amount_minor BIGINT NOT NULL,currency VARCHAR(3) NOT NULL,status VARCHAR(16) NOT NULL,
            created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS payment_events (id $id,provider VARCHAR(16) NOT NULL,
            event_id VARCHAR(80) NOT NULL,payment_id INT NULL,status VARCHAR(16) NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(payment_id) REFERENCES payments(id) ON DELETE RESTRICT)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'payments', 'idx_payments_provider_order', ['provider','provider_order_id'], true);
        MigrationSchema::ensureIndex($this->pdo, 'payments', 'idx_payments_order', ['order_id'], false);
        MigrationSchema::ensureIndex($this->pdo, 'payment_events', 'idx_payment_event', ['provider','event_id'], true);
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'payments', ['id'=>'id','order_id'=>'int','provider'=>'varchar(16)',
            'provider_order_id'=>'varchar(64)','provider_payment_id'=>'?varchar(64)','amount_minor'=>'bigint',
            'currency'=>'varchar(3)','status'=>'varchar(16)','created_at'=>'bigint','updated_at'=>'bigint'],
            [[['provider','provider_order_id'],true],[['order_id'],false]], [['order_id','orders','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo, 'payment_events', ['id'=>'id','provider'=>'varchar(16)',
            'event_id'=>'varchar(80)','payment_id'=>'?int','status'=>'varchar(16)','created_at'=>'bigint'],
            [[['provider','event_id'],true]], [['payment_id','payments','id','RESTRICT']]);
    }
}
