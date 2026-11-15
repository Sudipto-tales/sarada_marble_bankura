<?php

class Enquiries extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        $id = $mysql
            ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $suffix = $mysql
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS enquiries (
                id $id,
                product_id INT NOT NULL,
                customer_name VARCHAR(190) NOT NULL,
                phone VARCHAR(40) NOT NULL,
                email VARCHAR(190) NULL,
                quantity INT NULL,
                message TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                created_at VARCHAR(20) NOT NULL,
                updated_at VARCHAR(20) NOT NULL,
                FOREIGN KEY (product_id)
                    REFERENCES products(id)
                    ON DELETE RESTRICT
            )$suffix
        ");

        MigrationSchema::ensureIndex(
            $this->pdo,
            'enquiries',
            'idx_enquiries_product',
            ['product_id'],
            false
        );

        MigrationSchema::ensureIndex(
            $this->pdo,
            'enquiries',
            'idx_enquiries_status',
            ['status'],
            false
        );

        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition(
            $this->pdo,
            'enquiries',
            [
                'id' => 'id',
                'product_id' => 'int',
                'customer_name' => 'varchar(190)',
                'phone' => 'varchar(40)',
                'email' => '?varchar(190)',
                'quantity' => '?int',
                'message' => '?text',
                'status' => 'varchar(20)',
                'created_at' => 'varchar(20)',
                'updated_at' => 'varchar(20)',
            ],
            [
                [['product_id'], false],
                [['status'], false],
            ],
            [
                ['product_id', 'products', 'id', 'RESTRICT'],
            ]
        );

        MigrationSchema::verifyDefinition(
            $this->pdo,
            'catalog_versions',
            [
                'id' => 'id',
                'namespace' => 'varchar(40)',
                'version' => 'bigint',
            ],
            [[['namespace'], true]]
        );

        foreach (['commerce_write_lock', 'catalog_hierarchy_lock'] as $table) {
            MigrationSchema::verifyDefinition(
                $this->pdo,
                $table,
                [
                    'id' => 'id',
                    'revision' => 'bigint',
                ]
            );
        }
    }
}