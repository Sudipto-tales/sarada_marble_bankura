<?php

/** Advance (partial) payment: a basis-point share collected online now, balance on delivery.
    advance_bps is nullable at product and category level (NULL inherits; 0 forces full payment;
    >0 is the advance %). amount_due_minor is the committed online-now figure (NULL = legacy full). */
class AdvancePayment extends Migration
{
    public function up()
    {
        if(!isset(MigrationSchema::columns($this->pdo,'products')['advance_bps'])) $this->pdo->exec('ALTER TABLE products ADD COLUMN advance_bps INT NULL');
        if(!isset(MigrationSchema::columns($this->pdo,'categories')['advance_bps'])) $this->pdo->exec('ALTER TABLE categories ADD COLUMN advance_bps INT NULL');
        // Snapshot the resolved advance rate on each line so later config edits never alter a placed order.
        if(!isset(MigrationSchema::columns($this->pdo,'order_items')['advance_bps'])) $this->pdo->exec('ALTER TABLE order_items ADD COLUMN advance_bps INT NULL');
        // The online-now amount. Historical rows stay NULL and are read as the full total.
        if(!isset(MigrationSchema::columns($this->pdo,'orders')['amount_due_minor'])) $this->pdo->exec('ALTER TABLE orders ADD COLUMN amount_due_minor BIGINT NULL');
        $this->verify();
    }
    public function verify():void
    {
        foreach(['products','categories','order_items'] as $table) MigrationSchema::verifyDefinition($this->pdo,$table,['advance_bps'=>'?int']);
        MigrationSchema::verifyDefinition($this->pdo,'orders',['amount_due_minor'=>'?bigint']);
    }
}
