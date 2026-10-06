<?php

class CommerceAudit extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_activity_log (id $id,
            actor_user_id INT NOT NULL, action VARCHAR(80) NOT NULL, target_type VARCHAR(40) NOT NULL,
            target_id INT NULL, operation_key VARCHAR(100) NOT NULL, request_id VARCHAR(32) NOT NULL,
            changes_json TEXT NOT NULL, created_at VARCHAR(20) NOT NULL,
            FOREIGN KEY (actor_user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'admin_activity_log', 'idx_audit_operation', ['operation_key'], true);
        MigrationSchema::ensureIndex($this->pdo, 'admin_activity_log', 'idx_audit_actor_time', ['actor_user_id', 'created_at']);
        $this->verify();
    }
    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'admin_activity_log', ['id' => 'id', 'actor_user_id' => 'int',
            'action' => 'varchar(80)', 'target_type' => 'varchar(40)', 'target_id' => '?int', 'operation_key' => 'varchar(100)',
            'request_id' => 'varchar(32)', 'changes_json' => 'text', 'created_at' => 'varchar(20)'],
            [[['operation_key'], true], [['actor_user_id', 'created_at'], false]], [['actor_user_id', 'users_tbl', 'id', 'RESTRICT']]);
    }
}
