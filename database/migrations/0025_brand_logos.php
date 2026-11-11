<?php

/** Storefront brand tiles show a supplier logo instead of a bare name, so brands carry an image path. */
class BrandLogos extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $columns = MigrationSchema::columns($this->pdo, 'brands');
        if (!isset($columns['logo_path'])) {
            $this->pdo->exec('ALTER TABLE brands ADD COLUMN logo_path ' . ($mysql ? 'VARCHAR(190) DEFAULT NULL' : 'TEXT DEFAULT NULL'));
        }
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::requireColumns($this->pdo, 'brands', ['logo_path']);
    }
}
