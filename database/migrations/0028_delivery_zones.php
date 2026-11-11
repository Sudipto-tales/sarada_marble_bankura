<?php

/** Store-level delivery serviceability (DLV-PIN-01): a PIN code maps to one zone that carries an ETA
    window and (display-only for now) a shipping charge. A dedicated catalog_versions namespace lets the
    PDP delivery lookup be cached and invalidated transactionally with zone/pincode writes. */
class DeliveryZones extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS delivery_zones (id $id,name VARCHAR(120) NOT NULL,
            eta_min_days INT NOT NULL,eta_max_days INT NOT NULL,shipping_minor BIGINT NOT NULL DEFAULT 0,
            active INT NOT NULL DEFAULT 1,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS delivery_pincodes (id $id,pincode VARCHAR(6) NOT NULL,
            zone_id INT NOT NULL,active INT NOT NULL DEFAULT 1,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,
            FOREIGN KEY(zone_id) REFERENCES delivery_zones(id) ON DELETE RESTRICT)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'delivery_zones', 'idx_delivery_zone_name', ['name'], true);
        MigrationSchema::ensureIndex($this->pdo, 'delivery_pincodes', 'idx_delivery_pincode', ['pincode'], true);
        MigrationSchema::ensureIndex($this->pdo, 'delivery_pincodes', 'idx_delivery_pincode_zone', ['zone_id'], false);
        // A 'delivery' version seeds the cache namespace; INSERT OR IGNORE keeps the migration rerunnable.
        $insert = $mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $this->pdo->exec("$insert INTO catalog_versions (namespace,version) VALUES ('delivery',1)");
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'delivery_zones', ['id'=>'id','name'=>'varchar(120)',
            'eta_min_days'=>'int','eta_max_days'=>'int','shipping_minor'=>'bigint','active'=>'int',
            'created_at'=>'bigint','updated_at'=>'bigint'], [[['name'],true]]);
        MigrationSchema::verifyDefinition($this->pdo, 'delivery_pincodes', ['id'=>'id','pincode'=>'varchar(6)',
            'zone_id'=>'int','active'=>'int','created_at'=>'bigint','updated_at'=>'bigint'],
            [[['pincode'],true],[['zone_id'],false]], [['zone_id','delivery_zones','id','RESTRICT']]);
    }
}
