<?php

/** SRCH-01: a native MySQL FULLTEXT index over product name/description/tags for the ranked search's
    lexical stage. SQLite has no FULLTEXT, so there the search falls back to bounded escaped LIKE scoring
    (handled in the service); this migration is a MySQL-only index and a no-op elsewhere. */
class ProductSearch extends Migration
{
    public function up()
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $exists = (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND INDEX_NAME='ft_products'")->fetchColumn();
            if (!$exists) $this->pdo->exec('ALTER TABLE products ADD FULLTEXT INDEX ft_products (name, description, tags)');
        }
        $this->verify();
    }

    public function verify(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return; // SQLite uses the LIKE fallback
        $exists = (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND INDEX_NAME='ft_products'")->fetchColumn();
        if (!$exists) throw new RuntimeException('Missing products fulltext index.');
    }
}
