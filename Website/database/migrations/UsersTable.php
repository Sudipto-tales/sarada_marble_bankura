<?php

// Preserve this legacy ledger identity. No demo identities or passwords are seeded.
class UsersTable extends Migration
{
    public function up()
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS users_tbl (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                firstname VARCHAR(190) NOT NULL, lastname VARCHAR(190) NOT NULL,
                name VARCHAR(255) NOT NULL, email VARCHAR(190) NOT NULL,
                password VARCHAR(255) NOT NULL, designation VARCHAR(190) DEFAULT NULL,
                role VARCHAR(40) DEFAULT 'user', email_verify INT DEFAULT 0,
                verify_token VARCHAR(255) DEFAULT NULL, remember_token VARCHAR(255) DEFAULT NULL,
                login_time DATETIME DEFAULT NULL, status INT DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_ip VARCHAR(45) DEFAULT NULL, failed_attempts INT DEFAULT 0, locked_until DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS users_tbl (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                firstname TEXT NOT NULL, lastname TEXT NOT NULL, name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL, password TEXT NOT NULL, designation TEXT DEFAULT NULL,
                role TEXT DEFAULT 'user', email_verify INTEGER DEFAULT 0,
                verify_token TEXT DEFAULT NULL, remember_token TEXT DEFAULT NULL,
                login_time DATETIME DEFAULT NULL, status INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_ip TEXT DEFAULT NULL, failed_attempts INTEGER DEFAULT 0, locked_until DATETIME DEFAULT NULL
            )");
        }
        // Check shape before adding indexes; never silently alter existing user data.
        $this->verifyColumns();
        MigrationSchema::ensureIndex($this->pdo, 'users_tbl', 'idx_users_email', ['email'], true);
        MigrationSchema::ensureIndex($this->pdo, 'users_tbl', 'idx_users_verify_token', ['verify_token']);
        MigrationSchema::ensureIndex($this->pdo, 'users_tbl', 'idx_users_remember_token', ['remember_token']);
    }

    public function verify(): void
    {
        $this->verifyColumns();
        $indexes = MigrationSchema::indexes($this->pdo, 'users_tbl');
        foreach ([['email', true], ['verify_token', false], ['remember_token', false]] as [$column, $unique]) {
            if (!in_array(['unique' => $unique, 'columns' => [$column], 'partial' => false], $indexes, true)) {
                throw new RuntimeException('Missing or incompatible users index.');
            }
        }
    }

    private function verifyColumns(): void
    {
        $columns = MigrationSchema::requireColumns($this->pdo, 'users_tbl', [
            'id', 'firstname', 'lastname', 'name', 'email', 'password', 'designation', 'role',
            'email_verify', 'verify_token', 'remember_token', 'login_time', 'status',
            'created_at', 'updated_at', 'last_ip', 'failed_attempts', 'locked_until']);
        MigrationSchema::requireIntegerPrimaryKey($this->pdo, 'users_tbl', $columns['id']);
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        foreach ($columns as $name => $column) {
            if (!in_array($name, ['id', 'firstname', 'lastname', 'name', 'email', 'password', 'designation', 'role',
                'email_verify', 'verify_token', 'remember_token', 'login_time', 'status', 'created_at', 'updated_at',
                'last_ip', 'failed_attempts', 'locked_until'], true)) continue;
            $type = strtolower($sqlite ? $column['type'] : $column['Type']);
            $integer = in_array($name, ['id', 'email_verify', 'status', 'failed_attempts'], true);
            $time = in_array($name, ['login_time', 'created_at', 'updated_at', 'locked_until'], true);
            $valid = $sqlite ? ($integer ? $type === 'integer' : ($time ? $type === 'datetime' : $type === 'text'))
                : ($integer ? (bool) preg_match('/\Aint(?:\(\d+\))?\z/', $type)
                    : ($time ? $type === 'datetime' : (bool) preg_match('/\Avarchar\(\d+\)\z/', $type)));
            $required = in_array($name, ['firstname', 'lastname', 'name', 'email', 'password'], true);
            if (!$valid || ($required && ($sqlite ? !$column['notnull'] : $column['Null'] !== 'NO'))) {
                throw new RuntimeException('Incompatible users column definition.');
            }
            if (!$sqlite && !$integer && !$time) {
                $minimum = ['firstname' => 190, 'lastname' => 190, 'name' => 255, 'email' => 190,
                    'password' => 255, 'designation' => 190, 'role' => 40, 'verify_token' => 255,
                    'remember_token' => 255, 'last_ip' => 45][$name];
                preg_match('/\Avarchar\((\d+)\)\z/', $type, $length);
                if ((int) $length[1] < $minimum) throw new RuntimeException('Users column would truncate supported data.');
            }
        }
    }

    public function down() { $this->pdo->exec('DROP TABLE IF EXISTS users_tbl'); }
}
