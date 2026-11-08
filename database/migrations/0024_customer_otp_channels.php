<?php

/** Per-user two-step preference plus an OTP contact phone, and a wider settings value column
    so admin-entered WhatsApp/SMS provider credentials fit. */
class CustomerOtpChannels extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $columns = MigrationSchema::columns($this->pdo, 'users_tbl');
        $add = [
            'otp_enabled' => $mysql ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
            'otp_channel' => $mysql ? "VARCHAR(16) NOT NULL DEFAULT 'email'" : "TEXT NOT NULL DEFAULT 'email'",
            'otp_phone'   => $mysql ? 'VARCHAR(32) DEFAULT NULL' : 'TEXT DEFAULT NULL',
        ];
        foreach ($add as $name => $definition) {
            if (!isset($columns[$name])) $this->pdo->exec("ALTER TABLE users_tbl ADD COLUMN $name $definition");
        }
        // WhatsApp Cloud API access tokens exceed the original 190-char settings value width.
        if ($mysql) $this->pdo->exec('ALTER TABLE store_settings MODIFY svalue VARCHAR(512) NOT NULL');
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::requireColumns($this->pdo, 'users_tbl', ['otp_enabled', 'otp_channel', 'otp_phone']);
    }
}
