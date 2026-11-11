<?php

/** Explicitly expire legacy raw credentials; never grant their unbounded lifetimes. */
class AuthProtections extends Migration
{
    private const TABLES = [
        'auth_remember_tokens' => ['id', 'user_id', 'selector', 'validator_hash', 'expires_at', 'revoked_at'],
        'auth_verification_tokens' => ['id', 'user_id', 'token_hash', 'expires_at'],
        'auth_login_limits' => ['id', 'bucket_key', 'window_start', 'attempts'],
    ];

    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS auth_remember_tokens (
            id $id, user_id INT NOT NULL, selector VARCHAR(32) NOT NULL,
            validator_hash VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL, revoked_at BIGINT NULL,
            FOREIGN KEY (user_id) REFERENCES users_tbl(id) ON DELETE CASCADE)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS auth_verification_tokens (
            id $id, user_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users_tbl(id) ON DELETE CASCADE)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_limits (
            id $id, bucket_key VARCHAR(64) NOT NULL, window_start BIGINT NOT NULL,
            attempts INT NOT NULL)$suffix");
        $this->verifyShape();
        foreach ($this->expectedIndexes() as [$table, $name, $columns, $unique]) {
            MigrationSchema::ensureIndex($this->pdo, $table, $name, $columns, $unique);
        }
        if (!$mysql) {
            // Legacy signup did not normalize casing. Enforce one case-insensitive identity.
            // Colliding legacy emails require an operator repair, never an arbitrary winner.
            $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_nocase ON users_tbl(email COLLATE NOCASE)');
            $this->verifyEmailIndex();
        }
        // DDL may survive interrupted MySQL runs. Revocation is safe to repeat.
        $this->pdo->exec('UPDATE users_tbl SET remember_token = NULL, verify_token = NULL');
    }

    private function expectedIndexes(): array
    {
        return [
            ['auth_remember_tokens', 'idx_auth_remember_selector', ['selector'], true],
            ['auth_remember_tokens', 'idx_auth_remember_user', ['user_id'], false],
            ['auth_remember_tokens', 'idx_auth_remember_expiry', ['expires_at'], false],
            ['auth_verification_tokens', 'idx_auth_verification_user', ['user_id'], true],
            ['auth_verification_tokens', 'idx_auth_verification_hash', ['token_hash'], true],
            ['auth_verification_tokens', 'idx_auth_verification_expiry', ['expires_at'], false],
            ['auth_login_limits', 'idx_auth_login_bucket', ['bucket_key'], true],
            ['auth_login_limits', 'idx_auth_login_window', ['window_start'], false],
        ];
    }

    private function verifyShape(): void
    {
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        foreach (self::TABLES as $table => $names) {
            $columns = MigrationSchema::requireColumns($this->pdo, $table, $names);
            MigrationSchema::requireIntegerPrimaryKey($this->pdo, $table, $columns['id']);
            foreach (array_diff($names, ['id']) as $name) {
                $column = $columns[$name];
                $type = strtolower($sqlite ? $column['type'] : $column['Type']);
                $length = in_array($name, ['selector', 'validator_hash', 'token_hash', 'bucket_key'], true)
                    ? ($name === 'selector' ? 32 : 64) : null;
                $valid = $length ? $type === "varchar($length)"
                    : (bool) preg_match('/\A(?:int|bigint)(?:\(\d+\))?\z/', $type);
                $nullable = $sqlite ? !$column['notnull'] : $column['Null'] === 'YES';
                if (!$valid || $nullable !== ($name === 'revoked_at')) {
                    throw new RuntimeException('Incompatible authentication schema.');
                }
            }
            if ($table === 'auth_login_limits') continue;
            if ($sqlite) {
                $foreignKeys = $this->pdo->query('PRAGMA foreign_key_list(' . MigrationSchema::identifier($table) . ')')->fetchAll();
                $valid = array_filter($foreignKeys, static fn(array $key): bool => $key['table'] === 'users_tbl'
                    && $key['from'] === 'user_id' && $key['to'] === 'id' && $key['on_delete'] === 'CASCADE');
            } else {
                $query = $this->pdo->prepare("SELECT k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k
                    JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                    AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                    WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = 'user_id'
                    AND k.REFERENCED_TABLE_NAME = 'users_tbl' AND k.REFERENCED_COLUMN_NAME = 'id'
                    AND r.DELETE_RULE = 'CASCADE'");
                $query->execute([$table]);
                $valid = $query->fetchAll();
            }
            if (!$valid) throw new RuntimeException('Missing authentication ownership constraint.');
        }
    }

    public function verify(): void
    {
        $this->verifyShape();
        $this->verifyEmailIndex();
        foreach ($this->expectedIndexes() as [$table, $name, $columns, $unique]) {
            if (!in_array(['unique' => $unique, 'columns' => $columns, 'partial' => false],
                MigrationSchema::indexes($this->pdo, $table), true)) {
                throw new RuntimeException('Missing authentication index.');
            }
        }
    }

    private function verifyEmailIndex(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return;
        $index = MigrationSchema::indexes($this->pdo, 'users_tbl')['idx_users_email_nocase'] ?? null;
        $columns = $this->pdo->query('PRAGMA index_xinfo(idx_users_email_nocase)')->fetchAll(PDO::FETCH_ASSOC);
        $keys = array_values(array_filter($columns, static fn(array $column): bool => (bool) $column['key']));
        if ($index !== ['unique' => true, 'columns' => ['email'], 'partial' => false]
            || count($keys) !== 1 || $keys[0]['coll'] !== 'NOCASE') {
            throw new RuntimeException('Missing or incompatible case-insensitive email index.');
        }
    }
}
