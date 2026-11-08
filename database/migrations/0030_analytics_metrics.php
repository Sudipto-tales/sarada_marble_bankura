<?php

/** EVT-02 incremental analytics. `product_daily_metrics` holds unique per-product/UTC-day buckets with
    explicitly gross vs net counters (net excludes cancelled orders). `analytics_checkpoint` is a single
    observability row (max processed event id + running total); the durable work source is the pending
    `event_processing` rows, never a max(id) watermark. */
class AnalyticsMetrics extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS product_daily_metrics (id $id,product_id INT NOT NULL,metric_date CHAR(10) NOT NULL,
            views BIGINT NOT NULL DEFAULT 0,add_to_carts BIGINT NOT NULL DEFAULT 0,
            orders_gross BIGINT NOT NULL DEFAULT 0,orders_net BIGINT NOT NULL DEFAULT 0,
            units_gross_milli BIGINT NOT NULL DEFAULT 0,units_net_milli BIGINT NOT NULL DEFAULT 0,updated_at BIGINT NOT NULL,
            FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE)$suffix");
        MigrationSchema::ensureIndex($this->pdo, 'product_daily_metrics', 'idx_pdm_bucket', ['product_id', 'metric_date'], true);
        MigrationSchema::ensureIndex($this->pdo, 'product_daily_metrics', 'idx_pdm_date', ['metric_date'], false);

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS analytics_checkpoint (id $id,last_event_id BIGINT NOT NULL DEFAULT 0,
            processed_total BIGINT NOT NULL DEFAULT 0,updated_at BIGINT NOT NULL DEFAULT 0)$suffix");
        if (!$this->pdo->query('SELECT id FROM analytics_checkpoint LIMIT 1')->fetchColumn()) {
            $this->pdo->exec('INSERT INTO analytics_checkpoint (last_event_id,processed_total,updated_at) VALUES (0,0,0)');
        }
        $this->verify();
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo, 'product_daily_metrics', ['id'=>'id','product_id'=>'int','metric_date'=>'char(10)',
            'views'=>'bigint','add_to_carts'=>'bigint','orders_gross'=>'bigint','orders_net'=>'bigint',
            'units_gross_milli'=>'bigint','units_net_milli'=>'bigint','updated_at'=>'bigint'],
            [[['product_id','metric_date'],true],[['metric_date'],false]],
            [['product_id','products','id','CASCADE']]);
        MigrationSchema::verifyDefinition($this->pdo, 'analytics_checkpoint', ['id'=>'id','last_event_id'=>'bigint',
            'processed_total'=>'bigint','updated_at'=>'bigint'], [], []);
    }
}
