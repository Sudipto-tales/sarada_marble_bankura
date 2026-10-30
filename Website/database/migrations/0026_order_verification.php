<?php

/** Phone-confirmation gate (ADV-BOOK-01): records who verified an advance order and the call outcome,
    plus the two admin-set deadlines. `payment_deadline_at` bounds an unpaid order; `verify_deadline_at`
    bounds a paid-but-unverified order. Both are snapshots set when the order enters the relevant state.
    Column types are declared identically for both drivers so SQLite preserves them for verifyDefinition. */
class OrderVerification extends Migration
{
    public function up()
    {
        $columns = MigrationSchema::columns($this->pdo, 'orders');
        $add = [
            'verified_by'         => 'INT DEFAULT NULL',
            'verified_at'         => 'BIGINT DEFAULT NULL',
            'verify_outcome'      => 'VARCHAR(16) DEFAULT NULL',
            'verify_note'         => 'VARCHAR(500) DEFAULT NULL',
            'verify_attempts'     => 'INT NOT NULL DEFAULT 0',
            'verify_deadline_at'  => 'BIGINT DEFAULT NULL',
            'payment_deadline_at' => 'BIGINT DEFAULT NULL',
        ];
        foreach ($add as $name => $definition) {
            if (!isset($columns[$name])) $this->pdo->exec("ALTER TABLE orders ADD COLUMN $name $definition");
        }
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'orders', [
            'verified_by' => '?int', 'verified_at' => '?bigint', 'verify_outcome' => '?varchar(16)',
            'verify_note' => '?varchar(500)', 'verify_attempts' => 'int',
            'verify_deadline_at' => '?bigint', 'payment_deadline_at' => '?bigint',
        ]);
    }
}
