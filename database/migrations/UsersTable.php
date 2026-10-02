<?php

class UsersTable
{
    protected $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function up()
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS users_tbl (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                firstname TEXT NOT NULL,
                lastname TEXT NOT NULL,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                designation TEXT DEFAULT NULL,
                role TEXT DEFAULT 'user',
                email_verify INTEGER DEFAULT 0,
                verify_token TEXT DEFAULT NULL,
                remember_token TEXT DEFAULT NULL,
                login_time DATETIME DEFAULT NULL,
                status INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_ip TEXT DEFAULT NULL,
                failed_attempts INTEGER DEFAULT 0,
                locked_until DATETIME DEFAULT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_users_verify_token ON users_tbl (verify_token);
            CREATE INDEX IF NOT EXISTS idx_users_remember_token ON users_tbl (remember_token);
        ";

        $this->pdo->exec($sql);
    }

    public function down()
    {
        $this->pdo->exec("DROP TABLE IF EXISTS users_tbl");
        $this->pdo->exec("DROP INDEX IF EXISTS idx_users_verify_token");
        $this->pdo->exec("DROP INDEX IF EXISTS idx_users_remember_token");
    }

    public function seed()
    {
        $users = [
            [
                'firstname' => 'Admin',
                'lastname' => 'User',
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => password_hash('admin123', PASSWORD_BCRYPT),
                'role' => 'admin',
                'email_verify' => 1,
                'status' => 1
            ],
            [
                'firstname' => 'Regular',
                'lastname' => 'User',
                'name' => 'Regular User',
                'email' => 'user@example.com',
                'password' => password_hash('user123', PASSWORD_BCRYPT),
                'email_verify' => 1,
                'status' => 1
            ]
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO users_tbl 
            (firstname, lastname, name, email, password, role, email_verify, status) 
            VALUES 
            (:firstname, :lastname, :name, :email, :password, :role, :email_verify, :status)
        ");

        foreach ($users as $user) {
            $stmt->execute($user);
        }
    }
}