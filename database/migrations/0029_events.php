<?php

/** EVT-01 append-only analytics events. Raw `events` are immutable; `event_processing` carries the
    mutable pending/processed state separately so aggregation (EVT-02) has a durable work source. A
    trusted `event_business_keys` ledger records accepted purchase/cancellation intents and survives raw
    event retention, so an operator-replayed intent can never count the same order twice. */
class Events extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS events (id $id,event_type VARCHAR(32) NOT NULL,event_key VARCHAR(190) NULL,
            user_id INT NULL,session_key VARCHAR(64) NULL,product_id INT NULL,variant_id INT NULL,category_id INT NULL,order_id INT NULL,
            payload VARCHAR(1000) NULL,occurred_at BIGINT NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE SET NULL,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'events', 'idx_event_key', ['event_key'], true);
        MigrationSchema::ensureIndex($this->pdo, 'events', 'idx_event_session', ['session_key', 'created_at'], false);
        MigrationSchema::ensureIndex($this->pdo, 'events', 'idx_event_product', ['product_id', 'created_at'], false);
        MigrationSchema::ensureIndex($this->pdo, 'events', 'idx_event_type_time', ['event_type', 'created_at'], false);

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS event_processing (id $id,event_id INT NOT NULL,state VARCHAR(16) NOT NULL DEFAULT 'pending',
            processed_at BIGINT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'event_processing', 'idx_event_processing_event', ['event_id'], true);
        MigrationSchema::ensureIndex($this->pdo, 'event_processing', 'idx_event_processing_state', ['state', 'event_id'], false);

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS event_business_keys (id $id,business_key VARCHAR(190) NOT NULL,order_id INT NOT NULL,
            kind VARCHAR(16) NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'event_business_keys', 'idx_event_bizkey', ['business_key'], true);
        MigrationSchema::ensureIndex($this->pdo, 'event_business_keys', 'idx_event_bizkey_order', ['order_id'], false);
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'events', ['id'=>'id','event_type'=>'varchar(32)','event_key'=>'?varchar(190)',
            'user_id'=>'?int','session_key'=>'?varchar(64)','product_id'=>'?int','variant_id'=>'?int','category_id'=>'?int','order_id'=>'?int',
            'payload'=>'?varchar(1000)','occurred_at'=>'bigint','created_at'=>'bigint'],
            [[['event_key'],true],[['session_key','created_at'],false],[['product_id','created_at'],false],[['event_type','created_at'],false]],
            [['user_id','users_tbl','id','SET NULL'],['product_id','products','id','SET NULL'],['order_id','orders','id','SET NULL']]);
        MigrationSchema::verifyDefinition($this->pdo, 'event_processing', ['id'=>'id','event_id'=>'int','state'=>'varchar(16)',
            'processed_at'=>'?bigint','created_at'=>'bigint'], [[['event_id'],true],[['state','event_id'],false]],
            [['event_id','events','id','CASCADE']]);
        MigrationSchema::verifyDefinition($this->pdo, 'event_business_keys', ['id'=>'id','business_key'=>'varchar(190)','order_id'=>'int',
            'kind'=>'varchar(16)','created_at'=>'bigint'], [[['business_key'],true],[['order_id'],false]],
            [['order_id','orders','id','RESTRICT']]);
    }
}
