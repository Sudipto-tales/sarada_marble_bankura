<?php

/** REC-01 bounded recommendations. `rec_pair_support` holds one row per unique co-view/co-purchase support
    (the support_key makes refreshes idempotent, so the same session-day or order can never inflate a pair);
    co-purchase rows carry their order so a later cancellation drops them at aggregate time. `rec_suggestions`
    holds published top-N targets per source for a generation; the `recommendations` cache-version namespace
    points at the live generation (0 = none yet → similarity fallback), so a half-built generation is invisible. */
class Recommendations extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rec_pair_support (id $id,kind VARCHAR(10) NOT NULL,source_product_id INT NOT NULL,
            target_product_id INT NOT NULL,order_id INT NULL,support_key VARCHAR(190) NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(source_product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY(target_product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'rec_pair_support', 'idx_rec_support_key', ['support_key'], true);
        MigrationSchema::ensureIndex($this->pdo, 'rec_pair_support', 'idx_rec_support_src', ['source_product_id', 'kind', 'created_at'], false);

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rec_suggestions (id $id,generation BIGINT NOT NULL,source_product_id INT NOT NULL,
            target_product_id INT NOT NULL,position INT NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(source_product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY(target_product_id) REFERENCES products(id) ON DELETE CASCADE)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'rec_suggestions', 'idx_rec_sugg_unique', ['generation', 'source_product_id', 'target_product_id'], true);
        MigrationSchema::ensureIndex($this->pdo, 'rec_suggestions', 'idx_rec_sugg_src', ['generation', 'source_product_id', 'position'], false);

        $insert = $mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        if (!$this->pdo->query("SELECT id FROM catalog_versions WHERE namespace='recommendations'")->fetchColumn()) {
            $this->pdo->exec("$insert INTO catalog_versions (namespace,version) VALUES ('recommendations',0)");
        }
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'rec_pair_support', ['id'=>'id','kind'=>'varchar(10)','source_product_id'=>'int',
            'target_product_id'=>'int','order_id'=>'?int','support_key'=>'varchar(190)','created_at'=>'bigint'],
            [[['support_key'],true],[['source_product_id','kind','created_at'],false]],
            [['source_product_id','products','id','CASCADE'],['target_product_id','products','id','CASCADE'],['order_id','orders','id','SET NULL']]);
        MigrationSchema::verifyDefinition($this->pdo, 'rec_suggestions', ['id'=>'id','generation'=>'bigint','source_product_id'=>'int',
            'target_product_id'=>'int','position'=>'int','created_at'=>'bigint'],
            [[['generation','source_product_id','target_product_id'],true],[['generation','source_product_id','position'],false]],
            [['source_product_id','products','id','CASCADE'],['target_product_id','products','id','CASCADE']]);
    }
}
