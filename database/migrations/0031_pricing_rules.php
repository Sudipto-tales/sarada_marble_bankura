<?php

/** PRC-01 default-off bounded pricing rules. A rule enables one factor (demand/inventory/campaign/
    membership) for a scope (global/category/product) with an integer parts-per-million factor, optional
    floor/ceiling, priority and effective window. The demand/inventory factors are computed from published
    metrics/stock bands in code; campaign/membership use the stored factor. A `pricing` cache-version
    namespace advances with rule writes; `order_items.price_rule_version` records what checkout applied. */
class PricingRules extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS pricing_rules (id $id,factor_type VARCHAR(16) NOT NULL,scope VARCHAR(16) NOT NULL,
            scope_id INT NULL,factor_ppm INT NOT NULL,floor_minor BIGINT NULL,ceiling_minor BIGINT NULL,
            priority INT NOT NULL DEFAULT 0,effective_from BIGINT NULL,effective_to BIGINT NULL,enabled INT NOT NULL DEFAULT 0,
            revision BIGINT NOT NULL DEFAULT 1,actor_user_id INT NULL,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,
            FOREIGN KEY(actor_user_id) REFERENCES users_tbl(id) ON DELETE SET NULL)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'pricing_rules', 'idx_pricing_active', ['factor_type', 'scope', 'enabled'], false);
        MigrationSchema::ensureIndex($this->pdo, 'pricing_rules', 'idx_pricing_scope', ['scope', 'scope_id'], false);

        if (!isset(MigrationSchema::columns($this->pdo, 'order_items')['price_rule_version'])) {
            $this->pdo->exec('ALTER TABLE order_items ADD COLUMN price_rule_version BIGINT NULL');
        }
        $insert = $mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        if (!$this->pdo->query("SELECT id FROM catalog_versions WHERE namespace='pricing'")->fetchColumn()) {
            $this->pdo->exec("$insert INTO catalog_versions (namespace,version) VALUES ('pricing',1)");
        }
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'pricing_rules', ['id'=>'id','factor_type'=>'varchar(16)','scope'=>'varchar(16)',
            'scope_id'=>'?int','factor_ppm'=>'int','floor_minor'=>'?bigint','ceiling_minor'=>'?bigint','priority'=>'int',
            'effective_from'=>'?bigint','effective_to'=>'?bigint','enabled'=>'int','revision'=>'bigint','actor_user_id'=>'?int',
            'created_at'=>'bigint','updated_at'=>'bigint'], [[['factor_type','scope','enabled'],false],[['scope','scope_id'],false]],
            [['actor_user_id','users_tbl','id','SET NULL']]);
        MigrationSchema::verifyDefinition($this->pdo, 'order_items', ['price_rule_version'=>'?bigint']);
    }
}
