<?php

/** OTP second factor and trusted-device/PIN storage for customer authentication. */
class CustomerOtpAuth extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        // One live code per (user, purpose): a new request replaces the old row.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS auth_otp_codes (id $id,user_id INT NOT NULL,
            purpose VARCHAR(16) NOT NULL,code_hash VARCHAR(64) NOT NULL,expires_at BIGINT NOT NULL,
            attempts INT NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE CASCADE)$suffix");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS auth_trusted_devices (id $id,user_id INT NOT NULL,
            selector VARCHAR(32) NOT NULL,validator_hash VARCHAR(64) NOT NULL,fingerprint_hash VARCHAR(64) NOT NULL,
            pin_hash VARCHAR(255) NULL,expires_at BIGINT NOT NULL,revoked_at BIGINT NULL,
            created_at BIGINT NOT NULL,last_used_at BIGINT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE CASCADE)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'auth_otp_codes', 'idx_auth_otp_owner_purpose', ['user_id','purpose'], true);
        MigrationSchema::ensureIndex($this->pdo, 'auth_otp_codes', 'idx_auth_otp_expiry', ['expires_at'], false);
        MigrationSchema::ensureIndex($this->pdo, 'auth_trusted_devices', 'idx_auth_device_selector', ['selector'], true);
        MigrationSchema::ensureIndex($this->pdo, 'auth_trusted_devices', 'idx_auth_device_owner', ['user_id'], false);
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'auth_otp_codes',
            ['id'=>'id','user_id'=>'int','purpose'=>'varchar(16)','code_hash'=>'varchar(64)','expires_at'=>'bigint','attempts'=>'int','created_at'=>'bigint'],
            [[['user_id','purpose'],true],[['expires_at'],false]], [['user_id','users_tbl','id','CASCADE']]);
        MigrationSchema::verifyDefinition($this->pdo, 'auth_trusted_devices',
            ['id'=>'id','user_id'=>'int','selector'=>'varchar(32)','validator_hash'=>'varchar(64)','fingerprint_hash'=>'varchar(64)',
                'pin_hash'=>'?varchar(255)','expires_at'=>'bigint','revoked_at'=>'?bigint','created_at'=>'bigint','last_used_at'=>'bigint'],
            [[['selector'],true],[['user_id'],false]], [['user_id','users_tbl','id','CASCADE']]);
    }
}
