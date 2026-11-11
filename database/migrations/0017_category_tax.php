<?php
class CategoryTax extends Migration
{
    public function up()
    {
        if(!isset(MigrationSchema::columns($this->pdo,'categories')['tax_bps'])) $this->pdo->exec('ALTER TABLE categories ADD COLUMN tax_bps INT NULL');
        // Snapshot the resolved line rate; historical rows remain NULL (their order default applies).
        if(!isset(MigrationSchema::columns($this->pdo,'order_items')['tax_bps'])) $this->pdo->exec('ALTER TABLE order_items ADD COLUMN tax_bps INT NULL');
        $this->verify();
    }
    public function verify():void
    {
        foreach(['categories','order_items'] as $table) MigrationSchema::verifyDefinition($this->pdo,$table,['tax_bps'=>'?int']);
    }
}
