<?php

/** The `awaiting_verification` status (ORD-VERIFY-01) is 21 characters but the status columns were
    VARCHAR(20): MySQL would silently truncate it and break the state machine (SQLite does not enforce
    length, which is why it went unnoticed). Widen the order status columns so the full value fits on
    both drivers. SQLite ignores column length, so only MySQL needs the ALTER. */
class WidenOrderStatus extends Migration
{
    public function up()
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo->exec('ALTER TABLE orders MODIFY status VARCHAR(32) NOT NULL');
            $this->pdo->exec('ALTER TABLE order_status_history MODIFY from_status VARCHAR(32) NULL');
            $this->pdo->exec('ALTER TABLE order_status_history MODIFY to_status VARCHAR(32) NOT NULL');
        }
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::requireColumns($this->pdo, 'orders', ['status']);
        MigrationSchema::requireColumns($this->pdo, 'order_status_history', ['from_status', 'to_status']);
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $columns = MigrationSchema::columns($this->pdo, 'orders');
            if (strtolower($columns['status']['Type']) !== 'varchar(32)') throw new RuntimeException('orders.status width was not applied.');
        }
    }
}
